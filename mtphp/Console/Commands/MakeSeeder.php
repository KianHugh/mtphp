<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;

/**
 * make:seeder —— 生成 Seeder 类骨架
 *
 *   php bin/mtphp make:seeder UserSeeder
 *   php bin/mtphp make:seeder Users/AdminSeeder   # 生成到 app/Seeders/Users/AdminSeeder.php
 */
class MakeSeeder extends Command
{
    public function configure(): void
    {
        $this->name        = 'make:seeder';
        $this->description = '生成 Seeder 类骨架（写入 app/Seeders）';
    }

    public function handle(): int
    {
        $name = (string) $this->argument(0);
        if ($name === '') {
            $this->error('用法：php bin/mtphp make:seeder <SeederName>');
            return 1;
        }

        // 允许带子目录分隔；统一以 Seeder 结尾
        $rel   = ltrim(str_replace('\\', '/', $name), '/');
        $parts = explode('/', $rel);
        $base  = array_pop($parts);
        if (!str_ends_with($base, 'Seeder')) {
            $base .= 'Seeder';
        }
        $class = $base;
        $sub   = $parts !== [] ? implode('/', $parts) . '/' : '';
        $ns    = 'App\\Seeders' . ($parts !== [] ? '\\' . str_replace('/', '\\', implode('\\', $parts)) : '');

        $dir  = $this->getRoot() . '/app/Seeders/' . $sub;
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

use Mtphp\Database\DB;
use Mtphp\Database\Seeder;

/**
 * {$class}（由 make:seeder 生成）
 */
class {$class} extends Seeder
{
    public function run(): void
    {
        // 示例：DB::table('table')->insert([...]);
    }
}
PHP;
        file_put_contents($file, $stub);
        $this->info("已生成：{$file}");
        return 0;
    }
}
