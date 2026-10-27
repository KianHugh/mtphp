<?php
declare(strict_types=1);

namespace App\Controllers;

use Mtphp\App;
use Mtphp\Markdown\Markdown;
use Mtphp\Http\Response;

/**
 * 文档控制器
 *
 * 把《使用手册.md》按二级标题（##）拆成章节，配合左侧菜单 + 右侧内容
 * 的文档布局呈现。当前首页（home.tpl 的欢迎内容）直接作为“序言”页。
 *
 * 路由：
 *   GET /docs          → 序言（preface）
 *   GET /docs/{slug}   → 对应章节（slug 为章节编号，如 1、2 … 21）
 */
class DocController
{
    /** 文档源文件（框架根目录下的使用手册） */
    private const MANUAL = '使用手册.md';

    /** 序言（复用首页的核心特性清单） */
    private const FEATURES = [
        'RESTful 路由（含参数与正则约束、分组、编译缓存）',
        'PDO 数据库 + 链式查询构造器',
        '事务（含嵌套 SAVEPOINT）+ 持久连接',
        '模板引擎（编译缓存 + 自动转义防 XSS）+ 主流指令集',
        '中间件（洋葱模型）',
        'IoC 容器与依赖注入（PHP 8 联合类型安全）',
        'Swoole 常驻内存 + 协程连接池（已实现，需 Linux/WSL 运行）',
    ];

    /**
     * 使用手册的绝对路径
     */
    private static function file(): string
    {
        return app()->root() . '/' . self::MANUAL;
    }

    /**
     * 使用手册的最后修改时间（用作缓存版本号）
     */
    private static function mtime(): int
    {
        $file = self::file();
        return is_file($file) ? (int) @filemtime($file) : 0;
    }

    /**
     * 读取使用手册全文（归一化换行）
     */
    private static function manual(): string
    {
        $file = self::file();
        if (!is_file($file)) {
            throw new \RuntimeException("文档源文件不存在：{$file}");
        }
        return str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file));
    }

    /**
     * 把使用手册解析为章节数组（slug / title / lines）
     *
     * 性能优化：解析结果按“手册 mtime”缓存到 runtime/cache。
     * 手册未改动时直接命中缓存，避免每个请求重复切分/正则扫描近 4 万字符。
     * 手册一旦保存（mtime 变化），键随之变化 → 自动失效并重解析。
     */
    private static function sections(): array
    {
        $key = 'doc:sections:' . self::mtime();
        return (array) self::remember($key, static fn (): array => self::parseManual());
    }

    /**
     * 真正的解析实现（被缓存层包裹）
     */
    private static function parseManual(): array
    {
        $lines  = explode("\n", self::manual());
        $sections = [];
        $cur      = null;

        foreach ($lines as $ln) {
            if (preg_match('/^##\s+(.*)$/', $ln, $m)) {
                $text = trim($m[1]);
                $slug = preg_match('/^(\d+)\.?\s*/', $text, $mm) ? $mm[1] : Markdown::slug($text);
                if ($cur !== null) {
                    $sections[] = $cur;
                }
                $cur = [
                    'slug'  => $slug,
                    'title' => preg_replace('/^\d+\.?\s*/', '', $text),
                    'lines' => [],
                ];
            } elseif ($cur !== null) {
                $cur['lines'][] = $ln;
            }
        }
        if ($cur !== null) {
            $sections[] = $cur;
        }

        return $sections;
    }

    /**
     * 渲染单章节 Markdown 并缓存 HTML
     *
     * 章节正文经 Markdown 渲染是“确定且无状态”的，按 mtime+slug 记忆化，
     * 后续请求直接复用已渲染 HTML，省去每请求的正则块解析开销。
     */
    private static function renderSection(int $mtime, string $slug, array $lines): string
    {
        $key = 'doc:html:' . $mtime . ':' . $slug;
        return (string) self::remember($key, static fn (): string => Markdown::render(implode("\n", $lines)));
    }

    /**
     * 记忆化读取：应用已引导时走框架 Cache（按键缓存，永不过期但键含 mtime）；
     * 未引导（如孤立单元测试）则退化为直接执行回调，保证功能可用。
     *
     * @return mixed
     */
    private static function remember(string $key, callable $callback): mixed
    {
        if (App::instance() !== null && function_exists('cache')) {
            return cache()->remember($key, 0, $callback);
        }
        return $callback();
    }

    /**
     * 左菜单数据（slug + title）
     */
    private static function menu(): array
    {
        $menu = [];
        foreach (self::sections() as $s) {
            $menu[] = ['slug' => $s['slug'], 'title' => $s['title']];
        }
        return $menu;
    }

    /**
     * 序言页（/docs）：复用首页欢迎内容作为文档序言
     */
    public function index()
    {
        return view('docs.preface', [
            'menu'      => self::menu(),
            'slug'      => '',
            'title'     => 'mtphp 框架文档',
            'framework' => 'mtphp',
            'features'  => self::FEATURES,
        ]);
    }

    /**
     * 章节页（/docs/{slug}）
     */
    public function show(string $slug)
    {
        $mtime   = self::mtime();
        $sections = self::sections();
        $idx      = null;
        foreach ($sections as $i => $s) {
            if ($s['slug'] === $slug) {
                $idx = $i;
                break;
            }
        }

        if ($idx === null) {
            return response()->json(['error' => '文档章节不存在：' . $slug], 404);
        }

        $section = $sections[$idx];
        $html    = self::renderSection($mtime, $slug, $section['lines']);
        $prev    = $idx > 0 ? $sections[$idx - 1] : null;
        $next    = $idx < count($sections) - 1 ? $sections[$idx + 1] : null;

        return view('docs.page', [
            'menu'    => self::menu(),
            'slug'    => $slug,
            'title'   => $section['title'],
            'content' => $html,
            'prev'    => $prev,
            'next'    => $next,
        ]);
    }
}
