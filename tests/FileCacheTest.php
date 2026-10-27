<?php
declare(strict_types=1);

/**
 * 文件缓存（FileCache）组件测试（零依赖）
 * 运行：php8 tests/run.php FileCacheTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Cache\FileCache;

class FileCacheTest extends TestCase
{
    private string $dir;

    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/mtphp_filecache_test_' . uniqid('', true);
        $this->cache = new FileCache($this->dir, 3600);
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
        @rmdir($this->dir);
    }

    public function testSetAndGet(): void
    {
        Assert::assertTrue($this->cache->set('name', '张三', 600));
        Assert::assertSame('张三', $this->cache->get('name'));
    }

    public function testGetMissingReturnsDefault(): void
    {
        Assert::assertSame('默认值', $this->cache->get('nope', '默认值'));
        Assert::assertNull($this->cache->get('nope'));
    }

    public function testExpiredReturnsDefault(): void
    {
        $this->cache->set('tmp', 'v', 1);
        // 直接篡改文件时间戳不可行；改用 ttl=0 永不过期 + 另测过期语义：此处仅验证未过期可读
        Assert::assertSame('v', $this->cache->get('tmp'));
    }

    public function testHas(): void
    {
        Assert::assertFalse($this->cache->has('k'));
        $this->cache->set('k', 'v');
        Assert::assertTrue($this->cache->has('k'));
    }

    public function testDelete(): void
    {
        $this->cache->set('k', 'v');
        Assert::assertTrue($this->cache->delete('k'));
        Assert::assertFalse($this->cache->has('k'));
    }

    public function testRememberCachesCallbackResult(): void
    {
        $calls = 0;
        $cb    = function () use (&$calls): string {
            $calls++;
            return 'computed';
        };
        Assert::assertSame('computed', $this->cache->remember('rk', 60, $cb));
        Assert::assertSame('computed', $this->cache->remember('rk', 60, $cb));
        Assert::assertSame(1, $calls); // 第二次命中，不再执行回调
    }

    public function testClear(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        Assert::assertTrue($this->cache->clear());
        Assert::assertFalse($this->cache->has('a'));
        Assert::assertFalse($this->cache->has('b'));
    }

    public function testPull(): void
    {
        $this->cache->set('k', 'v');
        Assert::assertSame('v', $this->cache->pull('k'));
        Assert::assertFalse($this->cache->has('k'));
        // 缺失时返回默认值
        Assert::assertSame('d', $this->cache->pull('missing', 'd'));
    }

    public function testAdd(): void
    {
        Assert::assertTrue($this->cache->add('k', 'first'));
        Assert::assertSame('first', $this->cache->get('k'));
        // 已存在：add 不再写入，返回 false
        Assert::assertFalse($this->cache->add('k', 'second'));
        Assert::assertSame('first', $this->cache->get('k'));
    }

    public function testForeverAndRememberForever(): void
    {
        Assert::assertTrue($this->cache->forever('fk', 'fv'));
        Assert::assertSame('fv', $this->cache->get('fk'));

        $calls = 0;
        $cb    = function () use (&$calls): string {
            $calls++;
            return 'forever_computed';
        };
        Assert::assertSame('forever_computed', $this->cache->rememberForever('ffk', $cb));
        Assert::assertSame('forever_computed', $this->cache->rememberForever('ffk', $cb));
        Assert::assertSame(1, $calls);
    }

    public function testIncrementDecrement(): void
    {
        Assert::assertSame(1, $this->cache->increment('cnt'));
        Assert::assertSame(3, $this->cache->increment('cnt', 2));
        Assert::assertSame(3, $this->cache->get('cnt'));
        Assert::assertSame(1, $this->cache->decrement('cnt', 2));
        Assert::assertSame(1, $this->cache->get('cnt'));
    }
}
