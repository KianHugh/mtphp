<?php
declare(strict_types=1);

/**
 * 数据库驱动队列测试（零依赖，内存 SQLite）
 * 运行：php8 tests/run.php DatabaseQueueTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Queue\DatabaseQueue;
use Tests\SqliteConnector;

/**
 * 一个简单的“类任务”，handle 把数据写进静态数组，便于断言分发结果
 */
class DbQueueTestJob
{
    /** @var list<array<string,mixed>> */
    public static array $handled = [];

    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        self::$handled[] = $data;
    }
}

class DatabaseQueueTest extends TestCase
{
    private \PDO $pdo;

    private DatabaseQueue $queue;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->queue = new DatabaseQueue(new SqliteConnector($this->pdo));
        DbQueueTestJob::$handled = [];
    }

    public function testPushReturnsIdAndIncrementsSize(): void
    {
        $id = $this->queue->push(['hello' => 'world']);
        Assert::assertTrue($id !== '');
        Assert::assertSame(1, $this->queue->size());
    }

    public function testPopReturnsPayloadAndMarksReserved(): void
    {
        $this->queue->push(['k' => 'v']);
        $job = $this->queue->pop();
        Assert::assertTrue($job !== null);
        Assert::assertSame('v', $job['payload']['k']);
        // 已被认领（reserved_at 非空），不再计入待消费 size
        Assert::assertSame(0, $this->queue->size());
        Assert::assertTrue($this->queue->pop() === null);
    }

    public function testPopEmptyReturnsNull(): void
    {
        Assert::assertTrue($this->queue->pop() === null);
        Assert::assertSame(0, $this->queue->size());
    }

    public function testDelayedJobNotPoppedUntilAvailable(): void
    {
        $this->queue->push(['late' => 1], 'default', 60);
        Assert::assertSame(0, $this->queue->size());
        Assert::assertTrue($this->queue->pop() === null);
    }

    public function testWorkerProcessesUpToMaxAndDeletes(): void
    {
        $this->queue->push(['a' => 1]);
        $this->queue->push(['b' => 2]);
        $got = [];
        $this->queue->worker(function (array $job) use (&$got): void {
            $got[] = $job['payload'];
        }, 'default', ['max' => 1, 'sleep' => 0]);
        Assert::assertSame(1, count($got));
        Assert::assertSame(1, $got[0]['a']);
        Assert::assertSame(1, $this->queue->size()); // 还剩一个未处理
    }

    public function testWorkerOnceExitsAfterOne(): void
    {
        $this->queue->push(['x' => 1]);
        $this->queue->worker(fn () => null, 'default', ['once' => true, 'sleep' => 0]);
        Assert::assertSame(0, $this->queue->size()); // 取出并删除
    }

    public function testDispatchClassJob(): void
    {
        $this->queue->push(['class' => DbQueueTestJob::class, 'data' => ['id' => 42]]);
        $job = $this->queue->pop();
        Assert::assertTrue($job !== null);
        $ok = $this->queue->dispatch($job);
        Assert::assertTrue($ok);
        Assert::assertSame(1, count(DbQueueTestJob::$handled));
        Assert::assertSame(42, DbQueueTestJob::$handled[0]['id']);
    }

    public function testFailureIsReleasedForRetryWhenUnderMaxAttempts(): void
    {
        // maxAttempts=3, retryDelay=0 → 失败一次后应释放（reserved_at 清空，立即可再消费）
        $q = new DatabaseQueue(new SqliteConnector($this->pdo), 'jobs', 'failed_jobs', 3, 0);
        $q->push(['boom' => 1]);
        $q->worker(function (array $job): void {
            throw new \RuntimeException('故意失败');
        }, 'default', ['once' => true, 'sleep' => 0]);

        Assert::assertSame(1, $q->size());                 // 仍在队列、可重试
        Assert::assertSame(0, count($q->failedJobs()));     // 未进失败表

        $job2 = $q->pop();
        Assert::assertTrue($job2 !== null);
        Assert::assertSame(2, $job2['attempts']);           // 第二次认领 attempts=2
    }

    public function testFailureMovesToFailedTableAtMaxAttempts(): void
    {
        // maxAttempts=1 → 失败即入失败表
        $q = new DatabaseQueue(new SqliteConnector($this->pdo), 'jobs', 'failed_jobs', 1, 0);
        $q->push(['boom' => 1]);
        $q->worker(function (array $job): void {
            throw new \RuntimeException('故意失败');
        }, 'default', ['once' => true, 'sleep' => 0]);

        Assert::assertSame(0, $q->size());                  // 主表已空
        $failed = $q->failedJobs();
        Assert::assertSame(1, count($failed));               // 失败表有 1 条兜底
        Assert::assertSame('故意失败', $failed[0]['error']);
        Assert::assertSame(1, $failed[0]['payload']['boom']);
    }

    public function testStaleReservedJobIsReclaimedAfterTimeout(): void
    {
        // 默认 retryAfter=90s；把 reserved_at 设为 200s 前，模拟处理进程崩溃卡住
        $id = $this->queue->push(['task' => 'stale']);
        $this->pdo->exec(
            'UPDATE jobs SET reserved_at = ' . (time() - 200) . ' WHERE id = ' . $this->pdo->quote($id)
        );
        // 处于 reserved（即便是超时）不计入待消费 size
        Assert::assertSame(0, $this->queue->size());
        // pop() 应回收并重新认领（attempts 自 1 起，因为 pop 内 attempts+1）
        $job = $this->queue->pop();
        Assert::assertTrue($job !== null);
        Assert::assertSame('stale', $job['payload']['task']);
        Assert::assertSame(1, $job['attempts']);
    }

    public function testRecentlyReservedJobIsNotPrematurelyReclaimed(): void
    {
        // 刚被认领（reserved_at = now）的任务不应被误回收，避免处理中重复消费
        $this->queue->push(['task' => 'fresh']);
        $first = $this->queue->pop();
        Assert::assertTrue($first !== null);
        Assert::assertTrue($this->queue->pop() === null);
    }

    public function testClearEmptiesQueue(): void
    {
        $this->queue->push(['a' => 1]);
        $this->queue->push(['b' => 2], 'other');
        $this->queue->clear();          // 清空 default 队列
        $this->queue->clear('other');    // 清空 other 队列
        Assert::assertSame(0, $this->queue->size());
        Assert::assertSame(0, $this->queue->size('other'));
    }
}
