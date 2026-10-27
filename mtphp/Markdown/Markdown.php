<?php
declare(strict_types=1);

namespace Mtphp\Markdown;

/**
 * 轻量 Markdown → HTML 渲染器（零依赖）
 *
 * 覆盖本框架《使用手册》所需的语法子集：
 *   - 标题（# ~ ######，自动生成锚点 id）
 *   - 段落（段内换行转 <br>）
 *   - 无序列表（- / *）与有序列表（1.）
 *   - 引用块（>）
 *   - 分隔线（--- / ***）
 *   - 围栏代码块（``` 或 ```lang，内容原样转义）
 *   - 表格（| 表头 | --- | 表体 |，支持 :---/:--:/---: 对齐）
 *   - 行内：行内代码 `code`、加粗 **x**、斜体 *x*、链接 [t](url)
 *
 * 设计要点：
 *   - 默认对正文做 htmlspecialchars 转义，输出安全（防 XSS）；
 *   - 围栏代码块优先抽取为占位符，避免其内部被当作 Markdown 解析；
 *   - 不依赖任何外部扩展，符合框架“使用简单、配置简单”的原则。
 */
class Markdown
{
    /**
     * 渲染整段 Markdown 为 HTML 字符串
     */
    public static function render(string $markdown): string
    {
        // 1) 归一化换行
        $md = str_replace(["\r\n", "\r"], "\n", $markdown);

        // 2) 抽取围栏代码块（防止块内内容被当成 Markdown 解析）
        $codeStore = [];
        $md = preg_replace_callback(
            '/```([a-zA-Z0-9_+-]*)[ \t]*\n(.*?)```/s',
            static function (array $m) use (&$codeStore): string {
                $lang = trim($m[1]);
                $code = rtrim($m[2], "\n");
                $token = "\x00MD" . count($codeStore) . "\x00";
                $cls   = $lang !== '' ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES) . '"' : '';
                $codeStore[$token] = '<pre><code' . $cls . '>' . htmlspecialchars($code, ENT_QUOTES) . '</code></pre>';
                return $token;
            },
            $md
        );

        // 3) 按块解析
        $html = self::parseBlocks($md);

        // 4) 还原代码块
        foreach ($codeStore as $token => $snippet) {
            $html = str_replace($token, $snippet, $html);
        }

