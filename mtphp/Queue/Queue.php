<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Queue;

use Mtphp\App;

/**
 * 文件驱动队列（零依赖，思想借鉴主流框架的 Queue 组件）
 *
 * 设计目标：无需 Redis / Beanstalkd 等外部服务，开箱即用，适合中小规模、
 * 开发环境与边缘部署；任务以 JSON 文件落地到 runtime/queue/<队列名>/ 下。
 *
 * 任务文件命名：<id>.json（uniqid 带微秒前缀，按文件名天然有序 → FIFO）。
 * 任务载荷（解码后）结构：
 *   {
 *     "id": "....",
 *     "queue": "default",
 *     "payload": <mixed>,          // 任意可 JSON 序列化值；类任务用 {"class":..,"data":..}
 *     "created_at": 1700000000,
 *     "available_at": 1700000000   // 延迟任务：>= now 才会被 pop 出来
 *   }
 *
 * 用法：
 *   $q = new \Mtphp\Queue\Queue('runtime/queue');
 *   $q->push(['class' => App\Jobs\SendMail::class, 'data' => ['to' => 'a@b.c']]);
 *   $q->push('纯文本 / 任意数组任务');
 *   $q->worker(fn (array $job) => ...);                 // 常驻消费（默认）
 *   \Mtphp\Queue\Queue::instance()->worker(...);        // 全局单例（助手 queue()）
 *
 * 消费端（queue:work 命令）会自动把 {"class":..,"data":..} 形式的任务
 * 经容器解析实例后调用其 handle($data) 方法（见 dispatch()）。
 * 没有外部消息中间件的“可靠投递/重试”能力，但提供失败目录兜底（见 worker）。
 */
class Queue implements QueueInterface
{
    use DispatchTrait;

    protected string $path;

    protected static ?self $instance = null;

    public function __construct(string $path = 'runtime/queue')
    {
        $this->path = rtrim($path, '/\\');
    }

    /**
     * 全局单例（CLI / 助手 queue() 使用）
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** 运行中改存储目录（链式） */
    public function setPath(string $path): self
    {
        $this->path = rtrim($path, '/\\');
        return $this;
    }

    /**
     * 入队一个任务，返回任务 id
     *
     * @param mixed $payload 任意可 JSON 序列化的值；类任务用 ['class'=>类名,'data'=>任意]
     * @param string $queue  队列名
     * @param ?int   $delay  延迟秒数（null = 立即可消费）
     */
    public function push(mixed $payload, string $queue = 'default', ?int $delay = null): string
    {
        $now = time();
        $data = [
            'id'           => $this->id(),
            'queue'        => $queue,
            'payload'      => $payload,
            'created_at'   => $now,
            'available_at' => $now + (int) ($delay ?? 0),
        ];
        $this->writeJob($queue, $data);
        return $data['id'];
    }

    /**
     * 出队一个“当前可消费”的任务；无可消费任务（含全部仍在延迟中）时返回 null
     *
     * 取出即删除文件（at-most-once 语义）；处理失败的任务由 worker() 兜底移入 failed。
     */
    public function pop(string $queue = 'default'): ?array
    {
        $dir = $this->dir($queue);
        if (!is_dir($dir)) {
            return null;
        }
        $files = glob($dir . '/*.json') ?: [];
        sort($files); // 按文件名（=创建顺序）升序，保证 FIFO

        $now = time();
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $job = @json_decode($content, true);
            if (!is_array($job)) {
                @unlink($file);
                continue;
            }
            // 跳过尚未到点的延迟任务
            if (($job['available_at'] ?? 0) > $now) {
                continue;
            }
            @unlink($file);
            return $job;
        }
        return null;
    }

    /**
     * 队列中“待消费”任务数（不含已延迟未到点的）
     */
    public function size(string $queue = 'default'): int
    {
        $dir = $this->dir($queue);
        if (!is_dir($dir)) {
            return 0;
        }
        $now   = time();
        $count = 0;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $content = @file_get_contents($file);
            $job = $content === false ? null : @json_decode($content, true);
            if (!is_array($job)) {
                continue;
            }
            if (($job['available_at'] ?? 0) <= $now) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 清空某队列的全部任务文件（失败队列不在此清）
     */
    public function clear(string $queue = 'default'): void
    {
        $dir = $this->dir($queue);
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * 启动消费者循环
     *
     * @param callable(array):void $handler 处理单个任务（$job 为解码后的数组）
     * @param array $opts 选项：max(最多处理几个,0=不限) / once(只取一个就退) / sleep(空轮询间隔秒)
     */
    public function worker(callable $handler, string $queue = 'default', array $opts = []): void
    {
        $max       = (int) ($opts['max'] ?? 0);
        $once      = (bool) ($opts['once'] ?? false);
        $sleep     = (int) ($opts['sleep'] ?? 2);
        $processed = 0;

        while (true) {
            $job = $this->pop($queue);
            if ($job === null) {
                if ($once) {
                    break;
                }
                if ($sleep > 0) {
                    sleep($sleep);
                }
                continue;
            }
            try {
                $handler($job);
            } catch (\Throwable $e) {
                $this->moveToFailed($job, $e);
            }
            $processed++;
            if ($once) {
                break;
            }
            if ($max > 0 && $processed >= $max) {
                break;
            }
        }
    }

    /**
     * 列出某队列的失败任务（按文件名升序）
     *
     * @return list<array<string,mixed>>
     */
    public function failedJobs(string $queue = 'default'): array
    {
        $dir = $this->dir($queue) . '/failed';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            $job = $content === false ? null : @json_decode($content, true);
            if (is_array($job)) {
                $out[] = $job;
            }
        }
        return $out;
    }

    protected function moveToFailed(array $job, \Throwable $e): void
    {
        $dir = $this->dir($job['queue'] ?? 'default') . '/failed';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $job['failed_at'] = time();
        $job['error']     = $e->getMessage();
        @file_put_contents(
            $dir . '/' . ($job['id'] ?? uniqid('', true)) . '.json',
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    protected function dir(string $queue): string
    {
        return $this->path . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $queue);
    }

    protected function writeJob(string $queue, array $data): void
    {
        $dir = $this->dir($queue);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . $data['id'] . '.json';
        @file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    protected function id(): string
    {
        return uniqid('', true);
    }
}
