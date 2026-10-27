<?php
declare(strict_types=1);

/**
 * Query::getBindings() 测试：导出与 toSql() 一一对应的绑定参数序列
 * 运行：php8 tests/run.php QueryBindingsTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class QueryBindingsTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('bind_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('age');
            $t->string('city');
        });
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testBindingsMatchSql(): void
    {
        $q = DB::table('bind_users')->where('age', '>', 18)->where('city', '=', 'WH');
        $sql = $q->toSql();
        $binds = $q->getBindings();

        Assert::assertContains($sql, 'WHERE');
        Assert::assertContains($sql, '`age`');
        // 绑定与 SQL 占位一一对应：两个条件 → 两个值
        Assert::assertSame([18, 'WH'], $binds);
    }

    public function testWhereInBindings(): void
    {
        $binds = DB::table('bind_users')->whereIn('id', [1, 2, 3])->getBindings();
        Assert::assertSame([1, 2, 3], $binds);
    }

    public function testWhereRawAddsExtraBindings(): void
    {
        $q = DB::table('bind_users')
            ->where('name', '=', '张三')
            ->whereRaw('age > ?', [21]);
        $binds = $q->getBindings();
        // name 绑定在前，whereRaw 额外绑定在后
        Assert::assertSame(['张三', 21], $binds);
    }

    public function testEmptyBindingsForPlainSelect(): void
    {
        $binds = DB::table('bind_users')->getBindings();
        Assert::assertSame([], $binds);
    }
}
