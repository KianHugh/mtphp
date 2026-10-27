<?php
declare(strict_types=1);

use Mtphp\App;
use Mtphp\Database\Connector;
use Mtphp\Database\DB;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;

require_once __DIR__ . '/bootstrap.php';

/**
 * 跨分片“二次归并”助手测试：
 *  - countAcross：逐分片 count 求和；
 *  - sortAcross：跨片全局稳定排序；
 *  - paginateAcross：跨片全局分页。
 * 含纯数组场景与“真实 App + 多内存 SQLite 分片”场景。
 */
class ShardMergeTest extends TestCase
{
    protected function setUp(): void
    {
        new App(dirname(__DIR__));

        $pdos = [
            's0' => new \PDO('sqlite::memory:'),
            's1' => new \PDO('sqlite::memory:'),
            's2' => new \PDO('sqlite::memory:'),
        ];
        foreach ($pdos as $pdo) {
            $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, amount REAL)');
        }
        // 显式写入“全局唯一且单调”的 id（避免各分片自增 id 互相碰撞，便于游标分页演示）
        $pdos['s0']->exec('INSERT INTO orders (id, amount) VALUES (1,30),(3,10),(5,20)');
        $pdos['s1']->exec('INSERT INTO orders (id, amount) VALUES (2,5),(4,15)');
        // s2 故意留空，验证空分片不报错

        App::instance()->config()->set('database.sharding.orders', [
            'shards'    => ['s0', 's1', 's2'],
            'algorithm' => 'hash',
        ]);

