<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database;

use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;

/**
 * 迁移基类（零依赖）
 *
 * 用户迁移文件中的类继承本类，实现 up() / down() 两个方法，
 * 分别描述“前进”与“回滚”的表结构变更。方法体内通过 Schema 构造器描述结构。
 *
 * 用法（database/migrations/2024_01_01_000000_create_users_table.php）：
 *   use Mtphp\Database\Migration;
 *   return new class extends Migration {
 *       public function up(): void {
 *           Schema::create('users', function (Blueprint $t) {
 *               $t->id();
 *               $t->string('name');
 *               $t->timestamps();
 *           });
 *       }
 *       public function down(): void {
 *           Schema::dropIfExists('users');
 *       }
 *   };
 */
abstract class Migration
{
    /**
     * 应用迁移（建表 / 加列 / 加索引等）
     */
    abstract public function up(): void;

    /**
     * 回滚迁移（与 up 相反）
     */
    abstract public function down(): void;
}
