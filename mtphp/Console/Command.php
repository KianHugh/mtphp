<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console;

/**
 * 控制台命令基类（零依赖，思想借鉴主流框架的 Console 命令）
 *
 * 子类在 configure() 里声明命令名与描述，在 handle() 里写具体逻辑；
 * 通过 option() / argument() 读取入参，通过 line()/info()/warn()/error() 输出。
 *
 * 用法：
 *   class Greet extends Command {
 *       public function configure(): void {
 *           $this->name = 'greet';
 *           $this->description = '问好';
 *       }
 *       public function handle(): int {
 *           $this->info('Hello ' . $this->option('name', 'world'));
 *           return 0;
 *       }
 *   }
 */
abstract class Command
{
    protected string $name = '';

    protected string $description = '';

    protected Application $application;

    /** @var array<string,mixed> 解析后的选项（--key / --key=value） */
    protected array $options = [];

    /** @var list<string> 解析后的位置参数 */
    protected array $arguments = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setApplication(Application $application): void
    {
        $this->application = $application;
    }

    /**
     * 子类在此声明命令名与描述（由 Application::add 调用）
     */
    public function configure(): void
    {
    }

    /**
     * 解析命令行参数（已剔除程序名与命令名）
     *
     * @param list<string> $args
     */
    public function parse(array $args): void
    {
        foreach ($args as $a) {
            if (str_starts_with($a, '--')) {
                $kv = substr($a, 2);
                if (str_contains($kv, '=')) {
                    [$k, $v] = explode('=', $kv, 2);
                    $this->options[$k] = $v;
                } else {
                    $this->options[$kv] = true;
                }
            } else {
                $this->arguments[] = $a;
            }
        }
    }

    /**
     * @return mixed
     */
    public function option(string $key, mixed $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * @param int|string $key
     * @return mixed
     */
    public function argument(int|string $key, mixed $default = null)
    {
        if (is_int($key)) {
            return $this->arguments[$key] ?? $default;
        }
        // 位置参数也可按“第 N 个”命名读取（这里仅支持数字下标）
        return $default;
    }

    /**
     * 执行命令；返回进程退出码（0 成功）
     */
    abstract public function handle(): int;

    public function line(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public function info(string $message): void
    {
        $this->line('[info] ' . $message);
    }

    public function warn(string $message): void
    {
        $this->line('[warn] ' . $message);
    }

    public function error(string $message): void
    {
        fwrite(STDERR, '[error] ' . $message . PHP_EOL);
    }

    /**
     * 项目根目录（由 Application 注入）
     */
    public function getRoot(): string
    {
        return $this->application->getRoot();
    }
}
