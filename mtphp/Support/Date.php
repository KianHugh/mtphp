<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 日期时间工具（零依赖，不引入 Carbon，纯包 DateTimeImmutable）
 *
 * 覆盖：格式化、人性化（“3 分钟前”）、差值、当天起止、区间判断、相对增减。
 *
 * 用法：
 *   Date::format(time());                 // 2026-10-15 10:42:00
 *   Date::humanize(strtotime('-3 minutes')); // 3分钟前
 *   Date::startOfDay(time());             // 当日 00:00:00 的时间戳
 *   Date::between($t, $start, $end);
 */
class Date
{
    /**
     * 把各种时间表示转成 DateTimeImmutable
     *
     * @param int|string|\DateTimeInterface $time
     */
    protected static function toDt(int|string|\DateTimeInterface $time, ?\DateTimeZone $tz = null): \DateTimeImmutable
    {
        if ($time instanceof \DateTimeInterface) {
            return new \DateTimeImmutable(
                $time->format('Y-m-d H:i:s.u'),
                $time->getTimezone()
            );
        }
        if (is_numeric($time)) {
            return (new \DateTimeImmutable('@' . (int) $time))
                ->setTimezone($tz ?? new \DateTimeZone(date_default_timezone_get()));
        }
        return new \DateTimeImmutable((string) $time, $tz);
    }

    /**
     * 把各种时间表示转成 Unix 时间戳
     *
     * @param int|string|\DateTimeInterface $time
     */
    public static function toTimestamp(int|string|\DateTimeInterface $time): int
    {
        if ($time instanceof \DateTimeInterface) {
            return $time->getTimestamp();
        }
        if (is_numeric($time)) {
            return (int) $time;
        }
        return (new \DateTimeImmutable((string) $time))->getTimestamp();
    }

    /**
     * 格式化时间
     *
     * @param int|string|\DateTimeInterface $time
     */
    public static function format(int|string|\DateTimeInterface $time, string $format = 'Y-m-d H:i:s'): string
    {
        return self::toDt($time)->format($format);
    }

    /**
     * 人性化相对时间（中文）：刚刚 / N分钟前 / N小时前 / N天前 / N周前
     *
     * @param int|string|\DateTimeInterface $time
     */
    public static function humanize(int|string|\DateTimeInterface $time, ?int $now = null, string $lang = 'zh'): string
    {
        $ts  = self::toTimestamp($time);
        $now = $now ?? time();
        $diff = $now - $ts;

        if ($diff < 0) {
            return self::format($time);
        }
        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return (int) ($diff / 60) . '分钟前';
        }
        if ($diff < 86400) {
            return (int) ($diff / 3600) . '小时前';
        }
        if ($diff < 604800) {
            return (int) ($diff / 86400) . '天前';
        }
        if ($diff < 2592000) {
            return (int) ($diff / 604800) . '周前';
        }
        return self::format($time, 'Y-m-d');
    }

    /**
     * 差值（秒/分/时/天），取绝对值
     *
     * @param int|string|\DateTimeInterface $a
     * @param int|string|\DateTimeInterface $b
     */
    public static function diffInSeconds(int|string|\DateTimeInterface $a, int|string|\DateTimeInterface $b): int
    {
        return abs(self::toTimestamp($a) - self::toTimestamp($b));
    }

    public static function diffInMinutes(int|string|\DateTimeInterface $a, int|string|\DateTimeInterface $b): int
    {
        return (int) (self::diffInSeconds($a, $b) / 60);
    }

    public static function diffInHours(int|string|\DateTimeInterface $a, int|string|\DateTimeInterface $b): int
    {
        return (int) (self::diffInSeconds($a, $b) / 3600);
    }

    public static function diffInDays(int|string|\DateTimeInterface $a, int|string|\DateTimeInterface $b): int
    {
        return (int) (self::diffInSeconds($a, $b) / 86400);
    }

    /**
     * 当日 00:00:00 的时间戳
     *
     * @param int|string|\DateTimeInterface $time
     */
    public static function startOfDay(int|string|\DateTimeInterface $time): int
    {
        return self::toDt($time)->setTime(0, 0, 0)->getTimestamp();
    }

    /**
     * 当日 23:59:59 的时间戳
     *
     * @param int|string|\DateTimeInterface $time
     */
    public static function endOfDay(int|string|\DateTimeInterface $time): int
    {
        return self::toDt($time)->setTime(23, 59, 59)->getTimestamp();
    }

    /**
     * 是否在 [start, end] 区间内（含端点）
     *
     * @param int|string|\DateTimeInterface $time
     * @param int|string|\DateTimeInterface $start
     * @param int|string|\DateTimeInterface $end
     */
    public static function between(int|string|\DateTimeInterface $time, int|string|\DateTimeInterface $start, int|string|\DateTimeInterface $end): bool
    {
        $ts = self::toTimestamp($time);
        return $ts >= self::toTimestamp($start) && $ts <= self::toTimestamp($end);
    }

    public static function isToday(int|string|\DateTimeInterface $time): bool
    {
        return self::toDt($time)->format('Y-m-d') === date('Y-m-d');
    }

    public static function isPast(int|string|\DateTimeInterface $time): bool
    {
        return self::toTimestamp($time) < time();
    }

    public static function isFuture(int|string|\DateTimeInterface $time): bool
    {
        return self::toTimestamp($time) > time();
    }

    /**
     * 相对当前时间增减（如 '+1 day'、'-2 hours'），返回新时间戳
     *
     * @param int|string|\DateTimeInterface $time
     */
    public static function add(int|string|\DateTimeInterface $time, string $modify): int
    {
        return self::toDt($time)->modify($modify)->getTimestamp();
    }
}
