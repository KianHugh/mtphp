<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console;

/**
 * 控制台应用内核（零依赖，思想借鉴主流框架的 Artisan 内核）
 *
 * 负责：登记命令、解析 argv（命令名 + 选项/参数）、分发到对应命令、输出帮助。
 * 框架应用（Mtphp\App）与控制台解耦——控制台只管“命令分发”，需要框架上下文时
 * 通过 setApp() 注入；命令内部再用 app() 助手取配置 / 路由 / 数据库。
 */
class Application
{
    protected string $name = 'mtphp';

    protected string $version = '1.0.0';

    /** @var array<string,Command> */
    protected array $commands = [];

    protected string $root = '';

    protected ?\Mtphp\App $app = null;

    public function __construct(string $root = '')
    {
        $this->root = $root;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function line(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite(STDERR, '[error] ' . $message . PHP_EOL);
    }

    /**
     * 登记一条命令（自动调用其 configure 绑定自身）
     */
    public function add(Command $command): Command
    {
        $command->configure();
        $command->setApplication($this);
        $this->commands[$command->getName()] = $command;
        return $command;
    }

    public function command(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * @return array<string,Command>
     */
    public function all(): array
    {
        return $this->commands;
    }

    /**
     * 注入已引导的框架应用实例（供命令内部 app() 取用）
     */
    public function setApp(?\Mtphp\App $app): void
    {
        $this->app = $app;
    }

    public function getApp(): ?\Mtphp\App
    {
        return $this->app;
    }

    /**
     * 入口：传入完整 $argv（含程序名）
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        array_shift($argv); // 去掉程序名
        $name = array_shift($argv) ?? '';

        if ($name === '' || $name === 'list' || $name === '--help' || $name === '-h') {
            return $this->renderList();
        }
        if ($name === 'help') {
            $target = $argv[0] ?? '';
            return $this->renderHelp($target);
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $this->error("未知命令：{$name}");
            $this->line("用 `{$this->name} list` 查看可用命令。");
            return 1;
        }

        $command->parse(array_values($argv));
        return $command->handle();
    }

    protected function renderList(): int
    {
        $this->line($this->name . ' ' . $this->version);
        $this->line('');
        $this->line('可用命令：');
        foreach ($this->commands as $c) {
            $this->line('  ' . $c->getName() . str_repeat(' ', max(1, 22 - mb_strlen($c->getName()))) . $c->getDescription());
        }
        return 0;
    }

    protected function renderHelp(string $name): int
    {
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $this->error("未知命令：{$name}");
            return 1;
        }
        $this->line($command->getName());
        $this->line('  ' . $command->getDescription());
        return 0;
    }
}
