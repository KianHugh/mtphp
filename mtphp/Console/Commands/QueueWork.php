<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;
use Mtphp\Database\DB;
use Mtphp\Queue\DatabaseQueue;
use Mtphp\Queue\Queue;
use Mtphp\Queue\QueueInterface;

/**
 * queue:work —— 启动队列消费者
 *
 * 自动按 config('queue.driver') 选择驱动：
 *   file（默认）     → 处理 runtime/queue 下的任务文件
 *   database         → 处理数据库 jobs 表中的任务（可靠投递 + 重试）
 *
 * 选项：
 *   --queue=default   队列名
 *   --max=0           最多处理几个任务（0=不限）
 *   --sleep=2         空轮询间隔秒
 *   --once            只取一个任务就退出（常用于 cron 触发单次消费）
 */
class QueueWork extends Command
{
    public function configure(): void
    {
        $this->name        = 'queue:work';
        $this->description = '启动队列消费者（file / database 驱动自动适配）';
    }

    public function handle(): int
    {
        $queue = (string) $this->option('queue', 'default');
        $max   = (int) $this->option('max', 0);
        $sleep = (int) $this->option('sleep', 2);
        $once  = (bool) $this->option('once', false);

        $app    = $this->application->getApp();
        $cfg    = $app !== null ? $app->config()->get('queue', []) : [];
        $driver = $cfg['driver'] ?? 'file';

        $q = $this->resolveQueue($driver, $cfg);

        $this->info("队列消费者已启动：driver={$driver} queue={$queue} max={$max} sleep={$sleep} once=" . ($once ? 'yes' : 'no'));
        $this->info('按 Ctrl+C 退出（once 模式处理完一个即退出）。');

        $q->worker(function (array $job) use ($q): void {
            $handled = $q->dispatch($job);
            if (!$handled) {
                $this->warn('跳过未识别的任务 payload：' . json_encode($job['payload'] ?? null, JSON_UNESCAPED_UNICODE));
            } else {
                $this->line('[done] 任务 ' . ($job['id'] ?? '?'));
            }
        }, $queue, ['max' => $max, 'once' => $once, 'sleep' => $sleep]);

        return 0;
    }

    protected function resolveQueue(string $driver, array $cfg): QueueInterface
    {
        if ($driver === 'database') {
            $conn = DB::connection($cfg['connection'] ?? 'mysql');
            return new DatabaseQueue(
                $conn,
                $cfg['table'] ?? 'jobs',
                $cfg['failed_table'] ?? 'failed_jobs',
                (int) ($cfg['max_attempts'] ?? 3),
                (int) ($cfg['retry_delay'] ?? 5)
            );
        }
        $path = $cfg['path'] ?? 'runtime/queue';
        return new Queue($path);
    }
}
