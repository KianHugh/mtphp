<?php
declare(strict_types=1);

/**
 * CSRF 中间件测试（mtphp/Http/Middleware/Csrf.php）
 *
 * 覆盖：安全方法下发令牌 Cookie；非安全方法缺令牌被拒(419)；
 * 令牌一致则放行；csrf_field() 输出；csrf_except 放行名单。
 *
 * 运行：php8 tests/run.php CsrfTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\App;
use Mtphp\Http\Middleware\Csrf;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // 隔离全局输入与静态令牌，避免用例间串味
        $_COOKIE = [];
        $_POST   = [];
        $_GET    = [];
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = '80';

        $prop = (new \ReflectionClass(Csrf::class))->getProperty('requestToken');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // 引导 App，使 csrf_field() 助手与 csrf_except 配置可用
        new App(realpath(__DIR__ . '/..'));
    }

    private function request(string $method, string $uri = '/'): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        return Request::capture();
    }

    public function testSafeMethodIssuesTokenCookie(): void
    {
        $resp = (new Csrf())->handle($this->request('GET'), fn () => new Response('ok'));
        $cookies = $resp->getCookies();

        Assert::assertTrue(isset($cookies[Csrf::COOKIE]), '安全方法应下发 CSRF Cookie');
        Assert::assertSame('ok', (string) $resp->getBody());

        // 视图助手取到的令牌应与下发的 Cookie 一致
        Assert::assertSame($cookies[Csrf::COOKIE]['value'], Csrf::token());
    }

    public function testUnsafeWithoutTokenDenied(): void
    {
        $resp = (new Csrf())->handle($this->request('POST', '/form'), fn () => new Response('ok'));

        Assert::assertSame(419, $resp->getStatus());
        Assert::assertContains((string) $resp->getBody(), 'CSRF');
    }

    public function testUnsafeWithMatchingTokenPasses(): void
    {
        $token = bin2hex(random_bytes(32));
        $_COOKIE[Csrf::COOKIE]       = $token;
        $_POST[Csrf::TOKEN_FIELD]    = $token;

        $resp = (new Csrf())->handle($this->request('POST', '/form'), fn () => new Response('ok'));

        Assert::assertSame(200, $resp->getStatus());
        Assert::assertSame('ok', (string) $resp->getBody());
    }

    public function testTokenMismatchDenied(): void
    {
        $_COOKIE[Csrf::COOKIE]    = bin2hex(random_bytes(32));
        $_POST[Csrf::TOKEN_FIELD] = bin2hex(random_bytes(32)); // 故意不一致

        $resp = (new Csrf())->handle($this->request('POST', '/form'), fn () => new Response('ok'));
        Assert::assertSame(419, $resp->getStatus());
    }

    public function testCsrfFieldContainsToken(): void
    {
        // 先走一次安全方法，确定本请求令牌
        (new Csrf())->handle($this->request('GET'), fn () => new Response('ok'));
        $token = Csrf::token();

        $field = csrf_field();
        Assert::assertContains($field, 'name="' . Csrf::TOKEN_FIELD . '"');
        Assert::assertContains($field, 'value="' . $token . '"');
    }

    public function testExceptPathSkipsVerification(): void
    {
        App::instance()->config()->set('security.csrf_except', ['/api']);

        // 命中放行名单的 POST 即便无令牌也通过
        $resp = (new Csrf())->handle($this->request('POST', '/api/ping'), fn () => new Response('ok'));
        Assert::assertSame(200, $resp->getStatus());
        Assert::assertSame('ok', (string) $resp->getBody());
    }
}
