<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;
use Mtphp\Database\DB;
use Mtphp\Database\Migrator;

/**
 * migrate:rollback —— 回滚最近一个批次的迁移
 */
class MigrateRollback extends Command
{
    public function configure(): void
    {
        $this->name        = 'migrate:rollback';
        $this->description = '回滚最近一个批次的迁移';
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

        $rolled = $migrator->rollback($paths);
        if ($rolled === []) {
            $this->info('没有可回滚的迁移。');
        } else {
            foreach ($rolled as $name) {
                $this->line('已回滚：' . $name);
            }
        }
        return 0;
    }
}
