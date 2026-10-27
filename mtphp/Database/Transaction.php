<?php
declare(strict_types=1);

namespace Mtphp\Database;

/**
 * 嵌套事务（通过 SAVEPOINT 实现）
 *
 * 设计要点：
 *  - 事务嵌套层级挂在「物理连接（PDO 句柄）」上，而非 Transaction 实例上。
 *    因为 DB::connection() 在 resolver/连接池模式下每次可能返回新的连接器包装，
 *    但它们共享同一个底层 PDO；只有按 PDO 跟踪层级，嵌套 BEGIN/SAVEPOINT 才正确。
 *  - 最外层事务用 BEGIN / COMMIT / ROLLBACK；内层用 SAVEPOINT sp_N /
 *    RELEASE SAVEPOINT sp_N / ROLLBACK TO SAVEPOINT sp_N。
 *  - run() 自动提交或回滚；内层回滚只退到保存点，不影响外层；内层提交不会
 *    提前真正提交（仍属外层事务，外层回滚会一并撤销）。
 *  - 非 PDO 连接（如 Swoole 协程 MySQL，getPdo() 返回 null）退化为实例级计数，
 *    单层事务可用（嵌套支持取决于底层驱动）。
 */
class Transaction
{
    protected Connector $connection;

    /**
     * 物理连接（PDO）=> 事务嵌套层级；跨 Transaction 实例共享，解决连接包装每次新建的问题。
     *
     * @var array<int,int>
     */
    protected static array $levels = [];

    /** 非 PDO 连接（getPdo()===null）时的实例级兜底计数 */
    protected int $localLevel = 0;

    public function __construct(Connector $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 读取当前嵌套层级（按 PDO；非 PDO 退化为实例级）
     */
    protected function level(): int
    {
        $pdo = $this->connection->getPdo();
        if ($pdo === null) {
            return $this->localLevel;
        }
        return self::$levels[spl_object_id($pdo)] ?? 0;
    }

    /**
     * 写入当前嵌套层级；归零时清除映射
     */
    protected function setLevel(int $lv): void
    {
        $pdo = $this->connection->getPdo();
        if ($pdo === null) {
            $this->localLevel = $lv;
            return;
        }
        if ($lv === 0) {
            unset(self::$levels[spl_object_id($pdo)]);
        } else {
            self::$levels[spl_object_id($pdo)] = $lv;
        }
    }

    public function begin(): void
    {
        $lv = $this->level();
        if ($lv === 0) {
            $this->connection->statement('BEGIN');
        } else {
            $this->connection->statement("SAVEPOINT sp_{$lv}");
        }
        $this->setLevel($lv + 1);
    }

    public function commit(): void
    {
        $lv = $this->level();
        if ($lv === 0) {
            return; // 没有开启的事务，安全忽略
        }
        $this->setLevel($lv - 1);
        if ($lv === 1) {
            $this->connection->statement('COMMIT');
        } else {
            $this->connection->statement("RELEASE SAVEPOINT sp_" . ($lv - 1));
        }
    }

    public function rollBack(): void
    {
        $lv = $this->level();
        if ($lv === 0) {
            return; // 没有开启的事务，安全忽略
        }
        $this->setLevel($lv - 1);
        if ($lv === 1) {
            $this->connection->statement('ROLLBACK');
        } else {
            $this->connection->statement("ROLLBACK TO SAVEPOINT sp_" . ($lv - 1));
        }
    }

    /**
     * 自动事务：成功提交，异常回滚并原样抛出
     *
     * @return mixed
     */
    public function run(\Closure $callback): mixed
    {
        $this->begin();
        try {
            $result = $callback($this->connection);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }
}
