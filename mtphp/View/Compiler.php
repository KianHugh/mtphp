<?php
declare(strict_types=1);

namespace Mtphp\View;

/**
 * 模板编译器：把 .tpl 源码编译为纯 PHP（Blade 风格，思想借鉴、独立实现）
 *
 * 目标：调用极简（@extends / @section / @yield / {{ }} …），但功能向主流框架看齐。
 *
 * 输出（转义 / 原样）：
 *   {{ $var }}     → 自动 htmlspecialchars 转义（防 XSS）
 *   {!! $var !!}   → 原样输出（不转义，用于受信任的 HTML）
 *   {{-- 注释 --}}  → 整段注释，编译后完全移除
 *
 * 控制结构：
 *   @if / @elseif / @else / @endif
 *   @unless / @endunless            （条件取反）
 *   @isset($v) / @endisset          （变量是否存在）
 *   @empty($v) / @endempty          （变量是否为空）
 *   @switch($v) / @case(...) / @default / @endswitch
 *   @foreach($items as $it) / @endforeach   （自动注入 $loop 循环变量）
 *   @forelse($items as $it) ... @empty ... @endforelse   （空集合走 @empty）
 *   @for / @while / @endfor / @endwhile
 *   @continue / @break（可带条件，如 @break($i > 3)）
 *
 * 片段 / 组件：
 *   @include('tpl'[, $data])                  内联渲染子模板（继承当前数据）
 *   @includeIf / @includeWhen / @includeUnless / @includeFirst
 *   @each('row', $items, 'item'[, 'empty'])   一行模板迭代集合
 *   @component('comp'[, $props]) ... @slot('name') ... @endslot ... @endcomponent
 *   @props(['a' => 1])                         组件内声明带默认值的属性
 *
 * 资源栈（用于注入 CSS / JS）：
 *   @push('scripts') ... @endpush / @prepend(...) ... @endprepend
 *   @stack('scripts')                          输出该栈累积的内容
 *
 * 布局辅助：
 *   @hasSection('name') / @sectionMissing('name')   运行期判断子模板是否定义了某区块
 *
 * 输出助手：
 *   @json($data)            → json_encode（UNICODE / 不转义斜杠）
 *   @checked($cond) / @selected($cond) / @disabled($cond)   HTML 属性三元
 *   @class(['a' => $cond, 'b'])   条件类名拼接
 *
 * 其它：
 *   @php ... @endphp         原样 PHP 块
 *   @verbatim ... @endverbatim   原样保留（内部 {{ }} / @ 指令都不编译，给 Vue/Angular 用）
 *
 * 布局相关（由 Mtphp\View\Template 在两遍渲染流程中配合）：
 *   @extends('layout')              子模板声明继承的布局（仅子模板用）
 *   @section('name') ... @endsection/@show   定义区块（仅子模板用）
 *   @yield('name', '默认')          布局中输出区块；子模板未定义时回退默认内容
 *   @parent                        区块内引用“布局同名 yield 的默认内容”
 * 说明：@extends / @section / @parent 由 Template 解析原始源码处理；
 *       本类的 compileLayout() 专门负责把布局里的 @yield 编译成输出语句。
 */
class Compiler
{
    public static function compile(string $src): string
    {
        // 1) 保护字面量 @（Blade 风格 @@ 表示转义），避免正文里的 @ 被误当成指令
        $src = self::protectAt($src);
        $src = self::compileComments($src);
        [$src, $store] = self::compileVerbatim($src);
        $src = self::compileEcho($src);
        $src = self::compileStatements($src);
        // 还原 @verbatim 原文（原样保留，不编译）
        foreach ($store as $token => $content) {
            $src = str_replace($token, $content, $src);
        }
        // 最后还原字面量 @（@@ → @）
        return self::restoreAt($src);
    }

