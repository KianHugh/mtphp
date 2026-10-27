<?php
declare(strict_types=1);

/**
 * insertGetId 行为确认（内存 SQLite，零外部依赖）
 * 验证：DB::table()->insertGetId() 与 Model::create() 均返回自增主键。
 * 说明：底层走 PDO::lastInsertId()，SQLite 与 MySQL 行为同构（均返回最近插入行的自增 id）。
 * 运行：php8 tests/run.php InsertGetIdTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;

class IdUser extends Model
{
    protected static ?string $table = 'id_users';
    protected static string $primaryKey = 'id';
}

class InsertGetIdTest extends TestCase
{
    protected function setUp(): void
    {
        DB::setResolver(sqliteResolver(new \PDO('sqlite::memory:')));
        Schema::create('id_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
    }

    public function testQueryInsertGetIdReturnsAutoIncrement(): void
    {
        $id1 = DB::table('id_users')->insertGetId(['name' => 'a']);
        $id2 = DB::table('id_users')->insertGetId(['name' => 'b']);
        Assert::assertSame(1, $id1);
        Assert::assertSame(2, $id2);

        $row = DB::table('id_users')->where('id', $id2)->find();
        Assert::assertSame('b', $row['name']);
    }

    public function testModelCreateReturnsAutoIncrement(): void
    {
        $id = IdUser::create(['name' => 'neo']);
        Assert::assertSame(1, $id);
        Assert::assertSame('neo', IdUser::find($id)['name']);
    }
}
