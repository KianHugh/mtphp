<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;

/**
 * make:migration —— 生成数据库迁移文件（写入 database/migrations）
 *
 *   php bin/mtphp make:migration CreateUsersTable
 *   php bin/mtphp make:migration CreateUsersTable --create=users
 *   php bin/mtphp make:migration AddAgeToUsersTable --table=users
 *
 * 文件名按 `Ymd_His_名称` 生成；名称含 create_..._table 时自动推断表名。
 */
class MakeMigration extends Command
{
    public function configure(): void
    {
        $this->name        = 'make:migration';
        $this->description = '生成数据库迁移文件（写入 database/migrations）';
    }

    public function handle(): int
    {
        $name = (string) $this->argument(0);
        if ($name === '') {
            $this->error('用法：php bin/mtphp make:migration <Name> [--create=table] [--table=table]');
            return 1;
        }

        // CamelCase / 任意分隔 → snake_case 文件名（与框架迁移约定一致）
        $snake = strtolower(preg_replace('/(?<!^)(?=[A-Z])/', '_', preg_replace('/[^A-Za-z0-9]/', '', $name)));

        $table = (string) $this->option('create', '');
        $table = $table !== '' ? $table : (string) $this->option('table', '');
        if ($table === '' && preg_match('/create_(.+)_table$/i', $snake, $m)) {
            $table = $m[1];
        }

        $dir  = $this->getRoot() . '/database/migrations';
        $file = $dir . '/' . date('Y_m_d_His') . '_' . $snake . '.php';
        if (is_file($file)) {
            $this->warn("已存在，跳过：{$file}");
            return 0;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($table !== '') {
            $upBody = "Schema::create('{$table}', function (Blueprint \$t): void {\n"
                . "            \$t->id();\n"
                . "            // \$t->string('name');\n"
                . "            \$t->timestamps();\n"
                . "        });";
            $downBody = "Schema::dropIfExists('{$table}');";
        } else {
            $upBody = "/* Schema::create('table', function (Blueprint \$t): void {\n"
                . "            \$t->id();\n"
                . "            \$t->timestamps();\n"
                . "        }); */";
            $downBody = "/* 回滚逻辑：自行补全 */";
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

use Mtphp\Database\Migration;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        {$upBody}
    }

    public function down(): void
    {
        {$downBody}
    }
};
PHP;
        file_put_contents($file, $stub);
        $this->info("已生成：{$file}");
        return 0;
    }
}
