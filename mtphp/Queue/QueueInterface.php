<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Queue;

/**
 * 队列契约（思想借鉴主流框架的 Queue 组件，独立实现）
 *
 * 文件驱动（Queue）与数据库驱动（DatabaseQueue）都实现本接口，
 * 上层通过 QueueManager::resolve() 按 config('queue.driver') 透明切换。
 */
interface QueueInterface
{
    /**
     * 入队一个任务，返回任务 id
     *
     * @param mixed $payload 任意可 JSON 序列化的值；类任务用 ['class'=>类名,'data'=>任意]
     * @param string $queue  队列名
     * @param ?int   $delay  延迟秒数（null = 立即可消费）
     */
    public function push(mixed $payload, string $queue = 'default', ?int $delay = null): string;

    /**
     * 出队一个“当前可消费”的任务；无可消费任务时返回 null
     */
    public function pop(string $queue = 'default'): ?array;

    /**
     * 队列中“待消费”任务数（不含已延迟未到点的）
     */
    public function size(string $queue = 'default'): int;

    /**
     * 清空某队列的全部任务
     */
    public function clear(string $queue = 'default'): void;

    /**
     * 列出某队列的失败任务
     *
     * @return list<array<string,mixed>>
     */
    public function failedJobs(string $queue = 'default'): array;
}
