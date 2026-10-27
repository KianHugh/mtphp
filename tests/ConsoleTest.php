<?php
declare(strict_types=1);

/**
 * Console 内核 + 命令测试（零依赖）
 * 运行：php8 tests/run.php ConsoleTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Console\Application;
use Mtphp\Console\Command;
use Mtphp\Console\Commands\KeyGenerate;

/**
 * 测试用命令：把输出记录到 $captured，便于断言（避免直接捕获 STDOUT 流）
 */
class GreetCommand extends Command
{
    /** @var list<string> */
    public array $captured = [];

    public function configure(): void
    {
        $this->name        = 'greet';
        $this->description = '测试问好';
    }

    public function line(string $message = ''): void
    {
        $this->captured[] = $message;
    }

    public function handle(): int
    {
        $this->info('Hello ' . $this->option('name', 'world'));
        $this->line('arg0=' . ($this->argument(0) ?? ''));
        return 0;
    }
}

class ConsoleTest extends TestCase
{
    private function makeApp(string $root = ''): Application
    {
        return new Application($root);
    }

    public function testDispatchAndOptionParsing(): void
    {
        $console = $this->makeApp();
        $greet   = $console->add(new GreetCommand());

        $code = $console->run(['mtphp', 'greet', '--name=Bob', 'extra']);
        Assert::assertSame(0, $code);
        Assert::assertTrue(in_array('[info] Hello Bob', $greet->captured, true), '应输出问候语');
        Assert::assertTrue(in_array('arg0=extra', $greet->captured, true), '应解析位置参数');
    }

    public function testUnknownCommandReturnsOne(): void
    {
        $console = $this->makeApp();
        $console->add(new GreetCommand());

        $code = $console->run(['mtphp', 'nope']);
        Assert::assertSame(1, $code);
    }

    public function testListRendersCommands(): void
    {
        $console = $this->makeApp();
        $console->add(new GreetCommand());

        $code = $console->run(['mtphp', 'list']);
        Assert::assertSame(0, $code);
    }

    public function testKeyGenerateWritesEnv(): void
    {
        $dir = sys_get_temp_dir() . '/mtphp_console_' . uniqid();
        mkdir($dir);

        $app      = $this->makeApp($dir);
        $keyGen   = new KeyGenerate();
        $keyGen->configure();
        $keyGen->setApplication($app);

        $code = $keyGen->handle();
        Assert::assertSame(0, $code);

        $env = $dir . '/.env';
        Assert::assertTrue(is_file($env));
        $content = (string) file_get_contents($env);
        Assert::assertTrue(str_starts_with($content, 'APP_KEY='));

        // 再次生成应更新而非重复
        $keyGen2 = new KeyGenerate();
        $keyGen2->configure();
        $keyGen2->setApplication($app);
        $keyGen2->handle();
        $content2 = (string) file_get_contents($env);
        Assert::assertSame(1, preg_match_all('/^APP_KEY=/m', $content2));

        @unlink($dir . '/.env');
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testBuiltinCommandsRegistered(): void
    {
        $console = $this->makeApp();
        $console->add(new \Mtphp\Console\Commands\KeyGenerate());
        $console->add(new \Mtphp\Console\Commands\RoutesList());
        $console->add(new \Mtphp\Console\Commands\Migrate());
        $console->add(new \Mtphp\Console\Commands\MigrateRollback());
        $console->add(new \Mtphp\Console\Commands\Serve());

        Assert::assertNotNull($console->command('key:generate'));
        Assert::assertNotNull($console->command('routes:list'));
        Assert::assertNotNull($console->command('migrate'));
        Assert::assertNotNull($console->command('migrate:rollback'));
        Assert::assertNotNull($console->command('serve'));
    }
}
