<?php
declare(strict_types=1);

/**
 * ORM 补齐能力测试：morphToMany withCount / loadCount（懒加载计数）/
 * firstOrFail·updateOrFail·deleteOrFail / fillable·guarded 批量赋值保护
 * 运行：php8 tests/run.php OrmExtraTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Relations\HasMany;
use Mtphp\Database\Relations\MorphMany;
use Mtphp\Database\Relations\MorphTo;
use Mtphp\Database\Relations\MorphToMany;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;

// ===================== 多态多对多 + 一对多（计数 / 懒加载计数） =====================

class XePost extends Model
{
    protected static ?string $table = 'xe_posts';
    protected static string $primaryKey = 'id';

    public function tags(): MorphToMany
    {
        return $this->morphToMany(XeTag::class, 'taggable', 'xe_taggables', 'tag_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(XeComment::class, 'commentable');
    }
}

class XeTag extends Model
{
    protected static ?string $table = 'xe_tags';
}

class XeComment extends Model
{
    protected static ?string $table = 'xe_comments';
    protected static string $primaryKey = 'id';

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

// ===================== 一对多（计数路径） =====================

class XeUser extends Model
{
    protected static ?string $table = 'xe_users';
    protected static string $primaryKey = 'id';

    public function phones(): HasMany
    {
        return $this->hasMany(XePhone::class, 'user_id');
    }
}

class XePhone extends Model
{
    protected static ?string $table = 'xe_phones';
}

// ===================== 批量赋值保护 =====================

class XeFill extends Model
{
    protected static ?string $table = 'xe_fill';
    protected static array $fillable = ['name'];
}

class XeGuarded extends Model
{
    protected static ?string $table = 'xe_guarded';
    protected static array $guarded = ['*'];
    protected static array $fillable = ['name'];
}

class XeOpen extends Model
{
    protected static ?string $table = 'xe_open';
}

class OrmExtraTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('xe_posts', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
        });
        Schema::create('xe_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('xe_comments', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->string('commentable_type');
            $t->integer('commentable_id');
        });
        Schema::create('xe_taggables', function (Blueprint $t): void {
            $t->integer('taggable_id');
            $t->string('taggable_type');
            $t->integer('tag_id');
        });
        Schema::create('xe_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('xe_phones', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('number');
        });
        Schema::create('xe_fill', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('secret')->nullable();
        });
        Schema::create('xe_guarded', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('secret')->nullable();
        });
        Schema::create('xe_open', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('secret');
        });

        // —— 多态多对多数据：p1 有 2 个标签，p2 有 1 个标签 ——
        $p1 = XePost::create(['title' => 'p1']);
        $p2 = XePost::create(['title' => 'p2']);
        $t1 = XeTag::create(['name' => 't1']);
        $t2 = XeTag::create(['name' => 't2']);
        $t3 = XeTag::create(['name' => 't3']);
        $mc = XePost::getMorphClass();
        DB::table('xe_taggables')->insert(
            ['taggable_id' => $p1, 'taggable_type' => $mc, 'tag_id' => $t1]
        );
        DB::table('xe_taggables')->insert(
            ['taggable_id' => $p1, 'taggable_type' => $mc, 'tag_id' => $t2]
        );
        DB::table('xe_taggables')->insert(
            ['taggable_id' => $p2, 'taggable_type' => $mc, 'tag_id' => $t3]
        );

        // —— 一对多数据：u1 有 2 部电话，u2 有 1 部 ——
        $u1 = XeUser::create(['name' => 'u1']);
        $u2 = XeUser::create(['name' => 'u2']);
        DB::table('xe_phones')->insert(['user_id' => $u1, 'number' => '111']);
        DB::table('xe_phones')->insert(['user_id' => $u1, 'number' => '222']);
        DB::table('xe_phones')->insert(['user_id' => $u2, 'number' => '333']);
    }

    public function testMorphToManyWithCount(): void
    {
        $posts = XePost::withCount('tags')->orderBy('id')->get();
        Assert::assertSame(2, count($posts));
        Assert::assertSame(2, (int) $posts[0]['tags_count']);
        Assert::assertSame(1, (int) $posts[1]['tags_count']);
    }

    public function testMorphManyWithCount(): void
    {
        $p1 = XePost::create(['title' => 'withC']);
        $p2 = XePost::create(['title' => 'withC2']);
        $mc = XePost::getMorphClass();
        DB::table('xe_comments')->insert(
            ['body' => 'c1', 'commentable_type' => $mc, 'commentable_id' => $p1]
        );
        DB::table('xe_comments')->insert(
            ['body' => 'c2', 'commentable_type' => $mc, 'commentable_id' => $p1]
        );
        DB::table('xe_comments')->insert(
            ['body' => 'c3', 'commentable_type' => $mc, 'commentable_id' => $p2]
        );

        $posts = XePost::withCount('comments')->orderBy('id')->get();
        $map   = [];
        foreach ($posts as $p) {
            $map[$p['id']] = (int) $p['comments_count'];
        }
        Assert::assertSame(2, $map[$p1] ?? -1);
        Assert::assertSame(1, $map[$p2] ?? -1);
    }

    public function testHasManyLoadCount(): void
    {
        $users = XeUser::all();
        XeUser::loadCount($users, 'phones');
        $byName = [];
        foreach ($users as $u) {
            $byName[$u['name']] = (int) $u['phones_count'];
        }
        Assert::assertSame(2, $byName['u1']);
        Assert::assertSame(1, $byName['u2']);
    }

    public function testMorphToManyLoadCount(): void
    {
        $posts = XePost::all();
        XePost::loadCount($posts, 'tags');
        $byId = [];
        foreach ($posts as $p) {
            $byId[$p['id']] = (int) $p['tags_count'];
        }
        Assert::assertSame(2, $byId[$posts[0]['id']]);
        Assert::assertSame(1, $byId[$posts[1]['id']]);
    }

    public function testFillable(): void
    {
        Assert::assertTrue(XeFill::isFillable('name'));
        Assert::assertFalse(XeFill::isFillable('secret'));

        $id = XeFill::create(['name' => 'a', 'secret' => 'x']);
        $row = XeFill::find($id);
        Assert::assertSame('a', $row['name']);
        Assert::assertFalse(isset($row['secret']), 'secret 不应被写入');

        // unguard 后放开
        XeFill::unguard();
        $id2 = XeFill::create(['name' => 'b', 'secret' => 'y']);
        $row2 = XeFill::find($id2);
        Assert::assertSame('y', $row2['secret']);
        XeFill::reguard();
    }

    public function testGuarded(): void
    {
        Assert::assertTrue(XeGuarded::isFillable('name'));
        Assert::assertFalse(XeGuarded::isFillable('secret'));

        $id = XeGuarded::create(['name' => 'a', 'secret' => 'x']);
        $row = XeGuarded::find($id);
        Assert::assertSame('a', $row['name']);
        Assert::assertFalse(isset($row['secret']), 'secret 不应被写入');
    }

    public function testPermissiveByDefault(): void
    {
        // 未声明 fillable / guarded 时，默认允许全部字段
        Assert::assertTrue(XeOpen::isFillable('name'));
        Assert::assertTrue(XeOpen::isFillable('secret'));

        $id = XeOpen::create(['name' => 'a', 'secret' => 'x']);
        $row = XeOpen::find($id);
        Assert::assertSame('x', $row['secret']);
    }

    public function testFirstOrFail(): void
    {
        $row = XePost::firstOrFail(['title' => 'p1']);
        Assert::assertSame('p1', $row['title']);

        Assert::assertException(
            static fn (): \Mtphp\Database\Model => XePost::firstOrFail(['title' => 'nope']),
            \Mtphp\Exception\ModelNotFoundException::class
        );
    }

    public function testUpdateOrFail(): void
    {
        $affected = XePost::updateOrFail(1, ['title' => 'updated']);
        Assert::assertSame(1, $affected);
        Assert::assertSame('updated', XePost::find(1)['title']);

        Assert::assertException(
            static fn (): int => XePost::updateOrFail(999, ['title' => 'x']),
            \Mtphp\Exception\ModelNotFoundException::class
        );
    }

    public function testDeleteOrFail(): void
    {
        $affected = XePost::deleteOrFail(1);
        Assert::assertSame(1, $affected);
        Assert::assertNull(XePost::find(1));

        Assert::assertException(
            static fn (): int => XePost::deleteOrFail(999),
            \Mtphp\Exception\ModelNotFoundException::class
        );
    }
}
