<?php
declare(strict_types=1);

/**
 * 视图模板指令测试（覆盖主流 Blade 风格指令）
 * 不依赖 MySQL / App；每个用例用独立临时视图目录，直接指定路径渲染。
 *
 * 运行：php8 tests/run.php ViewTest   或   php8 tests/ViewTest.php
 *
 * 注意：模板字符串里含 PHP 变量（如 $it）需用 \ 转义，避免被 PHP 双引号插值。
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\View\Template;

class ViewTest extends TestCase
{
    private string $views;
    private string $cache;

    /**
     * 每个用例前准备干净的临时视图/缓存目录
     */
    protected function setUp(): void
    {
        $root = dirname(__DIR__);
        $this->views = $root . '/runtime/views_test';
        $this->cache = $root . '/runtime/views_test_cache';
        $this->rmrf($this->views);
        $this->rmrf($this->cache);
        mkdir($this->views, 0755, true);
        mkdir($this->cache, 0755, true);
        Template::setPaths($this->views, $this->cache);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->views);
        $this->rmrf($this->cache);
    }

    /**
     * 写一个视图文件（支持子目录，用 “.” 或 “/” 分隔）
     */
    private function writeView(string $name, string $content): void
    {
        $file = $this->views . '/' . str_replace('.', '/', $name) . '.tpl';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, $content);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $p = $dir . '/' . $it;
            if (is_dir($p)) {
                $this->rmrf($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    public function testEscapeVsRaw(): void
    {
        $this->writeView('e', 'A: {{ $x }} B: {!! $x !!}');   // $x 在双引号里需转义
        $html = Template::renderRaw('e', ['x' => '<b>hi</b>']);
        Assert::assertContains($html, 'A: &lt;b&gt;hi&lt;/b&gt;');  // 转义
        Assert::assertContains($html, 'B: <b>hi</b>');            // 原样
    }

    public function testCommentStripped(): void
    {
        $this->writeView('c', '前{{-- 这是注释 --}}后');
        $html = Template::renderRaw('c', []);
        Assert::assertContains($html, '前后');
        Assert::assertNotContains($html, '这是注释');
    }

    public function testVerbatimPreserved(): void
    {
        $this->writeView('v', '@verbatim<span>{{ vueVar }} @directive</span>@endverbatim');
        $html = Template::renderRaw('v', []);
        Assert::assertContains($html, '<span>{{ vueVar }} @directive</span>');
    }

    public function testLoopVariable(): void
    {
        $this->writeView('l', "@foreach (\$items as \$it)\n"
            . "{{ \$loop->iteration }}:{{ \$loop->count }}:{{ \$loop->first }}:{{ \$loop->last }}:{{ \$loop->odd }}\n"
            . '@endforeach');
        $html = Template::renderRaw('l', ['items' => ['a', 'b', 'c']]);
        Assert::assertContains($html, '1:3:1::');    // 第 1 项：first, odd
        Assert::assertContains($html, '3:3::1:1');   // 第 3 项：last, odd
    }

    public function testForelseEmpty(): void
    {
        $this->writeView('f', "@forelse (\$items as \$it)\n"
            . "{{ \$it }}\n@empty\n空集合@endforelse");
        $html = Template::renderRaw('f', ['items' => []]);
        Assert::assertContains($html, '空集合');
    }

    public function testForelseNonEmpty(): void
    {
        $this->writeView('f2', "@forelse (\$items as \$it)\n"
            . "{{ \$it }}\n@empty\n空集合@endforelse");
        $html = Template::renderRaw('f2', ['items' => ['x', 'y']]);
        Assert::assertContains($html, 'x');
        Assert::assertContains($html, 'y');
        Assert::assertNotContains($html, '空集合');
    }

    public function testSwitch(): void
    {
        $this->writeView('s', "@switch (\$st)\n"
            . "@case('a')<p>A</p>@break\n"
            . "@case('b')<p>B</p>@break\n"
            . "@default<p>D</p>\n@endswitch");
        Assert::assertContains(Template::renderRaw('s', ['st' => 'b']), '<p>B</p>');
        Assert::assertContains(Template::renderRaw('s', ['st' => 'z']), '<p>D</p>');
    }

    public function testBalancedParenCondition(): void
    {
        // 条件里带括号嵌套：验证平衡括号匹配（旧版会在首个 ) 截断）
        $this->writeView('bp', "@if (count(\$a) > 1 && (\$b + 2) < 10)\nOK@endif");
        Assert::assertContains(Template::renderRaw('bp', ['a' => [1, 2], 'b' => 1]), 'OK');
        Assert::assertNotContains(Template::renderRaw('bp', ['a' => [1], 'b' => 1]), 'OK');
    }

    public function testComponentSlot(): void
    {
        $this->writeView('components.box', "@props(['title' => ''])\n"
            . "<div>[{{ \$title }}]{{ \$slot }}</div>");
        $this->writeView('use', "@component('components.box', ['title' => 'T'])\n内容@endcomponent");
        $html = Template::renderRaw('use', []);
        Assert::assertContains($html, '[T]');
        Assert::assertContains($html, '内容');
    }

    public function testInclude(): void
    {
        $this->writeView('partial.head', '<h2>{{ $t }}</h2>');
        $this->writeView('main', "@include('partial.head', ['t' => '标题'])");
        Assert::assertContains(Template::renderRaw('main', []), '<h2>标题</h2>');
    }

    public function testIncludeIfMissing(): void
    {
        $this->writeView('m', "@includeIf('nope.missing', [])[之后]");
        // 不存在的模板应静默跳过，仅输出后续内容
        Assert::assertSame('[之后]', Template::renderRaw('m', []));
    }

    public function testStackPush(): void
    {
        $this->writeView('st', "@push('js')<s1>@endpush@push('js')<s2>@endpush@stack('js')");
        $html = Template::renderRaw('st', []);
        Assert::assertContains($html, '<s1>');
        Assert::assertContains($html, '<s2>');
        // 两个 push 都在 stack 之前执行，故先 s1 后 s2
        Assert::assertTrue(strpos($html, '<s1>') < strpos($html, '<s2>'));
    }

    public function testJson(): void
    {
        $this->writeView('j', '@json($d)');
        $html = Template::renderRaw('j', ['d' => ['name' => '中文']]);
        Assert::assertContains($html, '"name":"中文"');
    }

    public function testClassAttr(): void
    {
        $this->writeView('cl', "<x class=\"@class(['active' => \$on, 'disabled' => \$off, 'base'])\">");
        $html = Template::renderRaw('cl', ['on' => true, 'off' => false]);
        Assert::assertContains($html, 'class="active base"');
    }

    public function testLayoutHasSection(): void
    {
        $this->writeView('layouts.base', "<div>\n"
            . "@hasSection('side')<aside>@yield('side')</aside>@endif\n"
            . "@yield('content')\n</div>");
        // 子模板定义 side
        $this->writeView('child1', "@extends('layouts.base')\n"
            . "@section('content')主体@endsection\n"
            . "@section('side')侧栏@endsection");
        $html1 = Template::renderRaw('child1', []);
        Assert::assertContains($html1, '<aside>侧栏</aside>');
        Assert::assertContains($html1, '主体');

        // 子模板不定义 side → 走 @hasSection 的“未定义”分支（这里没有 else，故不输出 aside）
        $this->writeView('child2', "@extends('layouts.base')\n"
            . "@section('content')仅主体@endsection");
        $html2 = Template::renderRaw('child2', []);
        Assert::assertContains($html2, '仅主体');
        Assert::assertNotContains($html2, '<aside>');
    }

    public function testPhpBlock(): void
    {
        $this->writeView('php', "@php \$x = 3 * 3; @endphp结果={{ \$x }}");
        Assert::assertContains(Template::renderRaw('php', []), '结果=9');
    }

    public function testAtLiteralEscape(): void
    {
        // 正文里想原样显示指令名（如演示页标题），用 @@ 转义：
        // 编译后还原为单个 @ 且【不】被解析为指令，也不残留占位符。
        $this->writeView('at', "关键字：@@if 与 @@foreach 与 @@default");
        $html = Template::renderRaw('at', []);
        Assert::assertContains($html, '@if');
        Assert::assertContains($html, '@foreach');
        Assert::assertContains($html, '@default');
        // 不能残留内部占位符（空字节）
        Assert::assertNotContains($html, "\x00");
        // 不能把 @@if 误解析成 <?php if
        Assert::assertNotContains($html, '<?php');
    }

    public function testSwitchWithIndent(): void
    {
        // @case / @default 带缩进时也必须正确编译：验证“首个 case 紧贴 switch 关闭标签”，
        // 避免 PHP 报 unexpected T_INLINE_HTML（旧版在缩进场景下会崩）。
        $this->writeView('si', "@switch (\$st)\n"
            . "    @case('a')\n        <p>A</p>\n    @break\n"
            . "    @case('b')\n        <p>B</p>\n    @break\n"
            . "    @default\n        <p>D</p>\n@endswitch");
        Assert::assertContains(Template::renderRaw('si', ['st' => 'a']), '<p>A</p>');
        Assert::assertContains(Template::renderRaw('si', ['st' => 'b']), '<p>B</p>');
        Assert::assertContains(Template::renderRaw('si', ['st' => 'x']), '<p>D</p>');
    }
}
