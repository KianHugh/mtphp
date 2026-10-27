<?php
declare(strict_types=1);

/**
 * Cron 表达式解析器测试（零依赖）
 * 运行：php8 tests/run.php CronExpressionTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Schedule\CronExpression;

class CronExpressionTest extends TestCase
{
    private function dt(string $ymdHis): \DateTime
    {
        return new \DateTime($ymdHis, new \DateTimeZone('Asia/Shanghai'));
    }

    public function testAlwaysDue(): void
    {
        $c = new CronExpression('* * * * *');
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 10:03:00')));
    }

    public function testDailyMidnight(): void
    {
        $c = new CronExpression('0 0 * * *');
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 00:00:00')));
        Assert::assertFalse($c->isDue($this->dt('2024-01-01 00:01:00')));
        Assert::assertFalse($c->isDue($this->dt('2024-01-01 01:00:00')));
    }

    public function testEveryFiveMinutes(): void
    {
        $c = new CronExpression('*/5 * * * *');
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 10:00:00')));
        Assert::assertFalse($c->isDue($this->dt('2024-01-01 10:03:00')));
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 10:05:00')));
    }

    public function testWeekdaysOnly(): void
    {
        // 2024-01-01 周一，2024-01-06 周六，2024-01-07 周日
        $c = new CronExpression('15 10 * * 1-5');
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 10:15:00'))); // 周一
        Assert::assertTrue($c->isDue($this->dt('2024-01-02 10:15:00'))); // 周二
        Assert::assertFalse($c->isDue($this->dt('2024-01-06 10:15:00'))); // 周六
        Assert::assertFalse($c->isDue($this->dt('2024-01-07 10:15:00'))); // 周日
        Assert::assertFalse($c->isDue($this->dt('2024-01-01 10:16:00'))); // 分钟不符
    }

    public function testListOfHours(): void
    {
        $c = new CronExpression('0 2,14 * * *');
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 02:00:00')));
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 14:00:00')));
        Assert::assertFalse($c->isDue($this->dt('2024-01-01 03:00:00')));
    }

    public function testMonthly(): void
    {
        $c = new CronExpression('0 0 1 * *');
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 00:00:00')));
        Assert::assertFalse($c->isDue($this->dt('2024-01-02 00:00:00')));
    }

    public function testSundayWithZeroAndSeven(): void
    {
        // 2024-01-07 周日
        $c = new CronExpression('0 0 * * 0');
        Assert::assertTrue($c->isDue($this->dt('2024-01-07 00:00:00')));
        Assert::assertFalse($c->isDue($this->dt('2024-01-08 00:00:00')));

        $c7 = new CronExpression('0 0 * * 7');
        Assert::assertTrue($c7->isDue($this->dt('2024-01-07 00:00:00')));
    }

    public function testDayOfMonthOrDayOfWeek(): void
    {
        // 1 号 或 周一 才触发
        $c = new CronExpression('0 0 1 * 1');
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 00:00:00'))); // 周一且 1 号
        Assert::assertTrue($c->isDue($this->dt('2024-01-08 00:00:00'))); // 周一，非 1 号
        Assert::assertFalse($c->isDue($this->dt('2024-01-02 00:00:00'))); // 周二，非 1 号
        Assert::assertFalse($c->isDue($this->dt('2024-01-03 00:00:00'))); // 周三，非 1 号非周一
    }

    public function testNamedMonthAndWeekday(): void
    {
        $c = new CronExpression('0 0 1 JAN *');
        Assert::assertTrue($c->isDue($this->dt('2024-01-01 00:00:00')));
        Assert::assertFalse($c->isDue($this->dt('2024-02-01 00:00:00')));

        $w = new CronExpression('0 0 * * MON');
        Assert::assertTrue($w->isDue($this->dt('2024-01-01 00:00:00'))); // 周一
        Assert::assertFalse($w->isDue($this->dt('2024-01-02 00:00:00'))); // 周二
    }

    public function testInvalidExpressionThrows(): void
    {
        $thrown = false;
        try {
            new CronExpression('* * *'); // 仅 3 段
        } catch (\InvalidArgumentException $e) {
            $thrown = true;
        }
        Assert::assertTrue($thrown);
    }

    public function testNextRunComputesUpcoming(): void
    {
        $c = new CronExpression('0 0 * * *'); // 每日 00:00
        $next = $c->nextRun($this->dt('2024-01-01 10:00:00'));
        Assert::assertTrue($next !== null);
        Assert::assertSame('2024-01-02 00:00', $next->format('Y-m-d H:i'));
    }

    public function testNextRunDailyAt(): void
    {
        $c = new CronExpression('30 9 * * *'); // 每日 09:30
        $next = $c->nextRun($this->dt('2024-01-01 08:00:00'));
        Assert::assertSame('2024-01-01 09:30', $next->format('Y-m-d H:i'));
        $next2 = $c->nextRun($this->dt('2024-01-01 10:00:00'));
        Assert::assertSame('2024-01-02 09:30', $next2->format('Y-m-d H:i'));
    }
}
