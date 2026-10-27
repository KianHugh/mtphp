<?php
declare(strict_types=1);

use Mtphp\Console\Application;
use Mtphp\Console\Commands\MakeMigration;
use Mtphp\Console\Commands\Migrate;
use Mtphp\Database\Connector;
use Mtphp\Database\DB;
use Mtphp\Database\Migration;
use Mtphp\Database\Schema\Schema;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;

require_once __DIR__ . '/bootstrap.php';

/**
 * 脚手架 → 迁移 真实闭环：用临时工程根目录 + 内存 SQLite 解析器，
 * 经控制台 Application 依次执行 make:migration 生成迁移、再 migrate 执行，
 * 验证“生成文件 → 跑通建表”端到端（不污染仓库本身）。
 */
class MakeMigrateTest extends TestCase
{
    private string $root;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mtphp_mk_' . uniqid('', true);
        mkdir($this->root . '/database/migrations', 0777, true);

        $this->pdo = new \PDO('sqlite::memory:');
        $pdo       = $this->pdo;
        DB::setResolver(static fn (): Connector => new SqliteConnector($pdo));
    }

    protected function tearDown(): void
    {
        DB::flush();
        $this->rmrf($this->root);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $p = $dir . '/' . $it;
            if (is_dir($p)) {
                $this->rmrf($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }

    private function console(): Application
    {
        $app = new Application($this->root);
        $app->add(new MakeMigration());
        $app->add(new Migrate());
        return $app;
    }

    public function testMakeThenMigrateCreatesTable(): void
    {
        $console = $this->console();

        // 1) 生成迁移
        $code = $console->run(['mtphp', 'make:migration', 'CreateDemoTable', '--create=demo']);
        Assert::assertSame(0, $code, 'make:migration 应成功');

        $files = glob($this->root . '/database/migrations/*_create_demo_table.php');
        Assert::assertSame(1, count($files), '应生成且唯一一个 create_demo_table 迁移');

        // 2) 执行迁移
        $code = $console->run(['mtphp', 'migrate']);
        Assert::assertSame(0, $code, 'migrate 应成功');

        // 3) 校验 demo 表已建、迁移仓储已记录
        Assert::assertTrue(Schema::hasTable('demo'), 'demo 表应已创建');
        Assert::assertTrue(Schema::hasTable('migrations'), 'migrations 仓储表应已创建');

        // 二次执行应“无新迁移”（幂等，不重复建表）
        $code = $console->run(['mtphp', 'migrate']);
        Assert::assertSame(0, $code);
        Assert::assertTrue(Schema::hasTable('demo'), '重复 migrate 后 demo 仍应存在');
    }

    public function testGeneratedMigrationReturnsMigrationInstance(): void
    {
        $console = $this->console();
        $console->run(['mtphp', 'make:migration', 'CreatePostsTable', '--create=posts']);

        $files = glob($this->root . '/database/migrations/*_create_posts_table.php');
        Assert::assertSame(1, count($files), '应生成 create_posts_table 迁移');

        // 真实加载并校验返回的是 Migration 实例（与 Migrator::resolve 同款契约）
        /** @var mixed $m */
        $m = require $files[0];
        Assert::assertTrue($m instanceof Migration, '生成的迁移应 return 一个 Migration 实例');
    }
}
