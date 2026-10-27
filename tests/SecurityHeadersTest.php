<?php
declare(strict_types=1);

/**
 * 安全响应头中间件测试（mtphp/Http/Middleware/SecurityHeaders.php）
 *
 * 覆盖：默认头集合、HSTS 仅 HTTPS 下发、以及 config 覆盖/关闭单项。
 *
 * 运行：php8 tests/run.php SecurityHeadersTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\App;
use Mtphp\Http\Middleware\SecurityHeaders;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        // 引导最小 App，使中间件能读到 config
        new App(realpath(__DIR__ . '/..'));
    }

    private function request(string $method = 'GET', string $uri = '/'): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        return Request::capture();
    }

    public function testDefaultSecurityHeadersApplied(): void
    {
        $resp    = (new SecurityHeaders())->handle($this->request(), fn () => new Response('ok'));
        $headers = $resp->getHeaders();

        Assert::assertContains($headers['X-Content-Type-Options'] ?? '', 'nosniff');
        Assert::assertContains($headers['X-Frame-Options'] ?? '', 'SAMEORIGIN');
        Assert::assertContains($headers['Referrer-Policy'] ?? '', 'strict-origin-when-cross-origin');
        Assert::assertContains($headers['Content-Security-Policy'] ?? '', "default-src 'self'");
        Assert::assertContains($headers['Permissions-Policy'] ?? '', 'geolocation=()');
    }

    public function testHstsOnlyOnHttps(): void
    {
        // HTTP 环境：不应下发 HSTS
        $_SERVER['HTTPS']       = 'off';
        $_SERVER['SERVER_PORT'] = '80';
        $resp = (new SecurityHeaders())->handle($this->request(), fn () => new Response('ok'));
        Assert::assertFalse(isset($resp->getHeaders()['Strict-Transport-Security']));

        // HTTPS 环境：应当下发 HSTS
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['SERVER_PORT'] = '443';
        $resp = (new SecurityHeaders())->handle($this->request(), fn () => new Response('ok'));
        Assert::assertContains($resp->getHeaders()['Strict-Transport-Security'] ?? '', 'max-age=');
    }

    public function testOverrideAndDisableViaConfig(): void
    {
        // 关闭 CSP、新增自定义头
        App::instance()->config()->set('security.headers_list', [
            'Content-Security-Policy' => null,
            'X-Custom'                => 'hello',
        ]);

        $resp    = (new SecurityHeaders())->handle($this->request(), fn () => new Response('ok'));
        $headers = $resp->getHeaders();

        Assert::assertFalse(isset($headers['Content-Security-Policy']), 'CSP 应被配置关闭');
        Assert::assertSame('hello', $headers['X-Custom'] ?? null);
    }
}
