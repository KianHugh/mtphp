<?php
declare(strict_types=1);

/**
 * HTTP 客户端测试（用假传输层，离线验证请求构建与响应解析）
 * 运行：php8 tests/run.php HttpClientTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Http\Client;
use Mtphp\Http\ClientResponse;

class HttpClientTest extends TestCase
{
    /**
     * 假传输层：捕获收到的请求，返回固定响应
     */
    private function fakeTransport(array &$captured): callable
    {
        return function (string $method, string $url, array $opts) use (&$captured): array {
            $captured = ['method' => $method, 'url' => $url, 'opts' => $opts];
            return [200, ['content-type' => 'application/json'], '{"ok":true,"n":7}'];
        };
    }

    public function testGetBuildsUrlAndParsesJson(): void
    {
        $captured = [];
        $client = (new Client('https://api.test'))
            ->setTransport($this->fakeTransport($captured))
            ->withToken('abc');

        $resp = $client->get('/users', ['page' => 2]);

        Assert::assertSame('https://api.test/users?page=2', $captured['url']);
        Assert::assertSame('GET', $captured['method']);
        Assert::assertTrue(in_array('Authorization: Bearer abc', $this->headerLine($captured['opts']), true));
        Assert::assertTrue($resp->ok());
        Assert::assertSame(7, $resp->json()['n']);
    }

    public function testPostEncodesJsonBody(): void
    {
        $captured = [];
        $client = (new Client('https://api.test'))
            ->setTransport($this->fakeTransport($captured))
            ->asJson();

        $client->post('/users', ['name' => '张三']);

        Assert::assertSame('POST', $captured['method']);
        $body = $captured['opts'][CURLOPT_POSTFIELDS] ?? '';
        $dec = json_decode($body, true);
        Assert::assertSame('张三', $dec['name']);
        Assert::assertTrue(in_array('Content-Type: application/json; charset=utf-8', $this->headerLine($captured['opts']), true));
    }

    public function testErrorStatusNotOk(): void
    {
        $client = (new Client())
            ->setTransport(function () {
                return [404, [], 'not found'];
            });
        $resp = $client->get('https://api.test/x');
        Assert::assertFalse($resp->ok());
        Assert::assertSame(404, $resp->status());
        Assert::assertSame('not found', $resp->body());
    }

    /**
     * 从 curl opts 里取出 HTTP 头字符串数组（CURLOPT_HTTPHEADER）
     *
     * @param array<int,mixed> $opts
     * @return list<string>
     */
    private function headerLine(array $opts): array
    {
        return $opts[CURLOPT_HTTPHEADER] ?? [];
    }
}
