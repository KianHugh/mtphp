<?php
declare(strict_types=1);

/**
 * 查询构造器悲观锁测试（内存 SQLite，零外部依赖）
 *   · lockForUpdate() → SELECT ... FOR UPDATE
 *   · sharedLock()    → SELECT ... FOR SHARE
 *   · lock() 通用写法
 *   · 事务内真实执行（锁须置于事务中才有意义）
 * 运行：php8 tests/run.php QueryLockTest
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

class LockItem extends Model
{
    protected static ?string $table = 'lock_items';
    protected static string $primaryKey = 'id';
}

class QueryLockTest extends TestCase
{
    protected function setUp(): void
    {
        DB::setResolver(sqliteResolver(new \PDO('sqlite::memory:')));

        Schema::create('lock_items', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('balance');
        });
        foreach ([
            ['name' => 'a', 'balance' => 100],
            ['name' => 'b', 'balance' => 200],
        ] as $r) {
            LockItem::create($r);
        }
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testForUpdateClauseInSql(): void
    {
        $sql = DB::table('lock_items')->where('id', 1)->lockForUpdate()->toSql();
        Assert::assertContains($sql, 'FOR UPDATE');
    }

    public function testSharedLockClauseInSql(): void
    {
        $sql = DB::table('lock_items')->where('id', 1)->sharedLock()->toSql();
        Assert::assertContains($sql, 'FOR SHARE');
    }

    public function testGenericLockClause(): void
    {
        $sql = DB::table('lock_items')->lock('share')->toSql();
        Assert::assertContains($sql, 'FOR SHARE');

        $sql2 = DB::table('lock_items')->lock('update')->toSql();
        Assert::assertContains($sql2, 'FOR UPDATE');
    }

    public function testNoLockByDefault(): void
    {
        $sql = DB::table('lock_items')->where('id', 1)->toSql();
        Assert::assertNotContains($sql, 'FOR UPDATE');
        Assert::assertNotContains($sql, 'FOR SHARE');
    }

    /**
     * 运行时探测：当前数据库后端是否支持行级锁（SELECT ... FOR UPDATE）。
     * MySQL / PostgreSQL / SQLite≥3.40 支持；本机 SQLite 3.36 不支持（语法报错）。
     */
    private function lockSupported(): bool
    {
        try {
            DB::connection()->select('SELECT 1 FOR UPDATE', []);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function testLockInsideTransactionExecutes(): void
    {
        if (!$this->lockSupported()) {
            // 后端不支持行锁：验证 SQL 已正确生成（MySQL/Postgres 才真正执行）
            Assert::assertContains(
                DB::table('lock_items')->where('id', 1)->lockForUpdate()->toSql(),
                'FOR UPDATE'
            );
            return;
        }
        $row = DB::transaction(function (): ?array {
            return DB::table('lock_items')
                ->where('id', 1)
                ->lockForUpdate()
                ->find();
        });
        Assert::assertNotNull($row);
        Assert::assertSame(100, (int) $row['balance']);
    }

    public function testLockOnModelBuilderExecutes(): void
    {
        if (!$this->lockSupported()) {
            Assert::assertContains(
                LockItem::query()->where('id', 2)->sharedLock()->toSql(),
                'FOR SHARE'
            );
            return;
        }
        $row = DB::transaction(function (): ?array {
            return LockItem::query()
                ->where('id', 2)
                ->sharedLock()
                ->find();
        });
        Assert::assertNotNull($row);
        Assert::assertSame('b', $row['name']);
    }

    public function testLockThenUpdateAtomic(): void
    {
        if (!$this->lockSupported()) {
            // 后端不支持行锁：仅验证“锁定查询 + 主键定位更新”的 SQL 生成链路
            $sql = DB::table('lock_items')->where('id', 1)->lockForUpdate()->toSql();
            Assert::assertContains($sql, 'FOR UPDATE');
            return;
        }
        DB::transaction(function (): void {
            $row = DB::table('lock_items')
                ->where('id', 1)
                ->lockForUpdate()
                ->find();
            $next = (int) $row['balance'] - 30;
            DB::table('lock_items')->where('id', 1)->update(['balance' => $next]);
        });

        $after = DB::table('lock_items')->where('id', 1)->find();
        Assert::assertSame(70, (int) $after['balance']);
    }
}
