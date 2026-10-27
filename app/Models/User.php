<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace App\Models;

use Mtphp\Database\Model;

/**
 * 示例模型：演示如何使用 mtphp 的轻量模型（ORM-lite）
 *
 * 使用 / 配置极其简单：
 *   1. 继承 Mtphp\Database\Model；
 *   2. 声明表名（可省略，默认按类名推导为 user）；
 *   3. 即可调用 find / where / create / update / delete 等静态方法。
 *
 * 真实项目里把表名和字段换成你自己的即可；下面这些方法都是继承来的，无需重写。
 */
class User extends Model
{
    // 对应数据表名（不含前缀）；与 database/migrations 下的示例迁移、UserSeeder 保持一致
    protected static ?string $table = 'users';

    // 主键列名（默认就是 id，这里写出来仅作示意）
    protected static string $primaryKey = 'id';
}
