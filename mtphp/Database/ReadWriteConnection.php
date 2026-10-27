<?php
declare(strict_types=1);

namespace Mtphp\Database;

/**
 * 读写分离连接（实现 Connector 契约，对上层透明）
 *
 * 路由策略：
 *  - 写语句（insert/update/delete/DDL/事务原语）一律走写连接；
 *  - 读语句（select）默认走读连接（可配置多读，轮询负载）；
 *  - 当写连接正处于事务中时，读也走写连接，保证「事务内读己写」可见；
 *  - sticky（读己写一致性）：本上下文一旦发生过写，后续读强制走主，无需显式事务。
 *    上下文按运行模式自动识别：
 *      · FPM/CLI：以“请求”为界（即 _fpm 键），由 DB::resetSticky() 在请求开始复位；
 *      · Swoole 常驻：以“协程”为界（按协程 ID 隔离），由 onRequest 调 DB::resetSticky($cid) 复位。
 */
class ReadWriteConnection implements Connector
{
    protected Connector $write;

    /** @var list<Connector> 读连接池（为空则读也走写） */
    protected array $reads;

    protected int $rr = 0;

    /**
     * sticky 状态表：键为协程 ID（Swoole）或 '_fpm'（非协程模式）；值为“是否已写”
     *
     * @var array<int|string,bool>
     */
    protected array $sticky = [];

    /**
     * @param list<Connector> $reads
     */
    public function __construct(Connector $write, array $reads = [])
    {
        $this->write = $write;
        $this->reads = $reads;
    }

    public function getDriver(): string
    {
        return $this->write->getDriver();
    }

    public function getPdo(): ?\PDO
    {
        return $this->write->getPdo();
    }

    /**
     * 当前协程 ID（仅 Swoole 协程上下文有效，≥1）；非协程环境返回 null（退化为请求级）
     */
    protected static function currentCid(): ?int
    {
        if (class_exists('Swoole\\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            return \Swoole\Coroutine::getCid();
        }
        return null;
    }

    /**
     * 读连接选择：无读连接则回落写；有多个则轮询
     */
    protected function readConnector(): Connector
    {
        if ($this->reads === []) {
            return $this->write;
        }
        $idx = $this->rr++ % count($this->reads);
        return $this->reads[$idx];
    }

    /**
     * 是否应读主库（事务内必须读主，以见未提交数据）
     */
    protected function shouldReadWrite(): bool
    {
        return method_exists($this->write, 'inTransaction')
            && $this->write->inTransaction();
    }

    /**
     * 当前上下文是否处于 sticky（发生过写）
     */
    protected function isSticky(): bool
    {
        $key = self::currentCid() ?? '_fpm';
        return $this->sticky[$key] ?? false;
    }

    public function select(string $sql, array $bindings = []): array
    {
        // 事务内 或 已发生写（sticky）时读主，否则读从
        $conn = ($this->shouldReadWrite() || $this->isSticky()) ? $this->write : $this->readConnector();
        return $conn->select($sql, $bindings);
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        $ok = $this->write->statement($sql, $bindings);
        $this->markSticky();
        return $ok;
    }

    public function insert(string $sql, array $bindings = []): int
    {
        $id = $this->write->insert($sql, $bindings);
        $this->markSticky();
        return $id;
    }

    public function update(string $sql, array $bindings = []): int
    {
        $n = $this->write->update($sql, $bindings);
        $this->markSticky();
        return $n;
    }

    public function delete(string $sql, array $bindings = []): int
    {
        $n = $this->write->delete($sql, $bindings);
        $this->markSticky();
        return $n;
    }

    /**
     * 发生过写操作后标记 sticky，使本上下文后续读走主库（读己写一致性）
     */
    protected function markSticky(): void
    {
        $key = self::currentCid() ?? '_fpm';
        $this->sticky[$key] = true;
    }

    /**
     * 设置 sticky 状态（通常由本类在写后自动置 true；手动可强制开启/关闭本上下文）
     */
    public function setSticky(bool $value): void
    {
        $key = self::currentCid() ?? '_fpm';
        $this->sticky[$key] = $value;
    }

    /**
     * 复位 sticky（请求/协程开始时由 DB::resetSticky() 调用，避免跨上下文泄漏）
     */
    public function resetSticky(): void
    {
        $this->sticky = [];
    }

    /**
     * 仅复位指定协程的 sticky（Swoole onRequest 结束时调 DB::resetSticky($cid) 用）
     */
    public function resetStickyFor(int $cid): void
    {
        unset($this->sticky[$cid]);
    }
}
