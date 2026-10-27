<?php
declare(strict_types=1);

/**
 * 队列（文件驱动）测试（零依赖）
 * 运行：php8 tests/run.php QueueTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Queue\Queue;

/**
 * 一个简单的“类任务”，handle 把数据写进静态数组，便于断言分发结果
 */
class QueueTestJob
{
    /** @var list<array<string,mixed>> */
    public static array $handled = [];

    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        self::$handled[] = $data;
    }
}

class QueueTest extends TestCase
{
    private string $dir;

    private Queue $queue;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/mtphp_queue_test_' . uniqid('', true);
        $this->queue = new Queue($this->dir);
        QueueTestJob::$handled = [];
    }

    protected function tearDown(): void
    {
        $this->queue->clear();
        $failed = $this->dir . '/default/failed';
        if (is_dir($failed)) {
            foreach (glob($failed . '/*.json') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($failed);
        }
        @rmdir($this->dir . '/default');
        @rmdir($this->dir);
    }

    public function testPushReturnsIdAndIncrementsSize(): void
    {
        $id = $this->queue->push(['hello' => 'world']);
        Assert::assertTrue($id !== '');
        Assert::assertSame(1, $this->queue->size());
    }

    public function testPopReturnsPayloadAndRemovesFile(): void
    {
        $this->queue->push(['k' => 'v']);
        $job = $this->queue->pop();
        Assert::assertTrue($job !== null);
        Assert::assertSame('v', $job['payload']['k']);
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
        // 延迟 60 秒，现在不应被 pop 出，也不计入待消费 size
        $this->queue->push(['late' => 1], 'default', 60);
        Assert::assertSame(0, $this->queue->size());
        Assert::assertTrue($this->queue->pop() === null);
    }

    public function testWorkerProcessesUpToMax(): void
    {
        $this->queue->push(['a' => 1]);
        $this->queue->push(['b' => 2]);
        $got = [];
        $this->queue->worker(function (array $job) use (&$got): void {
            $got[] = $job['payload'];
        }, 'default', ['max' => 1, 'sleep' => 0]);
        Assert::assertSame(1, count($got));
        Assert::assertSame(1, $got[0]['a']);
        Assert::assertSame(1, $this->queue->size()); // 还剩一个
    }

    public function testWorkerOnceExitsAfterOne(): void
    {
        $this->queue->push(['x' => 1]);
        $this->queue->worker(fn () => null, 'default', ['once' => true, 'sleep' => 0]);
        Assert::assertSame(0, $this->queue->size()); // 取出即删
    }

    public function testDispatchClassJob(): void
    {
        $this->queue->push(['class' => QueueTestJob::class, 'data' => ['id' => 42]]);
        $job = $this->queue->pop();
        $ok  = $this->queue->dispatch($job);
        Assert::assertTrue($ok);
        Assert::assertSame(1, count(QueueTestJob::$handled));
        Assert::assertSame(42, QueueTestJob::$handled[0]['id']);
    }

    public function testDispatchNonClassReturnsFalse(): void
    {
        Assert::assertFalse($this->queue->dispatch(['payload' => 'just a string']));
        Assert::assertFalse($this->queue->dispatch(['payload' => ['no_class' => 1]]));
    }

    public function testWorkerMovesFailedJobToFailedDir(): void
    {
        $this->queue->push(['boom' => 1]);
        $this->queue->worker(function (array $job): void {
            throw new \RuntimeException('故意失败');
        }, 'default', ['max' => 1, 'sleep' => 0]);

        Assert::assertSame(0, $this->queue->size());          // 主队列已空
        Assert::assertSame(1, count($this->queue->failedJobs())); // 失败队列有 1 条兜底
    }
}
