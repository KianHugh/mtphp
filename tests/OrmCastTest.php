<?php
declare(strict_types=1);

/**
 * ORM 类型转换（casts / 访问器）与关联计数（withCount）测试（内存 SQLite，零外部依赖）
 * 运行：php8 tests/run.php OrmCastTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

// ---- 带 casts 的测试模型 ----

class CastUser extends Model
{
    protected static ?string $table = 'cast_users';

    protected static string $primaryKey = 'id';

    protected static array $casts = [
        'is_admin'    => 'bool',
        'score'       => 'int',
        'meta'        => 'json',
        'height'      => 'float',
        'created_at'  => 'datetime',
    ];

    public function orders(): \Mtphp\Database\Relations\HasMany
    {
        return $this->hasMany(CastOrder::class, 'user_id');
    }

    // 访问器示例：full_name = first_name . last_name
    protected static function hasMutators(): bool
    {
        return true;
    }

    protected static function getFullNameAttribute(mixed $value): string
    {
        // 访问器入参为当前属性值（此处 meta 已是数组），演示用法
        return (string) ($value ?? '');
    }
}

class CastOrder extends Model
{
    protected static ?string $table = 'cast_orders';

    protected static string $primaryKey = 'id';
}

class OrmCastTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('cast_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->integer('is_admin')->default(0);
            $t->integer('score')->default(0);
            $t->text('meta');
            $t->float('height')->default(0);
            $t->timestamp('created_at');
        });
        Schema::create('cast_orders', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('title');
        });

        // 注意：meta 以 JSON 字符串入库；is_admin 用 1/0
        CastUser::create([
            'name'       => 'alice',
            'is_admin'   => true,
            'score'      => 88,
            'meta'       => ['role' => 'admin', 'tags' => ['a', 'b']],
            'height'     => 1.68,
            'created_at' => '2024-01-02 03:04:05',
        ]);
        CastUser::create([
            'name'       => 'bob',
            'is_admin'   => false,
            'score'      => 12,
            'meta'       => ['role' => 'user'],
            'height'     => 1.75,
            'created_at' => '2024-05-06 07:08:09',
        ]);
        CastOrder::create(['user_id' => 1, 'title' => 'o1']);
        CastOrder::create(['user_id' => 1, 'title' => 'o2']);
        CastOrder::create(['user_id' => 2, 'title' => 'o3']);
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testCastsAppliedOnRead(): void
    {
        $u = CastUser::find(1);
        Assert::assertTrue(is_bool($u['is_admin']), 'is_admin 应为 bool');
        Assert::assertTrue($u['is_admin'] === true);
        Assert::assertTrue(is_int($u['score']), 'score 应为 int');
        Assert::assertSame(88, $u['score']);
        Assert::assertTrue(is_array($u['meta']), 'meta 应解码为数组');
        Assert::assertSame('admin', $u['meta']['role']);
        Assert::assertTrue(is_float($u['height']));
        Assert::assertSame(1.68, $u['height']);
        // datetime：字符串原样保留（DB 已存字符串）
        Assert::assertSame('2024-01-02 03:04:05', $u['created_at']);
    }

    public function testCastForStorageEncodesOnWrite(): void
    {
        $id = CastUser::create([
            'name'       => 'carol',
            'is_admin'   => true,
            'score'      => 99,
            'meta'       => ['x' => 1],
            'height'     => 1.6,
            'created_at' => '2024-09-10 11:12:13',
        ]);
        // 直接在库里读，确认写入形态：bool→1、json→字符串
        $row = $this->pdo->query("SELECT is_admin, meta FROM cast_users WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        Assert::assertSame(1, (int) $row['is_admin']);
        $dec = json_decode($row['meta'], true);
        Assert::assertTrue(is_array($dec));
        Assert::assertSame(1, $dec['x']);
        // 读出时再次被 cast 成数组
        $u = CastUser::find($id);
        Assert::assertTrue(is_array($u['meta']));
    }

    public function testWithCountEager(): void
    {
        $users = CastUser::withCount('orders')->get();
        Assert::assertSame(2, count($users));
        $byName = [];
        foreach ($users as $u) {
            $byName[$u['name']] = $u['orders_count'];
        }
        Assert::assertSame(2, $byName['alice']);
        Assert::assertSame(1, $byName['bob']);
    }

    public function testWithCountCombinedWithWith(): void
    {
        $users = CastUser::with('orders')->withCount('orders')->get();
        foreach ($users as $u) {
            Assert::assertSame(count($u['orders']), $u['orders_count']);
        }
    }
}
