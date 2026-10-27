<?php
declare(strict_types=1);

/**
 * 测试夹具（fixtures）
 *
 * 用「内存 SQLite」实现 Mtphp\Database\Connector 契约，让 DB / Model / Query
 * 在完全不依赖 MySQL 的情况下验证链路（与连接池解耦能力）。
 *
 * 命名空间 Tests\（不被框架自动加载，由 bootstrap.php 直接 require 进来）。
 */

namespace Tests;

use Mtphp\Database\Connector;
use Mtphp\Database\DB;

/**
 * 内存 SQLite 连接器：把 PDO 操作适配成框架 Connector 契约
 */
class SqliteConnector implements Connector
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * DB::table() / Model 会调用 getPrefix() 拼表前缀；内存库无前缀，返回空串。
     */
    public function getPrefix(): string
    {
        return '';
    }

    public function getDriver(): string
    {
        return 'sqlite';
    }

    public function getPdo(): ?\PDO
    {
        return $this->pdo;
    }

    public function select(string $sql, array $b = []): array
    {
        $s = $this->pdo->prepare($sql);
        $this->bindTyped($s, $b);
        $s->execute();
        return $s->fetchAll();
    }

    public function statement(string $sql, array $b = []): bool
    {
        $s = $this->pdo->prepare($sql);
        $this->bindTyped($s, $b);
        return $s->execute();
    }

    public function insert(string $sql, array $b = []): int
    {
        $s = $this->pdo->prepare($sql);
        $this->bindTyped($s, $b);
        $s->execute();
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $sql, array $b = []): int
    {
        $s = $this->pdo->prepare($sql);
        $this->bindTyped($s, $b);
        $s->execute();
        return $s->rowCount();
    }

    public function delete(string $sql, array $b = []): int
    {
        $s = $this->pdo->prepare($sql);
        $this->bindTyped($s, $b);
        $s->execute();
        return $s->rowCount();
    }

    /**
     * 按 PHP 类型绑定参数（同 Connection::bindTyped）：int / bool → PARAM_INT，
     * null → PARAM_NULL，其余 → PARAM_STR。修复数值比较的 SQLite 亲和性陷阱。
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
}

/**
 * 返回一个“连接供给器（resolver）”：每次调用返回同一个内存库的连接。
 *
 * 重要：in-memory SQLite（:memory:）是“按连接”隔离的，每个新 PDO 都是独立数据库，
 * 所以必须复用同一个 $pdo 句柄；这里闭包捕获同一个 $pdo，保证数据在多次查询间可见。
 * 这与生产 / Swoole 模式下 DB::setResolver() 注入协程连接池的用法完全对称。
 */
function sqliteResolver(\PDO $pdo): callable
{
    return static fn () => new SqliteConnector($pdo);
}
