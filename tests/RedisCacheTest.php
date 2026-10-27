<?php
declare(strict_types=1);

/**
 * Redis 缓存驱动测试（零依赖，纯 PHP RESP）
 * 运行：php8 tests/run.php RedisCacheTest
 *
 * 分两部分：
 *  1) 协议层单元测试（Resp 编码 / 解码），无需 Redis 服务即可验证；
 *  2) 端到端连接测试：若本机 127.0.0.1:6379 可达则跑完整读写，否则自动跳过。
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Cache\RedisConnector;
use Mtphp\Cache\RedisStore;
use Mtphp\Cache\Resp;
use Mtphp\Cache\RespIncomplete;

class RedisCacheTest extends TestCase
{
    // ---------- 协议层（不依赖 Redis 服务） ----------

    public function testRespEncode(): void
    {
        $wire = Resp::encode(['SET', 'foo', 'bar']);
        Assert::assertSame("*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n", $wire);
    }

    public function testRespDecodeSimpleString(): void
    {
        Assert::assertSame('OK', Resp::decode("+OK\r\n"));
    }

    public function testRespDecodeInteger(): void
    {
        Assert::assertSame(42, Resp::decode(":42\r\n"));
    }

    public function testRespDecodeBulkString(): void
    {
        Assert::assertSame('hello', Resp::decode("\$5\r\nhello\r\n"));
    }

    public function testRespDecodeNullBulk(): void
    {
        Assert::assertTrue(Resp::decode("\$-1\r\n") === null);
    }

    public function testRespDecodeNullArray(): void
    {
        Assert::assertTrue(Resp::decode("*-1\r\n") === null);
    }

    public function testRespDecodeArray(): void
    {
        $arr = Resp::decode("*2\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n");
        Assert::assertTrue(is_array($arr));
        Assert::assertSame(['foo', 'bar'], $arr);
    }

    public function testRespDecodeNestedArray(): void
    {
        $arr = Resp::decode("*2\r\n*1\r\n\$1\r\na\r\n:9\r\n");
        Assert::assertSame([['a'], 9], $arr);
    }

    public function testRespDecodeIncompleteThrows(): void
    {
        $thrown = false;
        try {
            Resp::decode("*1\r\n\$3\r\nfo"); // 截断的批量字符串
        } catch (RespIncomplete $e) {
            $thrown = true;
        }
        Assert::assertTrue($thrown);
    }

    // ---------- 端到端（需本机 Redis，否则跳过） ----------

    private function tryConnector(): ?RedisConnector
    {
        $conn = new RedisConnector('127.0.0.1', 6379, 0, null, 1.0);
        try {
            $conn->connect();
            $conn->command('PING');
            return $conn;
        } catch (\Throwable $e) {
            return null; // 无 Redis 服务，跳过端到端测试
        }
    }

    public function testLiveRedisSetGetHasDelete(): void
    {
        $conn = $this->tryConnector();
        if ($conn === null) {
            $this->markSkipped('未检测到本机 Redis（127.0.0.1:6379），跳过端到端测试');
            return;
        }
        $store = new RedisStore($conn, 'mtphp_test_');

        $store->delete('k');
        Assert::assertFalse($store->has('k'));
        Assert::assertTrue($store->set('k', 'v', 60));
        Assert::assertSame('v', $store->get('k'));
        Assert::assertTrue($store->has('k'));
        Assert::assertTrue($store->delete('k'));
        Assert::assertTrue($store->get('k', 'miss') === 'miss');
    }

    public function testLiveRedisRemember(): void
    {
        $conn = $this->tryConnector();
        if ($conn === null) {
            $this->markSkipped('未检测到本机 Redis，跳过端到端测试');
            return;
        }
        $store = new RedisStore($conn, 'mtphp_test_');
        $store->delete('rk');

        $calls = 0;
        $cb    = function () use (&$calls): string {
            $calls++;
            return 'computed';
        };
        Assert::assertSame('computed', $store->remember('rk', 60, $cb));
        Assert::assertSame('computed', $store->remember('rk', 60, $cb));
        Assert::assertSame(1, $calls);

        $store->clear();
        Assert::assertFalse($store->has('mtphp_test_rk'));
    }

    protected function markSkipped(string $reason): void
    {
        // 测试基座无 skip 原语：以“通过 + 说明”形式记录，避免无 Redis 时整套失败
        fwrite(STDERR, "[SKIP] {$reason}\n");
    }
}
