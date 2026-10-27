<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Cache;

/**
 * 缓存通用实现（文件驱动 / Redis 驱动共用）
 *
 * 仅用 get / set / delete 三个原语即可派生出 has / remember，
 * 避免各驱动重复实现“命中判定 / 记忆化”逻辑。
 */
trait AbstractCache
{
    /**
     * 判断键是否存在且未过期（用哨兵默认值区分“未命中”与“存了 null”）
     */
    public function has(string $key): bool
    {
        return $this->get($key, '__MTPHP_CACHE_MISS__') !== '__MTPHP_CACHE_MISS__';
    }

    /**
     * 记忆化读取：命中直接返回；未命中则执行回调、写入缓存再返回
     *
     * @param callable():mixed $callback
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * 读取并删除（get + delete）。常用于“一次性令牌 / 消费队列式取值”。
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        return $value;
    }

    /**
     * 仅当键不存在时写入（set-if-absent）；已存在则不动并返回 false。
     *
     * 注意：本通用实现为“has + set”两步（单进程安全）；高并发强一致场景建议由驱动覆盖为原子指令。
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }
        return $this->set($key, $value, $ttl);
    }

    /**
     * 写入并永不过期（ttl = null）。等价 set($key, $value, null)。
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, null);
    }

    /**
     * 记忆化且永不过期（ttl = null）。等价 remember($key, null, $callback)。
     *
     * @param callable():mixed $callback
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    /**
     * 自增计数：缺失视为 0 再 +step，返回自增后的值（默认步长 1）。
     *
     * 注意：本通用实现为“get + set”非原子读写改；高频并发计数建议由驱动覆盖为原子指令。
     */
    public function increment(string $key, int $step = 1): int
    {
        $next = (int) $this->get($key, 0) + $step;
        $this->set($key, $next);
        return $next;
    }

    /**
     * 自减计数：缺失视为 0 再 -step，返回自减后的值（默认步长 1）。
     */
    public function decrement(string $key, int $step = 1): int
    {
        return $this->increment($key, -$step);
    }

    /**
     * 创建一个缓存锁（尚未真正占用，须调用 Lock::get()/block() 获取）。
     *
     * @param int         $seconds
     * @param string|null $owner
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return new Lock($this, $name, $seconds, $owner);
    }
}
