<?php
declare(strict_types=1);

/**
 * 缓存锁 Lock 测试（文件驱动覆盖通用逻辑 + Redis 条件测试）
 * 运行：php8 tests/run.php CacheLockTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Cache\FileCache;
use Mtphp\Cache\RedisConnector;
use Mtphp\Cache\RedisStore;

class CacheLockTest extends TestCase
{
    private string $dir;

    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/mtphp_lock_test_' . uniqid('', true);
        $this->cache = new FileCache($this->dir, 3600);
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
        @rmdir($this->dir);
    }

    public function testAcquireAndRelease(): void
    {
        $lock = $this->cache->lock('order:1', 10);
        Assert::assertTrue($lock->get());
        Assert::assertTrue($lock->isAcquired());
        $rt = $lock->getRemainingLifeTime();
        Assert::assertTrue($rt > 0 && $rt <= 10);

        // 同一把锁重复 get() 幂等
        Assert::assertTrue($lock->get());

        // 释放后 owner 令牌不再持有
        Assert::assertTrue($lock->release());
        Assert::assertFalse($lock->isAcquired());
    }

    public function testLockIsExclusive(): void
    {
        $first = $this->cache->lock('res', 30);
        Assert::assertTrue($first->get());

        // 另一个竞争者拿不到
        $second = $this->cache->lock('res', 30);
        Assert::assertFalse($second->get());
        Assert::assertFalse($second->isAcquired());

        // 释放后竞争者可以拿到
        $first->release();
        Assert::assertTrue($second->get());
    }

    public function testOwnerTokenDiffers(): void
    {
        $a = $this->cache->lock('tok', 30, 'owner-a');
        $b = $this->cache->lock('tok', 30, 'owner-b');
        Assert::assertSame('owner-a', $a->owner());
        Assert::assertSame('owner-b', $b->owner());
        // 只有 owner 能释放：b 从未持锁，release 返回 false
        Assert::assertFalse($b->release());
    }

    public function testBlockRunsCallbackUnderLock(): void
    {
        $lock = $this->cache->lock('cb', 10);
        $result = $lock->block(2, static function (): string {
            return 'done';
        });
        Assert::assertSame('done', $result);
        // 回调结束自动释放
        Assert::assertFalse($lock->isAcquired());
    }

    public function testBlockTimesOutWhenHeld(): void
    {
        $holder = $this->cache->lock('busy', 60);
        $holder->get();

        $waiter = $this->cache->lock('busy', 60);
        // 已被占用且 holder 不放，2 秒内必超时，无回调时返回 false
        Assert::assertFalse($waiter->block(2));
    }

    public function testForceRelease(): void
    {
        $lock = $this->cache->lock('fr', 60);
        $lock->get();
        $other = $this->cache->lock('fr', 60);
        Assert::assertFalse($other->get());
        $lock->forceRelease();
        Assert::assertTrue($other->get());
    }

    public function testStaleLockTakeover(): void
    {
        // 手工植入一把已过期的锁记录
        $this->cache->set('lock:stale', ['someone', time() - 10], 0);

        $lock = $this->cache->lock('stale', 30);
        // 过期锁应能被安全接管
        Assert::assertTrue($lock->get());
        Assert::assertTrue($lock->isAcquired());
    }

    public function testPermanentLock(): void
    {
        $lock = $this->cache->lock('perm', 0); // 永不过期
        Assert::assertTrue($lock->get());
        Assert::assertSame(PHP_INT_MAX, $lock->getRemainingLifeTime());
        $lock->release();
    }

    // ---------- Redis 条件测试（无服务则跳过） ----------

    private function tryConnector(): ?RedisConnector
    {
        $conn = new RedisConnector('127.0.0.1', 6379, 0, null, 1.0);
        try {
            $conn->connect();
            $conn->command('PING');
            return $conn;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function testRedisLockExclusive(): void
    {
        $conn = $this->tryConnector();
        if ($conn === null) {
            fwrite(STDERR, "[SKIP] 未检测到本机 Redis，跳过 Redis 锁测试\n");
            return;
        }
        $store = new RedisStore($conn, 'mtphp_lock_test_');
        $store->delete('redislock');

        $first = $store->lock('redislock', 30);
        Assert::assertTrue($first->get());
        $second = $store->lock('redislock', 30);
        Assert::assertFalse($second->get());
        $first->release();
        Assert::assertTrue($second->get());
        $second->release();
    }
}