        DB::setResolver(static function (string $name) use ($pdos): Connector {
            return new SqliteConnector($pdos[$name] ?? $pdos['s0']);
        });
    }

    protected function tearDown(): void
    {
        DB::flush();
    }

    // ---- 纯函数：合并后的行列表 ----
    public function testSortAcrossAscIsStable(): void
    {
        $rows = [
            ['id' => 3, 'amount' => 10],
            ['id' => 1, 'amount' => 20],
            ['id' => 2, 'amount' => 20], // 与 id=1 同值，应保持原先后（id=1 先于 id=2）
            ['id' => 4, 'amount' => 5],
        ];
        $sorted = DB::sortAcross($rows, 'amount', 'ASC');
        Assert::assertSame([4, 3, 1, 2], array_column($sorted, 'id'), '按 amount 升序');
        Assert::assertSame([5, 10, 20, 20], array_column($sorted, 'amount'));
        Assert::assertSame(1, $sorted[2]['id'], '同值元素保持原顺序（稳定）');
        Assert::assertSame(2, $sorted[3]['id']);
    }

    public function testSortAcrossDesc(): void
    {
        $rows = [
            ['id' => 1, 'amount' => 20],
            ['id' => 2, 'amount' => 5],
            ['id' => 3, 'amount' => 20],
        ];
        $sorted = DB::sortAcross($rows, 'amount', 'DESC');
        Assert::assertSame([1, 3, 2], array_column($sorted, 'id'), '按 amount 降序，同值稳定');
    }

    public function testPaginateAcrossSlices(): void
    {
        $rows = array_map(static fn ($i) => ['id' => $i], range(1, 23));
        $page = DB::paginateAcross($rows, 2, 10);
        Assert::assertSame(23, $page['total']);
        Assert::assertSame(10, $page['perPage']);
        Assert::assertSame(2, $page['page']);
        Assert::assertSame(3, $page['lastPage']);
        Assert::assertSame(10, count($page['items']), '第 2 页应有 10 条');
        Assert::assertSame(11, $page['items'][0]['id'], '第 2 页起始应为第 11 条');
        Assert::assertTrue($page['hasMore'], '应还有下一页');

        $last = DB::paginateAcross($rows, 3, 10);
        Assert::assertSame(3, count($last['items']), '末页余数 3 条');
        Assert::assertFalse($last['hasMore']);
    }

    public function testPaginateAcrossNormalizesBadInputs(): void
    {
        $rows = [['id' => 1], ['id' => 2]];
        $page = DB::paginateAcross($rows, 0, 0); // 页码<=0、每页<=0 应回退
        Assert::assertSame(1, $page['page']);
        Assert::assertSame(15, $page['perPage']);
        Assert::assertSame(2, count($page['items']));
    }

    // ---- 集成：真实分片上的 count / sort / paginate ----
    public function testCountAcrossSumsPerShard(): void
    {
        Assert::assertSame(5, DB::countAcross('orders', 'orders'), '三片共 5 行（含空片）');
    }

    public function testCountAcrossWithModifyFilter(): void
    {
        // 仅统计 amount >= 15 的订单
        $n = DB::countAcross('orders', 'orders', static fn ($q) => $q->where('amount', '>=', 15));
        Assert::assertSame(3, $n, 'amount>=15 的跨片共 3 行');
    }

    public function testGlobalSortThenPaginateAcrossShards(): void
    {
        // 先每片收敛（取前 100，单库索引），再应用层全局排序 + 分页
        $per   = DB::across('orders', static fn ($q) => $q->table('orders')->orderBy('amount', 'desc')->limit(100)->get());
        $merged = DB::mergeAcross($per);
        $sorted = DB::sortAcross($merged, 'amount', 'DESC');
        Assert::assertSame([30, 20, 15, 10, 5], array_map('intval', array_column($sorted, 'amount')), '跨片全局降序');

        $page = DB::paginateAcross($sorted, 1, 2);
        Assert::assertSame(5, $page['total']);
        Assert::assertSame([30, 20], array_map('intval', array_column($page['items'], 'amount')), '首页取最大两条');
        Assert::assertTrue($page['hasMore']);

        $page2 = DB::paginateAcross($sorted, 3, 2);
        Assert::assertSame(1, count($page2['items']), '末页仅 1 条');
        Assert::assertSame([5], array_map('intval', array_column($page2['items'], 'amount')), '第 3 页取最小一条');
        Assert::assertFalse($page2['hasMore']);
    }

    public function testCursorAcrossPagesAndExhausts(): void
    {
        // 首页（after=null）：全局按 id 升序取前 2
        $p1 = DB::cursorAcross('orders', 'orders', 'id', 2);
        Assert::assertSame(2, $p1['count']);
        Assert::assertSame([1, 2], array_map('intval', array_column($p1['items'], 'id')));
        Assert::assertSame(2, (int) $p1['nextCursor']);

        // 第 2 页（after=2）
        $p2 = DB::cursorAcross('orders', 'orders', 'id', 2, $p1['nextCursor']);
        Assert::assertSame([3, 4], array_map('intval', array_column($p2['items'], 'id')));
        Assert::assertSame(4, (int) $p2['nextCursor']);

        // 第 3 页（after=4）：仅剩 1 条，nextCursor 应为 null（到底）
        $p3 = DB::cursorAcross('orders', 'orders', 'id', 2, $p2['nextCursor']);
        Assert::assertSame([5], array_map('intval', array_column($p3['items'], 'id')));
        Assert::assertNull($p3['nextCursor'], '末页后应无下一页游标');
    }

    public function testCursorAcrossWithModifyFilter(): void
    {
        // 仅翻 amount >= 15 的分片行：过滤后为 id 1(30)/5(20)/4(15)，全局按 id 升序为 1,4,5
        $p1 = DB::cursorAcross('orders', 'orders', 'id', 2, null, static fn ($q) => $q->where('amount', '>=', 15));
        Assert::assertSame([1, 4], array_map('intval', array_column($p1['items'], 'id')));
        Assert::assertSame(4, (int) $p1['nextCursor']);

        $p2 = DB::cursorAcross('orders', 'orders', 'id', 2, $p1['nextCursor'], static fn ($q) => $q->where('amount', '>=', 15));
        Assert::assertSame([5], array_map('intval', array_column($p2['items'], 'id')));
        Assert::assertNull($p2['nextCursor']);
    }
}