    /**
     * 编译“布局模板”专用方法（与 compile() 类似，但额外把 @yield 替换成区块/默认内容）。
     *
     * @param array<string,string> $sections 子模板区块已编译好的 PHP（name => php）
     * @param array<string,string> $yields   布局各 @yield 的默认内容已编译好的 PHP
     */
    public static function compileLayout(string $src, array $sections, array $yields): string
    {
        // 与 compile() 同款流水线，并同样先保护字面量 @@
        $src = self::protectAt($src);
        $src = self::compileComments($src);
        [$src, $store] = self::compileVerbatim($src);
        $src = self::compileEcho($src);
        $src = self::compileStatements($src);
        foreach ($store as $token => $content) {
            $src = str_replace($token, $content, $src);
        }

        // @yield 处内联区块/默认已编译 PHP（子模板优先，布局默认兜底）
        $src = preg_replace_callback(
            '#@yield\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*[\'"](.*?)[\'"])?\s*\)#s',
            static function (array $m) use ($sections, $yields): string {
                return $sections[$m[1]] ?? ($yields[$m[1]] ?? '');
            },
            $src
        );

        return self::restoreAt($src);
    }

    // ------------------------------------------------------------------
    // 字面量 @ 保护（Blade 风格 @@ 转义）
    // ------------------------------------------------------------------

    /**
     * 编译前把 “@@” 替换成占位符，避免正文里的 “@” 被误当成指令。
     * 例如用户想在页面里显示字面的 “@default”，应写成 “@@default”。
     */
    protected static function protectAt(string $src): string
    {
        return str_replace('@@', "\x00MTAT\x00", $src);
    }

    /**
     * 编译结束后把占位符还原为真实 “@”（@@ 的语义是“字面量 @”）。
     */
    protected static function restoreAt(string $src): string
    {
        return str_replace("\x00MTAT\x00", '@', $src);
    }

    // ------------------------------------------------------------------
    // 注释 / 原样块
    // ------------------------------------------------------------------

    /**
     * 移除 Blade 风格注释 {{-- --}}（支持跨行）
     */
    protected static function compileComments(string $src): string
    {
        return preg_replace('#\{\{--.*?--\}\}#s', '', $src);
    }

    /**
     * 抽取 @verbatim ... @endverbatim 块，用带空字节的占位符暂存，编译结束后再还原。
     * 这样块内的 {{ }} / @ 指令都不会被编译，适合放前端框架模板。
     *
     * @return array{0: string, 1: array<string,string>}
     */
    protected static function compileVerbatim(string $src): array
    {
        $store = [];
        $src = preg_replace_callback(
            '#@verbatim(.*?)@endverbatim#s',
            static function (array $m) use (&$store): string {
                $token = "\x00MTV" . count($store) . "\x00";
                $store[$token] = $m[1];
                return $token;
            },
            $src
        );
        return [$src, $store];
    }

    // ------------------------------------------------------------------
    // 输出（转义 / 原样）
    // ------------------------------------------------------------------

    protected static function compileEcho(string $src): string
    {
        // 不转义输出 {!! !!}
        $src = preg_replace('#\{\!!\s*(.+?)\s*\!\!\}#', '<?= ($1) ?>', $src);
        // 转义输出 {{ }}（防 XSS：ENT_QUOTES 同时转义单/双引号；SUBSTITUTE 处理非法字符）
        $src = preg_replace(
            '#\{\{\s*(.+?)\s*\}\}#',
            '<?= htmlspecialchars((string) ($1 ?? \'\'), ENT_QUOTES | ENT_SUBSTITUTE) ?>',
            $src
        );
        return $src;
    }

    // ------------------------------------------------------------------
    // 指令
    // ------------------------------------------------------------------

