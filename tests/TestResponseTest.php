<?php
declare(strict_types=1);

/**
 * TestResponse 测试客户端断言测试
 * 运行：php tests/run.php TestResponseTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Testing\TestResponse;
use Mtphp\Http\Response;

class TestResponseTest extends TestCase
{
    public function testAssertStatusAndSee(): void
    {
        $r = new TestResponse((new Response())->body('hello world')->status(200));
        $r->assertOk()->assertSee('world');
    }

    public function testAssertNotFound(): void
    {
        $r = new TestResponse((new Response())->body('nope')->status(404));
        $r->assertNotFound();
    }

    public function testAssertHeader(): void
    {
        $r = (new Response())->header('X-Foo', 'bar');
        (new TestResponse($r))->assertHeader('x-foo', 'bar');
    }

    public function testAssertJsonSubset(): void
    {
        $r = (new Response())->json(['ok' => true, 'data' => ['id' => 1, 'name' => 'a']]);
        (new TestResponse($r))
            ->assertStatus(200)
            ->assertJson(['ok' => true, 'data' => ['id' => 1]]);
    }

    public function testAssertStatusFails(): void
    {
        Assert::assertException(fn () => (new TestResponse((new Response())->status(500)))->assertStatus(200), \RuntimeException::class);
    }
}
