<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Cache;

use Mtphp\App;

/**
 * 缓存管理器（门面风格，按 config('cache.driver') 选择驱动）
 *
 *   cache()->set('k', $v);                // 默认 file
 *   config('cache.driver')='redis' 时使用 RedisStore（纯 PHP RESP，零依赖）
 */
class CacheManager
{
    protected static ?CacheInterface $instance = null;

    public static function resolve(): CacheInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $app = App::instance();
        $cfg = $app !== null ? $app->config()->get('cache', []) : [];

        if (($cfg['driver'] ?? 'file') === 'redis') {
            $conn = new RedisConnector(
                $cfg['host'] ?? '127.0.0.1',
                (int) ($cfg['port'] ?? 6379),
                (int) ($cfg['db'] ?? 0),
                $cfg['password'] ?? null,
                (float) ($cfg['timeout'] ?? 2.0)
            );
            self::$instance = new RedisStore($conn, $cfg['prefix'] ?? '');
        } else {
            $path = $cfg['path'] ?? ($app !== null ? $app->root() . '/runtime/cache' : 'runtime/cache');
            $ttl  = (int) ($cfg['ttl'] ?? 3600);
            self::$instance = new FileCache($path, $ttl);
        }

        return self::$instance;
    }

    /** 测试 / 运行时重置单例 */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