    protected static function compileStatements(string $src): string
    {
        // 1) 原样 PHP 块（最先处理，避免内部被其它指令/输出误伤）
        //    @php 是“无括号块指令”，故 $allowBare = true；其余块指令都必须带 “(”
        $src = self::replaceBlock(
            $src,
            '@php',
            '@endphp',
            static fn (string $args, string $body): string => '<?php ' . $body . ' ?>',
            true
        );

        // 2) 组件（含 @slot 解析）：放在通用指令之前，使其内部指令也能被编译
        $src = self::replaceBlock($src, '@component', '@endcomponent', [self::class, 'buildComponent']);

        // 3) forelse（含 @empty 块）：放在独立 @empty 之前处理
        $src = self::compileForelse($src);

        // 4) 含括号的指令（条件/集合表达式支持任意层级括号嵌套，用配平扫描而非正则递归）
        $src = self::replaceDirective($src, '@if',      static fn ($a) => '<?php if (' . $a . '): ?>');
        $src = self::replaceDirective($src, '@elseif',  static fn ($a) => '<?php elseif (' . $a . '): ?>');
        $src = self::replaceDirective($src, '@unless',  static fn ($a) => '<?php if (!(' . $a . ')): ?>');
        $src = self::replaceDirective($src, '@isset',   static fn ($a) => '<?php if (isset(' . $a . ')): ?>');
        $src = self::replaceDirective($src, '@empty',   static fn ($a) => '<?php if (empty(' . $a . ')): ?>');
        // @switch：编译为 “<?php switch (...) :” 形式（带结束标签）。注意下方会统一去掉
        // @case / @default 行的前导缩进，否则 switch 关闭标签与首个 case 之间的空白会被当成
        // HTML 输出，触发 “unexpected T_INLINE_HTML” 语法错误（case 之间的缩进则不受影响）。
        $src = self::replaceDirective($src, '@switch',  static fn ($a) => '<?php switch (' . $a . '): ?>');
        $src = self::replaceDirective($src, '@case',    static fn ($a) => '<?php case ' . $a . ': ?>');
        $src = self::replaceDirective($src, '@foreach', static fn ($a) => self::compileForeach($a));
        $src = self::replaceDirective($src, '@for',     static fn ($a) => '<?php for (' . $a . '): ?>');
        $src = self::replaceDirective($src, '@while',   static fn ($a) => '<?php while (' . $a . '): ?>');
        $src = self::replaceDirective($src, '@json',    static fn ($a) => '<?php echo json_encode(' . $a . ', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>');
        $src = self::replaceDirective($src, '@checked', static fn ($a) => '<?php echo (' . $a . ') ? \'checked\' : \'\'; ?>');
        $src = self::replaceDirective($src, '@selected',static fn ($a) => '<?php echo (' . $a . ') ? \'selected\' : \'\'; ?>');
        $src = self::replaceDirective($src, '@disabled',static fn ($a) => '<?php echo (' . $a . ') ? \'disabled\' : \'\'; ?>');
        $src = self::replaceDirective($src, '@class',   static fn ($a) => '<?php echo \\Mtphp\\View\\Template::classAttr(' . $a . '); ?>');
        $src = self::replaceDirective($src, '@props',   static fn ($a) => '<?php foreach ((' . $a . ') as $__k => $__v) { if (!isset($$__k)) { $$__k = $__v; } } unset($__k, $__v); ?>');
        $src = self::replaceDirective($src, '@each',    static fn ($a) => self::compileEach($a));

        // 5) 片段系列（include / includeIf / includeWhen / includeUnless / includeFirst）
        $src = self::replaceInclude($src);

        // 6) 字符串参数指令（区块判断 / 资源栈）
        $src = preg_replace_callback(
            "#@hasSection\s*\(\s*['\"]([^'\"]+)['\"]\s*\)#",
            static fn (array $m): string => '<?php if (!empty($__mt_defined_sections[\'' . $m[1] . '\'])): ?>',
            $src
        );
        $src = preg_replace_callback(
            "#@sectionMissing\s*\(\s*['\"]([^'\"]+)['\"]\s*\)#",
            static fn (array $m): string => '<?php if (empty($__mt_defined_sections[\'' . $m[1] . '\'])): ?>',
            $src
        );
        $src = preg_replace_callback(
            "#@stack\s*\(\s*['\"]([^'\"]+)['\"]\s*\)#",
            static fn (array $m): string => '<?php echo \\Mtphp\\View\\Template::stack(\'' . $m[1] . '\'); ?>',
            $src
        );
        $src = self::replaceBlock(
            $src,
            '@push',
            '@endpush',
            static fn (string $args, string $body): string => self::buildPush($args, $body, false)
        );
        $src = self::replaceBlock(
            $src,
            '@prepend',
            '@endprepend',
            static fn (string $args, string $body): string => self::buildPush($args, $body, true)
        );

        // 7) 闭合标签（统一收尾）
        $src = preg_replace('#@endif\b#',      '<?php endif; ?>',     $src);
        $src = preg_replace('#@endunless\b#',  '<?php endif; ?>',     $src);
        $src = preg_replace('#@endisset\b#',   '<?php endif; ?>',     $src);
        $src = preg_replace('#@endempty\b#',   '<?php endif; ?>',     $src);
        $src = preg_replace('#@endswitch\b#',  '<?php endswitch; ?>', $src);
        $src = preg_replace('#@default\b#',     '<?php default: ?>',   $src);

        // 7.1) 去掉 @case / @default 行的前导缩进，保证“首个 case”紧贴 @switch 的关闭标签。
        // 原因：PHP 不允许 switch 打开标签与首个 case 之间出现任何内联输出（含空白），
        // 否则报 “unexpected T_INLINE_HTML”。case 之间的缩进不受影响（允许内联输出）。
        // 同时兼容 Windows 的 \r\n 换行。
        $src = preg_replace('#\r?\n[ \t]*<\?php (case|default)#', "\n<?php $1", $src);
        $src = preg_replace('#@endforeach\b#', '<?php endforeach; $loop = $__mt_loop_parent; ?>', $src);
        $src = preg_replace('#@endfor\b#',     '<?php endfor; ?>',    $src);
        $src = preg_replace('#@endwhile\b#',   '<?php endwhile; ?>',  $src);

        // 8) @else（放在 @elseif 之后，避免误伤 @elseif）
        $src = preg_replace('#@else\b#', '<?php else: ?>', $src);

        // 9) 循环控制（支持可选条件）
        $src = self::replaceDirective(
            $src,
            '@continue',
            static fn ($a) => ($a !== null && $a !== '')
                ? '<?php if (' . $a . ') continue; ?>'
                : '<?php continue; ?>',
            true
        );
        $src = self::replaceDirective(
            $src,
            '@break',
            static fn ($a) => ($a !== null && $a !== '')
                ? '<?php if (' . $a . ') break; ?>'
                : '<?php break; ?>',
            true
        );

        return $src;
    }

