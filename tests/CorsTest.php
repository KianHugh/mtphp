<?php
declare(strict_types=1);

/**
 * CORS 中间件测试
 * 运行：php8 tests/run.php CorsTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Http\Middleware\Cors;

class CorsTest extends TestCase
{
    private function makeRequest(string $method, array $headers = []): Request
    {
        $r = new Request();
        $rm = new \ReflectionProperty(Request::class, 'method');
        $rm->setAccessible(true);
        $rm->setValue($r, $method);
        $rh = new \ReflectionProperty(Request::class, 'headers');
        $rh->setAccessible(true);
        $rh->setValue($r, $headers);
        return $r;
    }

    private function next(): callable
    {
        return fn (Request $r) => new Response('ok');
    }

    public function testPreflightReturns204(): void
    {
        $cors = new Cors();
        $resp = $cors->handle($this->makeRequest('OPTIONS', ['origin' => 'https://a.com']), $this->next());
        Assert::assertSame(204, $resp->getStatus());
        Assert::assertNotNull($resp->getHeaders()['Access-Control-Allow-Origin'] ?? null);
    }

    public function testDefaultEchoesStar(): void
    {
        $cors = new Cors();
        $resp = $cors->handle($this->makeRequest('GET', ['origin' => 'https://a.com']), $this->next());
        Assert::assertSame('*', $resp->getHeaders()['Access-Control-Allow-Origin'] ?? null);
    }

    public function testWhitelistAllowsAndDenies(): void
    {
        $cfg = [
            'cors_origins' => ['https://good.com'],
            'cors_methods' => ['GET', 'POST'],
            'cors_headers' => ['Content-Type'],
            'cors_max_age' => 86400,
        ];
        $cors = new Cors($cfg);

        $allow = $cors->handle($this->makeRequest('GET', ['origin' => 'https://good.com']), $this->next());
        Assert::assertSame('https://good.com', $allow->getHeaders()['Access-Control-Allow-Origin'] ?? null);

        $deny = $cors->handle($this->makeRequest('GET', ['origin' => 'https://evil.com']), $this->next());
        Assert::assertNull($deny->getHeaders()['Access-Control-Allow-Origin'] ?? null);
    }
}
