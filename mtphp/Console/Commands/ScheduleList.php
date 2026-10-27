<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;
use Mtphp\Schedule\Schedule;

/**
 * schedule:list —— 列出全部已登记的调度任务、cron 表达式、下次运行时间
 *
 * 便于核对调度定义是否符合预期，以及排查“为什么没触发”。
 */
class ScheduleList extends Command
{
    public function configure(): void
    {
        $this->name        = 'schedule:list';
        $this->description = '列出全部定时 / 重复任务及其下次运行时间';
    }

    public function handle(): int
    {
        $root     = $this->getRoot();
        $schedule = (new Schedule())->load($root . '/app/Routes/schedule.php');
        $events   = $schedule->events();

        if ($events === []) {
            $this->warn('未定义任何调度任务（缺少 app/Routes/schedule.php 或其中为空）。');
            return 0;
        }

        $tz = $this->appTimezone();

        $this->line('已登记任务（共 ' . count($events) . ' 个）：');
        $this->line(str_repeat('-', 70));
        foreach ($events as $event) {
            $next   = $event->nextRun($tz);
            $nextAt = $next !== null ? $next->format('Y-m-d H:i') : '—';
            $due    = $event->isDue($tz) ? '  <== 当前到期' : '';
            $detail = match ($event->getType()) {
                'command' => 'command ' . $event->getCommand(),
                'job'     => 'job@' . ($event->getQueueName() ?? 'default'),
                'event'   => 'event ' . $event->getEventName(),
                default   => 'call',
            };
            $this->line(
                '  ' . str_pad($event->getExpression(), 16) . '  '
                . str_pad($nextAt, 16) . '  ' . $detail . $due
            );
        }
        $this->line(str_repeat('-', 70));

        return 0;
    }

    private function appTimezone(): \DateTimeZone
    {
        $app = $this->application->getApp();
        $tz  = $app !== null ? (string) $app->config()->get('app.timezone', 'Asia/Shanghai') : 'Asia/Shanghai';
        try {
            return new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            return new \DateTimeZone('Asia/Shanghai');
        }
    }
}
