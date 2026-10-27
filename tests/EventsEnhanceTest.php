<?php
declare(strict_types=1);

/**
 * 事件分发器增强测试（优先级 / 通配）
 * 运行：php tests/run.php EventsEnhanceTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Events\Dispatcher;

class EvtOrderPaid
{
    public int $amount;
    public function __construct(int $amount)
    {
        $this->amount = $amount;
    }
}

class EventsEnhanceTest extends TestCase
{
    public function testPriorityOrdering(): void
    {
        $d = new Dispatcher();
        $order = [];
        $d->listen(EvtOrderPaid::class, static function () use (&$order): void {
            $order[] = 'low';
        }, 0);
        $d->listen(EvtOrderPaid::class, static function () use (&$order): void {
            $order[] = 'high';
        }, 10);
        $d->listen(EvtOrderPaid::class, static function () use (&$order): void {
            $order[] = 'mid';
        }, 5);

        $d->dispatch(new EvtOrderPaid(100));
        Assert::assertSame(['high', 'mid', 'low'], $order);
    }

    public function testGlobalWildcardFires(): void
    {
        $d = new Dispatcher();
        $hit = false;
        $d->listen('*', static function () use (&$hit): void {
            $hit = true;
        });
        $d->dispatch(new EvtOrderPaid(1));
        Assert::assertTrue($hit);
    }

    public function testNamespaceWildcardMatchesPrefix(): void
    {
        $d = new Dispatcher();
        $hit = false;
        $d->listen('App\Events\*', static function () use (&$hit): void {
            $hit = true;
        });
        // 事件类不以 App\Events\ 开头 → 不应触发
        $d->dispatch(new EvtOrderPaid(1));
        Assert::assertFalse($hit);

        // 用一个命名空间前缀匹配的匿名类事件
        $evt = new class {
        };
        $ns = 'App\\Events\\X';
        // 用实际位于该命名空间的事件类验证
        $matched = false;
        $d2 = new Dispatcher();
        $d2->listen('App\Events\*', static function () use (&$matched): void {
            $matched = true;
        });
        // 通过 eval 生成一个位于 App\Events 下的事件类（仅测试用）
        eval('namespace App\\Events; class WildEvent {}');
        $d2->dispatch(new \App\Events\WildEvent());
        Assert::assertTrue($matched);
    }
}
