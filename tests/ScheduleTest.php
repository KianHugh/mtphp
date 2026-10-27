<?php
declare(strict_types=1);

/**
 * 任务调度器测试（零依赖）
 * 运行：php8 tests/run.php ScheduleTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Queue\QueueInterface;
use Mtphp\Console\Application;
use Mtphp\Console\Command;
use Mtphp\Schedule\Event;
use Mtphp\Schedule\Schedule;

/**
 * 记录被调用的“假队列”，用于断言 job 类型任务确实被推入队列
 */
class FakeScheduleQueue implements QueueInterface
{
    public static array $pushed = [];

    public function push(mixed $payload, string $queue = 'default', ?int $delay = null): string
    {
        self::$pushed[] = ['payload' => $payload, 'queue' => $queue, 'delay' => $delay];
        return uniqid('', true);
    }

    public function pop(string $queue = 'default'): ?array
    {
        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    public function clear(string $queue = 'default'): void
    {
    }

    public function failedJobs(string $queue = 'default'): array
    {
        return [];
    }
}

/**
 * 记录被执行的“假命令”，用于断言 command 类型任务经由 Console 执行
 */
class ScheduleDummyCommand extends Command
{
    public static array $calls = [];

    public function configure(): void
    {
        $this->name = 'dummy:ping';
        $this->description = '测试命令';
    }

    public function handle(): int
    {
        self::$calls[] = $this->option('x', 'none');
        return 0;
    }
}

class ScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        FakeScheduleQueue::$pushed = [];
        ScheduleDummyCommand::$calls = [];
    }

    protected function tearDown(): void
    {
        \Mtphp\Queue\QueueManager::reset();
    }

    public function testCallEventRunsCallback(): void
    {
        $got = [];
        $e = (new Event(Event::TYPE_CALL, function () use (&$got): void {
            $got[] = 'ran';
        }))->everyMinute();

        Assert::assertTrue($e->isDue(new \DateTimeZone('Asia/Shanghai')));
        $e->run();
        Assert::assertSame(1, count($got));
    }

    public function testEveryMinuteIsAlwaysDue(): void
    {
        $e = (new Event(Event::TYPE_CALL, fn () => null))->everyMinute();
        Assert::assertTrue($e->isDue(new \DateTimeZone('Asia/Shanghai')));
    }

    public function testWhenAndSkipConstraints(): void
    {
        $blocked = (new Event(Event::TYPE_CALL, fn () => null))
            ->everyMinute()->when(fn () => false);
        Assert::assertFalse($blocked->isDue(new \DateTimeZone('Asia/Shanghai')));

        $skipped = (new Event(Event::TYPE_CALL, fn () => null))
            ->everyMinute()->skip(fn () => true);
        Assert::assertFalse($skipped->isDue(new \DateTimeZone('Asia/Shanghai')));

        $allowed = (new Event(Event::TYPE_CALL, fn () => null))
            ->everyMinute()->when(fn () => true)->skip(fn () => false);
        Assert::assertTrue($allowed->isDue(new \DateTimeZone('Asia/Shanghai')));
    }

    public function testCommandEventRunsViaConsole(): void
    {
        $app = new Application();
        $app->add(new ScheduleDummyCommand());

        $e = (new Event(Event::TYPE_COMMAND, 'dummy:ping --x=1'))->everyMinute();
        $e->run($app);

        Assert::assertSame(1, count(ScheduleDummyCommand::$calls));
        Assert::assertSame('1', ScheduleDummyCommand::$calls[0]);
    }

    public function testCommandEventRequiresConsole(): void
    {
        $thrown = false;
        $e = (new Event(Event::TYPE_COMMAND, 'dummy:ping'))->everyMinute();
        try {
            $e->run(null);
        } catch (\RuntimeException $ex) {
            $thrown = true;
        }
        Assert::assertTrue($thrown);
    }

    public function testJobEventPushesToQueue(): void
    {
        \Mtphp\Queue\QueueManager::setInstance(new FakeScheduleQueue());

        $e = (new Event(Event::TYPE_JOB, ['class' => 'App\\Jobs\\X::class', 'data' => [1]]))
            ->everyMinute()->setJobTarget('emails', 30);
        $e->run();

        Assert::assertSame(1, count(FakeScheduleQueue::$pushed));
        Assert::assertSame('emails', FakeScheduleQueue::$pushed[0]['queue']);
        Assert::assertSame(30, FakeScheduleQueue::$pushed[0]['delay']);
        Assert::assertSame('App\\Jobs\\X::class', FakeScheduleQueue::$pushed[0]['payload']['class']);
    }

    public function testWithoutOverlappingPreventsDoubleRun(): void
    {
        $dir = sys_get_temp_dir() . '/mtphp_sched_test_' . uniqid('', true);
        $e = (new Event(Event::TYPE_CALL, fn () => null))
            ->everyMinute()->withoutOverlapping(10)->setMutexPath($dir);

        Assert::assertTrue($e->isDue(new \DateTimeZone('Asia/Shanghai')));
        $e->run(); // 第一次：写锁
        Assert::assertFalse($e->isDue(new \DateTimeZone('Asia/Shanghai'))); // 窗口内被互斥拦截

        // 清理锁目录
        foreach (glob($dir . '/*.lock') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function testScheduleDueEventsAndRunDue(): void
    {
        $schedule = new Schedule();
        $ran = [];
        $schedule->call(function () use (&$ran): void {
            $ran[] = 'a';
        })->everyMinute()->name('a');

        // skip 的任务不应被 runDue 执行
        $schedule->call(function () use (&$ran): void {
            $ran[] = 'b';
        })->everyMinute()->name('b')->skip(fn () => true);

        $due = $schedule->dueEvents(new \DateTimeZone('Asia/Shanghai'));
        Assert::assertSame(1, count($due));
        Assert::assertSame('a', $due[0]->getName());

        $results = $schedule->runDue();
        Assert::assertSame(1, count($results));
        Assert::assertSame(1, count($ran));
    }

    public function testDailyAtNextRunThroughEvent(): void
    {
        $e = (new Event(Event::TYPE_CALL, fn () => null))->dailyAt('02:00');
        $next = $e->nextRun(new \DateTimeZone('Asia/Shanghai'), new \DateTime('2024-01-01 08:00:00', new \DateTimeZone('Asia/Shanghai')));
        Assert::assertTrue($next !== null);
        Assert::assertSame('2024-01-02 02:00', $next->format('Y-m-d H:i'));
    }
}
