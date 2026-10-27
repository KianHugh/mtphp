<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;
use Mtphp\Schedule\Schedule;

/**
 * schedule:run —— 执行所有“当前到期”的调度任务
 *
 * 通常由系统 crontab 每分钟触发一次：
 *   * * * * * php /var/www/mtphp/bin/mtphp schedule:run >> /var/www/mtphp/runtime/logs/schedule.log 2>&1
 *
 * 调度定义来自 app/Routes/schedule.php（由 Schedule 加载，文件不存在则无任务可跑）。
 * 单个任务失败会被捕获并记日志，不影响其余任务。
 */
class ScheduleRun extends Command
{
    public function configure(): void
    {
        $this->name        = 'schedule:run';
        $this->description = '执行所有到期的定时 / 重复任务（配合系统 cron 每分钟一次）';
    }

    public function handle(): int
    {
        $root     = $this->getRoot();
        $schedule = (new Schedule())->load($root . '/app/Routes/schedule.php');

        $events = $schedule->events();
        if ($events === []) {
            $this->warn('未定义任何调度任务（缺少 app/Routes/schedule.php 或其中为空）。');
            return 0;
        }

        $ran = $schedule->runDue($this->application);

        if ($ran === []) {
            $this->line('[schedule] 本次无到期任务。');
        }
        foreach ($ran as $item) {
            $name = $item['event']->getName();
            if (isset($item['error'])) {
                $this->error("✗ {$name} —— {$item['error']}");
            } else {
                $this->info("✓ {$name}");
            }
        }

        return 0;
    }
}
