<?php
/**
 * 任务调度定义（由 schedule:run / schedule:work 加载）
 *
 * 在 $schedule 上登记“定时 / 重复任务”。支持四类任务体：
 *   - call     闭包 / 可调用（如清理缓存、汇总统计）
 *   - command  控制台命令（如 queue:work --once）
 *   - job      入队一个类任务 / 数组任务，交给 queue:work 消费
 *   - event    派发一个框架事件（类名字符串或已构造对象）
 *
 * 运行方式（二选一）：
 *   1) 系统 crontab 每分钟触发：* * * * * php bin/mtphp schedule:run
 *   2) 框架内置守护：php bin/mtphp schedule:work（常驻循环，免系统 cron）
 */

use Mtphp\Schedule\Schedule;

// 每天凌晨 2 点清理一次应用缓存
$schedule->call(function (): void {
    cache()->clear();
})->dailyAt('02:00')->name('clear-cache')->description('每天凌晨清理应用缓存');

// 每 5 分钟消费一批队列任务（用 --once 单次触发，避免常驻；也可另跑 queue:work）
$schedule->command('queue:work --once --queue=default --sleep=1')
    ->everyFiveMinutes()
    ->name('queue-tick');

// 每天 3 点生成日报（以类任务形式入队，由 queue:work 实际执行，便于异步/重试）
$schedule->job(['class' => App\Jobs\DailyReport::class, 'data' => []])
    ->dailyAt('03:00')
    ->name('daily-report')
    ->description('每日日报（入队，异步执行）');

// 工作日（周一至周五）上午 9:30 提醒
$schedule->call(function (): void {
    // 业务提醒逻辑……
})->cron('30 9 * * 1-5')->name('workday-reminder');