    /**
     * 通用指令替换：找到所有 $kw 指令，用括号配平截取参数（允许嵌套括号），调用回调生成替换串。
     *
     * 约定：
     *   - $optionalParen=false（默认）：指令必须带 “(”，否则视为正文文本（例如标题里出现的
     *     “（@foreach）” 这种中文全角括号包裹的指令名）跳过，避免误判；
     *   - $optionalParen=true（如 @continue / @break）：无 “(” 时仍以无参形式处理。
     *
     * @param callable(string|null):string $cb
     */
    protected static function replaceDirective(string $src, string $kw, callable $cb, bool $optionalParen = false): string
    {
        $len = strlen($src);
        $nlen = strlen($kw);
        $i = 0;
        $hits = [];
        while (($pos = strpos($src, $kw, $i)) !== false) {
            $j = $pos + $nlen;
            while ($j < $len && ctype_space($src[$j])) {
                $j++;
            }
            if ($j < $len && $src[$j] === '(') {
                $mp = self::matchParen($src, $j);
                if ($mp === null) {        // 括号未闭合，跳过该处
                    $i = $pos + $nlen;
                    continue;
                }
                $hits[] = ['start' => $pos, 'args' => $mp['content'], 'end' => $mp['end']];
            } elseif ($optionalParen) {
                // 可选括号指令：无 “(” 时按无参处理
                $hits[] = ['start' => $pos, 'args' => null, 'end' => $j];
            }
            // 其余情况（非可选指令且无 “(”）：视为正文，跳过
            $i = $pos + $nlen;
        }
        // 从后往前替换，保证偏移有效
        for ($k = count($hits) - 1; $k >= 0; $k--) {
            $h = $hits[$k];
            $rep = $cb($h['args']);
            $src = substr($src, 0, $h['start']) . $rep . substr($src, $h['end']);
        }
        return $src;
    }

