<?php
declare(strict_types=1);

namespace Mtphp\Database;

/**
 * 数据库连接（基于 PDO，懒加载）
 *
 *  - 首次查询才建连，避免无谓开销。
 *  - 参数化查询防注入（ATTR_EMULATE_PREPARES=false）。
 *  - 支持持久连接（config['persistent']=true），降低 FPM 高频请求下的建连开销。
 *  - 提供事务原语；Swoole 常驻模式下可由 Pool 接管连接获取。
 */
class Connection implements Connector
{
    /** @var array<string,mixed> */
    protected array $config;

    protected ?\PDO $pdo = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getPrefix(): string
    {
        return (string) ($this->config['prefix'] ?? '');
    }

    /**
     * 当前连接使用的数据库驱动（用于 Schema 语法分叉，如自增主键写法）
     */
    public function getDriver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    public function getPdo(): ?\PDO
    {
        return $this->pdo;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    protected function connect(): void
    {
        $c = $this->config;
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            (string) ($c['host'] ?? '127.0.0.1'),
            (int) ($c['port'] ?? 3306),
            (string) ($c['dbname'] ?? ''),
            (string) ($c['charset'] ?? 'utf8mb4')
        );
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        // 持久连接：FPM 模式下跨请求复用同一底层连接，减少握手成本
        if (!empty($c['persistent'])) {
            $options[\PDO::ATTR_PERSISTENT] = true;
        }
        $this->pdo = new \PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), $options);
    }

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $this->bindTyped($stmt, $bindings);
        return $stmt;
    }

    /**
     * 按 PHP 类型绑定参数：int / bool → PARAM_INT，null → PARAM_NULL，其余 → PARAM_STR。
     *
     * 解决 SQLite / MySQL 下“整型值被当作字符串绑定导致数值比较恒错”的亲和性陷阱：
     * SQLite 按存储类排序比较——INTEGER 存储类恒小于 TEXT 存储类，故 30 > '15'
     * （字符串绑定的参数）会被判为 false。只有把整型值以原生类型绑定，比较才正确。
     */
    protected function bindTyped(\PDOStatement $stmt, array $bindings): void
    {
        $i = 1;
        foreach ($bindings as $value) {
            if (is_int($value)) {
                $stmt->bindValue($i, $value, \PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($i, $value ? 1 : 0, \PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($i, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($i, $value, \PDO::PARAM_STR);
            }
            $i++;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->fetchAll();
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        return $this->query($sql, $bindings) instanceof \PDOStatement;
    }

    public function insert(string $sql, array $bindings = []): int
    {
        $this->query($sql, $bindings);
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $sql, array $bindings = []): int
    {
        return $this->query($sql, $bindings)->rowCount();
    }

    public function delete(string $sql, array $bindings = []): int
    {
        return $this->query($sql, $bindings)->rowCount();
    }

    // ---- 事务原语 ----
    public function beginTransaction(): bool
    {
        return $this->pdo()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo()->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    /**
     * 事务闭包（自动提交/回滚；支持嵌套：内层以 SAVEPOINT 为单位）
     *
     * @return mixed
     */
    public function transaction(\Closure $callback): mixed
    {
        return (new Transaction($this))->run($callback);
    }
}
