<?php
declare(strict_types=1);

/**
 * 关联存在性约束（whereHas / has / doesntHave）测试（内存 SQLite，零外部依赖）
 * 覆盖 hasMany / belongsTo / belongsToMany 四种关联的 EXISTS / 计数 / 约束闭包 / NOT EXISTS。
 * 运行：php tests/run.php WhereHasTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Relations\HasMany;
use Mtphp\Database\Relations\BelongsTo;
use Mtphp\Database\Relations\BelongsToMany;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

// ---- 测试用模型（Wh 前缀，避免与其它测试文件重名）----

class WhUser extends Model
{
    protected static ?string $table = 'users';
    protected static string $primaryKey = 'id';

    public function posts(): HasMany
    {
        return $this->hasMany(WhPost::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(WhRole::class, 'wh_user_role');
    }
}

class WhPost extends Model
{
    protected static ?string $table = 'posts';
    protected static string $primaryKey = 'id';

    public function author(): BelongsTo
    {
        return $this->belongsTo(WhUser::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WhComment::class, 'post_id');
    }
}

class WhComment extends Model
{
    protected static ?string $table = 'comments';
    protected static string $primaryKey = 'id';
}

class WhRole extends Model
{
    protected static ?string $table = 'roles';
    protected static string $primaryKey = 'id';
}

class WhereHasTest extends TestCase
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
        Schema::create('posts', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('title');
        });
        Schema::create('comments', function (Blueprint $t): void {
            $t->id();
            $t->integer('post_id');
            $t->string('body');
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('wh_user_role', function (Blueprint $t): void {
            $t->integer('wh_user_id');
            $t->integer('wh_role_id');
        });

        WhUser::create(['name' => 'alice']);   // 1
        WhUser::create(['name' => 'bob']);     // 2
        WhUser::create(['name' => 'carol']);   // 3
        WhPost::create(['user_id' => 1, 'title' => 'a1']);  // 1
        WhPost::create(['user_id' => 1, 'title' => 'a2']);  // 2
        WhPost::create(['user_id' => 2, 'title' => 'b1']);  // 3
        WhPost::create(['user_id' => 3, 'title' => 'c1']);  // 4
        WhComment::create(['post_id' => 1, 'body' => 'c1']);
        WhComment::create(['post_id' => 1, 'body' => 'c2']);
        WhComment::create(['post_id' => 2, 'body' => 'c3']);
        WhRole::create(['name' => 'admin']);   // 1
        WhRole::create(['name' => 'editor']);  // 2
        // alice => admin；bob => editor；carol 无角色
        DB::table('wh_user_role')->insert(['wh_user_id' => 1, 'wh_role_id' => 1]);
        DB::table('wh_user_role')->insert(['wh_user_id' => 2, 'wh_role_id' => 2]);
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    private function names(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = $r['name'];
        }
        return $out;
    }

    public function testHasMany(): void
    {
        // 三个用户都有文章
        $rows = WhUser::has('posts')->get();
        Assert::assertSame(3, count($rows));
    }

    public function testHasManyWithCount(): void
    {
        // 文章数 > 1：仅 alice（2 篇）
        $rows = WhUser::has('posts', '>', 1)->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('alice', $rows[0]['name']);
    }

    public function testDoesntHaveHasMany(): void
    {
        // 没有评论的帖子：post3、post4
        $rows = WhPost::doesntHave('comments')->get();
        Assert::assertSame(2, count($rows));
    }

    public function testWhereHasOnHasMany(): void
    {
        // 拥有标题为 a1 的文章的用户：alice
        $rows = WhUser::whereHas('posts', function (Mtphp\Database\ModelBuilder $q): void {
            $q->where('title', '=', 'a1');
        })->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('alice', $rows[0]['name']);
    }

    public function testWhereHasOnBelongsTo(): void
    {
        // 作者名为 alice 的帖子：p1、p2
        $rows = WhPost::whereHas('author', function (Mtphp\Database\ModelBuilder $q): void {
            $q->where('name', '=', 'alice');
        })->get();
        Assert::assertSame(2, count($rows));
    }

    public function testWhereHasOnBelongsToMany(): void
    {
        // 至少拥有一个角色的用户：alice、bob
        $rows = WhUser::whereHas('roles')->get();
        Assert::assertSame(2, count($rows));
        Assert::assertTrue(in_array('alice', $this->names($rows), true));
        Assert::assertTrue(in_array('bob', $this->names($rows), true));
    }

    public function testWhereHasOnBelongsToManyWithConstraint(): void
    {
        // 拥有 admin 角色的用户：alice
        $rows = WhUser::whereHas('roles', function (Mtphp\Database\ModelBuilder $q): void {
            $q->where('name', '=', 'admin');
        })->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('alice', $rows[0]['name']);
    }

    public function testDoesntHaveBelongsToMany(): void
    {
        // 没有任何角色的用户：carol
        $rows = WhUser::doesntHave('roles')->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('carol', $rows[0]['name']);
    }

    public function testOrHas(): void
    {
        // alice（>1 篇）或 拥有 c1 标题文章的用户（carol）
        $rows = WhUser::has('posts', '>', 1)
            ->orWhereHas('posts', function (Mtphp\Database\ModelBuilder $q): void {
                $q->where('title', '=', 'c1');
            })
            ->get();
        $names = $this->names($rows);
        Assert::assertTrue(in_array('alice', $names, true));
        Assert::assertTrue(in_array('carol', $names, true));
        Assert::assertSame(2, count($rows));
    }
}
