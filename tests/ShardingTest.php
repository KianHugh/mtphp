<?php
declare(strict_types=1);

use Mtphp\App;
use Mtphp\Database\Connector;
use Mtphp\Database\DB;
use Mtphp\Database\ShardManager;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

require_once __DIR__ . '/bootstrap.php';

/**
 * 分库路由测试
 *  - ShardManager 纯函数（无需 App / 数据库）；
 *  - DB::shardName / DB::shard 通过“真实 App 实例 + resolver”做端到端验证。
 */
class ShardFakeConn implements Connector
{
    public string $name = '';

    public function getDriver(): string { return 'mysql'; }
    public function getPdo(): ?\PDO { return null; }
    public function select(string $sql, array $b = []): array { return []; }
    public function statement(string $sql, array $b = []): bool { return true; }
    public function insert(string $sql, array $b = []): int { return 1; }
    public function update(string $sql, array $b = []): int { return 1; }
    public function delete(string $sql, array $b = []): int { return 1; }
}

class ShardingTest extends TestCase
{
    protected function setUp(): void
    {
        // 构造真实 App（读取项目 config/，设置 App::instance()），便于 DB::shardName 读配置
        new App(dirname(__DIR__));
        DB::setResolver(static function (string $name): Connector {
            $c        = new ShardFakeConn();
            $c->name  = $name;
            return $c;
        });
        App::instance()->config()->set('database.sharding.orders', [
            'shards'    => ['orders0', 'orders1'],
            'algorithm' => 'hash',
        ]);
    }

    protected function tearDown(): void
    {
        DB::flush(); // 清空连接缓存与 resolver，避免跨用例污染
    }

    // ---- ShardManager 纯函数 ----

    public function testHashNumericIsValueModShardCount(): void
    {
        $shards = ['s0', 's1', 's2', 's3'];
        Assert::assertSame(2, ShardManager::index(10, $shards, 'hash'));
        Assert::assertSame(0, ShardManager::index(8, $shards, 'hash'));
    }

    public function testHashStringIsDeterministicAndInRange(): void
    {
        $shards = ['s0', 's1', 's2', 's3'];
        $a = ShardManager::index('user-abc', $shards, 'hash');
        $b = ShardManager::index('user-abc', $shards, 'hash');
        Assert::assertSame($a, $b);
        Assert::assertTrue($a >= 0 && $a < count($shards), '索引应落在分片范围内');
    }

    public function testHashDistributionHitsAllShards(): void
    {
        $shards = ['s0', 's1', 's2', 's3'];
        $seen   = [];
        for ($i = 0; $i < 400; $i++) {
            $seen[ShardManager::index('k' . $i, $shards, 'hash')] = true;
        }
        Assert::assertSame(4, count($seen), 'hash 分布应覆盖全部 4 个分片');
    }

    public function testRangeRoutingByBoundaries(): void
    {
        $shards     = ['logs0', 'logs1', 'logs2'];
        $boundaries = [100, 200];
        Assert::assertSame(0, ShardManager::index(50, $shards, 'range', $boundaries));
        Assert::assertSame(1, ShardManager::index(150, $shards, 'range', $boundaries));
        Assert::assertSame(2, ShardManager::index(250, $shards, 'range', $boundaries));
    }

    public function testEmptyShardsThrows(): void
    {
        Assert::assertException(static fn () => ShardManager::index(1, [], 'hash'), \RuntimeException::class);
    }

    public function testRangeWithoutBoundariesThrows(): void
    {
        Assert::assertException(
            static fn () => ShardManager::index(1, ['a', 'b'], 'range', []),
            \RuntimeException::class
        );
    }

    // ---- DB::shardName / DB::shard 集成 ----

    public function testShardNameStableAndWithinConfiguredSet(): void
    {
        $name = DB::shardName('orders', 7);
        Assert::assertSame($name, DB::shardName('orders', 7), '同一键应稳定落到同一分片');
        Assert::assertTrue(
            $name === 'orders0' || $name === 'orders1',
            '应落在已配置分片之一'
        );
    }

    public function testShardReturnsConnectionForResolvedShard(): void
    {
        $name = DB::shardName('orders', 3);
        $conn = DB::shard('orders', 3);
        Assert::assertTrue($conn instanceof Connector, 'DB::shard 应返回 Connector');
        Assert::assertSame($name, $conn->name, 'DB::shard 返回的连接应对应路由出的分片名');
    }

    public function testRangeShardGroupConfig(): void
    {
        App::instance()->config()->set('database.sharding.logs', [
            'shards'     => ['logs0', 'logs1', 'logs2'],
            'algorithm'  => 'range',
            'boundaries' => [100, 200],
        ]);
        Assert::assertSame('logs0', DB::shardName('logs', 50));
        Assert::assertSame('logs1', DB::shardName('logs', 150));
        Assert::assertSame('logs2', DB::shardName('logs', 250));
    }

    public function testUnknownGroupThrows(): void
    {
        Assert::assertException(
            static fn () => DB::shardName('nope', 1),
            \RuntimeException::class
        );
    }
}
