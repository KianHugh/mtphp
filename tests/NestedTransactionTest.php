<?php
declare(strict_types=1);

/**
 * 嵌套事务（SAVEPOINT）测试（内存 SQLite，零外部依赖）
 * 验证：内层回滚不影响外层；内层提交不会提前真正提交（外层回滚一并撤销）；
 *       三层深度嵌套、内层回滚后外层继续提交。
 * 运行：php8 tests/run.php NestedTransactionTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class NestedTransactionTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('nt_items', function (Blueprint $t): void {
            $t->id();
            $t->string('label');
        });
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    private function countItems(): int
    {
        return (int) DB::table('nt_items')->count();
    }

    private function labels(): array
    {
        return array_map(static fn ($r) => $r['label'], DB::table('nt_items')->get());
    }

    public function testNestedRollbackKeepsOuter(): void
    {
        DB::transaction(function (): void {
            DB::table('nt_items')->insert(['label' => 'outer']);
            try {
                DB::transaction(function (): void {
                    DB::table('nt_items')->insert(['label' => 'inner']);
                    throw new \RuntimeException('rollback inner');
                });
            } catch (\RuntimeException $e) {
                // 内层回滚到保存点，外层继续
            }
        });

        Assert::assertSame(1, $this->countItems());
        Assert::assertSame(['outer'], $this->labels());
    }

    public function testNestedCommitDoesNotPrematurelyCommit(): void
    {
        try {
            DB::transaction(function (): void {
                DB::table('nt_items')->insert(['label' => 'outer']);
                DB::transaction(function (): void {
                    DB::table('nt_items')->insert(['label' => 'inner']);
                });
                throw new \RuntimeException('rollback outer');
            });
        } catch (\RuntimeException $e) {
            // 外层回滚
        }

        // 内层“提交”仅释放保存点，仍属外层事务；外层回滚 → 一并撤销
        Assert::assertSame(0, $this->countItems());
    }

    public function testDeepNestingThreeLevels(): void
    {
        DB::transaction(function (): void {
            DB::table('nt_items')->insert(['label' => 'L1']);
            DB::transaction(function (): void {
                DB::table('nt_items')->insert(['label' => 'L2']);
                DB::transaction(function (): void {
                    DB::table('nt_items')->insert(['label' => 'L3']);
                });
            });
        });

        Assert::assertSame(3, $this->countItems());
    }

    public function testInnerRollbackThenContinueOuter(): void
    {
        DB::transaction(function (): void {
            DB::table('nt_items')->insert(['label' => 'a']);
            try {
                DB::transaction(function (): void {
                    DB::table('nt_items')->insert(['label' => 'b']);
                    throw new \RuntimeException('x');
                });
            } catch (\RuntimeException $e) {
            }
            DB::table('nt_items')->insert(['label' => 'c']);
        });

        Assert::assertSame(2, $this->countItems());
        $labels = $this->labels();
        Assert::assertTrue(in_array('a', $labels, true));
        Assert::assertTrue(in_array('c', $labels, true));
        Assert::assertFalse(in_array('b', $labels, true));
    }

    public function testNestedCommitThenOuterCommitPersistsAll(): void
    {
        DB::transaction(function (): void {
            DB::table('nt_items')->insert(['label' => 'x']);
            DB::transaction(function (): void {
                DB::table('nt_items')->insert(['label' => 'y']);
            });
        });

        // 内层提交（释放保存点）+ 外层提交 → 两条都留存
        Assert::assertSame(2, $this->countItems());
    }
}
