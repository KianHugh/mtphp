<?php
declare(strict_types=1);

/**
 * 条件构造补全：闭包嵌套 WHERE 分组 / whereIn 子查询版 / Model::destroy 批量删除
 * 运行：php tests/run.php QueryConditionsTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use function Tests\sqliteResolver;

class QcdUser extends Model
{
    protected static ?string $table = 'qcd_users';
    protected static string $primaryKey = 'id';
}

class QcdGuardUser extends Model
{
    protected static ?string $table = 'qcd_guard_users';
    protected static string $primaryKey = 'id';
}

class QueryConditionsTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($pdo));

        Schema::create('qcd_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('age');
            $t->integer('vip');
            $t->integer('score');
        });
        Schema::create('qcd_vip', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
        });
        Schema::create('qcd_guard_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('age');
            $t->integer('vip');
            $t->integer('score');
        });

        $rows = [
            ['name' => 'a', 'age' => 30, 'vip' => 1, 'score' => 50],
            ['name' => 'b', 'age' => 25, 'vip' => 0, 'score' => 200],
            ['name' => 'c', 'age' => 40, 'vip' => 1, 'score' => 10],
            ['name' => 'd', 'age' => 22, 'vip' => 0, 'score' => 5],
            ['name' => 'e', 'age' => 35, 'vip' => 1, 'score' => 300],
        ];
        foreach ($rows as $r) {
            DB::table('qcd_users')->insert($r);
        }
        DB::table('qcd_vip')->insert(['user_id' => 1]);
        DB::table('qcd_vip')->insert(['user_id' => 3]);
        DB::table('qcd_vip')->insert(['user_id' => 5]);
    }

    public function testNestedWhereAndGroup(): void
    {
        // age > 20 AND (vip = 1 OR score > 100)
        $count = DB::table('qcd_users')
            ->where('age', '>', 20)
            ->where(function ($q): void {
                $q->where('vip', 1)->orWhere('score', '>', 100);
            })
            ->count();
        Assert::assertSame(4, $count);
        Assert::assertTrue(str_contains(
            DB::table('qcd_users')->where('age', '>', 20)
                ->where(function ($q): void {
                    $q->where('vip', 1)->orWhere('score', '>', 100);
                })->toSql(),
            '('
        ) && str_contains(
            DB::table('qcd_users')->where('age', '>', 20)
                ->where(function ($q): void {
                    $q->where('vip', 1)->orWhere('score', '>', 100);
                })->toSql(),
            'vip'
        ) && str_contains(
            DB::table('qcd_users')->where('age', '>', 20)
                ->where(function ($q): void {
                    $q->where('vip', 1)->orWhere('score', '>', 100);
                })->toSql(),
            'OR'
        ));
    }

    public function testNestedOrWhereGroup(): void
    {
        // vip = 0 OR (score > 150)
        $count = DB::table('qcd_users')
            ->where('vip', 0)
            ->orWhere(function ($q): void {
                $q->where('score', '>', 150);
            })
            ->count();
        Assert::assertSame(3, $count);
    }

    public function testWhereInSubquery(): void
    {
        // id 落在 vip.user_id 子查询 {1,3,5}
        $names = DB::table('qcd_users')
            ->whereIn('id', function ($q): void {
                $q->table('qcd_vip')->select('user_id');
            })
            ->orderBy('id')
            ->pluck('name');
        Assert::assertSame(['a', 'c', 'e'], $names);

        // 闭包与 Query 实例两种形态都支持
        $viaQuery = DB::table('qcd_users')
            ->whereIn('id', DB::table('qcd_vip')->select('user_id'))
            ->count();
        Assert::assertSame(3, $viaQuery);
    }

    public function testWhereNotInSubquery(): void
    {
        $names = DB::table('qcd_users')
            ->whereNotIn('id', function ($q): void {
                $q->table('qcd_vip')->select('user_id');
            })
            ->orderBy('id')
            ->pluck('name');
        Assert::assertSame(['b', 'd'], $names);
    }

    public function testDestroy(): void
    {
        DB::table('qcd_users')->delete(); // 清掉 setUp 预置数据，保证本例从空表开始
        $ids = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $n) {
            $ids[] = QcdUser::create(['name' => $n, 'age' => 1, 'vip' => 0, 'score' => 0]);
        }
        Assert::assertSame(5, QcdUser::count());

        // 销毁第 2、4 条（用实际自增 id，规避 AUTOINCREMENT 在不同清空策略下的偏移）
        Assert::assertSame(2, QcdUser::destroy([$ids[1], $ids[3]]));
        Assert::assertSame(3, QcdUser::count());

        Assert::assertSame(1, QcdUser::destroy($ids[0]));
        Assert::assertSame(2, QcdUser::count());

        Assert::assertSame(2, QcdUser::destroy([$ids[2], $ids[4]]));
        Assert::assertSame(0, QcdUser::count());
    }

    public function testDestroyBlockedByEvent(): void
    {
        QcdGuardUser::clearModelEvents();
        QcdGuardUser::registerModelEvent('deleting', function (): bool {
            return false; // 拦截整批删除
        });
        QcdGuardUser::create(['name' => 'x', 'age' => 1, 'vip' => 0, 'score' => 0]);
        QcdGuardUser::create(['name' => 'y', 'age' => 1, 'vip' => 0, 'score' => 0]);

        Assert::assertSame(0, QcdGuardUser::destroy([1, 2]));
        Assert::assertSame(2, QcdGuardUser::count());
    }
}
