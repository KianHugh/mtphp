<?php
declare(strict_types=1);

/**
 * HTTP 客户端重试 / 超时 测试
 * 运行：php tests/run.php HttpRetryTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Http\Client;

class HttpRetryTest extends TestCase
{
    public function testNoRetryOnFirstSuccess(): void
    {
        $calls = 0;
        $client = (new Client('https://api.test'))
            ->setTransport(function () use (&$calls): array {
                $calls++;
                return [200, [], 'ok'];
            });

        $resp = $client->get('/x');
        Assert::assertSame(200, $resp->status());
        Assert::assertSame(1, $calls);
    }

    public function testRetriesUntilSuccess(): void
    {
        $calls = 0;
        // 前两次“传输失败”（状态码 0），第三次成功
        $client = (new Client('https://api.test'))
            ->retry(3, 1)
            ->setTransport(function () use (&$calls): array {
                $calls++;
                if ($calls < 3) {
                    return [0, [], '']; // 模拟连接失败
                }
                return [200, [], 'recovered'];
            });

        $resp = $client->get('/x');
        Assert::assertSame(200, $resp->status());
        Assert::assertSame('recovered', $resp->body());
        Assert::assertSame(3, $calls);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $calls = 0;
        $client = (new Client('https://api.test'))
            ->retry(2, 1)
            ->setTransport(function () use (&$calls): array {
                $calls++;
                return [0, [], '']; // 始终失败
            });

        $resp = $client->get('/x');
        Assert::assertSame(0, $resp->status());
        Assert::assertSame(2, $calls);
    }

    public function testTimeoutAndConnectTimeoutAreSet(): void
    {
        $client = (new Client('https://api.test'))
            ->timeout(30)
            ->connectTimeout(7);
        // 通过注入的传输层观察 opts 里是否写入了对应 curl 选项
        $seen = null;
        $client->setTransport(function ($method, $url, $opts) use (&$seen): array {
            $seen = $opts;
            return [200, [], ''];
        });
        $client->get('/x');
        Assert::assertSame(30, $seen[\CURLOPT_TIMEOUT]);
        Assert::assertSame(7, $seen[\CURLOPT_CONNECTTIMEOUT]);
    }
}
