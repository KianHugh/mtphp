<?php
declare(strict_types=1);

/**
 * 预加载约束闭包测试：with(['rel' => fn ($q) => ...]) 与 withCount 约束
 * 覆盖 hasMany / hasOne / belongsTo / belongsToMany / morphMany / morphOne /
 * morphToMany / hasManyThrough，以及嵌套路径约束（'a.b' => fn）。
 * 运行：php8 tests/run.php EagerConstraintTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Relations\HasMany;
use Mtphp\Database\Relations\HasOne;
use Mtphp\Database\Relations\BelongsTo;
use Mtphp\Database\Relations\BelongsToMany;
use Mtphp\Database\Relations\MorphMany;
use Mtphp\Database\Relations\MorphOne;
use Mtphp\Database\Relations\MorphToMany;
use Mtphp\Database\Relations\HasManyThrough;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

// ===================== 模型 =====================

class ECUser extends Model
{
    protected static ?string $table = 'ec_users';
    protected static string $primaryKey = 'id';

    public function posts(): HasMany
    {
        return $this->hasMany(ECPost::class, 'user_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(ECProfile::class, 'user_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(ECCountry::class, 'country_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(ECRole::class, 'ec_role_user', 'ec_user_id', 'ec_role_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(ECComment::class, 'commentable');
    }

    public function avatar(): MorphOne
    {
        return $this->morphOne(ECImage::class, 'imageable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(ECTag::class, 'taggable', 'ec_taggables', 'tag_id');
    }
}

class ECPost extends Model
{
    protected static ?string $table = 'ec_posts';
    protected static string $primaryKey = 'id';

    public function comments(): MorphMany
    {
        return $this->morphMany(ECComment::class, 'commentable');
    }
}

class ECProfile extends Model
{
    protected static ?string $table = 'ec_profiles';
    protected static string $primaryKey = 'id';
}

class ECCountry extends Model
{
    protected static ?string $table = 'ec_countries';
    protected static string $primaryKey = 'id';

    public function cities(): HasManyThrough
    {
        return $this->hasManyThrough(ECCity::class, ECUser::class, 'country_id', 'user_id');
    }
}

class ECRole extends Model
{
    protected static ?string $table = 'ec_roles';
    protected static string $primaryKey = 'id';
}

class ECComment extends Model
{
    protected static ?string $table = 'ec_comments';
    protected static string $primaryKey = 'id';
}

class ECImage extends Model
{
    protected static ?string $table = 'ec_images';
    protected static string $primaryKey = 'id';
}

class ECTag extends Model
{
    protected static ?string $table = 'ec_tags';
    protected static string $primaryKey = 'id';
}

class ECCity extends Model
{
    protected static ?string $table = 'ec_cities';
    protected static string $primaryKey = 'id';
}

// ===================== 测试 =====================

class EagerConstraintTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('ec_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('country_id');
            $t->integer('age');
        });
        Schema::create('ec_posts', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('title');
            $t->integer('published');
        });
        Schema::create('ec_profiles', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('bio');
        });
        Schema::create('ec_countries', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('ec_roles', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('ec_role_user', function (Blueprint $t): void {
            $t->id();
            $t->integer('ec_user_id');
            $t->integer('ec_role_id');
        });
        Schema::create('ec_comments', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->string('commentable_type');
            $t->integer('commentable_id');
        });
        Schema::create('ec_images', function (Blueprint $t): void {
            $t->id();
            $t->string('url');
            $t->string('imageable_type');
            $t->integer('imageable_id');
        });
        Schema::create('ec_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('ec_taggables', function (Blueprint $t): void {
            $t->id();
            $t->string('taggable_type');
            $t->integer('taggable_id');
            $t->integer('tag_id');
        });
        Schema::create('ec_cities', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('user_id');
        });
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    /**
     * 准备一套标准数据：user1（CN，年龄 30，profile=bio_x，role admin/editor，tag php/sql，
     * 2 posts，1 comment）与 user2（US，1 post）。
     */
    private function seed(): array
    {
        $cn = ECCountry::create(['name' => 'CN']);
        $us = ECCountry::create(['name' => 'US']);

        $u1 = ECUser::create(['name' => 'u1', 'country_id' => $cn, 'age' => 30]);
        $u2 = ECUser::create(['name' => 'u2', 'country_id' => $us, 'age' => 40]);

        // posts：u1 有 p1(published=1) + p2(published=0)；u2 有 p3(published=1)
        $p1 = ECPost::create(['user_id' => $u1, 'title' => 'p1', 'published' => 1]);
        ECPost::create(['user_id' => $u1, 'title' => 'p2', 'published' => 0]);
        ECPost::create(['user_id' => $u2, 'title' => 'p3', 'published' => 1]);

        // profile（仅 u1）
        ECProfile::create(['user_id' => $u1, 'bio' => 'bio_x']);

        // roles：u1=admin+editor；u2=editor
        $rAdmin   = ECRole::create(['name' => 'admin']);
        $rEditor  = ECRole::create(['name' => 'editor']);
        DB::table('ec_role_user')->insert(['ec_user_id' => $u1, 'ec_role_id' => $rAdmin]);
        DB::table('ec_role_user')->insert(['ec_user_id' => $u1, 'ec_role_id' => $rEditor]);
        DB::table('ec_role_user')->insert(['ec_user_id' => $u2, 'ec_role_id' => $rEditor]);

        // comments：u1 一条（body=hi）；p1 两条（hi / bye）
        ECComment::create(['body' => 'hi', 'commentable_type' => ECUser::class, 'commentable_id' => $u1]);
        ECComment::create(['body' => 'hi', 'commentable_type' => ECPost::class, 'commentable_id' => $p1]);
        ECComment::create(['body' => 'bye', 'commentable_type' => ECPost::class, 'commentable_id' => $p1]);

        // avatar（仅 u1）
        ECImage::create(['url' => 'u1', 'imageable_type' => ECUser::class, 'imageable_id' => $u1]);

        // tags：u1=php+sql
        $tPhp = ECTag::create(['name' => 'php']);
        $tSql = ECTag::create(['name' => 'sql']);
        DB::table('ec_taggables')->insert(['taggable_type' => ECUser::class, 'taggable_id' => $u1, 'tag_id' => $tPhp]);
        DB::table('ec_taggables')->insert(['taggable_type' => ECUser::class, 'taggable_id' => $u1, 'tag_id' => $tSql]);

        // cities：CN(u1,u2) -> u1 拥有 bj+sh；u2 拥有 gz
        $cu1 = ECUser::find($u1);
        $cu2 = ECUser::find($u2);
        ECCity::create(['name' => 'bj', 'user_id' => $u1]);
        ECCity::create(['name' => 'sh', 'user_id' => $u1]);
        ECCity::create(['name' => 'gz', 'user_id' => $u2]);

        return ['u1' => $u1, 'u2' => $u2, 'cn' => $cn, 'p1' => $p1];
    }

    // ---------------- hasMany 约束（过滤 / 排序 / 限量） ----------------

    public function testHasManyConstraintFilter(): void
    {
        $this->seed();
        $users = ECUser::with(['posts' => fn ($q) => $q->where('published', 1)])->get();
        Assert::assertSame(1, count($users[0]['posts']), 'u1 仅 published=1 的 post 被加载');
        Assert::assertSame('p1', $users[0]['posts'][0]['title']);

        // 无约束时回到两条
        $all = ECUser::with('posts')->get();
        Assert::assertSame(2, count($all[0]['posts']));
    }

    public function testHasManyConstraintOrderAndLimit(): void
    {
        $this->seed();
        $users = ECUser::with(['posts' => fn ($q) => $q->orderBy('id', 'desc')])->get();
        Assert::assertSame(2, $users[0]['posts'][0]['id'], '按 id desc，首条应为 p2');

        $limited = ECUser::with(['posts' => fn ($q) => $q->limit(1)])->get();
        Assert::assertSame(1, count($limited[0]['posts']), 'limit(1) 仅加载一条');
    }

    // ---------------- hasOne 约束 ----------------

    public function testHasOneConstraint(): void
    {
        $this->seed();
        $ok = ECUser::with(['profile' => fn ($q) => $q->where('bio', 'bio_x')])->get();
        Assert::assertNotNull($ok[0]['profile']);
        Assert::assertSame('bio_x', $ok[0]['profile']['bio']);

        $none = ECUser::with(['profile' => fn ($q) => $q->where('bio', 'nope')])->get();
        Assert::assertNull($none[0]['profile'], '不匹配应为 null');
    }

    // ---------------- belongsTo 约束 ----------------

    public function testBelongsToConstraint(): void
    {
        $this->seed();
        $ok = ECUser::with(['country' => fn ($q) => $q->where('name', 'CN')])->get();
        Assert::assertNotNull($ok[0]['country']);
        Assert::assertSame('CN', $ok[0]['country']['name']);

        $none = ECUser::with(['country' => fn ($q) => $q->where('name', 'US')])->get();
        // u1 国家是 CN，约束 US 不匹配 -> null
        Assert::assertNull($none[0]['country']);
    }

    // ---------------- belongsToMany 约束 ----------------

    public function testBelongsToManyConstraint(): void
    {
        $this->seed();
        $users = ECUser::with(['roles' => fn ($q) => $q->where('name', 'admin')])->get();
        Assert::assertSame(1, count($users[0]['roles']), 'u1 仅 admin 角色被加载');
        Assert::assertSame('admin', $users[0]['roles'][0]['name']);

        $all = ECUser::with('roles')->get();
        Assert::assertSame(2, count($all[0]['roles']));
    }

    // ---------------- morphMany 约束 ----------------

    public function testMorphManyConstraint(): void
    {
        $this->seed();
        $users = ECUser::with(['comments' => fn ($q) => $q->where('body', 'hi')])->get();
        Assert::assertSame(1, count($users[0]['comments']), 'u1 仅 body=hi 的评论被加载');
        Assert::assertSame('hi', $users[0]['comments'][0]['body']);
    }

    // ---------------- morphOne 约束 ----------------

    public function testMorphOneConstraint(): void
    {
        $this->seed();
        $ok = ECUser::with(['avatar' => fn ($q) => $q->where('url', 'u1')])->get();
        Assert::assertNotNull($ok[0]['avatar']);
        Assert::assertSame('u1', $ok[0]['avatar']['url']);

        $none = ECUser::with(['avatar' => fn ($q) => $q->where('url', 'nope')])->get();
        Assert::assertNull($none[0]['avatar']);
    }

    // ---------------- morphToMany 约束 ----------------

    public function testMorphToManyConstraint(): void
    {
        $this->seed();
        $users = ECUser::with(['tags' => fn ($q) => $q->where('name', 'php')])->get();
        Assert::assertSame(1, count($users[0]['tags']), 'u1 仅 php 标签被加载');
        Assert::assertSame('php', $users[0]['tags'][0]['name']);

        $all = ECUser::with('tags')->get();
        Assert::assertSame(2, count($all[0]['tags']));
    }

    // ---------------- hasManyThrough 约束 ----------------

    public function testHasManyThroughConstraint(): void
    {
        $this->seed();
        $countries = ECCountry::with(['cities' => fn ($q) => $q->where('name', 'bj')])->get();
        Assert::assertSame(1, count($countries[0]['cities']), 'CN 仅 bj 城市被加载');
        Assert::assertSame('bj', $countries[0]['cities'][0]['name']);

        $all = ECCountry::with('cities')->get();
        Assert::assertSame(2, count($all[0]['cities']), 'CN 共拥有 2 座城市（bj+sh）');
    }

    // ---------------- withCount 约束 ----------------

    public function testWithCountConstraint(): void
    {
        $this->seed();
        $users = ECUser::withCount(['posts' => fn ($q) => $q->where('published', 1)])->get();
        // u1 共 2 帖，其中 published=1 的 1 帖
        Assert::assertSame(1, $users[0]['posts_count']);

        $all = ECUser::withCount('posts')->get();
        Assert::assertSame(2, $all[0]['posts_count']);
    }

    // ---------------- 嵌套路径约束（'a.b' => fn） ----------------

    public function testNestedPathConstraint(): void
    {
        $this->seed();
        // posts.comments：仅加载 body=hi 的评论
        $users = ECUser::with(['posts.comments' => fn ($q) => $q->where('body', 'hi')])->get();
        $p1Comments = $users[0]['posts'][0]['comments'] ?? [];
        Assert::assertSame(1, count($p1Comments), 'p1 仅 body=hi 评论被加载');
        Assert::assertSame('hi', $p1Comments[0]['body']);

        // 无约束时 p1 有 2 条评论
        $all = ECUser::with('posts.comments')->get();
        Assert::assertSame(2, count($all[0]['posts'][0]['comments']));
    }
}
