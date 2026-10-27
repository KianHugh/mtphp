<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Queue;

use Mtphp\Database\Connector;

/**
 * 数据库驱动队列（零依赖，思想借鉴主流框架的 DB Queue，独立实现）
 *
 * 相对文件驱动的优势：
 *  - 原子认领（事务 + SELECT ... FOR UPDATE / 原子 UPDATE 子查询），并发安全；
 *  - 处理中崩溃不会永久丢失任务（reserved_at 标记，可重试 / 回收）；
 *  - 崩溃卡住的 reserved 任务会被超时回收（retryAfter），重新认领重试，不会永久滞留；
 *  - 内置重试（attempts）与失败表（failed_jobs），满足“严格可靠投递”场景。
 *
 * 表结构由本类自动建（ensureTables，CREATE TABLE IF NOT EXISTS，跨 mysql/sqlite 方言），
 * 也无需额外迁移；如需显式迁移，可调用 tablesSql() 拿到 DDL。
 *
 * 任务载荷结构（与文件驱动一致）：
 *   {
 *     "id": "....", "queue": "default", "payload": <mixed>,
 *     "attempts": 0, "reserved_at": null, "available_at": 1700000000, "created_at": 1700000000
 *   }
 */
class DatabaseQueue implements QueueInterface
{
    use DispatchTrait;

    protected Connector $connection;

    protected string $table;

    protected string $failedTable;

    protected int $maxAttempts;

    protected int $retryDelay;

    /**
     * 任务被认领后，超过该秒数仍处 reserved（即处理进程崩溃 / 卡死）即视为超时，
     * 下一次 pop() 会回收它并重新认领重试。必须大于单任务最大正常处理耗时。
     */
    protected int $retryAfter;

    protected bool $tablesReady = false;

    public function __construct(
        Connector $connection,
        string $table = 'jobs',
        string $failedTable = 'failed_jobs',
        int $maxAttempts = 3,
        int $retryDelay = 5,
        int $retryAfter = 90
    ) {
        $this->connection  = $connection;
        $this->table       = $table;
        $this->failedTable = $failedTable;
        $this->maxAttempts = $maxAttempts;
        $this->retryDelay  = $retryDelay;
        $this->retryAfter  = $retryAfter;
    }

