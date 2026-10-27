<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;

/**
 * make:model —— 生成模型骨架（写入 app/Models）
 *
 *   php bin/mtphp make:model User
 *   php bin/mtphp make:model Api/Order
 *   php bin/mtphp make:model User --migration      # 同时生成建表迁移
 *   php bin/mtphp make:model User -m               # 同上
 *
 * 生成类继承 Mtphp\Database\Model；表名默认按类名 snake_case 推导，可显式声明 $table 覆盖。
 * 若模型需分库，可补充：protected static ?string \$shardGroup = 'orders';
 */
class MakeModel extends Command
{
    public function configure(): void
    {
        $this->name        = 'make:model';
        $this->description = '生成模型骨架（写入 app/Models），-m/--migration 联动生成建表迁移';
    }

    public function handle(): int
    {
        $name = (string) $this->argument(0);
        if ($name === '') {
            $this->error('用法：php bin/mtphp make:model <ModelName> [--migration]');
            return 1;
        }

        $rel   = ltrim(str_replace('\\', '/', $name), '/');
        $parts = explode('/', $rel);
        $base  = array_pop($parts);
        $sub   = $parts !== [] ? implode('/', $parts) . '/' : '';
        $ns    = 'App\\Models' . ($parts !== [] ? '\\' . str_replace('/', '\\', implode('\\', $parts)) : '');
        // 表名：类名 snake_case（不含 Model 后缀）
        $table = strtolower(preg_replace('/(?<!^)(?=[A-Z])/', '_', preg_replace('/Model$/', '', $base)));

        $dir  = $this->getRoot() . '/app/Models/' . $sub;
        $file = $dir . $base . '.php';
        if (is_file($file)) {
            $this->warn("已存在，跳过：{$file}");
            return 0;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace {$ns};

use Mtphp\Database\Model;

/**
 * {$base}（由 make:model 生成）
 *
 * 约定：表名默认按类名 snake_case 推导（可显式声明 \$table 覆盖）；
 * 若需分库，声明 protected static ?string \$shardGroup = '组名'; 后用 Order::onShard(\$key)。
 */
class {$base} extends Model
{
    protected static ?string \$table = '{$table}';

    protected static string \$primaryKey = 'id';
}
PHP;
        file_put_contents($file, $stub);
        $this->info("已生成：{$file}");

        // 联动生成建表迁移（复用 make:migration）
        if ((bool) $this->option('migration', false) || (bool) $this->option('m', false)) {
            $this->generateMigration($table);
        }
        return 0;
    }

    /**
     * 复用 MakeMigration 生成「Create{StudlyTable}Table --create=表名」迁移
     */
    protected function generateMigration(string $table): void
    {
        $studly   = str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));
        $mName    = 'Create' . $studly . 'Table';
        $migration = new MakeMigration();
        $migration->setApplication($this->application);
        $migration->parse(['Create' . $studly . 'Table', '--create=' . $table]);
        $migration->handle();
    }
}
