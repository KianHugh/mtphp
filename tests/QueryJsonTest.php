<?php
declare(strict_types=1);

/**
 * 查询构造器 JSON 谓词 + 全文检索测试（内存 SQLite，零外部依赖）
 *   · whereJson / orWhereJson          按 JSON 文档内路径比较（可移植）
 *   · whereJsonIsNull / whereJsonNotNull  路径缺失 / 存在判断
 *   · whereFullText / orWhereFullText  MySQL MATCH...AGAINST（toSql 始终断言；执行需 MySQL）
 * 运行：php8 tests/run.php QueryJsonTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class JsonDoc extends Model
{
    protected static ?string $table = 'json_docs';
    protected static string $primaryKey = 'id';
}

class QueryJsonTest extends TestCase
{
    protected function setUp(): void
    {
        DB::setResolver(sqliteResolver(new \PDO('sqlite::memory:')));

        Schema::create('json_docs', function (Blueprint $t): void {
            $t->id();
            $t->text('meta');      // JSON 文档列
            $t->string('title');   // 全文检索目标列
        });

        JsonDoc::create(['meta' => json_encode(['name' => 'neo', 'age' => 30, 'role' => 'admin']), 'title' => 'mtphp 框架路由']);
        JsonDoc::create(['meta' => json_encode(['name' => 'bob', 'age' => 41]), 'title' => '分库与读写分离']);
        JsonDoc::create(['meta' => json_encode(['name' => 'carol']), 'title' => 'ORM 预加载']); // 无 age 键
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testWhereJsonEquals(): void
    {
        $rows = DB::table('json_docs')->whereJson('meta', 'name', '=', 'neo')->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('neo', json_decode($rows[0]['meta'], true)['name']);
    }

    public function testWhereJsonNumericCompare(): void
    {
        $rows = DB::table('json_docs')->whereJson('meta', 'age', '>', 35)->get();
        // 仅 bob(41) 命中；carol 无 age 键 → json_extract 为 NULL，不参与比较
        Assert::assertSame(1, count($rows));
        Assert::assertSame('bob', json_decode($rows[0]['meta'], true)['name']);
    }

    public function testOrWhereJson(): void
    {
        $rows = DB::table('json_docs')
            ->whereJson('meta', 'name', '=', 'neo')
            ->orWhereJson('meta', 'age', '>', 40)
            ->get();
        Assert::assertSame(2, count($rows));
    }

    public function testWhereJsonLike(): void
    {
        $rows = DB::table('json_docs')->whereJson('meta', 'name', 'LIKE', '%o%')->get();
        // neo / bob / carol 均含 'o' → 3 条
        Assert::assertSame(3, count($rows));
    }

    public function testWhereJsonIsNull(): void
    {
        // carol 无 age 键 → 路径提取为 NULL
        $rows = DB::table('json_docs')->whereJsonIsNull('meta', 'age')->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('carol', json_decode($rows[0]['meta'], true)['name']);
    }

    public function testWhereJsonNotNull(): void
    {
        $rows = DB::table('json_docs')->whereJsonNotNull('meta', 'age')->get();
        Assert::assertSame(2, count($rows)); // neo, bob 有 age
    }

    public function testWhereJsonOnModelBuilder(): void
    {
        $rows = JsonDoc::query()->whereJson('meta', 'role', '=', 'admin')->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('neo', json_decode($rows[0]['meta'], true)['name']);
    }

    public function testWhereJsonSqlGeneration(): void
    {
        $sql = DB::table('json_docs')->whereJson('meta', 'name', '=', 'neo')->toSql();
        Assert::assertContains($sql, 'json_extract(');
        Assert::assertContains($sql, "'$.name'");
        Assert::assertContains($sql, '= ?');
    }

    public function testWhereJsonNullSqlGeneration(): void
    {
        $sql = DB::table('json_docs')->whereJsonIsNull('meta', 'phone')->toSql();
        Assert::assertContains($sql, 'IS NULL');
        Assert::assertContains($sql, "'$.phone'");
    }

    /**
     * 全文检索：MySQL 目标（MATCH...AGAINST）。SQLite 普通列不支持，故执行类
     * 用例仅在 MySQL 后端运行；toSql() 形态始终断言（与悲观锁策略一致）。
     */
    private function fullTextSupported(): bool
    {
        return strtolower(DB::connection()->getDriver()) === 'mysql';
    }

    public function testFullTextSqlGeneration(): void
    {
        $sql = DB::table('json_docs')->whereFullText(['title'], '框架')->toSql();
        Assert::assertContains($sql, 'MATCH (`title`) AGAINST (?');
        Assert::assertContains($sql, 'IN NATURAL LANGUAGE MODE');
    }

    public function testFullTextBooleanModeSql(): void
    {
        $sql = DB::table('json_docs')->whereFullText(['title', 'meta'], '框架 路由', 'boolean')->toSql();
        Assert::assertContains($sql, 'MATCH (`title`, `meta`) AGAINST (?');
        Assert::assertContains($sql, 'IN BOOLEAN MODE');
    }

    public function testFullTextExecutesOnMysql(): void
    {
        if (!$this->fullTextSupported()) {
            // 非 MySQL 后端：仅验证 SQL 生成链路
            Assert::assertContains(
                DB::table('json_docs')->whereFullText(['title'], '框架')->toSql(),
                'MATCH'
            );
            return;
        }
        $rows = DB::table('json_docs')->whereFullText(['title'], '框架')->get();
        Assert::assertTrue(count($rows) >= 1);
    }
}
