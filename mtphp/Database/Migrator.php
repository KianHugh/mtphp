<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database;

use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;

/**
 * 迁移执行器（零依赖，思想借鉴主流框架的 Migrator）
 *
 * 职责：
 *  - 维护一张 migrations 仓储表，记录已执行的迁移名与批次（batch）；
 *  - 发现 migrations 目录下的迁移文件（按文件名升序＝按时间顺序）；
 *  - run() 执行未跑过的前进迁移，rollback() 回滚“最近一批”。
 *
 * 迁移文件约定：返回 Migration 实例（通常采用匿名类 return new class extends Migration {…}）。
 */
class Migrator
{
    protected Connector $conn;

    protected string $driver;

    public function __construct(Connector $conn, string $driver)
    {
        $this->conn    = $conn;
        $this->driver  = $driver;
    }

    /**
     * 仓储表是否已存在
     */
    public function repositoryExists(): bool
    {
        return Schema::hasTable('migrations');
    }

    /**
     * 创建 migrations 仓储表
     */
    public function createRepository(): void
    {
        Schema::create('migrations', function (Blueprint $t): void {
            $t->id();
            $t->string('migration');
            $t->integer('batch');
        });
    }

    protected function ensureRepository(): void
    {
        if (!$this->repositoryExists()) {
            $this->createRepository();
        }
    }

    /**
     * 已执行过的迁移名（按执行顺序）
     *
     * @return list<string>
     */
    public function getRan(): array
    {
        $rows = DB::table('migrations')
            ->orderBy('batch', 'asc')
            ->orderBy('migration', 'asc')
            ->get();
        return array_map(static fn (array $r): string => (string) $r['migration'], $rows);
    }

    /**
     * 发现迁移文件：返回 名称 => 文件路径 的升序映射
     *
     * @param list<string> $paths
     * @return array<string,string>
     */
    public function getMigrationFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            foreach (glob($path . '/*.php') ?: [] as $f) {
                $files[basename($f, '.php')] = $f;
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * 解析一个迁移文件为 Migration 实例（文件需 return 一个 Migration 实例）
     */
    public function resolve(string $file): Migration
    {
        /** @var mixed $m */
        $m = require $file;
        if ($m instanceof Migration) {
            return $m;
        }
        throw new \RuntimeException('迁移文件必须 return 一个 Migration 实例：' . $file);
    }

    /**
     * 执行尚未运行过的迁移，返回本次执行了的迁移名
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public function run(array $paths): array
    {
        $this->ensureRepository();
        $files = $this->getMigrationFiles($paths);
        $ran   = $this->getRan();
        $todo  = array_diff(array_keys($files), $ran);
        sort($todo);
        if ($todo === []) {
            return [];
        }

        $batch     = $this->getNextBatchNumber();
        $migrated  = [];

        foreach ($todo as $name) {
            $m = $this->resolve($files[$name]);
            $m->up();
            DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
            $migrated[] = $name;
        }

        return $migrated;
    }

    /**
     * 回滚最近一个批次的迁移（按迁移名降序执行 down），返回被回滚的迁移名
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public function rollback(array $paths): array
    {
        $this->ensureRepository();
        $last = $this->getLastBatchNumber();
        if ($last === 0) {
            return [];
        }

        $files = $this->getMigrationFiles($paths);
        $rows  = DB::table('migrations')
            ->where('batch', '=', $last)
            ->orderBy('migration', 'desc')
            ->get();

        $rolled = [];
        foreach ($rows as $r) {
            $name = (string) $r['migration'];
            if (isset($files[$name])) {
                $this->resolve($files[$name])->down();
            }
            DB::table('migrations')->where('migration', '=', $name)->delete();
            $rolled[] = $name;
        }

        return $rolled;
    }

    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    public function getLastBatchNumber(): int
    {
        $row = DB::table('migrations')->orderBy('batch', 'desc')->first();
        return $row ? (int) $row['batch'] : 0;
    }
}
