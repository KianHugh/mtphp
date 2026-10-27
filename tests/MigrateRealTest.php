<?php
declare(strict_types=1);

use Mtphp\App;
use Mtphp\Database\DB;
use Mtphp\Database\Migrator;
use Mtphp\Database\Schema\Schema;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;
use App\Seeders\UserSeeder;
use App\Models\User;

require_once __DIR__ . '/bootstrap.php';

/**
 * 示例闭环的“真实 migrate”验证：基于内存 SQLite 跑真实的 Migrator::run，
 * 覆盖 迁移建表 → 仓储记录 → 填充 → 模型读取 → 回滚 全链路（无需 MySQL）。
 */
class MigrateRealTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        DB::setResolver(static fn () => new SqliteConnector($pdo));
        new App(dirname(__DIR__)); // 引导 App，提供 config / 路由等上下文
    }

    protected function tearDown(): void
    {
        DB::flush();
    }

    private function migrator(): Migrator
    {
        $conn = DB::connection();
        return new Migrator($conn, $conn->getDriver());
    }

    private function migrationPath(): string
    {
        return dirname(__DIR__) . '/database/migrations';
    }

    public function testMigrateBuildsTablesAndRecordsMigration(): void
    {
        $ran = $this->migrator()->run([$this->migrationPath()]);

        Assert::assertTrue(
            in_array('2026_10_11_000001_create_users_table', $ran, true),
            '应执行示例 users 迁移'
        );
        Assert::assertTrue(Schema::hasTable('users'), 'users 表应已创建');
        Assert::assertTrue(Schema::hasTable('migrations'), 'migrations 仓储表应已创建');
    }

    public function testSeedAndModelReadAfterMigrate(): void
    {
        $this->migrator()->run([$this->migrationPath()]);
        (new UserSeeder())->run();

        // 通过 DB 门面读取
        $rows = DB::table('users')->orderBy('id', 'asc')->get();
        Assert::assertSame(3, count($rows));
        Assert::assertSame('admin@example.com', $rows[0]['email']);

        // 通过模型读取（闭环：迁移 → 填充 → ORM 读，表名已与迁移/填充对齐）
        $all = User::all();
        Assert::assertSame(3, count($all));

        $first = User::find(1);
        Assert::assertNotNull($first, 'User::find(1) 应返回首条');
        Assert::assertSame('admin@example.com', $first['email']);
        Assert::assertSame('admin', $first['role']);
    }

    public function testRollbackDropsUsersTable(): void
    {
        $this->migrator()->run([$this->migrationPath()]);
        $rolled = $this->migrator()->rollback([$this->migrationPath()]);

        Assert::assertTrue(
            in_array('2026_10_11_000001_create_users_table', $rolled, true),
            '回滚应撤销示例迁移'
        );
        Assert::assertFalse(Schema::hasTable('users'), '回滚后 users 表应被删除');
    }
}
