<?php
declare(strict_types=1);

/**
 * Query / Model 便捷方法测试：doesntExist / sole / each / reorder / findMany / Model::sole / SoftDeletes::trashed
 * 运行：php8 tests/run.php QueryConvenienceTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\SoftDeletes;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class QCUser extends Model
{
    protected static ?string $table = 'qc_users';
    protected static string $primaryKey = 'id';
}

class QCSoftUser extends Model
{
    protected static ?string $table = 'qc_soft_users';
    protected static string $primaryKey = 'id';

    use SoftDeletes;
}

class QueryConvenienceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('qc_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('age');
        });
        Schema::create('qc_soft_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    // ---------------- Query::doesntExist ----------------

    public function testDoesntExist(): void
    {
        Assert::assertTrue(DB::table('qc_users')->doesntExist(), '空表应 doesntExist');
        DB::table('qc_users')->insert(['name' => 'a', 'age' => 1]);
        Assert::assertFalse(DB::table('qc_users')->doesntExist(), '有数据后应 exists');
        Assert::assertTrue(DB::table('qc_users')->where('id', 999)->doesntExist(), '不匹配也应 doesntExist');
    }

    // ---------------- Query::sole ----------------

    public function testSoleZeroThrows(): void
    {
        Assert::assertException(
            fn () => DB::table('qc_users')->sole(),
            \Mtphp\Exception\ModelNotFoundException::class
        );
    }

    public function testSoleOneReturns(): void
    {
        DB::table('qc_users')->insert(['name' => 'a', 'age' => 1]);
        $row = DB::table('qc_users')->sole();
        Assert::assertSame('a', $row['name']);
    }

    public function testSoleManyThrows(): void
    {
        DB::table('qc_users')->insert(['name' => 'a', 'age' => 1]);
        DB::table('qc_users')->insert(['name' => 'b', 'age' => 2]);
        Assert::assertException(
            fn () => DB::table('qc_users')->sole(),
            \RuntimeException::class
        );
    }

    // ---------------- Query::each ----------------

    public function testEachIterates(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            DB::table('qc_users')->insert(['name' => 'n' . $i, 'age' => $i]);
        }
        $seen = 0;
        $sum  = 0;
        DB::table('qc_users')->orderBy('id', 'asc')->each(function (array $row) use (&$seen, &$sum): void {
            $seen++;
            $sum += (int) $row['age'];
        });
        Assert::assertSame(25, $seen);
        Assert::assertSame(25 * 26 / 2, $sum);

        // 小批次也能跑完所有行
        $seen2 = 0;
        DB::table('qc_users')->each(function () use (&$seen2): void {
            $seen2++;
        }, 7);
        Assert::assertSame(25, $seen2);
    }

    // ---------------- Query::reorder ----------------

    public function testReorder(): void
    {
        DB::table('qc_users')->insert(['name' => 'a', 'age' => 3]);
        DB::table('qc_users')->insert(['name' => 'b', 'age' => 1]);

        // 先按 age 排序，再 reorder 为按 id 升序：旧排序应被清除
        $sql = DB::table('qc_users')->orderBy('age', 'desc')->reorder('id', 'asc')->toSql();
        Assert::assertContains($sql, 'ORDER BY `id` ASC');
        Assert::assertNotContains($sql, 'age');

        // reorder() 无参：彻底清除排序
        $sql2 = DB::table('qc_users')->orderBy('age', 'desc')->reorder()->toSql();
        Assert::assertNotContains($sql2, 'ORDER BY');

        // 排序确实生效（reorder 后首条为 id=1，age=3）
        $first = DB::table('qc_users')->orderBy('age', 'desc')->reorder('id', 'asc')->first();
        Assert::assertSame(3, (int) $first['age']);
    }

    // ---------------- Model::findMany ----------------

    public function testModelFindMany(): void
    {
        $a = QCUser::create(['name' => 'a', 'age' => 1]);
        $b = QCUser::create(['name' => 'b', 'age' => 2]);
        $c = QCUser::create(['name' => 'c', 'age' => 3]);

        $rows = QCUser::findMany([$b, $a]);
        Assert::assertSame(2, count($rows));
        $names = array_column($rows, 'name');
        Assert::assertTrue(in_array('a', $names, true));
        Assert::assertTrue(in_array('b', $names, true));

        // 空数组直接返回空
        Assert::assertSame([], QCUser::findMany([]));

        // 含不存在 id 不报错（只返回命中的）
        $some = QCUser::findMany([$c, 9999]);
        Assert::assertSame(1, count($some));

        // 指定列（PDO 默认 FETCH_BOTH 含数字键，仅校验业务列）
        $cols = QCUser::findMany([$a], ['id', 'name']);
        Assert::assertSame($a, $cols[0]['id']);
        Assert::assertSame('a', $cols[0]['name']);
    }

    // ---------------- Model::sole ----------------

    public function testModelSole(): void
    {
        QCUser::create(['name' => 'a', 'age' => 1]);
        $row = QCUser::sole(['name' => 'a']);
        Assert::assertSame('a', $row['name']);

        // 0 条 → ModelNotFoundException
        Assert::assertException(
            fn () => QCUser::sole(['name' => 'nope']),
            \Mtphp\Exception\ModelNotFoundException::class
        );

        // 多条 → RuntimeException
        QCUser::create(['name' => 'a', 'age' => 2]);
        Assert::assertException(
            fn () => QCUser::sole(['name' => 'a']),
            \RuntimeException::class
        );
    }

    // ---------------- SoftDeletes::trashed ----------------

    public function testSoftDeletesTrashed(): void
    {
        $id = QCSoftUser::create(['name' => 'a']);
        Assert::assertFalse(QCSoftUser::trashed($id), '未删应返回 false');

        QCSoftUser::delete($id);
        Assert::assertTrue(QCSoftUser::trashed($id), '软删后应返回 true');

        QCSoftUser::restore($id);
        Assert::assertFalse(QCSoftUser::trashed($id), '恢复后应返回 false');

        // 不存在的 id
        Assert::assertFalse(QCSoftUser::trashed(99999));
    }
}
