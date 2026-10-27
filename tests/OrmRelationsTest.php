<?php
declare(strict_types=1);

/**
 * ORM 关联 + 预加载测试（内存 SQLite，零外部依赖）
 * 运行：php8 tests/run.php OrmRelationsTest
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
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

// ---- 测试用模型（全局命名；项目里的 OrmUser 是 App\Models\OrmUser，不冲突）----

class OrmUser extends Model
{
    protected static ?string $table = 'users';
    protected static string $primaryKey = 'id';

    public function posts(): HasMany
    {
        return $this->hasMany(OrmPost::class, 'user_id');
    }
}

class OrmPost extends Model
{
    protected static ?string $table = 'posts';
    protected static string $primaryKey = 'id';

    public function author(): BelongsTo
    {
        return $this->belongsTo(OrmUser::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(OrmComment::class, 'post_id');
    }
}

class OrmComment extends Model
{
    protected static ?string $table = 'comments';
    protected static string $primaryKey = 'id';
}

class OrmRelationsTest extends TestCase
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

        OrmUser::create(['name' => 'alice']);
        OrmUser::create(['name' => 'bob']);
        OrmPost::create(['user_id' => 1, 'title' => 'a1']);
        OrmPost::create(['user_id' => 1, 'title' => 'a2']);
        OrmPost::create(['user_id' => 2, 'title' => 'b1']);
        OrmComment::create(['post_id' => 1, 'body' => 'c1']);
        OrmComment::create(['post_id' => 1, 'body' => 'c2']);
        OrmComment::create(['post_id' => 2, 'body' => 'c3']);
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testHasManyLazy(): void
    {
        $user = OrmUser::find(1);
        Assert::assertSame('alice', $user['name']);

        // Model 返回数组，关联通过“模型实例”发起（与数组行解耦，便于复用）
        $posts = (new OrmUser())->posts()->getResults($user);
        Assert::assertSame(2, count($posts));
        Assert::assertSame('a1', $posts[0]['title']);

        $bob = OrmUser::find(2);
        $bobPosts = (new OrmUser())->posts()->getResults($bob);
        Assert::assertSame(1, count($bobPosts));
    }

    public function testWithEagerLoading(): void
    {
        $users = OrmUser::with('posts')->get();
        Assert::assertSame(2, count($users));

        // alice 应有 2 篇，bob 1 篇
        $byName = [];
        foreach ($users as $u) {
            $byName[$u['name']] = $u['posts'];
        }
        Assert::assertSame(2, count($byName['alice']));
        Assert::assertSame(1, count($byName['bob']));
        Assert::assertSame('a1', $byName['alice'][0]['title']);
    }

    public function testBelongsToLazy(): void
    {
        $post = OrmPost::find(1);
        $author = (new OrmPost())->author()->getResults($post);
        Assert::assertNotNull($author);
        Assert::assertSame('alice', $author['name']);
    }

    public function testWithBelongsTo(): void
    {
        $posts = OrmPost::with('author')->get();
        Assert::assertSame(3, count($posts));

        $titles = [];
        foreach ($posts as $p) {
            $titles[] = $p['title'] . '=>' . ($p['author']['name'] ?? 'null');
        }
        Assert::assertTrue(in_array('a1=>alice', $titles, true));
        Assert::assertTrue(in_array('b1=>bob', $titles, true));
    }

    public function testLoadIntoExistingRows(): void
    {
        $users = OrmUser::all();          // 不含关联
        Assert::assertTrue(!array_key_exists('posts', $users[0]));
        OrmUser::load($users, 'posts');    // 懒加载

        $byName = [];
        foreach ($users as $u) {
            $byName[$u['name']] = $u['posts'];
        }
        Assert::assertSame(2, count($byName['alice']));
    }

    public function testWhereIn(): void
    {
        $rows = OrmPost::query()->whereIn('user_id', [1])->get();
        Assert::assertSame(2, count($rows));

        // 空集合兜底：IN () 应返回空
        $empty = OrmPost::query()->whereIn('user_id', [])->get();
        Assert::assertSame(0, count($empty));
    }

    public function testNestedWith(): void
    {
        // 嵌套预加载：users -> posts -> comments，应一次性批量取回（避免 N+1）
        $users = OrmUser::with('posts.comments')->get();
        Assert::assertSame(2, count($users));

        $alice = null;
        foreach ($users as $u) {
            if ($u['name'] === 'alice') {
                $alice = $u;
            }
        }
        Assert::assertNotNull($alice, '应存在 alice');

        Assert::assertSame(2, count($alice['posts']));
        // 第一篇 post 下有 2 条评论
        Assert::assertSame(2, count($alice['posts'][0]['comments']));
        Assert::assertSame('c1', $alice['posts'][0]['comments'][0]['body']);
    }
}
