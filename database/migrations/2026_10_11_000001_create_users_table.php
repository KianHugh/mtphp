<?php
declare(strict_types=1);

use Mtphp\Database\Migration;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;

/**
 * 创建 users 表示例迁移（由 `php bin/mtphp migrate` 执行）
 *
 * 这是「示例迁移 + 填充 + 路由」可跑闭环的起点：
 *   migrate → 建表； seed → 写入演示数据； 访问 /demo/users → 读取并展示。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 60);
            $t->string('email', 120)->unique();
            $t->string('role', 20)->default('user');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
