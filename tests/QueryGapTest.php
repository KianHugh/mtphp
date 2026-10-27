<?php
declare(strict_types=1);

/**
 * Query 构造器补齐的谓词与便捷方法测试：
 * whereNotBetween / orWhereNotBetween / whereBetweenColumns / orWhereBetweenColumns /
 * havingBetween / orHavingBetween / latest / oldest / inRandomOrder /
 * take / skip / offset / forPage / firstOrFail
 * 运行：php8 tests/run.php QueryGapTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;

class QueryGapTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('gap_items', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('score');
            $t->integer('lo');
            $t->integer('hi');
            $t->integer('cat');
            $t->timestamp('created_at');
        });

        $rows = [
            ['name' => 'a', 'score' => 5,  'lo' => 1, 'hi' => 10, 'cat' => 1, 'created_at' => '2026-01-03 00:00:00'],
            ['name' => 'b', 'score' => 15, 'lo' => 1, 'hi' => 10, 'cat' => 1, 'created_at' => '2026-01-01 00:00:00'],
            ['name' => 'c', 'score' => 25, 'lo' => 1, 'hi' => 10, 'cat' => 2, 'created_at' => '2026-01-02 00:00:00'],
            ['name' => 'd', 'score' => 50, 'lo' => 1, 'hi' => 10, 'cat' => 2, 'created_at' => '2026-01-05 00:00:00'],
            ['name' => 'e', 'score' => 60, 'lo' => 1, 'hi' => 10, 'cat' => 2, 'created_at' => '2026-01-04 00:00:00'],
            ['name' => 'f', 'score' => 70, 'lo' => 1, 'hi' => 10, 'cat' => 3, 'created_at' => '2026-01-06 00:00:00'],
        ];
        foreach ($rows as $r) {
            DB::table('gap_items')->insert($r);
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    public function testWhereNotBetween(): void
    {
        $got = DB::table('gap_items')->whereNotBetween('score', [10, 20])->orderBy('id')->get();
        // 落在 [10,20] 之外的：5,25,50,60,70 => ids 1,3,4,5,6
        Assert::assertSame([1, 3, 4, 5, 6], $this->ids($got));
        $sql = DB::table('gap_items')->whereNotBetween('score', [10, 20])->toSql();
        Assert::assertTrue(str_contains($sql, 'NOT BETWEEN ? AND ?'), 'SQL 应含 NOT BETWEEN');
    }

    public function testOrWhereNotBetween(): void
    {
        $got = DB::table('gap_items')
            ->where('score', 5)
            ->orWhereNotBetween('score', [10, 20])
            ->orderBy('id')->get();
        // score=5 (id1) OR 不在 [10,20] (ids 3,4,5,6) => 1,3,4,5,6
        Assert::assertSame([1, 3, 4, 5, 6], $this->ids($got));
    }

    public function testWhereBetweenColumns(): void
    {
        $got = DB::table('gap_items')->whereBetweenColumns('score', ['lo', 'hi'])->orderBy('id')->get();
        // lo=1, hi=10 => score 在 [1,10] 仅 5 => id1
        Assert::assertSame([1], $this->ids($got));
        $sql = DB::table('gap_items')->whereBetweenColumns('score', ['lo', 'hi'])->toSql();
        Assert::assertTrue(str_contains($sql, 'BETWEEN `lo` AND `hi`'), 'SQL 应含 BETWEEN `lo` AND `hi`');
    }

    public function testOrWhereBetweenColumns(): void
    {
        $got = DB::table('gap_items')
            ->where('cat', 3)
            ->orWhereBetweenColumns('score', ['lo', 'hi'])
            ->orderBy('id')->get();
        // cat=3 (id6) OR score in [1,10] (id1) => 1,6
        Assert::assertSame([1, 6], $this->ids($got));
    }

    public function testHavingBetween(): void
    {
        $got = DB::table('gap_items')
            ->select(['cat', 'COUNT(*) AS c'])
            ->groupBy('cat')
            ->havingBetween('COUNT(*)', [2, 3])
            ->orderBy('cat')
            ->get();
        // cat1=2 条, cat2=3 条, cat3=1 条 => 仅 2..3 命中 => cat 1,2
        $cats = array_map(static fn (array $r): int => (int) $r['cat'], $got);
        Assert::assertSame([1, 2], $cats);
    }

    public function testOrHavingBetween(): void
    {
        $got = DB::table('gap_items')
            ->select(['cat', 'COUNT(*) AS c'])
            ->groupBy('cat')
            ->having('COUNT(*)', '>=', 3)
            ->orHavingBetween('COUNT(*)', [1, 1])
            ->orderBy('cat')
            ->get();
        // count>=3 (cat2=3) OR count in [1,1] (cat3=1) => cat 2,3
        $cats = array_map(static fn (array $r): int => (int) $r['cat'], $got);
        Assert::assertSame([2, 3], $cats);
    }

    public function testLatestOldest(): void
    {
        $latest = DB::table('gap_items')->latest('created_at')->first();
        Assert::assertSame(6, (int) $latest['id']); // 2026-01-06
        $oldest = DB::table('gap_items')->oldest('created_at')->first();
        Assert::assertSame(2, (int) $oldest['id']); // 2026-01-01
    }

    public function testInRandomOrder(): void
    {
        $got = DB::table('gap_items')->inRandomOrder()->get();
        Assert::assertSame(6, count($got));
        $sql = DB::table('gap_items')->inRandomOrder()->toSql();
        Assert::assertTrue(str_contains($sql, 'RANDOM()'), 'sqlite 下应为 RANDOM()');
    }

    public function testTakeSkipOffsetForPage(): void
    {
        // take
        Assert::assertSame(2, count(DB::table('gap_items')->orderBy('id')->take(2)->get()));

        // skip + take
        $skipped = DB::table('gap_items')->orderBy('id')->skip(1)->take(2)->get();
        Assert::assertSame([2, 3], $this->ids($skipped));

        // forPage(2, 2) => 第2页每页2条 => ids 3,4
        $page = DB::table('gap_items')->orderBy('id')->forPage(2, 2)->get();
        Assert::assertSame([3, 4], $this->ids($page));

        // offset 别名 + take（offset 须配合 limit 才有尾段切片效果）
        $off = DB::table('gap_items')->orderBy('id')->offset(4)->take(2)->get();
        Assert::assertSame([5, 6], $this->ids($off));
    }

    public function testFirstOrFail(): void
    {
        $row = DB::table('gap_items')->where('id', 1)->firstOrFail();
        Assert::assertSame(1, (int) $row['id']);

        Assert::assertException(
            static function (): void {
                DB::table('gap_items')->where('id', 999)->firstOrFail();
            },
            \Mtphp\Exception\ModelNotFoundException::class
        );
    }
}
