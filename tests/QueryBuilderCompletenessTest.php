<?php
declare(strict_types=1);

/**
 * Query 构造器补全：whereColumn / addSelect / orderByRaw / join 变体 / pluck / when / tap
 * 运行：php tests/run.php QueryBuilderCompletenessTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use function Tests\sqliteResolver;

class QueryBuilderCompletenessTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($pdo));

        Schema::create('qbc_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('age');
        });
        Schema::create('qbc_profiles', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('bio');
        });
        Schema::create('qbc_cmp', function (Blueprint $t): void {
            $t->id();
            $t->integer('a');
            $t->integer('b');
        });

        DB::table('qbc_users')->insert(['name' => 'alice', 'age' => 30]); // id 1
        DB::table('qbc_users')->insert(['name' => 'bob',   'age' => 25]); // id 2
        DB::table('qbc_users')->insert(['name' => 'carol', 'age' => 40]); // id 3
        DB::table('qbc_users')->insert(['name' => 'dave',  'age' => 22]); // id 4

        DB::table('qbc_profiles')->insert(['user_id' => 1, 'bio' => 'a-bio']);
        DB::table('qbc_profiles')->insert(['user_id' => 2, 'bio' => 'b-bio']);
        // 用户 3、4 无 profile

        DB::table('qbc_cmp')->insert(['a' => 10, 'b' => 5]);  // a > b
        DB::table('qbc_cmp')->insert(['a' => 3,  'b' => 8]);  // a < b
        DB::table('qbc_cmp')->insert(['a' => 7,  'b' => 7]);  // a = b
    }

    public function testWhereColumn(): void
    {
        Assert::assertSame(1, DB::table('qbc_cmp')->whereColumn('a', '>', 'b')->count());
        // 组合：a<b 或 a=b 共 2 行
        Assert::assertSame(
            2,
            DB::table('qbc_cmp')->whereColumn('a', '<', 'b')->orWhereColumn('a', '=', 'b')->count()
        );
        // toSql 不应反引号列名（列间比较，原样透传）
        Assert::assertTrue(
            str_contains(DB::table('qbc_cmp')->whereColumn('a', '>', 'b')->toSql(), '`a` > `b`')
            || str_contains(DB::table('qbc_cmp')->whereColumn('a', '>', 'b')->toSql(), 'a > b'),
            'whereColumn 应生成列间比较'
        );
    }

    public function testAddSelect(): void
    {
        $row = DB::table('qbc_users')->select('id')->addSelect('name')->where('id', 1)->first();
        Assert::assertNotNull($row);
        Assert::assertSame(1, $row['id']);
        Assert::assertSame('alice', $row['name']);
        Assert::assertTrue(str_contains(
            DB::table('qbc_users')->select('id')->addSelect('name')->toSql(),
            '`name`'
        ));
    }

    public function testOrderByRaw(): void
    {
        $rows = DB::table('qbc_users')->orderByRaw('id DESC')->get();
        Assert::assertSame(4, $rows[0]['id']);
        Assert::assertSame(1, $rows[3]['id']);

        // 带绑定：id=3 排最前
        $ordered = DB::table('qbc_users')
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [3])
            ->pluck('id');
        Assert::assertSame(3, $ordered[0]);
    }

    public function testLeftJoin(): void
    {
        $rows = DB::table('qbc_users')
            ->select('qbc_users.name', 'qbc_profiles.bio')
            ->leftJoin('qbc_profiles', 'qbc_users.id = qbc_profiles.user_id')
            ->orderBy('name')
            ->get();
        Assert::assertSame(4, count($rows));
        // carol / dave 无 profile，bio 应为 null
        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']] = $r['bio'] ?? null;
        }
        Assert::assertSame('a-bio', $byName['alice']);
        Assert::assertSame('b-bio', $byName['bob']);
        Assert::assertNull($byName['carol']);
        Assert::assertNull($byName['dave']);
    }

    public function testRightJoinAndCrossJoin(): void
    {
        // SQLite 不支持 RIGHT JOIN 执行，仅校验生成 SQL 含 RIGHT JOIN
        Assert::assertTrue(str_contains(
            DB::table('qbc_profiles')
                ->rightJoin('qbc_users', 'qbc_users.id = qbc_profiles.user_id')
                ->toSql(),
            'RIGHT JOIN'
        ));
        // CROSS JOIN：笛卡尔积 4 × 2 = 8
        Assert::assertSame(8, DB::table('qbc_users')->crossJoin('qbc_profiles')->count());
        Assert::assertTrue(str_contains(
            DB::table('qbc_users')->crossJoin('qbc_profiles')->toSql(),
            'CROSS JOIN'
        ));
    }

    public function testPluck(): void
    {
        $names = DB::table('qbc_users')->orderBy('id')->pluck('name');
        Assert::assertSame(['alice', 'bob', 'carol', 'dave'], $names);

        $map = DB::table('qbc_users')->orderBy('id')->pluck('name', 'id');
        Assert::assertSame(['1' => 'alice', '2' => 'bob', '3' => 'carol', '4' => 'dave'], $map);
    }

    public function testWhen(): void
    {
        $name = 'bob';
        $rows = DB::table('qbc_users')
            ->when($name, fn ($q) => $q->where('name', $name))
            ->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('bob', $rows[0]['name']);

        // 条件为假 → 走 default 回调
        $all = DB::table('qbc_users')
            ->when(false, fn ($q) => $q->where('name', 'nobody'), fn ($q) => $q->where('age', '>', 0))
            ->count();
        Assert::assertSame(4, $all);
    }

    public function testTap(): void
    {
        $captured = null;
        $builder  = DB::table('qbc_users')->tap(function (object $q) use (&$captured): void {
            $captured = $q->toSql();
        });
        Assert::assertTrue(is_string($captured) && str_contains($captured, 'FROM `qbc_users`'));
        // tap 返回构造器自身，链路仍可用
        $row = $builder->where('id', 1)->first();
        Assert::assertSame('alice', $row['name']);
    }
}
