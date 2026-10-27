<?php
declare(strict_types=1);

use Mtphp\Database\DB;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;
use App\Seeders\UserSeeder;

require_once __DIR__ . '/bootstrap.php';

/**
 * 示例闭环验证：迁移建表 → 填充 → 读取（基于内存 SQLite，无需 MySQL）
 */
class DemoClosureTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $conn = new SqliteConnector($pdo);
        DB::setResolver(static fn () => $conn);
    }

    public function testMigrationAndSeedAndRead(): void
    {
        // 1) 加载并执行迁移
        /** @var \Mtphp\Database\Migration $migration */
        $migration = require $this->root() . '/database/migrations/2026_10_11_000001_create_users_table.php';
        $migration->up();

        // 2) 运行示例填充
        (new UserSeeder())->run();

        // 3) 读取并断言
        $rows = DB::table('users')->orderBy('id', 'asc')->get();
        Assert::assertSame(3, count($rows));
        Assert::assertSame('admin@example.com', $rows[0]['email']);
        Assert::assertSame('admin', $rows[0]['role']);
        Assert::assertTrue(array_key_exists('created_at', $rows[0]), 'users 表应包含 created_at 列');
    }

    protected function root(): string
    {
        return dirname(__DIR__);
    }
}
