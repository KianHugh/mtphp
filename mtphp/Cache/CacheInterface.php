<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Cache;

/**
 * 缓存契约（思想借鉴主流框架的 Cache 组件，独立实现）
 *
 * 文件驱动（FileCache）与 Redis 驱动（RedisStore）都实现本接口，
 * 上层通过 CacheManager::resolve() 按 config('cache.driver') 透明切换。
 */
interface CacheInterface
{
    /**
     * 读取缓存；缺失 / 过期返回 $default
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 写入缓存
     *
     * @param string $key   缓存键
     * @param mixed  $value 任意可序列化的值
     * @param ?int   $ttl   过期秒数（null 用默认；0 表示永不过期）
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * 判断键是否存在且未过期
     */
    public function has(string $key): bool;

    /**
     * 删除单个键（键不存在也返回 true）
     */
    public function delete(string $key): bool;

    /**
     * 清空整个缓存
     */
    public function clear(): bool;

    /**
     * 记忆化：命中直接返回；未命中执行回调、写入缓存再返回
     *
     * @param callable():mixed $callback
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed;

    /**
     * 读取并删除（get + delete）
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * 仅当键不存在时写入（set-if-absent）；已存在返回 false
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * 写入并永不过期（ttl = null）
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * 记忆化且永不过期（ttl = null）
     *
     * @param callable():mixed $callback
     */
    public function rememberForever(string $key, callable $callback): mixed;

    /**
     * 自增计数（缺失视为 0 后 +step），返回自增后的值
     */
    public function increment(string $key, int $step = 1): int;

    /**
     * 自减计数（缺失视为 0 后 -step），返回自减后的值
     */
    public function decrement(string $key, int $step = 1): int;

    /**
     * 创建一个缓存锁（互斥锁，借助缓存驱动 + owner 令牌实现）。
     * 尚未真正占用，须调用 Lock::get() / Lock::block() 获取。
     *
     * @param int         $seconds 锁自动过期秒数（0 = 永不过期，仍可被 owner 主动释放）
     * @param string|null $owner   持有者令牌（不传则自动生成，用于安全释放）
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock;
}