    /**
     * 块指令替换：@kw(可选参数) ... @endKw，捕获参数与块体，调用回调生成替换串。
     *
     * $allowBare=false（默认）：指令必须带 “(”，否则视为正文文本跳过。
     *   这避免了正文里出现的指令名（例如标题里的 “@component”“@push”）被当成块起点、
     *   进而贪婪匹配到文件后面真正的 @endcomponent/@endpush 而把整段正文吞掉。
     * $allowBare=true（仅 @php）：允许无括号的裸块（@php ... @endphp）。
     *
     * @param callable(string,string):string $cb
     */
    protected static function replaceBlock(string $src, string $kw, string $endKw, callable $cb, bool $allowBare = false): string
    {
        $len = strlen($src);
        $nlen = strlen($kw);
        $i = 0;
        $hits = [];
        while (($pos = strpos($src, $kw, $i)) !== false) {
            $j = $pos + $nlen;
            while ($j < $len && ctype_space($src[$j])) {
                $j++;
            }
            if ($j < $len && $src[$j] === '(') {
                $mp = self::matchParen($src, $j);
                if ($mp === null) {
                    $i = $pos + $nlen;
                    continue;
                }
                $args = $mp['content'];
                $end = $mp['end'];
            } elseif ($allowBare) {
                // 仅 @php 允许无括号裸块
                $args = '';
                $end = $j;
            } else {
                // 必须带 “(” 却没带的块指令（如正文里的 @component/@push 字样）→ 当文本跳过
                $i = $pos + $nlen;
                continue;
            }
            $endPos = strpos($src, $endKw, $end);
            if ($endPos === false) {
                $i = $pos + $nlen;
                continue;
            }
            $body = substr($src, $end, $endPos - $end);
            $hits[] = ['start' => $pos, 'args' => $args, 'end' => $endPos + strlen($endKw), 'body' => $body];
            $i = $pos + $nlen;
        }
        for ($k = count($hits) - 1; $k >= 0; $k--) {
            $h = $hits[$k];
            $rep = $cb($h['args'], $h['body']);
            $src = substr($src, 0, $h['start']) . $rep . substr($src, $h['end']);
        }
        return $src;
    }

