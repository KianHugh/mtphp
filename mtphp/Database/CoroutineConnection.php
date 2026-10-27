<?php
declare(strict_types=1);

namespace Mtphp\Database;

/**
 * Swoole 协程 MySQL 连接适配器
 *
 * 包装 Swoole\Coroutine\MySQL，使其满足 Connector 契约，供 Query 构造器直接使用。
 * 仅在 Swoole 协程上下文（由 Pool 提供连接）中使用；FPM 模式不会触碰本类。
 *
 * 注意：本类不 type-hint Swoole 具体类（仅以 object 接收），
 *       以便在没有安装 Swoole 扩展的机器（如 Windows 开发机）上也能正常加载与 lint。
 */
class CoroutineConnection implements Connector
{
    protected object $mysql;

    protected string $prefix;

    public function __construct(object $mysql, string $prefix = '')
    {
        $this->mysql  = $mysql;
        $this->prefix = $prefix;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getPdo(): ?\PDO
    {
        return null;
    }

    /**
     * 执行 SQL（有绑定走 prepare/execute，否则直接 query）
     *
     * @return array<int,array<string,mixed>>|bool
     */
    protected function exec(string $sql, array $bindings = []): array|bool
    {
        if (empty($bindings)) {
            $result = $this->mysql->query($sql);
        } else {
            $stmt = $this->mysql->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException('Prepare 失败：' . ($this->mysql->error ?? 'unknown'));
            }
            $result = $stmt->execute($bindings);
        }
        if ($result === false) {
            throw new \RuntimeException('SQL 执行失败：' . ($this->mysql->error ?? 'unknown'));
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        $result = $this->exec($sql, $bindings);
        return is_array($result) ? $result : [];
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        $this->exec($sql, $bindings);
        return true;
    }

    public function insert(string $sql, array $bindings = []): int
    {
        $this->exec($sql, $bindings);
        return (int) ($this->mysql->insert_id ?? 0);
    }

    public function update(string $sql, array $bindings = []): int
    {
        $this->exec($sql, $bindings);
        return (int) ($this->mysql->affected_rows ?? 0);
    }

    public function delete(string $sql, array $bindings = []): int
    {
        $this->exec($sql, $bindings);
        return (int) ($this->mysql->affected_rows ?? 0);
    }

    /**
     * 关闭底层协程连接（归还/销毁时调用）
     */
    public function close(): void
    {
        if (method_exists($this->mysql, 'close')) {
            $this->mysql->close();
        }
    }
}
