<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace App\Seeders;

use Mtphp\Database\Seeder;

/**
 * 主填充（示例）
 *
 * 在这里 call() 各个子填充，或直接在 run() 里写数据。
 * 演示闭环：php bin/mtphp migrate → php bin/mtphp seed → 访问 /demo/users。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 组织初始数据：UserSeeder 内部先清后写，保证可重复 seed
        $this->call(UserSeeder::class);
    }
}
