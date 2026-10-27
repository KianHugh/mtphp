<?php
declare(strict_types=1);

/**
 * 模型进阶功能测试：本地作用域 / 全局作用域 / 多态关联 / 远程一对多
 * 运行：php8 tests/run.php ModelFeaturesTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Relations\MorphMany;
use Mtphp\Database\Relations\MorphOne;
use Mtphp\Database\Relations\MorphTo;
use Mtphp\Database\Relations\MorphToMany;
use Mtphp\Database\Relations\HasManyThrough;
use Mtphp\Database\Scopes\Scope;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

// ===================== 本地 / 全局作用域 =====================

class MScopePost extends Model
{
    protected static ?string $table = 'mscope_posts';
    protected static string $primaryKey = 'id';

    // 本地作用域：scopeXxx($builder, ...$args)
    public function scopePublished(\Mtphp\Database\ModelBuilder $q): void
    {
        $q->where('published', 1);
    }

    public function scopePopular(\Mtphp\Database\ModelBuilder $q, int $views): void
    {
        $q->where('views', '>=', $views);
    }
}

// 全局作用域（对象形式）
class OnlyPublishedScope implements Scope
{
    public function apply(\Mtphp\Database\ModelBuilder $builder): void
    {
        $builder->where('published', 1);
    }
}

class MScopeGated extends Model
{
    protected static ?string $table = 'mgated_posts';
    protected static string $primaryKey = 'id';
}

// ===================== 多态关联 =====================

class MorphPost extends Model
{
    protected static ?string $table = 'mposts';
    protected static string $primaryKey = 'id';

    public function comments(): MorphMany
    {
        return $this->morphMany(MorphComment::class, 'commentable');
    }

    public function cover(): MorphOne
    {
        return $this->morphOne(MorphImage::class, 'imageable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(MorphTag::class, 'taggable', 'mtaggables', 'tag_id');
    }
}

class MorphVideo extends Model
{
    protected static ?string $table = 'mvideos';
    protected static string $primaryKey = 'id';

    public function comments(): MorphMany
    {
        return $this->morphMany(MorphComment::class, 'commentable');
    }
}

class MorphComment extends Model
{
    protected static ?string $table = 'mcomments';
    protected static string $primaryKey = 'id';

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class MorphImage extends Model
{
    protected static ?string $table = 'mimages';
    protected static string $primaryKey = 'id';
}

class MorphTag extends Model
{
    protected static ?string $table = 'mtags';
    protected static string $primaryKey = 'id';
}

// ===================== 远程一对多 =====================

class HMCountry extends Model
{
    protected static ?string $table = 'hmcountries';
    protected static string $primaryKey = 'id';

    public function cities(): HasManyThrough
    {
        return $this->hasManyThrough(HMCity::class, HMUser::class, 'country_id', 'user_id');
    }
}

class HMUser extends Model
{
    protected static ?string $table = 'hmusers';
    protected static string $primaryKey = 'id';

    public function country(): \Mtphp\Database\Relations\BelongsTo
    {
        return $this->belongsTo(HMCountry::class, 'country_id');
    }
}

class HMCity extends Model
{
    protected static ?string $table = 'hmcities';
    protected static string $primaryKey = 'id';
}

class ModelFeaturesTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        // 作用域测试表
        Schema::create('mscope_posts', function (Blueprint $t): void {
            $t->id();
            $t->integer('published');
            $t->integer('views');
        });
        Schema::create('mgated_posts', function (Blueprint $t): void {
            $t->id();
            $t->integer('published');
        });

        // 多态表
        Schema::create('mposts', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
        });
        Schema::create('mvideos', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
        });
        Schema::create('mcomments', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->string('commentable_type');
            $t->integer('commentable_id');
        });
        Schema::create('mimages', function (Blueprint $t): void {
            $t->id();
            $t->string('url');
            $t->string('imageable_type');
            $t->integer('imageable_id');
        });
        Schema::create('mtags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('mtaggables', function (Blueprint $t): void {
            $t->id();
            $t->string('taggable_type');
            $t->integer('taggable_id');
            $t->integer('tag_id');
        });

        // 远程一对多表
        Schema::create('hmcountries', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('hmusers', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('country_id');
        });
        Schema::create('hmcities', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('user_id');
        });
    }

    protected function tearDown(): void
    {
        MScopePost::clearGlobalScopes();
        MScopeGated::clearGlobalScopes();
        MorphPost::clearMorphMap();
        MorphVideo::clearMorphMap();
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    // ---------------- 本地作用域 ----------------

    public function testLocalScopeStaticAndBuilder(): void
    {
        MScopePost::create(['published' => 1, 'views' => 100]);
        MScopePost::create(['published' => 0, 'views' => 200]);
        MScopePost::create(['published' => 1, 'views' => 50]);

        // 静态调用本地作用域：Post::published()
        $published = MScopePost::published()->get();
        Assert::assertSame(2, count($published));

        // 带参数的本地作用域
        $popular = MScopePost::popular(150)->get();
        Assert::assertSame(1, count($popular));
        Assert::assertSame(200, $popular[0]['views']);

        // 构造器上调用本地作用域（与 where 链式）
        $both = MScopePost::query()->published()->where('views', '>=', 50)->get();
        Assert::assertSame(2, count($both));
    }

    // ---------------- 全局作用域 ----------------

    public function testGlobalScopeAutoApplied(): void
    {
        MScopeGated::addGlobalScope('only_published', fn ($q) => $q->where('published', 1));
        MScopeGated::create(['published' => 1]);
        MScopeGated::create(['published' => 0]);
        MScopeGated::create(['published' => 1]);

        // get / find / count 都应自动套用全局作用域
        Assert::assertSame(2, count(MScopeGated::all()));
        Assert::assertSame(2, MScopeGated::count());
        Assert::assertSame(2, count(MScopeGated::where('published', 1)->get()));
    }

    public function testGlobalScopeObjectForm(): void
    {
        MScopeGated::addGlobalScope(new OnlyPublishedScope());
        MScopeGated::create(['published' => 1]);
        MScopeGated::create(['published' => 0]);

        Assert::assertSame(1, count(MScopeGated::all()));
    }

    public function testWithoutGlobalScope(): void
    {
        $scope = fn ($q) => $q->where('published', 1);
        MScopeGated::addGlobalScope($scope);
        MScopeGated::create(['published' => 1]);
        MScopeGated::create(['published' => 0]);

        // 默认只看到已发布
        Assert::assertSame(1, count(MScopeGated::all()));
        // 移除后再查，看到全部
        Assert::assertSame(2, count(MScopeGated::withoutGlobalScope($scope)->get()));
        // withoutGlobalScopes 移除全部
        Assert::assertSame(2, count(MScopeGated::withoutGlobalScopes()->get()));
    }

    // ---------------- 多态一对多 morphMany ----------------

    public function testMorphManyLazyAndEager(): void
    {
        $postId = MorphPost::create(['title' => 'p1']);
        $videoId = MorphVideo::create(['title' => 'v1']);
        // Post 的两条评论 + Video 的一条（类型不同，互不可见）
        MorphComment::create(['body' => 'pc1', 'commentable_type' => MorphPost::class, 'commentable_id' => $postId]);
        MorphComment::create(['body' => 'pc2', 'commentable_type' => MorphPost::class, 'commentable_id' => $postId]);
        MorphComment::create(['body' => 'vc1', 'commentable_type' => MorphVideo::class, 'commentable_id' => $videoId]);

        // 懒加载
        $post = MorphPost::find($postId);
        $cmts = (new MorphPost())->comments()->getResults($post);
        Assert::assertSame(2, count($cmts));

        // 预加载（按类型列隔离）
        $posts = MorphPost::with('comments')->get();
        Assert::assertSame(2, count($posts[0]['comments']));
        $videos = MorphVideo::with('comments')->get();
        Assert::assertSame(1, count($videos[0]['comments']));

        // withCount（多态计数）
        $posts = MorphPost::withCount('comments')->get();
        Assert::assertSame(2, $posts[0]['comments_count']);
    }

    // ---------------- 多态一对一 morphOne ----------------

    public function testMorphOneLazyAndEager(): void
    {
        $postId = MorphPost::create(['title' => 'p1']);
        MorphImage::create(['url' => 'u1', 'imageable_type' => MorphPost::class, 'imageable_id' => $postId]);

        $post = MorphPost::find($postId);
        $cover = (new MorphPost())->cover()->getResults($post);
        Assert::assertNotNull($cover);
        Assert::assertSame('u1', $cover['url']);

        $posts = MorphPost::with('cover')->get();
        Assert::assertNotNull($posts[0]['cover']);
        Assert::assertSame('u1', $posts[0]['cover']['url']);
    }

    // ---------------- 多态反向 morphTo ----------------

    public function testMorphToLazyAndEager(): void
    {
        $postId = MorphPost::create(['title' => 'p1']);
        $videoId = MorphVideo::create(['title' => 'v1']);
        $c1 = MorphComment::create(['body' => 'pc1', 'commentable_type' => MorphPost::class, 'commentable_id' => $postId]);
        $c2 = MorphComment::create(['body' => 'vc1', 'commentable_type' => MorphVideo::class, 'commentable_id' => $videoId]);

        // 懒加载：评论反查出真实父模型
        $comment = MorphComment::find($c1);
        $parent = (new MorphComment())->commentable()->getResults($comment);
        Assert::assertNotNull($parent);
        Assert::assertSame('p1', $parent['title']);
        Assert::assertTrue(is_array($parent));

        // 预加载：不同类型父模型分别取回
        $comments = MorphComment::with('commentable')->get();
        $byBody = [];
        foreach ($comments as $c) {
            $byBody[$c['body']] = $c['commentable']['title'] ?? null;
        }
        Assert::assertSame('p1', $byBody['pc1']);
        Assert::assertSame('v1', $byBody['vc1']);
    }

    // ---------------- 多态类型映射 morph map ----------------

    public function testMorphMap(): void
    {
        MorphPost::setMorphMap(['post' => MorphPost::class]);
        Assert::assertSame('post', MorphPost::getMorphClass());
        Assert::assertSame(MorphPost::class, MorphPost::getMorphAlias('post'));

        $postId = MorphPost::create(['title' => 'p1']);
        // 写入的 type 应为别名 'post'
        MorphComment::create(['body' => 'pc1', 'commentable_type' => MorphPost::getMorphClass(), 'commentable_id' => $postId]);

        // morphTo 能凭别名 'post' 反查出 MorphPost
        $comment = MorphComment::find(1);
        $parent = (new MorphComment())->commentable()->getResults($comment);
        Assert::assertSame('p1', $parent['title']);
    }

    // ---------------- 多态多对多 morphToMany ----------------

    public function testMorphToManyLazyAndEager(): void
    {
        $postId = MorphPost::create(['title' => 'p1']);
        $t1 = MorphTag::create(['name' => 'php']);
        $t2 = MorphTag::create(['name' => 'sql']);
        $t3 = MorphTag::create(['name' => 'css']);

        // 仅给 post 打 php + sql 两个标签（中间表 type 应为 Post 类名）
        DB::table('mtaggables')->insert([
            'taggable_type' => MorphPost::class, 'taggable_id' => $postId, 'tag_id' => $t1,
        ]);
        DB::table('mtaggables')->insert([
            'taggable_type' => MorphPost::class, 'taggable_id' => $postId, 'tag_id' => $t2,
        ]);

        $post = MorphPost::find($postId);
        $tags = (new MorphPost())->tags()->getResults($post);
        Assert::assertSame(2, count($tags));

        $posts = MorphPost::with('tags')->get();
        Assert::assertSame(2, count($posts[0]['tags']));
        $names = array_column($posts[0]['tags'], 'name');
        Assert::assertTrue(in_array('php', $names, true));
        Assert::assertTrue(in_array('sql', $names, true));
        Assert::assertFalse(in_array('css', $names, true));
    }

    // ---------------- 远程一对多 hasManyThrough ----------------

    public function testHasManyThroughLazyEagerAndWhereHas(): void
    {
        $cId = HMCountry::create(['name' => 'CN']);
        $u1 = HMUser::create(['name' => 'u1', 'country_id' => $cId]);
        $u2 = HMUser::create(['name' => 'u2', 'country_id' => $cId]);
        HMCity::create(['name' => 'bj', 'user_id' => $u1]);
        HMCity::create(['name' => 'sh', 'user_id' => $u1]);
        HMCity::create(['name' => 'gz', 'user_id' => $u2]);

        // 懒加载：CN 经 users 跨到 cities（共 3 座）
        $country = HMCountry::find($cId);
        $cities = (new HMCountry())->cities()->getResults($country);
        Assert::assertSame(3, count($cities));

        // 预加载
        $countries = HMCountry::with('cities')->get();
        Assert::assertSame(3, count($countries[0]['cities']));

        // whereHas：存在名为 'bj' 的城市的国
        $hasBj = HMCountry::whereHas('cities', fn ($q) => $q->where('name', 'bj'))->get();
        Assert::assertSame(1, count($hasBj));
        Assert::assertSame('CN', $hasBj[0]['name']);

        // 不存在名为 'nonexist' 的城市的国
        $none = HMCountry::doesntHave('cities', fn ($q) => $q->where('name', 'nonexist'))->get();
        Assert::assertSame(1, count($none));
    }
}
