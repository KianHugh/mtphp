<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace App\Seeders;

use Mtphp\Database\DB;
use Mtphp\Database\Seeder;

/**
 * 用户表填充（示例子填充）
 *
 * 先清后写，保证可重复 seed；表由 database/migrations 下的迁移创建。
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->whereNotNull('id')->delete();

        DB::table('users')->bulkInsert([
            ['name' => '管理员', 'email' => 'admin@example.com', 'role' => 'admin'],
            ['name' => '张三',   'email' => 'zhangsan@example.com', 'role' => 'user'],
            ['name' => '李四',   'email' => 'lisi@example.com', 'role' => 'user'],
        ]);
    }
}
