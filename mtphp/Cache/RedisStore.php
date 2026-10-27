<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Cache;

/**
 * Redis 缓存驱动（零依赖，纯 PHP RESP 实现）
 *
 *  - 值经 serialize 包裹（['_v'=>值]）写入，便于区分“未命中”与“存了 false / null”。
 *  - 过期走 Redis 原生 EXPIRE（SET ... EX），不依赖本进程时钟。
 *  - 多服务器 / 分布式部署场景替代文件缓存；默认不启用（config('cache.driver')=file）。
 */
class RedisStore implements CacheInterface
{
    use AbstractCache;

    protected RedisConnector $conn;

    protected string $prefix;

    public function __construct(RedisConnector $conn, string $prefix = '')
    {
        $this->conn   = $conn;
        $this->prefix = $prefix;
    }

    protected function key(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->conn->command('GET', $this->key($key));
        if ($raw === null) {
            return $default;
        }
        $arr = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($arr) || !array_key_exists('_v', $arr)) {
            return $default; // 数据损坏兜底
        }
        return $arr['_v'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $payload = serialize(['_v' => $value]);
        if ($ttl !== null && $ttl > 0) {
            $this->conn->command('SET', $this->key($key), $payload, 'EX', (string) $ttl);
        } else {
            $this->conn->command('SET', $this->key($key), $payload);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return (bool) $this->conn->command('EXISTS', $this->key($key));
    }

    /**
     * 仅当键不存在时原子写入（set-if-absent）：SET ... NX（可选 EX）。
     * 成功（+OK）返回 true；已存在（nil）返回 false。是缓存锁与并发安全 add 的基础。
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $payload = serialize(['_v' => $value]);
        $args    = ['SET', $this->key($key), $payload, 'NX'];
        if ($ttl !== null && $ttl > 0) {
            $args[] = 'EX';
            $args[] = (string) $ttl;
        }
        return $this->conn->command(...$args) !== null;
    }

    public function delete(string $key): bool
    {
        $this->conn->command('DEL', $this->key($key));
        return true; // 删除幂等，键不存在也视为成功
    }

    public function clear(): bool
    {
        $this->conn->command('FLUSHDB');
        return true;
    }
}
