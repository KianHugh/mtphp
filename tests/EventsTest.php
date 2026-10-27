<?php
declare(strict_types=1);

/**
 * 事件分发器测试
 * 运行：php8 tests/run.php EventsTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Events\Dispatcher;

class OrderPaid
{
    public int $amount;

    public function __construct(int $amount)
    {
        $this->amount = $amount;
    }
}

class EventsTest extends TestCase
{
    public function testClosureListenerReceivesEvent(): void
    {
        $d = new Dispatcher();
        $captured = null;
        $d->listen(OrderPaid::class, function (OrderPaid $e) use (&$captured) {
            $captured = $e;
        });
        $event = $d->dispatch(new OrderPaid(99));
        Assert::assertNotNull($captured);
        Assert::assertSame(99, $captured->amount);
        Assert::assertSame(99, $event->amount);
    }

    public function testListenerCanMutateEvent(): void
    {
        $d = new Dispatcher();
        $d->listen(OrderPaid::class, function (OrderPaid $e) {
            $e->amount += 1;
        });
        $event = $d->dispatch(new OrderPaid(10));
        Assert::assertSame(11, $event->amount);
    }

    public function testClassListenerViaContainer(): void
    {
        // 类监听器：[类名, 方法]，经容器解析实例（此处无 App，直接 new）
        $d = new Dispatcher();
        $flag = new \stdClass();
        $flag->hit = false;
        $d->listen(OrderPaid::class, [EventsTestListener::class, 'handle']);
        $d->dispatch(new OrderPaid(5));
        Assert::assertTrue(EventsTestListener::$hit);
    }

    public function testForgetRemovesListeners(): void
    {
        $d = new Dispatcher();
        $hit = false;
        $d->listen(OrderPaid::class, function () use (&$hit) {
            $hit = true;
        });
        $d->forget(OrderPaid::class);
        $d->dispatch(new OrderPaid(1));
        Assert::assertFalse($hit);
    }
}

class EventsTestListener
{
    public static bool $hit = false;

    public function handle(OrderPaid $e): void
    {
        EventsTestListener::$hit = true;
    }
}
