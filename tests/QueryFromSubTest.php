<?php
declare(strict_types=1);

/**
 * 派生表（FROM 子查询）+ orHaving + 限定列反引号修复
 * 运行：php tests/run.php QueryFromSubTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Query;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use PDO;
use function Tests\sqliteResolver;

class QueryFromSubTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($pdo));

        Schema::create('fsub_orders', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->integer('amount');
        });

        DB::table('fsub_orders')->insert(['user_id' => 1, 'amount' => 30]);
        DB::table('fsub_orders')->insert(['user_id' => 1, 'amount' => 90]);
        DB::table('fsub_orders')->insert(['user_id' => 2, 'amount' => 20]);
        DB::table('fsub_orders')->insert(['user_id' => 2, 'amount' => 5]);
        DB::table('fsub_orders')->insert(['user_id' => 3, 'amount' => 200]);
    }

    public function testFromSubWithClosure(): void
    {
        // 派生表：按 user_id 汇总金额，外层再按汇总值过滤
        $rows = DB::table('fsub_tmp')
            ->fromSub(function (Query $q): void {
                $q->from('fsub_orders')
                    ->select('user_id', 'SUM(amount) AS total')
                    ->groupBy('user_id');
            }, 'o')
            ->where('o.total', '>', 100)
            ->get();

        // 仅 user_id=1(120)、user_id=3(200) 满足
        Assert::assertSame(2, count($rows));
        $ids = array_column($rows, 'user_id');
        Assert::assertTrue(in_array(1, $ids, true));
        Assert::assertTrue(in_array(3, $ids, true));
    }

    public function testFromSubWithQueryInstance(): void
    {
        $sub = DB::table('fsub_orders')
            ->select('user_id', 'SUM(amount) AS total')
            ->groupBy('user_id');

        $rows = DB::table('fsub_tmp')
            ->fromSub($sub, 'o')
            ->where('o.total', '>=', 100)
            ->get();

        // user_id=1(120)、user_id=3(200) 满足 >=100
        Assert::assertSame(2, count($rows));
        $ids = array_column($rows, 'user_id');
        sort($ids);
        Assert::assertSame([1, 3], $ids);
    }

    public function testFromSubWithOrderByOnNonAggregate(): void
    {
        // 派生表非聚合时，ORDER BY 限定列正常工作（SQLite 对“聚合派生表 + ORDER BY 限定列”有引擎层限制，与框架无关）
        $rows = DB::table('fsub_tmp')
            ->fromSub(function (Query $q): void {
                $q->from('fsub_orders')->select('user_id', 'amount');
            }, 'o')
            ->orderByDesc('o.amount')
            ->get();

        Assert::assertSame(5, count($rows));
        Assert::assertSame(200, (int) $rows[0]['amount']); // 金额最大者排在最前
    }

    public function testFromRawWithBindings(): void
    {
        $rows = DB::table('fsub_tmp')
            ->fromRaw(
                '(SELECT user_id, SUM(amount) AS total FROM fsub_orders GROUP BY user_id)',
                [],
                'o'
            )
            ->where('o.total', '<', 100)
            ->get();

        // user_id=1(120) 与 3(200) 被排除，剩 2(25)
        Assert::assertSame(1, count($rows));
        Assert::assertSame(2, (int) $rows[0]['user_id']);
    }

    public function testFromSubRendersDerivedTableInSql(): void
    {
        $sql = DB::table('fsub_tmp')
            ->fromSub(function (Query $q): void {
                $q->from('fsub_orders')->select('user_id')->groupBy('user_id');
            }, 'o')
            ->toSql();

        Assert::assertTrue(str_contains($sql, 'FROM (SELECT'));
        Assert::assertTrue(str_contains($sql, 'AS `o`'));
    }

    public function testOrHaving(): void
    {
        $rows = DB::table('fsub_orders')
            ->select('user_id', 'SUM(amount) AS total')
            ->groupBy('user_id')
            ->having('total', '>', 100)
            ->orHaving('total', '<', 10)
            ->orderBy('user_id')
            ->get();

        // total>100：user 1(120)、3(200)；total<10：user 2(25 不满足) → 仅 1 与 3
        Assert::assertSame(2, count($rows));
        Assert::assertSame([1, 3], array_column($rows, 'user_id'));
    }

    public function testOrHavingRendersInSql(): void
    {
        $sql = DB::table('fsub_orders')
            ->select('user_id', 'SUM(amount) AS total')
            ->groupBy('user_id')
            ->having('total', '>', 100)
            ->orHaving('total', '<', 10)
            ->toSql();

        Assert::assertTrue(str_contains($sql, 'HAVING'));
        Assert::assertTrue(str_contains($sql, ' OR '), 'orHaving 应生成 OR 连接');
    }
}
