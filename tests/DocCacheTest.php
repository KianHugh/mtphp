<?php
declare(strict_types=1);

/**
 * 文档解析缓存测试（app/Controllers/DocController.php）
 *
 * 验证：章节解析结果与单章节 Markdown 渲染 HTML 均按“手册 mtime”缓存，
 * 避免每个请求重复切分近 4 万字符的手册并重新渲染。
 *
 * 运行：php8 tests/run.php DocCacheTest
 */

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\DocController;
use Mtphp\App;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

class DocCacheTest extends TestCase
{
    protected function setUp(): void
    {
        new App(realpath(__DIR__ . '/..'));
    }

    /**
     * 通过反射调用 DocController 的私有静态方法
     *
     * @return mixed
     */
    private function callPrivate(string $method, ...$args): mixed
    {
        $ref = new \ReflectionMethod(DocController::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    public function testSectionsParsedIntoChapters(): void
    {
        $sections = $this->callPrivate('sections');

        Assert::assertGreaterThan(0, count($sections));
        $slugs = array_column($sections, 'slug');
        Assert::assertTrue(in_array('1', $slugs, true), '章节应包含 slug=1');
    }

    public function testSectionsCachedToFile(): void
    {
        $mtime = $this->callPrivate('mtime');
        $key   = 'doc:sections:' . $mtime;

        cache()->delete($key);
        Assert::assertFalse(cache()->has($key), '前置：缓存应已清空');

        $this->callPrivate('sections');

        Assert::assertTrue(cache()->has($key), '解析结果应写入缓存');
    }

    public function testRenderedHtmlCached(): void
    {
        $mtime   = $this->callPrivate('mtime');
        $htmlKey = 'doc:html:' . $mtime . ':1';

        cache()->delete($htmlKey);
        Assert::assertFalse(cache()->has($htmlKey), '前置：HTML 缓存应已清空');

        $resp = (new DocController())->show('1');

        Assert::assertTrue(cache()->has($htmlKey), '章节渲染 HTML 应写入缓存');
        Assert::assertContains((string) $resp->getBody(), '<');
    }

    public function testShowUnknownSlugReturns404(): void
    {
        $resp = (new DocController())->show('__not_exist__');
        Assert::assertSame(404, $resp->getStatus());
    }
}
