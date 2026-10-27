<?php
declare(strict_types=1);

/**
 * 查询构造器集合操作 / 子查询 / 原生 HAVING 测试（内存 SQLite，零外部依赖）
 *   · whereExists / whereNotExists           EXISTS 子查询
 *   · union / unionAll                       集合操作
 *   · havingRaw                              原生 HAVING 片段
 * 运行：php8 tests/run.php QuerySetOpsTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;

class SetItem extends Model
{
    protected static ?string $table = 'set_items';
    protected static string $primaryKey = 'id';
}

class QuerySetOpsTest extends TestCase
{
    protected function setUp(): void
    {
        DB::setResolver(sqliteResolver(new \PDO('sqlite::memory:')));
        Schema::create('set_items', function (Blueprint $t): void {
            $t->id();
            $t->string('grp');
            $t->integer('val');
        });
        foreach ([
            ['grp' => 'a', 'val' => 10],
            ['grp' => 'a', 'val' => 20],
            ['grp' => 'b', 'val' => 5],
        ] as $r) {
            SetItem::create($r);
        }
    }

    public function testWhereExistsReturnsAllWhenSubqueryHasRows(): void
    {
        // 非相关 EXISTS：子查询命中即返回全部主表行
        $rows = DB::table('set_items')
            ->whereExists(fn ($q) => $q->table('set_items')->where('val', 20))
            ->get();
        Assert::assertSame(3, count($rows));
    }

    public function testWhereNotExistsReturnsAllWhenSubqueryEmpty(): void
    {
        // 子查询为空（val=999 不存在）→ NOT EXISTS 恒成立 → 全部返回
        $rows = DB::table('set_items')
            ->whereNotExists(fn ($q) => $q->table('set_items')->where('val', 999))
            ->get();
        Assert::assertSame(3, count($rows));
    }

    public function testWhereExistsEmptyWhenSubqueryMisses(): void
    {
        $rows = DB::table('set_items')
            ->whereExists(fn ($q) => $q->table('set_items')->where('val', 999))
            ->get();
        Assert::assertSame(0, count($rows));
    }

    public function testExistsSqlGeneration(): void
    {
        $sql = DB::table('set_items')
            ->whereExists(fn ($q) => $q->table('admins')->where('id', 1))
            ->toSql();
        Assert::assertContains($sql, 'EXISTS (');
        Assert::assertContains($sql, 'FROM `admins`');
    }

    public function testUnionMergesBranches(): void
    {
        $rows = DB::table('set_items')->where('val', 10)
            ->union(DB::table('set_items')->where('val', 5))
            ->get();
        // 去重 UNION：val=10（1 行）+ val=5（1 行）→ 2 行
        Assert::assertSame(2, count($rows));
    }

    public function testUnionClosureAndRaw(): void
    {
        // 闭包分支
        $rows = DB::table('set_items')->where('val', 10)
            ->union(fn ($q) => $q->table('set_items')->where('val', 20))
            ->get();
        Assert::assertSame(2, count($rows));

        // 原生 SQL 分支（列投影须与主查询一致）
        $rows2 = DB::table('set_items')->where('val', 10)
            ->union('SELECT * FROM `set_items` WHERE `val` = 5')
            ->get();
        Assert::assertSame(2, count($rows2));
    }

    public function testUnionAllKeepsDuplicates(): void
    {
        // 两条分支都命中 val=10 的那一行 → UNION ALL 保留重复（2 行）
        $rows = DB::table('set_items')->where('val', 10)
            ->unionAll(DB::table('set_items')->where('val', 10))
            ->get();
        Assert::assertSame(2, count($rows));
    }

    public function testUnionSqlGeneration(): void
    {
        $sql = DB::table('set_items')->where('val', 10)
            ->union(DB::table('set_items')->where('val', 5))
            ->toSql();
        Assert::assertContains($sql, 'UNION');
        Assert::assertContains($sql, 'FROM `set_items`');
    }

    public function testHavingRawFiltersGroups(): void
    {
        $rows = DB::table('set_items')
            ->select('grp', 'SUM(val) AS total')
            ->groupBy('grp')
            ->havingRaw('SUM(val) > ?', [15])
            ->get();
        // grp=a: 10+20=30 > 15 ✓ ；grp=b: 5 ≯ 15 ✗ → 仅 a
        Assert::assertSame(1, count($rows));
        Assert::assertSame('a', $rows[0]['grp']);
        Assert::assertSame(30, (int) $rows[0]['total']);
    }

    public function testHavingRawSqlGeneration(): void
    {
        $sql = DB::table('set_items')
            ->groupBy('grp')
            ->havingRaw('COUNT(*) > ?', [1])
            ->toSql();
        Assert::assertContains($sql, 'HAVING');
        Assert::assertContains($sql, 'COUNT(*) > ?');
    }
}
