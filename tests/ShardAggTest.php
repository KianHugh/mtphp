<?php
declare(strict_types=1);

use Mtphp\App;
use Mtphp\Database\Connector;
use Mtphp\Database\DB;
use Mtphp\Database\ShardManager;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;

require_once __DIR__ . '/bootstrap.php';

/**
 * 跨分片聚合查询测试
 *  - ShardManager::shardsFromConfig 纯函数；
 *  - DB::across / mergeAcross / sumAcross 通过“真实 App + 多内存 SQLite 分片”做端到端验证。
 */
class ShardAggTest extends TestCase
{
    protected function setUp(): void
    {
        // 构造真实 App（提供 config 上下文），并准备 3 个互相独立的内存 SQLite 分片
        new App(dirname(__DIR__));

        $pdos = [
            's0' => new \PDO('sqlite::memory:'),
            's1' => new \PDO('sqlite::memory:'),
            's2' => new \PDO('sqlite::memory:'),
        ];
        foreach ($pdos as $pdo) {
            $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, amount REAL)');
        }
        $pdos['s0']->exec("INSERT INTO users (name, amount) VALUES ('a',10),('b',20)");
        $pdos['s1']->exec("INSERT INTO users (name, amount) VALUES ('c',30)");
        // s2 故意留空，验证“空分片不报错”

        App::instance()->config()->set('database.sharding.orders', [
            'shards'    => ['s0', 's1', 's2'],
            'algorithm' => 'hash',
        ]);

        DB::setResolver(static function (string $name) use ($pdos): Connector {
            $pdo = $pdos[$name] ?? $pdos['s0'];
            return new SqliteConnector($pdo);
        });
    }

    protected function tearDown(): void
    {
        DB::flush(); // 清空连接缓存与 resolver，避免跨用例污染
    }

    // ---- 纯函数 ----

    public function testShardsFromConfigReturnsList(): void
    {
        Assert::assertSame(['a', 'b'], ShardManager::shardsFromConfig(['shards' => ['a', 'b']]));
    }

    public function testShardsFromConfigThrowsWhenMissing(): void
    {
        Assert::assertException(
            static fn () => ShardManager::shardsFromConfig(['algorithm' => 'hash']),
            \RuntimeException::class
        );
    }

    public function testMergeAndSumAcrossPure(): void
    {
        $per = [
            's0' => [['amount' => 1], ['amount' => 2]],
            's1' => [['amount' => 3]],
            's2' => [],
        ];
        Assert::assertSame(3, count(DB::mergeAcross($per)), '应合并出 3 行');
        Assert::assertSame(6.0, DB::sumAcross($per, 'amount'), '跨片求和应为 6');
    }

    // ---- 集成：逐分片查询 + 聚合 ----

    public function testAcrossQueriesEachShard(): void
    {
        $per = DB::across('orders', static fn ($q) => $q->table('users')->orderBy('id', 'asc')->get());
        Assert::assertSame(3, count($per), '应覆盖 3 个分片');
        Assert::assertSame(2, count($per['s0'] ?? []), 's0 应有 2 行');
        Assert::assertSame(1, count($per['s1'] ?? []), 's1 应有 1 行');
        Assert::assertSame(0, count($per['s2'] ?? []), 's2 应为空');
    }

    public function testMergeAndSumAcrossRealShards(): void
    {
        $per   = DB::across('orders', static fn ($q) => $q->table('users')->get());
        $rows  = DB::mergeAcross($per);
        $total = DB::sumAcross($per, 'amount');
        Assert::assertSame(3, count($rows), '跨片合并行数应为 3');
        Assert::assertSame(60.0, $total, '跨片金额汇总应为 60');
    }

    public function testAcrossWithCountAggregation(): void
    {
        $counts = DB::across('orders', static fn ($q) => $q->table('users')->count());
        Assert::assertSame(3, array_sum($counts), '各分片 count 之和应为 3');
    }
}
