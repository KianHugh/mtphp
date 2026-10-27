<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Cache;

/**
 * 缓存锁（借助缓存驱动实现的互斥锁，分布式友好）
 *
 * 设计目标：用最简单的 API 提供“拿锁 → 执行临界区 → 释放”的互斥能力，
 * 同时借助缓存驱动的原子原语（文件 fopen('x') / Redis SET NX）避免“has + set”竞态。
 *
 * 用法：
 *   $lock = cache()->lock('order:' . $id, 10);   // 持锁 10 秒
 *   if ($lock->get()) {
 *       try {
 *           // 临界区
 *       } finally {
 *           $lock->release();
 *       }
 *   }
 *
 *   // 或阻塞等待获取，并在拿到后执行回调（结束时自动释放）：
 *   $lock->block(5, function () {
 *       // 临界区
 *   });
 *
 * 实现要点：
 *   - 占用记录 owner 令牌 + 过期时间；仅 owner 可释放，防误删他人锁；
 *   - 已过期锁可被后续竞争者安全接管（stale 兜底），避免死锁；
 *   - seconds = 0 表示永不过期（仍可被 owner 主动释放）。
 */
class Lock
{
    protected CacheInterface $store;

    protected string $name;

    protected int $seconds;

    protected string $owner;

    protected bool $acquired = false;

    public function __construct(CacheInterface $store, string $name, int $seconds, ?string $owner = null)
    {
        $this->store   = $store;
        $this->name    = $name;
        $this->seconds = $seconds;
        $this->owner   = $owner ?? bin2hex(random_bytes(8));
    }

    /**
     * 尝试获取锁；已持有则直接返回 true。
     */
    public function get(): bool
    {
        if ($this->acquired) {
            return true;
        }
        $lockKey   = $this->key();
        $expiresAt = $this->expiresAt();
        if ($this->store->add($lockKey, [$this->owner, $expiresAt], $this->seconds)) {
            $this->acquired = true;
            return true;
        }
        // 过期锁接管（防死锁兜底）：仅当锁记录已过期时删除并重建
        $current = $this->store->get($lockKey);
        if (is_array($current) && isset($current[1]) && $current[1] < time()) {
            $this->store->delete($lockKey);
            if ($this->store->add($lockKey, [$this->owner, $expiresAt], $this->seconds)) {
                $this->acquired = true;
                return true;
            }
        }
        return false;
    }

    /**
     * 阻塞等待获取锁；拿到后（可选）执行回调并在结束时释放。
     *
     * @param callable|null $callback 拿到锁后执行的回调（无参）；为 null 时返回是否拿到锁
     * @return mixed 有回调时返回回调结果；无回调返回 bool
     */
    public function block(int $timeout, ?callable $callback = null): mixed
    {
        $deadline = microtime(true) + max(0, $timeout);
        while (true) {
            if ($this->get()) {
                if ($callback === null) {
                    return true;
                }
                try {
                    return $callback();
                } finally {
                    $this->release();
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(100000); // 100ms 轮询
        }
        return $callback === null ? false : null;
    }

    /**
     * 仅当本实例为持有者时释放锁。
     */
    public function release(): bool
    {
        if (!$this->acquired) {
            return false;
        }
        $lockKey = $this->key();
        $current = $this->store->get($lockKey);
        if (is_array($current) && isset($current[0]) && $current[0] === $this->owner) {
            $this->store->delete($lockKey);
        }
        $this->acquired = false;
        return true;
    }

    /**
     * 强制释放（无视持有者，谨慎使用）。
     */
    public function forceRelease(): void
    {
        $this->store->delete($this->key());
        $this->acquired = false;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function isAcquired(): bool
    {
        return $this->acquired;
    }

    /**
     * 剩余有效秒数（永不过期返回 PHP_INT_MAX）。
     */
    public function getRemainingLifeTime(): int
    {
        $current = $this->store->get($this->key());
        if (!is_array($current) || !isset($current[1])) {
            return 0;
        }
        $exp = $current[1];
        if ($exp >= PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        return max(0, $exp - time());
    }

    protected function key(): string
    {
        return 'lock:' . $this->name;
    }

    protected function expiresAt(): int
    {
        return $this->seconds > 0 ? time() + $this->seconds : PHP_INT_MAX;
    }
}
