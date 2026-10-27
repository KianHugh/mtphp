<?php
declare(strict_types=1);

/**
 * 多对多（belongsToMany）关联 + 预加载测试（内存 SQLite，零外部依赖）
 * 运行：php8 tests/run.php BelongsToManyTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Relations\BelongsToMany;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

// ---- 测试用模型（全局命名，互不冲突）----

class MtmUser extends Model
{
    protected static ?string $table = 'users';
    protected static string $primaryKey = 'id';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(MtmRole::class);
    }
}

class MtmRole extends Model
{
    protected static ?string $table = 'roles';
    protected static string $primaryKey = 'id';

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(MtmUser::class);
    }
}

class BelongsToManyTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        // 多对多中间表：默认名 = 两「单数类名 snake」字母序拼接
        // （MtmUser => mtm_user，MtmRole => mtm_role => mtm_role_mtm_user）；
        // 外键列默认 = 单数类名 snake + _id（mtm_user_id / mtm_role_id，与 foreignKeyName 一致）。
        Schema::create('mtm_role_mtm_user', function (Blueprint $t): void {
            $t->integer('mtm_user_id');
            $t->integer('mtm_role_id');
        });

        MtmUser::create(['name' => 'alice']);   // id 1
        MtmUser::create(['name' => 'bob']);     // id 2
        MtmRole::create(['name' => 'admin']);   // id 1
        MtmRole::create(['name' => 'editor']);  // id 2
        MtmRole::create(['name' => 'viewer']);  // id 3

        // alice: admin + editor；bob: editor + viewer
        DB::table('mtm_role_mtm_user')->insert(['mtm_user_id' => 1, 'mtm_role_id' => 1]);
        DB::table('mtm_role_mtm_user')->insert(['mtm_user_id' => 1, 'mtm_role_id' => 2]);
        DB::table('mtm_role_mtm_user')->insert(['mtm_user_id' => 2, 'mtm_role_id' => 2]);
        DB::table('mtm_role_mtm_user')->insert(['mtm_user_id' => 2, 'mtm_role_id' => 3]);
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testDefaultPivotTableName(): void
    {
        // 默认中间表名 = 两「单数类名 snake」字母序拼接（mtm_role_mtm_user），
        // 与 setUp 建的表名一致，下面各查询能取到数据即证明。
        $rel = (new MtmUser())->roles();
        Assert::assertTrue($rel instanceof BelongsToMany, 'belongsToMany 应返回 BelongsToMany 实例');
    }

    public function testLazyGetResults(): void
    {
        $alice = MtmUser::find(1);
        $roles = (new MtmUser())->roles()->getResults($alice);
        Assert::assertSame(2, count($roles));

        $names = [];
        foreach ($roles as $r) {
            $names[] = $r['name'];
        }
        Assert::assertTrue(in_array('admin', $names, true));
        Assert::assertTrue(in_array('editor', $names, true));

        $bob = MtmUser::find(2);
        $bobRoles = (new MtmUser())->roles()->getResults($bob);
        Assert::assertSame(2, count($bobRoles));
        $bobNames = [];
        foreach ($bobRoles as $r) {
            $bobNames[] = $r['name'];
        }
        Assert::assertTrue(in_array('viewer', $bobNames, true));
    }

    public function testEagerLoadingWith(): void
    {
        $users = MtmUser::with('roles')->get();
        Assert::assertSame(2, count($users));

        $byName = [];
        foreach ($users as $u) {
            $byName[$u['name']] = $u['roles'];
        }
        Assert::assertSame(2, count($byName['alice']));
        Assert::assertSame(2, count($byName['bob']));
        // 关联行是关联模型的结果（经 castRow），含主键
        Assert::assertSame('admin', $byName['alice'][0]['name']);
    }

    public function testReverseEagerLoading(): void
    {
        $roles = MtmRole::with('users')->get();
        Assert::assertSame(3, count($roles));

        $byName = [];
        foreach ($roles as $r) {
            $byName[$r['name']] = count($r['users']);
        }
        // admin: alice(1)；editor: alice+bob(2)；viewer: bob(1)
        Assert::assertSame(1, $byName['admin']);
        Assert::assertSame(2, $byName['editor']);
        Assert::assertSame(1, $byName['viewer']);
    }

    public function testNestedManyToMany(): void
    {
        // users -> roles -> users（角色反查用户）也应批量预加载
        $users = MtmUser::with('roles.users')->get();
        Assert::assertSame(2, count($users));

        $alice = null;
        foreach ($users as $u) {
            if ($u['name'] === 'alice') {
                $alice = $u;
            }
        }
        Assert::assertNotNull($alice, '应存在 alice');
        Assert::assertSame(2, count($alice['roles']));
        // admin 角色下应回查到 alice（至少 1 个用户）
        Assert::assertTrue(count($alice['roles'][0]['users']) >= 1);
    }
}
