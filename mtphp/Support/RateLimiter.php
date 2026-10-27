<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

use Mtphp\Cache\CacheInterface;

/**
 * 通用限流器（零依赖，基于缓存驱动实现固定窗口）
 *
 * 从 Throttle 中间件抽离出来，便于在任意场景（API 防刷、登录防爆破、发券限制）复用。
 *
 * 用法：
 *   $limiter = new RateLimiter(cache());
 *   if ($limiter->tooManyAttempts($key, 60, 60)) { /* 返回 429 *\/ }
 *   $limiter->hit($key, 60);
 *   // 或一键式：
 *   $ok = $limiter->attempt($key, 5, 60, fn () => doSomething());
 */
class RateLimiter
{
    public function __construct(protected CacheInterface $store)
    {
    }

    /**
     * 是否超出限制（窗口内计数 >= max）
     */
    public function tooManyAttempts(string $key, int $max, int $decay): bool
    {
        return $this->attempts($key) >= $max;
    }

    /**
     * 当前已尝试次数
     */
    public function attempts(string $key): int
    {
        return (int) $this->store->get($key, 0);
    }

    /**
     * 剩余可用次数
     */
    public function remaining(string $key, int $max): int
    {
        return max(0, $max - $this->attempts($key));
    }

    /**
     * 记一次请求（自增并将窗口过期时间设为 decay 秒）
     */
    public function hit(string $key, int $decay): int
    {
        $count = $this->attempts($key) + 1;
        $this->store->set($key, $count, $decay);
        return $count;
    }

    /**
     * 清除计数
     */
    public function clear(string $key): void
    {
        $this->store->delete($key);
    }

    /**
     * 一键式尝试：未超限则记一次并返回回调结果；超限则返回 false
     *
     * @param callable():mixed $callback
     * @return mixed
     */
    public function attempt(string $key, int $max, int $decay, callable $callback): mixed
    {
        if ($this->tooManyAttempts($key, $max, $decay)) {
            return false;
        }
        $this->hit($key, $decay);
        return $callback();
    }
}
