<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Schedule;

use Mtphp\App;
use Mtphp\Console\Application;

/**
 * 任务调度器（零依赖，思想借鉴主流框架的 Task Scheduler，独立实现）
 *
 * 负责登记被调度任务（Event）、按 cron 计算“到期”列表并执行；与控制台 / 队列 / 事件解耦。
 *
 * 用法（在 app/Routes/schedule.php 中定义，由 schedule:run / schedule:work 加载）：
 *   $schedule->call(fn () => cache()->clear())->daily();
 *   $schedule->command('queue:work --once')->everyFiveMinutes();
 *   $schedule->job(['class' => App\Jobs\Report::class])->dailyAt('02:00');
 *
 * 运行方式（二选一）：
 *   1) 系统 crontab 每分钟触发：* * * * * php bin/mtphp schedule:run
 *   2) 框架内置守护：php bin/mtphp schedule:work（自带常驻循环，无需系统 cron）
 */
class Schedule
{
    /** @var list<Event> */
    private array $events = [];

    /** 互斥锁目录（供 withoutOverlapping 使用） */
    private string $mutexPath = '';

    public function __construct()
    {
        $app = App::instance();
        if ($app !== null) {
            $this->mutexPath = $app->root() . '/runtime/schedule';
        }
    }

    /**
     * 登记一个闭包 / 可调用任务
     */
    public function call(callable $callback, ?string $name = null): Event
    {
        $e = new Event(Event::TYPE_CALL, $callback);
        if ($name !== null) {
            $e->name($name);
        }
        return $this->add($e);
    }

    /**
     * 登记一个控制台命令任务（如 "queue:work --once"）
     */
    public function command(string $command, array $args = []): Event
    {
        $e = new Event(Event::TYPE_COMMAND, trim($command . ' ' . implode(' ', $args)));
        return $this->add($e);
    }

    /**
     * 登记一个“入队任务”：到点后把 payload 推入队列（交给 queue:work 消费）。
     * 这是定时 / 重复任务与队列的集成点——用 cron 表达周期性，用队列承载实际执行。
     *
     * @param mixed $payload 任意可 JSON 序列化值；类任务用 ['class'=>类名,'data'=>任意]
     */
    public function job($payload, string $queue = 'default', ?int $delay = null): Event
    {
        $e = new Event(Event::TYPE_JOB, $payload);
        return $this->add($e)->setJobTarget($queue, $delay);
    }

    /**
     * 登记一个“派发事件”任务：到点后派发一个框架事件（字符串类名或已构造对象）
     *
     * @param string|object $event
     */
    public function event($event): Event
    {
        $e = new Event(Event::TYPE_EVENT, $event);
        return $this->add($e);
    }

    public function add(Event $event): Event
    {
        if ($this->mutexPath !== '') {
            $event->setMutexPath($this->mutexPath);
        }
        $this->events[] = $event;
        return $event;
    }

    /**
     * @return list<Event>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * 返回“当前到期”的任务（按 cron + 条件 + 互斥过滤）
     */
    public function dueEvents(\DateTimeZone $appTz): array
    {
        $due = [];
        foreach ($this->events as $event) {
            if ($event->isDue($appTz)) {
                $due[] = $event;
            }
        }
        return $due;
    }

    /**
     * 执行全部到期任务；单个失败不影响其余（错误记入日志）。
     * 返回实际执行的任务列表（便于测试 / 调试）。
     *
     * @return list<array{event:Event,result:mixed,error?:string}>
     */
    public function runDue(?Application $console = null): array
    {
        $tz  = $this->appTimezone();
        $ran = [];
        foreach ($this->dueEvents($tz) as $event) {
            try {
                $result = $event->run($console);
                $ran[]  = ['event' => $event, 'result' => $result];
            } catch (\Throwable $e) {
                $ran[] = ['event' => $event, 'result' => null, 'error' => $e->getMessage()];
                if (function_exists('logger')) {
                    logger()->error("调度任务 [{$event->getName()}] 执行失败：" . $e->getMessage(), [
                        'exception' => get_class($e),
                        'file'      => $e->getFile() . ':' . $e->getLine(),
                    ]);
                } else {
                    error_log('[mtphp] schedule: ' . $e->getMessage());
                }
            }
        }
        return $ran;
    }

    /**
     * 加载调度定义文件（$schedule 变量在该文件作用域内可用）
     */
    public function load(string $file): self
    {
        if (!is_file($file)) {
            return $this;
        }
        $schedule = $this;
        (static function (string $__file, Schedule $schedule): void {
            require $__file;
        })($file, $schedule);
        return $this;
    }

    private function appTimezone(): \DateTimeZone
    {
        $app = App::instance();
        $tz  = $app !== null ? (string) $app->config()->get('app.timezone', 'Asia/Shanghai') : 'Asia/Shanghai';
        try {
            return new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            return new \DateTimeZone('Asia/Shanghai');
        }
    }
}
