<?php
declare(strict_types=1);

/**
 * Markdown 渲染器测试（mtphp/Markdown/Markdown.php，供文档站使用）
 * 覆盖标题/段落/列表/引用/表格/围栏代码/行内代码的渲染，并验证“行内代码内的 * 不被误当斜体”。
 *
 * 运行：php8 tests/run.php MarkdownTest   或   php8 tests/MarkdownTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Markdown\Markdown;

class MarkdownTest extends TestCase
{
    /**
     * 标题 / 段落 / 加粗 / 链接
     */
    public function testHeadingsAndInline(): void
    {
        $html = Markdown::render("# 标题一\n\n这是一段**加粗**文字，还有[链接](https://example.com)。");

        Assert::assertContains($html, '<h1 id="标题一">标题一</h1>');
        Assert::assertContains($html, '<strong>加粗</strong>');
        Assert::assertContains($html, '<a href="https://example.com">链接</a>');
        Assert::assertContains($html, '<p>这是一段');
    }

    /**
     * 表格：表头 + 分隔行 + 表体，且默认转义单元格内容
     */
    public function testTable(): void
    {
        $md   = "| 项目 | 要求 |\n| --- | --- |\n| PHP | >= 8.0 |";
        $html = Markdown::render($md);

        Assert::assertContains($html, '<table>');
        Assert::assertContains($html, '<th>项目</th>');
        Assert::assertContains($html, '<td>&gt;= 8.0</td>');
    }

    /**
     * 围栏代码块：内部内容原样转义、不被当作 Markdown
     */
    public function testFencedCode(): void
    {
        $md   = "```html\n" . '{{ $title }}' . "\n```";
        $html = Markdown::render($md);

        Assert::assertContains($html, '<pre><code class="language-html">');
        Assert::assertContains($html, '{{ $title }}');
    }

    /**
     * 关键回归：行内代码 `*.tpl` 里的 * 不能被斜体规则误伤
     */
    public function testInlineCodeAsteriskNotItalic(): void
    {
        $html = Markdown::render("源文件 `app/Views/*.tpl` 首次渲染。");

        Assert::assertContains($html, '<code>app/Views/*.tpl</code>');
        Assert::assertNotContains($html, '<em>');
    }

    /**
     * slug 生成：中文保留、空白与标点转连字符
     */
    public function testSlug(): void
    {
        Assert::assertContains(Markdown::slug('环境要求'), '环境要求');
        Assert::assertNotContains(Markdown::slug('a b! c'), ' ');
    }

    /**
     * 安全回归：拦截 javascript:/vbscript:/data:/file: 危险协议，避免 XSS 注入点
     */
    public function testDangerousLinkSanitized(): void
    {
        $html = Markdown::render("[点我](javascript:alert(1))");

        Assert::assertNotContains($html, 'javascript:');
        Assert::assertContains($html, '<a href="#">点我</a>');
    }
}
