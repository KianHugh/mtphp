<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;
use Mtphp\Database\DB;
use Mtphp\Database\Seeder;

/**
 * seed —— 运行数据库填充（Seeder）
 *
 * 默认运行 App\Seeders\DatabaseSeeder；可用 --class 指定其它 Seeder。
 *
 *   php bin/mtphp seed
 *   php bin/mtphp seed --class=App\Seeders\UserSeeder
 */
class Seed extends Command
{
    public function configure(): void
    {
        $this->name        = 'seed';
        $this->description = '运行数据库填充（默认 App\\Seeders\\DatabaseSeeder）';
    }

    public function handle(): int
    {
        $class = (string) $this->option('class', 'App\\Seeders\\DatabaseSeeder');

        if (!class_exists($class)) {
            $this->error("Seeder 类不存在：{$class}");
            $this->line("请确认 {$class} 已定义，或先用 `php bin/mtphp make:seeder` 生成。");
            return 1;
        }

        $seeder = new $class();
        if (!$seeder instanceof Seeder) {
            $this->error("{$class} 必须继承 " . Seeder::class);
            return 1;
        }

        $seeder->run();
        $this->info("填充完成：{$class}");
        return 0;
    }
}
