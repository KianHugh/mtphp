<?php
declare(strict_types=1);

use Mtphp\Exception\HttpException;
use Mtphp\Http\Response;
use Mtphp\Http\Session;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

require_once __DIR__ . '/bootstrap.php';

/**
 * abort / redirect / back / with / Session flash 测试
 */
class HttpAbortRedirectTest extends TestCase
{
    public function testAbortThrowsHttpException(): void
    {
        $caught = null;
        try {
            abort(404, '没了');
        } catch (HttpException $e) {
            $caught = $e;
        }
        Assert::assertNotNull($caught, 'abort 应抛出 HttpException');
        Assert::assertSame(404, $caught->getStatusCode());
        Assert::assertContains('没了', $caught->getMessage());
    }

    public function testAbortIfThrowsWhenTrue(): void
    {
        Assert::assertException(static fn () => abort_if(true, 403, 'no'), HttpException::class);
    }

    public function testAbortIfNoThrowWhenFalse(): void
    {
        $threw = false;
        try {
            abort_if(false, 403);
        } catch (HttpException $e) {
            $threw = true;
        }
        Assert::assertFalse($threw);
    }

    public function testAbortUnlessThrowsWhenFalse(): void
    {
        Assert::assertException(static fn () => abort_unless(false, 401, 'login'), HttpException::class);
    }

    public function testAbortUnlessNoThrowWhenTrue(): void
    {
        $threw = false;
        try {
            abort_unless(true, 401);
        } catch (HttpException $e) {
            $threw = true;
        }
        Assert::assertFalse($threw);
    }

    public function testRedirectSetsLocationAnd302(): void
    {
        $resp = redirect('/login');
        Assert::assertSame(302, $resp->getStatus());
        Assert::assertContains('/login', $resp->getHeaders()['Location']);
    }

    public function testRedirectCustomCode(): void
    {
        $resp = (new Response())->redirect('/moved', 301);
        Assert::assertSame(301, $resp->getStatus());
        Assert::assertContains('/moved', $resp->getHeaders()['Location']);
    }

    public function testBackReturnsRedirect(): void
    {
        $resp = back();
        Assert::assertSame(302, $resp->getStatus());
        Assert::assertTrue(isset($resp->getHeaders()['Location']));
    }

    public function testWithIsChainableAndNoOpWithoutSession(): void
    {
        $resp = redirect('/a')->with('msg', 'hi');
        Assert::assertSame(302, $resp->getStatus());
        Assert::assertContains('/a', $resp->getHeaders()['Location']);
    }

    public function testSessionFlashSurvivesStart(): void
    {
        $s = new Session('array');
        $s->flash('msg', 'saved');
        $s->start(); // 模拟下一轮请求：nextFlash → flashed
        Assert::assertSame('saved', $s->getFlash('msg'));
        Assert::assertSame('default', $s->getFlash('missing', 'default'));
    }
}
