<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Queue;

use Mtphp\App;
use Mtphp\Database\DB;

/**
 * 队列管理器（门面风格，按 config('queue.driver') 选择驱动）
 *
 *   queue()->push(...);                 // 默认按配置选择 file / database
 *   queue() 返回 QueueInterface 实例（懒加载单例）
 */
class QueueManager
{
    protected static ?QueueInterface $instance = null;

    public static function resolve(): QueueInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $app = App::instance();
        $cfg = $app !== null ? $app->config()->get('queue', []) : [];

        if (($cfg['driver'] ?? 'file') === 'database') {
            $conn  = DB::connection($cfg['connection'] ?? 'mysql');
            $queue = new DatabaseQueue(
                $conn,
                $cfg['table'] ?? 'jobs',
                $cfg['failed_table'] ?? 'failed_jobs',
                (int) ($cfg['max_attempts'] ?? 3),
                (int) ($cfg['retry_delay'] ?? 5),
                (int) ($cfg['retry_after'] ?? 90)
            );
        } else {
            $path = $cfg['path'] ?? ($app !== null ? $app->root() . '/runtime/queue' : 'runtime/queue');
            $queue = new Queue($path);
        }

        self::$instance = $queue;
        return $queue;
    }

    /** 测试 / 运行时重置单例 */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /** 显式注入队列实例（测试桩 / 运行时覆盖驱动时使用） */
    public static function setInstance(QueueInterface $instance): void
    {
        self::$instance = $instance;
    }
}
