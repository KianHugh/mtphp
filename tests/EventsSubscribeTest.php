<?php
declare(strict_types=1);

/**
 * 事件增强测试：subscribe（批注册/订阅者）/ until（短路）/ flush（清空）
 * 运行：php8 tests/run.php EventsSubscribeTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Events\Dispatcher;

class EvtPing
{
    public int $value = 0;
}

class EvtSub
{
    public static array $log = [];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EvtPing::class, static function (EvtPing $e): void {
            EvtSub::$log[] = 'ping:' . $e->value;
        });
    }
}

class EventsSubscribeTest extends TestCase
{
    public function testSubscribeArray(): void
    {
        $d = new Dispatcher();
        $hits = [];
        $d->subscribe([
            EvtPing::class => static function (EvtPing $e) use (&$hits): void {
                $hits[] = $e->value;
            },
        ]);
        $d->dispatch(new EvtPing()); // value 默认 0
        Assert::assertSame([0], $hits);
    }

    public function testSubscribeSubscriberObject(): void
    {
        $d = new Dispatcher();
        EvtSub::$log = [];
        $d->subscribe(new EvtSub());
        $e = new EvtPing();
        $e->value = 5;
        $d->dispatch($e);
        Assert::assertSame(['ping:5'], EvtSub::$log);
    }

    public function testUntilStopsAtFirstNonNull(): void
    {
        $d = new Dispatcher();
        // 高优先级返回 null，低优先级返回 'b' → until 应返回 'b' 且不再继续
        $order = [];
        $d->listen(EvtPing::class, static function (EvtPing $e) use (&$order): ?string {
            $order[] = 'a';
            return null;
        }, 10);
        $d->listen(EvtPing::class, static function (EvtPing $e) use (&$order): ?string {
            $order[] = 'b';
            return 'b';
        }, 0);

        $result = $d->until(new EvtPing());
        Assert::assertSame('b', $result);
        Assert::assertSame(['a', 'b'], $order); // 'b' 之后不应再有监听器
    }

    public function testUntilReturnsNullWhenAllNull(): void
    {
        $d = new Dispatcher();
        $d->listen(EvtPing::class, static function (): ?string {
            return null;
        });
        Assert::assertNull($d->until(new EvtPing()));
    }

    public function testFlushClearsListeners(): void
    {
        $d = new Dispatcher();
        $hit = false;
        $d->listen(EvtPing::class, static function () use (&$hit): void {
            $hit = true;
        });
        $d->flush();
        $d->dispatch(new EvtPing());
        Assert::assertFalse($hit); // 清空后不再触发
    }
}
