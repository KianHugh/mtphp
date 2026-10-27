<?php
declare(strict_types=1);

/**
 * 软删除 SoftDeletes 测试（内存 SQLite）
 * 运行：php8 tests/run.php SoftDeleteTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\SoftDeletes;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class SoftUser extends Model
{
    protected static ?string $table = 'soft_users';
    protected static string $primaryKey = 'id';

    use SoftDeletes;
}

class SoftDeleteTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('soft_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->softDeletes();
        });

        SoftUser::create(['name' => 'a']);
        SoftUser::create(['name' => 'b']);
        SoftUser::create(['name' => 'c']);
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testDefaultExcludesTrashed(): void
    {
        $id = SoftUser::create(['name' => 'tmp']);
        SoftUser::delete($id);                 // 软删
        Assert::assertNull(SoftUser::find($id), '默认查询应查不到软删记录');
        Assert::assertSame(3, SoftUser::count(), 'count 应排除软删记录');
    }

    public function testWithTrashedFindsIt(): void
    {
        $id = SoftUser::create(['name' => 'tmp']);
        SoftUser::delete($id);
        $row = SoftUser::withTrashed()->find($id);
        Assert::assertNotNull($row);
        Assert::assertNotNull($row['deleted_at']);
    }

    public function testRestore(): void
    {
        $id = SoftUser::create(['name' => 'tmp']);
        SoftUser::delete($id);
        SoftUser::restore($id);
        Assert::assertNotNull(SoftUser::find($id), '恢复后应可查到');
    }

    public function testOnlyTrashed(): void
    {
        $id = SoftUser::create(['name' => 'tmp']);
        SoftUser::delete($id);
        $trashed = SoftUser::onlyTrashed()->get();
        Assert::assertSame(1, count($trashed));
        Assert::assertSame($id, $trashed[0]['id']);
    }

    public function testForceDelete(): void
    {
        $id = SoftUser::create(['name' => 'tmp']);
        SoftUser::delete($id);
        SoftUser::forceDelete($id);
        Assert::assertNull(SoftUser::withTrashed()->find($id), '物理删除后应彻底消失');
    }
}