        return $html;
    }

    /**
     * 块级解析：逐行扫描，识别标题/分隔线/表格/引用/列表/段落
     */
    protected static function parseBlocks(string $md): string
    {
        $lines = explode("\n", $md);
        $n     = count($lines);
        $out   = '';
        $i     = 0;

        while ($i < $n) {
            $line = $lines[$i];

            // 空行：跳过
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // 标题 # ~ ######
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = trim($m[2]);
                $out  .= '<h' . $level . ' id="' . self::slug($text) . '">' . self::inline($text) . '</h' . $level . ">\n";
                $i++;
                continue;
            }

            // 分隔线 --- / *** / ___
            if (preg_match('/^([-*_])\1{2,}\s*$/', $line)) {
                $out .= "<hr>\n";
                $i++;
                continue;
            }

            // 表格：表头行含 ≥2 个 |，且下一行是分隔行
            if (self::isTableStart($line, $i + 1 < $n ? $lines[$i + 1] : '')) {
                [$table, $i] = self::parseTable($lines, $i);
                $out .= $table;
                continue;
            }

            // 引用块 >
            if (str_starts_with(trim($line), '>')) {
                [$quote, $i] = self::parseQuote($lines, $i);
                $out .= $quote;
                continue;
            }

            // 列表（- / * / 1.）
            if (preg_match('/^\s*([-*]|\d+\.)\s+/', $line)) {
                [$list, $i] = self::parseList($lines, $i);
                $out .= $list;
                continue;
            }

            // 段落：收集连续的非空、非特殊行
            $buf = [];
            while (
                $i < $n
                && trim($lines[$i]) !== ''
                && !preg_match('/^(#{1,6})\s+/', $lines[$i])
                && !preg_match('/^([-*_])\1{2,}\s*$/', $lines[$i])
                && !str_starts_with(trim($lines[$i]), '>')
                && !preg_match('/^\s*([-*]|\d+\.)\s+/', $lines[$i])
                && !self::isTableStart($lines[$i], $i + 1 < $n ? $lines[$i + 1] : '')
            ) {
                $buf[] = $lines[$i];
                $i++;
            }
            $text = self::inline(implode("\n", $buf));
            $text = str_replace("\n", "<br>\n", $text);
            $out .= '<p>' . $text . "</p>\n";
        }

        return $out;
    }

    /**
     * 是否为表格起始行（表头）
     */
    protected static function isTableStart(string $line, string $next): bool
    {
        if (substr_count($line, '|') < 2) {
            return false;
        }
        if (!str_contains($next, '-')) {
            return false;
        }
        return (bool) preg_match('/^\s*\|?[\s:|-]+\|?\s*$/', $next);
    }

    /**
     * 解析表格（表头 + 分隔行 + 表体）
     */
    protected static function parseTable(array $lines, int $start): array
    {
        $header  = trim($lines[$start]);
        $sep     = trim($lines[$start + 1]);
        $headers = self::splitRow($header);

        $align = [];
        foreach (self::splitRow($sep) as $c) {
            $c = trim($c);
            $left  = str_starts_with($c, ':');
            $right = str_ends_with($c, ':');
            $align[] = $right && $left ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
        }

        $html  = "<table>\n<thead>\n<tr>";
        foreach ($headers as $idx => $cell) {
            $html .= self::thTd('th', $align[$idx] ?? '', trim($cell));
        }
        $html .= "</tr>\n</thead>\n<tbody>\n";

        $i = $start + 2;
        $n = count($lines);
        while ($i < $n && trim($lines[$i]) !== '' && substr_count($lines[$i], '|') >= 2) {
            $cells = self::splitRow($lines[$i]);
            $html .= "<tr>";
            foreach ($headers as $idx => $_) {
                $html .= self::thTd('td', $align[$idx] ?? '', trim($cells[$idx] ?? ''));
            }
            $html .= "</tr>\n";
            $i++;
        }
        $html .= "</tbody>\n</table>\n";

        return [$html, $i];
    }

    protected static function thTd(string $tag, string $align, string $content): string
    {
        $attr = $align !== '' ? ' style="text-align:' . $align . '"' : '';
        return '<' . $tag . $attr . '>' . self::inline($content) . '</' . $tag . '>';
    }

    /**
     * 按 | 切分一行（去掉首尾的 |）
     */
    protected static function splitRow(string $row): array
    {
        $row = trim($row);
        if (str_starts_with($row, '|')) {
            $row = substr($row, 1);
        }
        if (str_ends_with($row, '|')) {
            $row = substr($row, 0, -1);
        }
        return array_map('trim', explode('|', $row));
    }

    /**
     * 解析引用块（连续以 > 开头的行）
     */
    protected static function parseQuote(array $lines, int $start): array
    {
        $buf = [];
        $i   = $start;
        $n   = count($lines);
        while ($i < $n && str_starts_with(trim($lines[$i]), '>')) {
            $buf[] = ltrim(substr(trim($lines[$i]), 1));
            $i++;
        }
        return ["<blockquote>\n<p>" . self::inline(implode("\n", $buf)) . "</p>\n</blockquote>\n", $i];
    }

    /**
     * 解析列表（仅单层；空行结束）
     */
    protected static function parseList(array $lines, int $start): array
    {
        $i       = $start;
        $n       = count($lines);
        $ordered = null;
        $items   = [];

        while ($i < $n) {
            if (!preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $lines[$i], $m)) {
                break;
            }
            $isOrdered = (bool) preg_match('/^\d+\.$/', $m[2]);
            if ($ordered === null) {
                $ordered = $isOrdered;
            }
            $items[] = $m[3];
            $i++;
            // 遇到空行即结束当前列表（简化：不支持跨空行的连续列表）
            if ($i < $n && trim($lines[$i]) === '') {
                break;
            }
        }

        $tag  = $ordered ? 'ol' : 'ul';
        $html = '<' . $tag . ">\n";
        foreach ($items as $it) {
            $html .= '<li>' . self::inline($it) . "</li>\n";
        }
        $html .= '</' . $tag . ">\n";

        return [$html, $i];
    }

    /**
     * 行内解析：先整体转义，再处理代码/加粗/斜体/链接
     *
     * 注意：行内代码必须“先抽取为占位符”，否则其内部字符（如 *.tpl 的 *）
     * 会被后续的加粗/斜体规则误伤。还原在最后一步完成。
     */
    protected static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES);

        // 1) 抽取行内代码为占位符（保护内部不被其它行内规则处理）
        $store = [];
        $text  = preg_replace_callback(
            '/`([^`]+)`/',
            static function (array $m) use (&$store): string {
                $token            = "\x00IC" . count($store) . "\x01";
                $store[$token]    = '<code>' . $m[1] . '</code>';
                return $token;
            },
            $text
        );

        // 2) 加粗 **x**
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

        // 3) 斜体 *x*
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);

        // 4) 链接 [text](url)
        //    安全：拦截 javascript:/vbscript:/data:/file: 等危险协议（XSS 注入点），
        //    命中则降级为锚点 #，避免渲染出可执行的 <a href="javascript:...">。
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            static function (array $m): string {
                $url = trim($m[2]);
                if (preg_match('#^(?i:javascript|vbscript|data|file):#', $url)) {
                    return '<a href="#">' . $m[1] . '</a>';
                }
                return '<a href="' . $url . '">' . $m[1] . '</a>';
            },
            $text
        );

        // 5) 还原行内代码
        foreach ($store as $token => $snippet) {
            $text = str_replace($token, $snippet, $text);
        }

        return $text;
    }

    /**
     * 生成 URL/锚点友好的 slug（中文保留，空白与标点转连字符）
     */
    public static function slug(string $text): string
    {
        $text = (string) preg_replace('/[^\p{L}\p{N}+]+/u', '-', trim($text));
        $text = trim($text, '-');
        return $text === '' ? 'section' : mb_strtolower($text);
    }
}