    /**
     * 跨驱动建表 DDL（mysql / sqlite），使用实际表名
     *
     * @return array{0:string,1:string} [jobs 表 SQL, failed_jobs 表 SQL]
     */
    public static function tablesSql(string $driver, string $table, string $failedTable): array
    {
        if ($driver === 'sqlite') {
            $jobs = <<<SQL
CREATE TABLE IF NOT EXISTS "{$table}" (
  "id" VARCHAR(64) NOT NULL PRIMARY KEY,
  "queue" VARCHAR(64) NOT NULL DEFAULT 'default',
  "payload" TEXT NOT NULL,
  "attempts" INTEGER NOT NULL DEFAULT 0,
  "reserved_at" INTEGER NULL DEFAULT NULL,
  "available_at" INTEGER NOT NULL,
  "created_at" INTEGER NOT NULL
)
SQL;
            $failed = <<<SQL
CREATE TABLE IF NOT EXISTS "{$failedTable}" (
  "id" VARCHAR(64) NOT NULL PRIMARY KEY,
  "queue" VARCHAR(64) NOT NULL DEFAULT 'default',
  "payload" TEXT NOT NULL,
  "error" TEXT NULL,
  "failed_at" INTEGER NOT NULL
)
SQL;
        } else {
            $jobs = <<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` VARCHAR(64) NOT NULL,
  `queue` VARCHAR(64) NOT NULL DEFAULT 'default',
  `payload` LONGTEXT NOT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `reserved_at` INT NULL DEFAULT NULL,
  `available_at` INT NOT NULL,
  `created_at` INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_idx` (`queue`,`reserved_at`,`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
            $failed = <<<SQL
CREATE TABLE IF NOT EXISTS `{$failedTable}` (
  `id` VARCHAR(64) NOT NULL,
  `queue` VARCHAR(64) NOT NULL DEFAULT 'default',
  `payload` LONGTEXT NOT NULL,
  `error` TEXT NULL,
  `failed_at` INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `failed_queue_idx` (`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
        }
        return [$jobs, $failed];
    }

    protected function ensureTables(): void
    {
        if ($this->tablesReady) {
            return;
        }
        $driver = method_exists($this->connection, 'getDriver')
            ? $this->connection->getDriver()
            : 'mysql';
        foreach (self::tablesSql($driver, $this->table, $this->failedTable) as $sql) {
            $this->connection->statement($sql);
        }
        $this->tablesReady = true;
    }

    public function push(mixed $payload, string $queue = 'default', ?int $delay = null): string
    {
        $this->ensureTables();
        $now = time();
        $id  = uniqid('', true);
        $this->connection->insert(
            "INSERT INTO `{$this->table}` (`id`,`queue`,`payload`,`attempts`,`reserved_at`,`available_at`,`created_at`)"
            . " VALUES (?,?,?,0,NULL,?,?)",
            [$id, $queue, json_encode($payload, JSON_UNESCAPED_UNICODE), $now + (int) ($delay ?? 0), $now]
        );
        return $id;
    }

    public function pop(string $queue = 'default'): ?array
    {
        $this->ensureTables();
        $now = time();
        $staleAt = $now - $this->retryAfter;
        $this->connection->statement('BEGIN');
        try {
            $forUpdate = (method_exists($this->connection, 'getDriver')
                && $this->connection->getDriver() === 'mysql')
                ? ' FOR UPDATE'
                : '';
            $rows = $this->connection->select(
                "SELECT `id` FROM `{$this->table}`"
                . " WHERE `queue`=? AND `available_at`<=?"
                . " AND (`reserved_at` IS NULL OR `reserved_at`<=?)"
                . " ORDER BY `id` ASC LIMIT 1{$forUpdate}",
                [$queue, $now, $staleAt]
            );
            if (empty($rows)) {
                $this->connection->statement('COMMIT');
                return null;
            }
            $id = $rows[0]['id'];
            $this->connection->update(
                "UPDATE `{$this->table}` SET `reserved_at`=?, `attempts`=`attempts`+1 WHERE `id`=?",
                [$now, $id]
            );
            $row = $this->connection->select(
                "SELECT `id`,`queue`,`payload`,`attempts`,`created_at`,`available_at`"
                . " FROM `{$this->table}` WHERE `id`=?",
                [$id]
            );
            $this->connection->statement('COMMIT');
            if (empty($row)) {
                return null;
            }
            $r = $row[0];
            return [
                'id'           => $r['id'],
                'queue'        => $r['queue'],
                'payload'      => json_decode($r['payload'], true),
                'attempts'     => (int) $r['attempts'],
                'created_at'   => (int) $r['created_at'],
                'available_at' => (int) $r['available_at'],
                'reserved_at'  => $now,
            ];
        } catch (\Throwable $e) {
            $this->connection->statement('ROLLBACK');
            throw $e;
        }
    }

    public function size(string $queue = 'default'): int
    {
        $this->ensureTables();
        $now = time();
        $rows = $this->connection->select(
            "SELECT COUNT(*) AS _c FROM `{$this->table}`"
            . " WHERE `queue`=? AND `available_at`<=? AND `reserved_at` IS NULL",
            [$queue, $now]
        );
        return (int) ($rows[0]['_c'] ?? 0);
    }

    public function clear(string $queue = 'default'): void
    {
        $this->ensureTables();
        $this->connection->delete("DELETE FROM `{$this->table}` WHERE `queue`=?", [$queue]);
    }

    public function failedJobs(string $queue = 'default'): array
    {
        $this->ensureTables();
        $rows = $this->connection->select(
            "SELECT `id`,`queue`,`payload`,`error`,`failed_at` FROM `{$this->failedTable}`"
            . " WHERE `queue`=? ORDER BY `failed_at` DESC, `id` DESC",
            [$queue]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => $r['id'],
                'queue'      => $r['queue'],
                'payload'    => json_decode($r['payload'], true),
                'error'      => $r['error'],
                'failed_at'  => (int) $r['failed_at'],
            ];
        }
        return $out;
    }

