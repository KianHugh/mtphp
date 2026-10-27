<?php
declare(strict_types=1);

namespace Mtphp\View;

use Mtphp\App;

/**
 * 模板引擎入口
 *
 * 性能要点：模板在**首次渲染**时编译为纯 PHP 文件缓存到 runtime/views，
 * 运行期直接 include（等价于手写 PHP，无运行时解析开销）。
 * 源文件更新后自动重新编译。
 *
 * 两种渲染模式：
 *  1) 普通模板：源码里没有 @extends，直接编译 + include（传统单遍）。
 *  2) 布局模板：子模板用 @extends('layout') 继承一个布局，
 *     子模板用 @section('name') ... @endsection 定义区块，
 *     布局用 @yield('name'[, '默认']) 输出区块；
 *     子模板区块里可用 @parent 引用“布局同名 yield 的默认内容”。
 *     ——采用“两遍渲染”：第一遍收集并预渲染子模板区块，第二遍渲染布局并注入区块。
 */
class Template
{
    protected static ?string $viewsPath = null;

    protected static ?string $cachePath = null;

    /** 当前渲染的数据作用域（供 @include / @component 继承） */
    protected static ?array $currentData = null;

    /** 资源栈（@push / @prepend / @stack） */
    protected static array $stacks = [];

    /** 渲染嵌套深度：仅最外层（第 1 层）重置资源栈，避免子模板/组件误清空 */
    protected static int $renderDepth = 0;

    /**
     * 显式设置视图目录与编译缓存目录
     *
     * 主要用于：单元测试（未引导 App 时也能指定路径）、或想自定义视图/缓存位置。
     * 不调用时，renderRaw 会自动从 App 实例推断（app/Views 与 runtime/views）。
     */
    public static function setPaths(string $viewsPath, string $cachePath): void
    {
        self::$viewsPath = rtrim($viewsPath, '/\\');
        self::$cachePath = rtrim($cachePath, '/\\');
    }

    protected static function paths(): void
    {
        if (self::$viewsPath === null) {
            $root              = App::instance()->root();
            self::$viewsPath   = $root . '/app/Views';
            self::$cachePath   = $root . '/runtime/views';
        }
    }