    /**
     * 从 $s 的 $openPos（须指向 '('）开始，配平截取对应 ')' 内的内容。
     * 正确处理字符串字面量内的括号与转义，返回 ['content' => 内部串, 'end' => 结束 ')' 后位置]；失败返回 null。
     *
     * @return array{content: string, end: int}|null
     */
    protected static function matchParen(string $s, int $openPos): ?array
    {
        $len = strlen($s);
        if ($openPos < 0 || $openPos >= $len || $s[$openPos] !== '(') {
            return null;
        }
        $depth = 0;
        $inStr = null;
        for ($i = $openPos; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr !== null) {
                if ($c === $inStr && ($i === 0 || $s[$i - 1] !== '\\')) {
                    $inStr = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inStr = $c;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return [
                        'content' => substr($s, $openPos + 1, $i - $openPos - 1),
                        'end'     => $i + 1,
                    ];
                }
            }
        }
        return null;
    }

    /**
     * 编译 @foreach：自动注入 $loop 循环变量（见 Mtphp\View\Loop）
     */
    protected static function compileForeach(string $inner): string
    {
        $inner = trim($inner);
        $parts = preg_split('/\s+as\s+/', $inner, 2);
        if ($parts === false || count($parts) < 2) {
            $items = $inner;
            $binding = '$__mt_value';
        } else {
            $items = trim($parts[0]);
            $binding = trim($parts[1]);
        }
        $open = "<?php \$__mt_loop_parent = (isset(\$loop) ? \$loop : null); "
              . "\$__mt_loop = new \\Mtphp\\View\\Loop({$items}, \$__mt_loop_parent); ?>\n"
              . "<?php foreach (\$__mt_loop->items() as {$binding}): \$loop = \$__mt_loop->advance(); ?>";
        return $open;
    }

    /**
     * 编译 @forelse：集合为空时走 @empty 分支（同样注入 $loop）
     */
    protected static function compileForelse(string $src): string
    {
        $len = strlen($src);
        $i = 0;
        $hits = [];
        while (($pos = strpos($src, '@forelse', $i)) !== false) {
            $j = $pos + strlen('@forelse');
            while ($j < $len && ctype_space($src[$j])) {
                $j++;
            }
            if ($j >= $len || $src[$j] !== '(') {
                $i = $pos + strlen('@forelse');
                continue;
            }
            $mp = self::matchParen($src, $j);
            if ($mp === null) {
                $i = $pos + strlen('@forelse');
                continue;
            }
            $emptyPos = self::strposToken($src, '@empty', $mp['end']);
            $endPos = strpos($src, '@endforelse', $mp['end']);
            if ($emptyPos === false || $endPos === false) {
                $i = $pos + strlen('@forelse');
                continue;
            }
            $body = substr($src, $mp['end'], $emptyPos - $mp['end']);
            $emptyBody = substr($src, $emptyPos + strlen('@empty'), $endPos - ($emptyPos + strlen('@empty')));
            $hits[] = ['start' => $pos, 'args' => $mp['content'], 'body' => $body, 'empty' => $emptyBody, 'end' => $endPos + strlen('@endforelse')];
            $i = $pos + strlen('@forelse');
        }
        for ($k = count($hits) - 1; $k >= 0; $k--) {
            $h = $hits[$k];
            $inner = trim($h['args']);
            $parts = preg_split('/\s+as\s+/', $inner, 2);
            $items = trim($parts[0] ?? '$__mt_items');
            $binding = trim($parts[1] ?? '$__mt_value');
            $open = "<?php \$__mt_loop_parent = (isset(\$loop) ? \$loop : null); "
                  . "\$__mt_loop = new \\Mtphp\\View\\Loop({$items}, \$__mt_loop_parent); ?>\n"
                  . "<?php if (\$__mt_loop->count === 0): ?>\n"
                  . $h['empty'] . "\n"
                  . "<?php else: ?>\n"
                  . "<?php foreach (\$__mt_loop->items() as {$binding}): \$loop = \$__mt_loop->advance(); ?>";
            $close = "<?php endforeach; ?>\n<?php endif; ?>\n<?php \$loop = \$__mt_loop_parent; ?>";
            $src = substr($src, 0, $h['start']) . $open . $h['body'] . $close . substr($src, $h['end']);
        }
        return $src;
    }

    /**
     * 编译 @component：抽取 @slot 具名插槽与默认插槽，渲染指定组件视图。
     */
    protected static function buildComponent(string $args, string $body): string
    {
        $argList = self::splitTopLevel($args);
        $view = $argList[0] ?? "''";        // 组件视图名（字符串字面量）
        $props = $argList[1] ?? '[]';        // 组件属性（数组表达式），默认空

        // 先抽出具名插槽 @slot('name')...@endslot，并从 body 中移除
        $slots = [];
        $body = preg_replace_callback(
            '#@slot\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)(?<sbody>.*?)@endslot#s',
            static function (array $sm) use (&$slots): string {
                $slots[$sm[1]] = $sm['sbody'];
                return '';
            },
            $body
        );

        $php = "<?php \$__mt_slots = []; ?>\n";
        foreach ($slots as $name => $content) {
            $php .= "<?php ob_start(); ?>\n" . $content
                  . "\n<?php \$__mt_slots['" . $name . "'] = ob_get_clean(); ?>\n";
        }
        // 默认插槽（body 中未被 @slot 包裹的内容）
        $php .= "<?php ob_start(); ?>\n" . $body
              . "\n<?php \$__mt_slots['__default'] = ob_get_clean(); "
              . "\$__mt_slots['slot'] = \$__mt_slots['__default']; ?>\n";
        $php .= '<?php echo \\Mtphp\\View\\Template::renderRaw(' . $view
              . ', array_merge(\\Mtphp\\View\\Template::currentData() ?? [], ' . $props . ', $__mt_slots)); ?>';
        return $php;
    }

    /**
     * 编译 @include 系列（include / includeIf / includeWhen / includeUnless / includeFirst）
     */
    protected static function replaceInclude(string $src): string
    {
        $len = strlen($src);
        $i = 0;
        $hits = [];
        while (($pos = strpos($src, '@include', $i)) !== false) {
            $after = $pos + strlen('@include');
            $suffix = '';
            foreach (['If', 'When', 'Unless', 'First'] as $suf) {
                if (substr($src, $after, strlen($suf)) === $suf) {
                    $suffix = $suf;
                    break;
                }
            }
            $j = $after + strlen($suffix);
            while ($j < $len && ctype_space($src[$j])) {
                $j++;
            }
            if ($j >= $len || $src[$j] !== '(') {
                $i = $after;
                continue;
            }
            $mp = self::matchParen($src, $j);
            if ($mp === null) {
                $i = $after;
                continue;
            }
            $hits[] = ['start' => $pos, 'args' => $mp['content'], 'end' => $mp['end'], 'suffix' => $suffix];
            $i = $after;
        }
        for ($k = count($hits) - 1; $k >= 0; $k--) {
            $h = $hits[$k];
            $rep = self::compileInclude($h['suffix'], $h['args']);
            $src = substr($src, 0, $h['start']) . $rep . substr($src, $h['end']);
        }
        return $src;
    }

    /**
     * 生成 @include 系列对应的运行时调用代码
     */
    protected static function compileInclude(string $suffix, string $args): string
    {
        $argList = self::splitTopLevel($args);
        switch ($suffix) {
            case 'If':
                $name = $argList[0] ?? "''";
                $data = $argList[1] ?? '[]';
                return '<?php echo \\Mtphp\\View\\Template::includeIf(' . $name . ', ' . $data . '); ?>';
            case 'When':
                $cond = $argList[0] ?? 'false';
                $name = $argList[1] ?? "''";
                $data = $argList[2] ?? '[]';
                return '<?php echo \\Mtphp\\View\\Template::includeWhen(' . $cond . ', ' . $name . ', ' . $data . '); ?>';
            case 'Unless':
                $cond = $argList[0] ?? 'false';
                $name = $argList[1] ?? "''";
                $data = $argList[2] ?? '[]';
                return '<?php echo \\Mtphp\\View\\Template::includeUnless(' . $cond . ', ' . $name . ', ' . $data . '); ?>';
            case 'First':
                $names = $argList[0] ?? '[]';
                $data = $argList[1] ?? '[]';
                return '<?php echo \\Mtphp\\View\\Template::includeFirst(' . $names . ', ' . $data . '); ?>';
            default:
                $name = $argList[0] ?? "''";
                $data = $argList[1] ?? '[]';
                return '<?php echo \\Mtphp\\View\\Template::include(' . $name . ', ' . $data . '); ?>';
        }
    }

    /**
     * 编译 @each('row', $items, 'item'[, 'empty'])：一行模板迭代集合
     */
    protected static function compileEach(string $inner): string
    {
        $argList = self::splitTopLevel($inner);
        $tpl = $argList[0] ?? "''";
        $items = $argList[1] ?? '[]';
        $var = $argList[2] ?? "''";
        $empty = $argList[3] ?? 'null';
        return '<?php echo \\Mtphp\\View\\Template::each(' . $tpl . ', ' . $items . ', ' . $var . ', ' . $empty . '); ?>';
    }

    /**
     * 生成 @push / @prepend 的资源栈代码
     */
    protected static function buildPush(string $args, string $body, bool $prepend): string
    {
        $name = trim($args, " '\"");
        $method = $prepend ? 'prepend' : 'push';
        return "<?php ob_start(); ?>\n" . $body
             . "\n<?php \\Mtphp\\View\\Template::{$method}('" . $name . "', ob_get_clean()); ?>";
    }

    /**
     * 查找指令关键字 $token（如 @empty），但排除其后紧跟 '(' 的情况（那是独立 @empty($x) 指令）。
     */
    protected static function strposToken(string $s, string $token, int $from): int|false
    {
        $len = strlen($s);
        $tlen = strlen($token);
        $i = $from;
        while (($p = strpos($s, $token, $i)) !== false) {
            $q = $p + $tlen;
            while ($q < $len && ctype_space($s[$q])) {
                $q++;
            }
            if ($q >= $len || $s[$q] !== '(') {
                return $p;
            }
            $i = $p + $tlen;
        }
        return false;
    }

    /**
     * 按顶层逗号切分参数（忽略 (), [], {}, 字符串内的逗号），用于 include/each/component 的参数解析。
     *
     * @return array<int,string>
     */
    protected static function splitTopLevel(string $s, string $sep = ','): array
    {
        $out = [];
        $depth = 0;
        $inStr = null;
        $buf = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr !== null) {
                $buf .= $c;
                if ($c === $inStr && ($i === 0 || $s[$i - 1] !== '\\')) {
                    $inStr = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inStr = $c;
                $buf .= $c;
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
                $buf .= $c;
                continue;
            }
            if ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                $buf .= $c;
                continue;
            }
            if ($c === $sep && $depth === 0) {
                $out[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if ($buf !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
