<?php
declare(strict_types=1);

use Mtphp\Database\DB;
use Mtphp\Database\Query;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;

require_once __DIR__ . '/bootstrap.php';

/**
 * Query 聚合 / 谓词增强测试（sum/avg/max/min/distinct/whereNotIn/whereBetween/orderByDesc）
 */
class QueryAggTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY, cat TEXT, price INTEGER)');
        DB::setResolver(static fn () => new SqliteConnector($pdo));

        DB::table('product')->insert(['cat' => 'a', 'price' => 10]);
        DB::table('product')->insert(['cat' => 'a', 'price' => 20]);
        DB::table('product')->insert(['cat' => 'b', 'price' => 30]);
        DB::table('product')->insert(['cat' => 'b', 'price' => 40]);
    }

    public function testSum(): void
    {
        Assert::assertSame(100.0, DB::table('product')->sum('price'));
    }

    public function testAvg(): void
    {
        Assert::assertSame(25.0, DB::table('product')->avg('price'));
    }

    public function testMax(): void
    {
        Assert::assertSame(40, DB::table('product')->max('price'));
    }

    public function testMin(): void
    {
        Assert::assertSame(10, DB::table('product')->min('price'));
    }

    public function testDistinct(): void
    {
        $cats = DB::table('product')->distinct()->select('cat')->get();
        Assert::assertSame(2, count($cats));
    }

    public function testWhereNotIn(): void
    {
        $rows = DB::table('product')->whereNotIn('cat', ['a'])->get();
        Assert::assertSame(2, count($rows));
        foreach ($rows as $r) {
            Assert::assertFalse($r['cat'] === 'a');
        }
    }

    public function testWhereBetween(): void
    {
        $rows = DB::table('product')->whereBetween('price', [20, 30])->orderBy('price')->get();
        Assert::assertSame(2, count($rows));
        Assert::assertSame(20, $rows[0]['price']);
        Assert::assertSame(30, $rows[1]['price']);
    }

    public function testOrderByDesc(): void
    {
        $rows = DB::table('product')->orderByDesc('price')->get();
        Assert::assertSame(40, $rows[0]['price']);
        Assert::assertSame(10, $rows[3]['price']);
    }

    public function testIncrement(): void
    {
        $n = DB::table('product')->where('cat', 'a')->increment('price', 5);
        Assert::assertSame(2, $n);
        Assert::assertSame(40.0, DB::table('product')->where('cat', 'a')->sum('price'));
    }

    public function testDecrement(): void
    {
        $n = DB::table('product')->where('id', 1)->decrement('price', 3);
        Assert::assertSame(1, $n);
        Assert::assertSame(7, DB::table('product')->where('id', 1)->value('price'));
    }

    public function testIncrementRequiresWhere(): void
    {
        $threw = false;
        try {
            DB::table('product')->increment('price');
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        Assert::assertTrue($threw, '无 where 的 increment 应拒绝');
    }
}