    /**
     * 渲染并返回 Response
     */
    public static function render(string $template, array $data = []): \Mtphp\Http\Response
    {
        $html = self::renderRaw($template, $data);
        return new \Mtphp\Http\Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * 渲染并返回字符串（供 @include 复用）
     */
    public static function renderRaw(string $template, array $data = []): string
    {
        self::paths();

        // 维护渲染深度与数据作用域；仅最外层重置资源栈
        self::$renderDepth++;
        $isTop = self::$renderDepth === 1;
        if ($isTop) {
            self::$stacks = [];
        }
        $prevData = self::$currentData;
        self::$currentData = $data;

        try {
            $src = self::loadSrc($template);

            // 没有 @extends → 普通模板，单遍编译渲染
            $extends = self::parseExtends($src);
            if ($extends === null) {
                $html = self::emit(self::compileToCache($template, $src), $data);
            } else {
                // 布局模板：子模板继承布局，两遍渲染
                $html = self::renderLayout($template, $src, $extends, $data);
            }
        } finally {
            // 恢复外层数据作用域与深度（支持嵌套渲染）
            self::$currentData = $prevData;
            self::$renderDepth--;
        }

        return $html;
    }

    /**
     * 布局两遍渲染：收集子模板区块 → 编译 → 注入布局并渲染
     */
    protected static function renderLayout(string $template, string $src, string $extends, array $data): string
    {
        $layoutSrc   = self::loadSrc($extends);
        $yieldsRaw   = self::parseYields($layoutSrc);   // 布局默认内容（原始源码）
        $sectionsRaw = self::parseSections($src);       // 子模板区块（原始源码）

        // @parent：把子区块里的 @parent 替换为布局同名 yield 的默认内容（原始文本层面）
        // （注意：布局默认内容里不要出现字面量 @parent，否则会被原样输出）
        foreach ($sectionsRaw as $name => &$body) {
            if (str_contains($body, '@parent')) {
                $body = str_replace('@parent', $yieldsRaw[$name] ?? '', $body);
            }
        }
        unset($body);

        // 编译（不是预渲染！）：把区块 / 默认内容各自编译成 PHP 源码字符串。
        // 这些 PHP 会在布局最终 include 时，按“当前请求的 $data”求值——
        // 这样 {{ $now }} 之类的数据不会固化进缓存，跨请求/跨数据都正确。
        $sectionsPhp = [];
        foreach ($sectionsRaw as $name => $body) {
            $sectionsPhp[$name] = Compiler::compile($body);
        }
        $yieldsPhp = [];
        foreach ($yieldsRaw as $name => $def) {
            $yieldsPhp[$name] = Compiler::compile($def);
        }

        // 注入“子模板已定义区块”集合，供布局里的 @hasSection / @sectionMissing 运行期判断
        $data['__mt_defined_sections'] = array_fill_keys(array_keys($sectionsRaw), true);

        // 编译布局：@yield 处内联区块/默认已编译 PHP（子模板优先，布局默认兜底）
        $layoutCompiled = Compiler::compileLayout($layoutSrc, $sectionsPhp, $yieldsPhp);
        $cache = self::writeLayoutCache(
            '_layout_' . md5($template . '|' . $extends),
            $layoutCompiled,
            (int) filemtime(self::$viewsPath . '/' . self::rel($template) . '.tpl'),
            (int) filemtime(self::$viewsPath . '/' . self::rel($extends) . '.tpl')
        );

        return self::emit($cache, $data);
    }

    // ==================================================================
    // 运行期助手（被编译后的模板调用：@include / @each / @stack / @class …）
    // ==================================================================

    /**
     * 当前渲染数据作用域（供 @include / @component 继承父模板数据）
     */
    public static function currentData(): ?array
    {
        return self::$currentData;
    }

    /**
     * 内联渲染子模板，并自动继承当前数据（可用第二参数覆盖/追加）。
     */
    public static function include(string $template, array $data = []): string
    {
        return self::renderRaw($template, array_merge(self::$currentData ?? [], $data));
    }

    public static function includeIf(string $template, array $data = []): string
    {
        return self::viewExists($template) ? self::include($template, $data) : '';
    }

    public static function includeWhen($cond, string $template, array $data = []): string
    {
        return $cond ? self::include($template, $data) : '';
    }

    public static function includeUnless($cond, string $template, array $data = []): string
    {
        return $cond ? '' : self::include($template, $data);
    }

    /**
     * 依次尝试多个模板名，渲染第一个存在的（Blade 的 @includeFirst）。
     */
    public static function includeFirst(array $templates, array $data = []): string
    {
        foreach ($templates as $t) {
            if (self::viewExists($t)) {
                return self::include($t, $data);
            }
        }
        return '';
    }

    /**
     * 一行模板迭代集合（Blade 的 @each）：遍历 $items，每项用 $variable 名渲染 $template；
     * 集合为空且提供了 $emptyTemplate 时，渲染空模板；否则返回空串。
     *
     * @param iterable<mixed> $items
     */
    public static function each(string $template, $items, string $variable, ?string $emptyTemplate = null): string
    {
        if (!is_iterable($items) || count((array) $items) === 0) {
            if ($emptyTemplate !== null && self::viewExists($emptyTemplate)) {
                return self::renderRaw($emptyTemplate, self::$currentData ?? []);
            }
            return '';
        }
        $out = '';
        foreach ($items as $item) {
            $out .= self::renderRaw($template, array_merge(self::$currentData ?? [], [$variable => $item]));
        }
        return $out;
    }

    /**
     * 资源栈：@push 追加、@prepend 前插、@stack 输出全部累积内容（常用于注入 CSS/JS）。
     */
    public static function push(string $name, string $content): void
    {
        self::$stacks[$name][] = $content;
    }

    public static function prepend(string $name, string $content): void
    {
        self::$stacks[$name] = array_merge([$content], self::$stacks[$name] ?? []);
    }

    public static function stack(string $name): string
    {
        return implode('', self::$stacks[$name] ?? []);
    }

    /**
     * 条件类名拼接（Blade 的 @class）：
     *   ['a', 'b']                → 始终包含 a、b
     *   ['active' => $cond]       → $cond 为真才包含 active
     */
    public static function classAttr(array $map): string
    {
        $classes = [];
        foreach ($map as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $value !== '') {
                    $classes[] = $value;
                }
            } elseif ($value) {
                $classes[] = $key;
            }
        }
        return implode(' ', $classes);
    }

    /**
     * 视图文件是否存在（用于 includeIf / includeFirst / each 的空模板判断）
     */
    protected static function viewExists(string $template): bool
    {
        self::paths();
        return is_file(self::$viewsPath . '/' . self::rel($template) . '.tpl');
    }

    /**
     * 把模板名中的 “.” 转成目录分隔符（layouts.app → layouts/app）
     */
    protected static function rel(string $template): string
    {
        return str_replace('.', '/', $template);
    }

    /**
     * 读取模板原始源码（.tpl）
     */
    protected static function loadSrc(string $template): string
    {
        $file = self::$viewsPath . '/' . self::rel($template) . '.tpl';
        if (!is_file($file)) {
            throw new \RuntimeException("模板不存在：{$file}");
        }
        $src = (string) file_get_contents($file);
        if ($src === '') {
            throw new \RuntimeException("模板为空：{$file}");
        }
        return $src;
    }

    /**
     * 解析 @extends('layout') 返回布局名；无则返回 null
     */
    protected static function parseExtends(string $src): ?string
    {
        if (preg_match("#@extends\s*\(\s*['\"]([^'\"]+)['\"]\s*\)#", $src, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * 解析子模板的所有 @section('name') ... @endsection / @show（块形式）
     *
     * 返回 [区块名 => 区块原始内容]。@show 视为与 @endsection 等价的结束符
     * （在 Blade 中 @show 同时“定义区块并立即 yield”，本框架由布局的 @yield 统一输出）。
     */
    protected static function parseSections(string $src): array
    {
        $sections = [];
        if (preg_match_all(
            "#@section\s*\(\s*['\"]([^'\"]+)['\"]\s*\)(.*?)(?:@endsection|@show)#s",
            $src,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $set) {
                // 去掉首尾空白/换行（@section 标签后的换行），输出更干净
                $sections[$set[1]] = trim($set[2]);
            }
        }
        return $sections;
    }

    /**
     * 解析布局的所有 @yield('name'[, '默认'])（块/默认形式）
     *
     * 返回 [区块名 => 默认内容原始源码]；未写默认的返回空字符串。
     */
    protected static function parseYields(string $src): array
    {
        $yields = [];
        if (preg_match_all(
            "#@yield\s*\(\s*['\"]([^'\"]+)['\"]\s*(?:,\s*['\"](.*?)['\"])?\s*\)#s",
            $src,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $set) {
                $yields[$set[1]] = $set[2] ?? '';
            }
        }
        return $yields;
    }

    /**
     * 编译普通模板到缓存文件（源更新才重写），返回缓存路径
     */
    protected static function compileToCache(string $template, string $src): string
    {
        $cache   = self::$cachePath . '/' . self::rel($template) . '.php';
        $srcFile = self::$viewsPath . '/' . self::rel($template) . '.tpl';
        if (!is_file($cache) || filemtime($cache) < filemtime($srcFile)) {
            $dir = dirname($cache);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($cache, Compiler::compile($src));
        }
        return $cache;
    }

    /**
     * 写入布局编译缓存（同时受子模板与布局源文件的 mtime 约束）
     */
    protected static function writeLayoutCache(string $name, string $compiled, int $childMtime, int $layoutMtime): string
    {
        $cache = self::$cachePath . '/' . $name . '.php';
        if (!is_file($cache) || filemtime($cache) < $childMtime || filemtime($cache) < $layoutMtime) {
            $dir = dirname($cache);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($cache, $compiled);
        }
        return $cache;
    }

    /**
     * 在 $data 作用域内 include 编译缓存并捕获输出
     *
     * 注意：编译缓存里的 PHP 一旦抛错（例如组件名缺失、数据缺失），
     * 异常必须向上抛出，绝不能靠 finally 的 return 静默吞掉——
     * 否则调用方只会拿到空串、却看不到任何报错，极难排查。
     */
    protected static function emit(string $cache, array $data): string
    {
        ob_start();
        try {
            extract($data, EXTR_SKIP);
            include $cache;
        } catch (\Throwable $e) {
            // 发生异常时先丢弃缓冲区，再原样抛出
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
