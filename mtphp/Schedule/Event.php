<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Schedule;

use Mtphp\Console\Application;
use Mtphp\Events\Dispatcher;
use Mtphp\Queue\QueueManager;

/**
 * 单个被调度任务（零依赖，思想借鉴主流框架的 Scheduler Event，独立实现）
 *
 * 支持四种任务体：
 *   - call     ：闭包 / 可调用（如清理缓存、汇总统计）
 *   - command  ：控制台命令（如 "queue:work --once"），由调度器经 Console\Application 执行
 *   - job      ：入队一个类任务 / 数组任务，交给队列消费者处理（定时 + 重复任务的队列集成）
 *   - event    ：派发一个框架事件
 *
 * 时间表达式（cron）通过 CronExpression 解析；并支持 when/skip 条件、withoutOverlapping 互斥。
 */
class Event
{
    public const TYPE_CALL    = 'call';
    public const TYPE_COMMAND = 'command';
    public const TYPE_JOB     = 'job';
    public const TYPE_EVENT   = 'event';

    private string $type;

    /** @var callable|null 仅 TYPE_CALL 使用 */
    private $callback;

    private string $command = '';

    /** @var list<string> */
    private array $commandArgs = [];

    /** @var mixed 仅 TYPE_JOB 使用（任意可 JSON 序列化值 / 类任务数组） */
    private $jobPayload;

    private string $jobQueue = 'default';

    private ?int $jobDelay = null;

    /** 仅 TYPE_EVENT 使用：事件类名字符串，或已构造好的事件对象 */
    private $eventName;

    private CronExpression $expression;

    private ?string $timezone = null;

    /** @var callable|null 仅当返回 true 才运行 */
    private $whenCallback = null;

    /** @var callable|null 返回 true 则跳过本次 */
    private $skipCallback = null;

    private bool $withoutOverlapping = false;

    /** 互斥窗口（分钟，默认 1440=24h），与主流框架一致 */
    private int $overlapTtl = 1440;

    private string $description = '';

    private ?string $name = null;

    /** 互斥锁目录（由 Schedule 注入；默认运行时目录 / 系统临时目录） */
    private string $mutexPath = '';

    /**
     * @param mixed $payload
     */
    public function __construct(string $type, $payload = null)
    {
        $this->type = $type;
        if ($type === self::TYPE_CALL) {
            $this->callback = $payload;
        } elseif ($type === self::TYPE_COMMAND) {
            $parts = is_string($payload) ? preg_split('/\s+/', trim($payload), -1, PREG_SPLIT_NO_EMPTY) : [];
            $this->command = (string) ($parts[0] ?? '');
            $this->commandArgs = array_slice($parts, 1);
        } elseif ($type === self::TYPE_JOB) {
            $this->jobPayload = $payload;
        } elseif ($type === self::TYPE_EVENT) {
            $this->eventName = $payload;
        }
        // 默认每分钟（调用方通常用 everyXxx / cron 覆盖）
        $this->expression = new CronExpression('* * * * *');
    }

    // ── 时间表达式（流式） ─────────────────────────────────────────────

