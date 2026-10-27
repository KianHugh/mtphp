<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;
use Mtphp\Database\DB;
use Mtphp\Database\Migrator;

/**
 * migrate —— 执行尚未运行过的迁移
 */
class Migrate extends Command
{
    public function configure(): void
    {
        $this->name        = 'migrate';
        $this->description = '执行数据库迁移（运行 migrations 目录下的新迁移）';
    }

    public function handle(): int
    {
        $paths = [$this->getRoot() . '/database/migrations'];
        if (!is_dir($paths[0])) {
            $this->error('未找到迁移目录：' . $paths[0]);
            return 1;
        }

        $conn   = DB::connection();
        $driver = method_exists($conn, 'getDriver') ? $conn->getDriver() : 'mysql';
        $migrator = new Migrator($conn, $driver);

        $ran = $migrator->run($paths);
        if ($ran === []) {
            $this->info('没有需要执行的迁移。');
        } else {
            foreach ($ran as $name) {
                $this->line('迁移完成：' . $name);
            }
        }
        return 0;
    }
}
