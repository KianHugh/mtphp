<?php
declare(strict_types=1);

/**
 * 数据填充 Seeder 与 seed 命令测试
 * 运行：php tests/run.php SeedTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Seeder;
use Mtphp\Console\Application;
use Mtphp\Console\Commands\Seed;
use Tests\SqliteConnector;
use function Tests\sqliteResolver;

// 全局演示 Seeder（测试内定义，避免污染真实 app/Seeders）
class DemoSeeder extends Seeder
{
    public static bool $ran = false;

    public function run(): void
    {
        $conn = DB::connection();
        $conn->statement('CREATE TABLE IF NOT EXISTS seed_demo (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $conn->insert('INSERT INTO seed_demo (name) VALUES (?)', ['alpha']);
        self::$ran = true;
        // 演示 call() 层级调用
        $this->call(ChildSeeder::class);
    }
}

class ChildSeeder extends Seeder
{
    public static bool $ran = false;

    public function run(): void
    {
        self::$ran = true;
    }
}

class SeedTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        DB::setResolver(sqliteResolver($pdo));
        DemoSeeder::$ran = false;
        ChildSeeder::$ran = false;
    }

    public function testSeederRunAndCallChaining(): void
    {
        $s = new DemoSeeder();
        $s->run();

        Assert::assertTrue(DemoSeeder::$ran);
        Assert::assertTrue(ChildSeeder::$ran, 'call() 应触发子 Seeder');

        $count = DB::table('seed_demo')->count();
        Assert::assertSame(1, (int) $count);
        $row = DB::table('seed_demo')->first();
        Assert::assertSame('alpha', $row['name']);
    }

    public function testSeedCommandRunsSeeder(): void
    {
        $app  = new Application(dirname(__DIR__));
        $seed = new Seed();
        $app->add($seed);

        $seed->parse(['--class=DemoSeeder']);
        $code = $seed->handle();

        Assert::assertSame(0, $code);
        Assert::assertTrue(DemoSeeder::$ran);
        Assert::assertTrue(ChildSeeder::$ran);
    }

    public function testSeedCommandErrorsOnMissingClass(): void
    {
        $app  = new Application(dirname(__DIR__));
        $seed = new Seed();
        $app->add($seed);

        $seed->parse(['--class=NoSuchSeeder']);
        $code = $seed->handle();

        Assert::assertSame(1, $code);
    }
}