    public function cron(string $expression): self
    {
        $this->expression = new CronExpression($expression);
        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron($minute . ' * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * 每天固定时刻运行，如 dailyAt('02:30')
     */
    public function dailyAt(string $time): self
    {
        $parts = explode(':', $time, 2);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);
        return $this->cron($m . ' ' . $h . ' * * *');
    }

    public function at(string $time): self
    {
        return $this->dailyAt($time);
    }

    public function twiceDaily(int $hour1 = 1, int $hour2 = 13): self
    {
        return $this->cron('0 ' . $hour1 . ',' . $hour2 . ' * * *');
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    /**
     * 仅工作日（周一至周五）运行
     */
    public function weekdays(): self
    {
        return $this->cron('* * * * 1-5');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function monthlyOn(int $day = 1, string $time = '0:0'): self
    {
        $parts = explode(':', $time, 2);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);
        return $this->cron($m . ' ' . $h . ' ' . $day . ' * *');
    }

    public function yearly(): self
    {
        return $this->cron('0 0 1 1 *');
    }

    // ── 条件与互斥 ─────────────────────────────────────────────────────

    public function timezone(string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function when(callable $callback): self
    {
        $this->whenCallback = $callback;
        return $this;
    }

    public function skip(callable $callback): self
    {
        $this->skipCallback = $callback;
        return $this;
    }

    /**
     * 防止同一任务重叠运行：在互斥窗口内只允许运行一次（基于文件锁，跨进程安全）。
     *
     * @param int $ttl 互斥窗口（分钟，默认 1440）
     */
    public function withoutOverlapping(int $ttl = 1440): self
    {
        $this->withoutOverlapping = true;
        $this->overlapTtl = max(1, $ttl);
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setMutexPath(string $path): self
    {
        $this->mutexPath = rtrim($path, '/\\');
        return $this;
    }

    /**
     * 设置 job 类型任务的入队目标（队列名 / 延迟秒）；仅 TYPE_JOB 有效
     */
    public function setJobTarget(string $queue, ?int $delay): self
    {
        if ($this->type === self::TYPE_JOB) {
            $this->jobQueue = $queue;
            $this->jobDelay = $delay;
        }
        return $this;
    }

    // ── 判定与执行 ─────────────────────────────────────────────────────

    public function getType(): string
    {
        return $this->type;
    }

    public function getExpression(): string
    {
        return $this->expression->expression();
    }

    public function getName(): string
    {
        return $this->name ?? $this->buildName();
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCommand(): string
    {
        return trim($this->command . ' ' . implode(' ', $this->commandArgs));
    }

    public function getQueueName(): string
    {
        return $this->jobQueue;
    }

    public function getEventName(): string
    {
        return is_object($this->eventName) ? get_class($this->eventName) : (string) $this->eventName;
    }

    /**
     * 计算下一次运行时间（供 schedule:list 展示）
     */
    public function nextRun(\DateTimeZone $appTz, \DateTimeInterface $after = null): ?\DateTime
    {
        $tz  = $this->timezone !== null ? new \DateTimeZone($this->timezone) : $appTz;
        $now = $after ?? new \DateTime('now', $tz);
        return $this->expression->nextRun($now, $tz);
    }

    /**
     * 是否“现在该运行”：命中 cron 且未被互斥 / 条件拦截
     */
    public function isDue(\DateTimeZone $appTz): bool
    {
        $tz  = $this->timezone !== null ? new \DateTimeZone($this->timezone) : $appTz;
        $now = new \DateTime('now', $tz);

        if (!$this->expression->isDue($now)) {
            return false;
        }
        if ($this->withoutOverlapping && $this->isOverlapping()) {
            return false;
        }
        if ($this->whenCallback !== null && !($this->whenCallback)()) {
            return false;
        }
        if ($this->skipCallback !== null && ($this->skipCallback)()) {
            return false;
        }
        return true;
    }

    /**
     * 执行任务体；返回任务体的返回值（command 类型返回退出码）
     *
     * @param Application|null $console 执行 command 类型任务所需的控制台实例
     */
    public function run(?Application $console = null)
    {
        if ($this->withoutOverlapping) {
            $this->createMutex();
        }

        switch ($this->type) {
            case self::TYPE_CALL:
                return ($this->callback)();

            case self::TYPE_COMMAND:
                if ($console === null) {
                    throw new \RuntimeException(
                        "调度任务 [{$this->getName()}] 是 command 类型，需要传入 Console\\Application 才能执行。"
                    );
                }
                // Console\Application::run 期望含“程序名”的完整 argv，首位为占位程序名
                return $console->run(array_merge(['mtphp'], [$this->command], $this->commandArgs));

            case self::TYPE_JOB:
                return QueueManager::resolve()->push($this->jobPayload, $this->jobQueue, $this->jobDelay);

            case self::TYPE_EVENT:
                $event = is_object($this->eventName) ? $this->eventName : new $this->eventName();
                return Dispatcher::instance()->dispatch($event);

            default:
                throw new \RuntimeException("未知调度任务类型：{$this->type}");
        }
    }

    // ── 互斥锁（文件锁，零依赖、跨进程） ───────────────────────────────

    private function buildName(): string
    {
        switch ($this->type) {
            case self::TYPE_COMMAND:
                return 'command:' . $this->getCommand();
            case self::TYPE_JOB:
                $label = is_array($this->jobPayload) && isset($this->jobPayload['class'])
                    ? $this->jobPayload['class']
                    : 'job';
                return 'job:' . $label . '@' . $this->jobQueue;
            case self::TYPE_EVENT:
                return 'event:' . $this->getEventName();
            default:
                return 'call:' . spl_object_hash((object) $this->callback);
        }
    }

    private function mutexFile(): string
    {
        $base = $this->mutexPath !== ''
            ? $this->mutexPath
            : (sys_get_temp_dir() . '/mtphp_schedule');
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base . '/' . md5($this->getName()) . '.lock';
    }

    private function isOverlapping(): bool
    {
        $file = $this->mutexFile();
        if (!is_file($file)) {
            return false;
        }
        $expire = (int) @file_get_contents($file);
        // 锁内容写入的是“到期时间戳”；未到期即视为仍在运行中
        return $expire > time();
    }

    private function createMutex(): void
    {
        $file = $this->mutexFile();
        @file_put_contents($file, (string) (time() + $this->overlapTtl * 60), LOCK_EX);
    }
}
