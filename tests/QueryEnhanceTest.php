<?php
declare(strict_types=1);

/**
 * Query 增强（whereNull/whereNotNull/having/raw/bulkInsert/upsert）测试（内存 SQLite）
 * 运行：php8 tests/run.php QueryEnhanceTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class QueryEnhanceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('qe_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('age')->nullable();
            $t->integer('score')->default(0);
        });
        Schema::create('qe_tags', function (Blueprint $t): void {
            $t->id();
            $t->integer('item_id');
            $t->string('tag');
        });

        DB::table('qe_users')->bulkInsert([
            ['name' => 'a', 'age' => 18, 'score' => 1],
            ['name' => 'b', 'age' => null, 'score' => 2],
            ['name' => 'c', 'age' => 20, 'score' => 3],
        ]);
        DB::table('qe_tags')->bulkInsert([
            ['item_id' => 1, 'tag' => 'x'],
            ['item_id' => 1, 'tag' => 'y'],
            ['item_id' => 2, 'tag' => 'z'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testWhereNull(): void
    {
        $rows = DB::table('qe_users')->whereNull('age')->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('b', $rows[0]['name']);
    }

    public function testWhereNotNull(): void
    {
        $rows = DB::table('qe_users')->whereNotNull('age')->orderBy('age')->get();
        Assert::assertSame(2, count($rows));
        Assert::assertSame('a', $rows[0]['name']);
    }

    public function testHaving(): void
    {
        // 按 item_id 分组，筛选出标签数 > 1 的
        $rows = DB::table('qe_tags')
            ->select('item_id, COUNT(*) AS cnt')
            ->groupBy('item_id')
            ->having('COUNT(*)', '>', 1)
            ->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame(1, (int) $rows[0]['item_id']);
    }

    public function testBulkInsert(): void
    {
        $n = DB::table('qe_users')->bulkInsert([
            ['name' => 'd', 'age' => 30, 'score' => 4],
            ['name' => 'e', 'age' => 31, 'score' => 5],
        ]);
        Assert::assertSame(2, $n);
        Assert::assertSame(5, DB::table('qe_users')->count());
    }

    public function testUpsertInsertAndUpdate(): void
    {
        // id=1 已存在 → 更新；id=9 不存在 → 插入
        DB::table('qe_users')->upsert([
            ['id' => 1, 'name' => 'a2', 'age' => 99, 'score' => 10],
            ['id' => 9, 'name' => 'new', 'age' => 1, 'score' => 1],
        ], ['id']);

        $u1 = DB::table('qe_users')->where('id', '=', 1)->find();
        Assert::assertSame('a2', $u1['name']);
        Assert::assertSame(99, (int) $u1['age']);

        $u9 = DB::table('qe_users')->where('id', '=', 9)->find();
        Assert::assertNotNull($u9);
        Assert::assertSame('new', $u9['name']);
    }

    public function testRaw(): void
    {
        $rows = DB::table('qe_users')->raw('SELECT name FROM qe_users WHERE score > ?', [1]);
        Assert::assertTrue(is_array($rows));
        Assert::assertSame(2, count($rows));
    }
}