    /**
     * 启动消费者循环（语义与文件驱动一致；成功删除、失败重试 / 入失败表）
     *
     * @param callable(array):void $handler 处理单个任务（$job 为解码后的数组）
     * @param array $opts 选项：max / once / sleep / max_attempts / retry_delay
     */
    public function worker(callable $handler, string $queue = 'default', array $opts = []): void
    {
        $max         = (int) ($opts['max'] ?? 0);
        $once        = (bool) ($opts['once'] ?? false);
        $sleep       = (int) ($opts['sleep'] ?? 2);
        $maxAttempts = (int) ($opts['max_attempts'] ?? $this->maxAttempts);
        $retryDelay  = (int) ($opts['retry_delay'] ?? $this->retryDelay);
        $processed   = 0;

        while (true) {
            $job = $this->pop($queue);
            if ($job === null) {
                if ($once) {
                    break;
                }
                if ($sleep > 0) {
                    sleep($sleep);
                }
                continue;
            }
            try {
                $handler($job);
                $this->deleteReserved($job);
            } catch (\Throwable $e) {
                $this->handleFailure($job, $e, $maxAttempts, $retryDelay);
            }
            $processed++;
            if ($once) {
                break;
            }
            if ($max > 0 && $processed >= $max) {
                break;
            }
        }
    }

    /** 成功处理完：删除任务行 */
    protected function deleteReserved(array $job): void
    {
        $this->connection->delete("DELETE FROM `{$this->table}` WHERE `id`=?", [$job['id']]);
    }

    /**
     * 处理失败：attempts 达上限 → 入失败表并删除；否则按指数退避释放（延迟后重试）
     *
     * 退避公式：delay = retryDelay × 2^(attempts−1)，并封顶 $cap 秒，
     * 避免重试风暴把延迟无限放大（如 5s → 10s → 20s … 封顶 3600s）。
     */
    protected function handleFailure(array $job, \Throwable $e, int $maxAttempts, int $retryDelay): void
    {
        $attempts = (int) ($job['attempts'] ?? 0);
        if ($attempts >= $maxAttempts) {
            $this->storeFailed($job, $e);
        } else {
            $this->connection->update(
                "UPDATE `{$this->table}` SET `reserved_at`=NULL, `available_at`=? WHERE `id`=?",
                [time() + $this->backoff($attempts, $retryDelay), $job['id']]
            );
        }
    }

    /**
     * 计算指数退避延迟（秒）
     */
    protected function backoff(int $attempts, int $retryDelay, int $cap = 3600): int
    {
        $delay = $retryDelay * (2 ** max(0, $attempts - 1));
        return min($delay, $cap);
    }

    /** 将失败任务写入 failed_jobs 并从 jobs 删除 */
    protected function storeFailed(array $job, \Throwable $e): void
    {
        $this->connection->insert(
            "INSERT INTO `{$this->failedTable}` (`id`,`queue`,`payload`,`error`,`failed_at`) VALUES (?,?,?,?,?)",
            [
                $job['id'],
                $job['queue'] ?? 'default',
                json_encode($job['payload'] ?? null, JSON_UNESCAPED_UNICODE),
                $e->getMessage(),
                time(),
            ]
        );
        $this->connection->delete("DELETE FROM `{$this->table}` WHERE `id`=?", [$job['id']]);
    }
}
