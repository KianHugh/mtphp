<?php
declare(strict_types=1);

/**
 * 极简断言助手（零依赖，框架自带）
 *
 * 设计原则（呼应“使用简单、配置简单”）：
 *   - 不依赖 PHPUnit，任何 PHP 8 环境 `php8 tests/run.php` 即可跑；
 *   - 每个断言失败即抛 RuntimeException，由 tests/run.php 统一捕获并汇总；
 *   - 内置计数器，便于运行器统计“断言数”。
 *
 * 用法（在 TestCase 的 test* 方法里）：
 *   Assert::same(1, $id);
 *   Assert::true($cache->has('k'));
 *   Assert::contains($html, 'mtphp 站点');
 *   Assert::exception(fn () => riskyCall());
 */
namespace Mtphp\Testing;

final class Assert
{
    /** 已通过的断言计数（运行器在每个用例前 reset） */
    private static int $count = 0;

    /**
     * 读取并清空计数（运行器在每个用例开始时调用）
     */
    public static function reset(): void
    {
        self::$count = 0;
    }

    /**
     * 当前已通过的断言数
     */
    public static function count(): int
    {
        return self::$count;
    }

    /**
     * 期望为真（truthy）。失败抛异常。
     */
    public static function assertTrue(mixed $cond, string $msg = ''): void
    {
        self::$count++;
        if (!$cond) {
            self::fail($msg ?: '期望为真（truthy），实际为假');
        }
    }

    /**
     * 期望为假（falsy）。
     */
    public static function assertFalse(mixed $cond, string $msg = ''): void
    {
        self::$count++;
        if ($cond) {
            self::fail($msg ?: '期望为假（falsy），实际为真');
        }
    }

    /**
     * 期望完全相等（类型 + 值，等价于 ===）。
     */
    public static function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::$count++;
        if ($expected !== $actual) {
            self::fail($msg ?: sprintf('assertSame 失败：期望 %s，实际 %s', self::desc($expected), self::desc($actual)));
        }
    }

    /**
     * 期望松散相等（仅值相等，等价于 ==）。
     */
    public static function assertEquals(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::$count++;
        if ($expected != $actual) {
            self::fail($msg ?: sprintf('assertEquals 失败：期望 %s，实际 %s', self::desc($expected), self::desc($actual)));
        }
    }

    /**
     * 期望为 null。
     */
    public static function assertNull(mixed $actual, string $msg = ''): void
    {
        self::$count++;
        if ($actual !== null) {
            self::fail($msg ?: sprintf('期望为 null，实际 %s', self::desc($actual)));
        }
    }

    /**
     * 期望非 null。
     */
    public static function assertNotNull(mixed $actual, string $msg = ''): void
    {
        self::$count++;
        if ($actual === null) {
            self::fail($msg ?: '期望非 null，实际为 null');
        }
    }

    /**
     * 期望 $haystack 包含子串 $needle。
     */
    public static function assertContains(string $haystack, string $needle, string $msg = ''): void
    {
        self::$count++;
        if (!str_contains($haystack, $needle)) {
            self::fail($msg ?: sprintf('assertContains 失败：未找到子串 "%s"', $needle));
        }
    }

    /**
     * 期望 $haystack 不包含子串 $needle。
     */
    public static function assertNotContains(string $haystack, string $needle, string $msg = ''): void
    {
        self::$count++;
        if (str_contains($haystack, $needle)) {
            self::fail($msg ?: sprintf('assertNotContains 失败：不应包含子串 "%s"', $needle));
        }
    }

    /**
     * 期望 $actual 严格大于 $expected。
     */
    public static function assertGreaterThan(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::$count++;
        if (!(($actual) > $expected)) {
            self::fail($msg ?: sprintf('assertGreaterThan 失败：%s 不大于 %s', self::desc($actual), self::desc($expected)));
        }
    }

    /**
     * 期望 $actual 严格小于 $expected。
     */
    public static function assertLessThan(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::$count++;
        if (!(($actual) < $expected)) {
            self::fail($msg ?: sprintf('assertLessThan 失败：%s 不小于 %s', self::desc($actual), self::desc($expected)));
        }
    }

    /**
     * 期望 $callback 执行时抛出异常（默认任意 Throwable）。
     *
     * @param callable $callback  待测闭包
     * @param string   $expected  期望抛出的异常类名（默认 \Throwable，可传具体类如 \RuntimeException）
     */
    public static function assertException(callable $callback, string $expected = \Throwable::class, string $msg = ''): void
    {
        self::$count++;
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $expected) {
                self::fail($msg ?: sprintf('异常类型不符：期望 %s，实际 %s', $expected, get_class($e)));
            }
            return; // 抛出了期望的异常 → 通过
        }
        self::fail($msg ?: sprintf('期望抛出异常（%s）但未抛出', $expected));
    }

    /**
     * 统一失败出口：抛 RuntimeException，携带可读信息。
     */
    private static function fail(string $msg): void
    {
        throw new \RuntimeException('断言失败: ' . $msg);
    }

    /**
     * 把任意值转成简短可读描述（用于断言错误信息）。
     */
    private static function desc(mixed $v): string
    {
        if (is_null($v)) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_string($v)) {
            $s = mb_strlen($v) > 50 ? mb_substr($v, 0, 50) . '…' : $v;
            return '"' . $s . '"';
        }
        if (is_array($v)) {
            return 'array(' . count($v) . ')';
        }
        if (is_object($v)) {
            return get_class($v);
        }
        return (string) $v;
    }
}
