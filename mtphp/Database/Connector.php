<?php
declare(strict_types=1);

namespace Mtphp\Database;

/**
 * 数据库连接契约
 *
 * 让 Query 构造器与「具体连接实现」解耦：
 *  - FPM/CLI 模式下由 PDO 版 Connection 实现；
 *  - Swoole 常驻模式下由协程版 CoroutineConnection（Swoole\Coroutine\MySQL）实现。
 * 上层（DB / Query）只依赖本接口，无需关心底层驱动。
 */
interface Connector
{
    /**
     * 当前连接使用的数据库驱动（用于 Schema / Queue 方言分叉）
     */
    public function getDriver(): string;

    /**
     * 底层 PDO 句柄（用于事务嵌套层级跟踪）；非 PDO 连接（如 Swoole 协程 MySQL）返回 null
     */
    public function getPdo(): ?\PDO;

    /**
     * 执行查询并返回全部行
     *
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    public function statement(string $sql, array $bindings = []): bool;

    public function insert(string $sql, array $bindings = []): int;

    public function update(string $sql, array $bindings = []): int;

    public function delete(string $sql, array $bindings = []): int;
}
