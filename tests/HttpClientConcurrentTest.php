<?php
declare(strict_types=1);

/**
 * HTTP 客户端并发（concurrent / curl_multi）测试：用假多路传输层离线验证
 * 运行：php8 tests/run.php HttpClientConcurrentTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Http\Client;

class HttpClientConcurrentTest extends TestCase
{
    public function testConcurrentBuildsRequestsAndReturnsInOrder(): void
    {
        $prepared = [];
        // 假多路传输层：记录收到的 [url, opts]，按序返回固定响应
        $client = (new Client('https://api.test'))
            ->withToken('tk')
            ->setMultiTransport(function (array $specs) use (&$prepared): array {
                $prepared = $specs;
                return [
                    [200, [], '{"id":1}'],
                    [201, [], '{"id":2}'],
                    [404, [], 'nope'],
                ];
            });

        $responses = $client->concurrent([
            '/a',                                              // string → GET
            ['url' => '/b', 'method' => 'POST', 'data' => ['x' => 9]], // array POST
            ['url' => 'https://other.test/c'],                 // 绝对 URL，沿用 headers
        ]);

        // 3 个请求被同序记录
        Assert::assertSame(3, count($prepared));

        // 第 1 个：GET，baseUrl 拼接，带 token
        [$u0, $o0] = $prepared[0];
        Assert::assertSame('https://api.test/a', $u0);
        Assert::assertSame('GET', $o0[CURLOPT_CUSTOMREQUEST]);
        Assert::assertTrue(in_array('Authorization: Bearer tk', $o0[CURLOPT_HTTPHEADER] ?? [], true));

        // 第 2 个：POST + JSON body
        [$u1, $o1] = $prepared[1];
        Assert::assertSame('https://api.test/b', $u1);
        Assert::assertSame('POST', $o1[CURLOPT_CUSTOMREQUEST]);
        $body1 = json_decode($o1[CURLOPT_POSTFIELDS] ?? '', true);
        Assert::assertSame(9, $body1['x']);

        // 第 3 个：绝对 URL 透传
        [$u2] = $prepared[2];
        Assert::assertSame('https://other.test/c', $u2);

        // 响应同序映射为 ClientResponse
        Assert::assertSame(200, $responses[0]->status());
        Assert::assertSame(1, $responses[0]->json()['id']);
        Assert::assertSame(201, $responses[1]->status());
        Assert::assertSame(404, $responses[2]->status());
        Assert::assertFalse($responses[2]->ok());
    }

    public function testConcurrentAcceptsClientInstance(): void
    {
        $prepared = [];
        $other = (new Client('https://svc.test'));
        $client = (new Client('https://api.test'))
            ->setMultiTransport(function (array $specs) use (&$prepared): array {
                $prepared = $specs;
                return [[200, [], 'ok']];
            });

        $client->concurrent([$other]);
        Assert::assertSame('https://svc.test', $prepared[0][0]);
    }
}
