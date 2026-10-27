<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;
use Mtphp\Schedule\Schedule;

/**
 * schedule:work —— 内置常驻守护，自带每分钟循环触发调度（无需系统 crontab）
 *
 * 适合：没有系统 cron 权限的托管环境、开发机、容器（用 Supervisor 守护本进程即可）。
 * 选项：
 *   --interval=60   轮询间隔秒（默认 60，与 cron 语义一致）
 *
 * 注意：本进程本身需要被守护（Supervisor / systemd 的 Restart=always），
 * 框架只负责“每分钟触发调度”，不负责“进程保活”。
 */
class ScheduleWork extends Command
{
    public function configure(): void
    {
        $this->name        = 'schedule:work';
        $this->description = '常驻守护：每分钟自动触发到期任务（免系统 cron）';
    }

    public function handle(): int
    {
        $interval = (int) $this->option('interval', 60);
        if ($interval < 1) {
            $interval = 60;
        }
        $root     = $this->getRoot();
        $schedule = (new Schedule())->load($root . '/app/Routes/schedule.php');

        $this->info("调度守护已启动（每 {$interval}s 轮询一次，Ctrl+C 退出）。");

        while (true) {
            $ran = $schedule->runDue($this->application);
            foreach ($ran as $item) {
                $name = $item['event']->getName();
                if (isset($item['error'])) {
                    $this->error('✗ ' . $name . ' —— ' . $item['error']);
                } else {
                    $this->line('[run] ' . $name);
                }
            }
            sleep($interval);
        }

        return 0;
    }
}
