<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Schedule;

/**
 * 标准 5 段 cron 表达式解析（零依赖，思想借鉴主流框架的 Scheduler，独立实现）
 *
 * 表达式：分 时 日 月 周
 *   ┌───────────── 分钟 (0-59)
 *   │ ┌─────────── 小时 (0-23)
 *   │ │ ┌───────── 日 (1-31)
 *   │ │ │ ┌─────── 月 (1-12 或 JAN-DEC)
 *   │ │ │ │ ────── 周 (0-7，0 与 7 均为周日；或 SUN-SAT)
 *   │ │ │ │ │
 *   * * * * *
 *
 * 段内支持：
 *   *        任意值
 *   "每N个"  步长（如 "每5分钟" 表示 /5；a-b/N 在范围内按步长）
 *   a-b      范围（含端点）
 *   a,b,c    枚举列表（可与范围混合，逗号分隔）
 *   单值      精确匹配
 *
 * 日与周的关系遵循 Vixie cron 规则：两者都非 * 时为“或（OR）”关系。
 */
class CronExpression
{
    /** @var list<string> [minute, hour, dayOfMonth, month, dayOfWeek] */
    private array $fields;

    /** 月份名 → 数字 */
    private const MONTHS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    /** 星期名 → 数字（0=周日） */
    private const WEEKDAYS = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) !== 5) {
            throw new \InvalidArgumentException(
                "Cron 表达式必须是 5 段（分 时 日 月 周），收到：" . $expression
            );
        }
        // 把月 / 周字段中的英文缩写规范为数字
        $parts[3] = $this->normalizeNames($parts[3], self::MONTHS);
        $parts[4] = $this->normalizeNames($parts[4], self::WEEKDAYS);
        $this->fields = $parts;
    }

    /**
     * 判断给定时间（已处于正确时区）是否命中本表达式
     */
    public function isDue(\DateTimeInterface $date): bool
    {
        $minute = (int) $date->format('i');
        $hour   = (int) $date->format('G');
        $day    = (int) $date->format('j');
        $month  = (int) $date->format('n');
        $dow    = (int) $date->format('w'); // 0=周日

        if (!$this->matchesField($this->fields[0], $minute, 0, 59)) {
            return false;
        }
        if (!$this->matchesField($this->fields[1], $hour, 0, 23)) {
            return false;
        }
        if (!$this->matchesField($this->fields[3], $month, 1, 12)) {
            return false;
        }

        $domRestricted = $this->fields[2] !== '*';
        $dowRestricted = $this->fields[4] !== '*';

        $dom = $this->matchesField($this->fields[2], $day, 1, 31);
        // 周日可用 0 或 7 表达，所以同时用 w 与 (w===0?7:w) 试匹配
        $dow = $this->matchesField($this->fields[4], $dow, 0, 7)
            || $this->matchesField($this->fields[4], $dow === 0 ? 7 : $dow, 0, 7);

        if ($domRestricted && $dowRestricted) {
            return $dom || $dow;
        }
        if ($domRestricted) {
            return $dom;
        }
        if ($dowRestricted) {
            return $dow;
        }
        return true;
    }

    /**
     * 计算 $after 之后的下一次命中时间（按分钟步进，最多向前推 4 年）
     *
     * @return \DateTime|null 无命中（理论上不会发生）返回 null
     */
    public function nextRun(\DateTimeInterface $after, ?\DateTimeZone $tz = null): ?\DateTime
    {
        $next = ($after instanceof \DateTime)
            ? clone $after
            : new \DateTime($after->format('Y-m-d H:i:s'), $after->getTimezone());
        $next->setTimezone($tz ?? $after->getTimezone());
        $next->setTime((int) $next->format('G'), (int) $next->format('i'), 0);

        // 从下一分钟开始找（避免把“当前这一分钟”算作 next）
        $next->modify('+1 minute');

        $limit = clone $next;
        $limit->modify('+4 years');

        while ($next <= $limit) {
            if ($this->isDue($next)) {
                return $next;
            }
            $next->modify('+1 minute');
        }
        return null;
    }

    public function expression(): string
    {
        return implode(' ', $this->fields);
    }

    /**
     * 段匹配：支持 星号、步长(每N)、范围(a-b)、列表(a,b) 与单值
     */
    private function matchesField(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*' || $field === '') {
            return true;
        }
        foreach (explode(',', $field) as $item) {
            if ($item === '') {
                continue;
            }
            if (str_contains($item, '/')) {
                [$range, $stepStr] = explode('/', $item, 2);
                $step = (int) $stepStr;
                if ($step <= 0) {
                    continue;
                }
                if ($range === '*') {
                    $start = $min;
                    $end   = $max;
                } elseif (str_contains($range, '-')) {
                    [$a, $b] = explode('-', $range, 2);
                    $start = (int) $a;
                    $end   = (int) $b;
                } else {
                    $start = (int) $range;
                    $end   = $max;
                }
                if ($value >= $start && $value <= $end && (($value - $start) % $step) === 0) {
                    return true;
                }
            } elseif (str_contains($item, '-')) {
                [$a, $b] = explode('-', $item, 2);
                if ($value >= (int) $a && $value <= (int) $b) {
                    return true;
                }
            } elseif ($value === (int) $item) {
                return true;
            }
        }
        return false;
    }

    /**
     * 把月 / 周字段里的英文缩写替换成数字（大小写不敏感）
     */
    private function normalizeNames(string $field, array $map): string
    {
        if (!preg_match('/[A-Za-z]/', $field)) {
            return $field;
        }
        $out = [];
        foreach (explode(',', $field) as $item) {
            if (str_contains($item, '-')) {
                [$a, $b] = explode('-', $item, 2);
                $out[] = ($map[strtoupper($a)] ?? $a) . '-' . ($map[strtoupper($b)] ?? $b);
            } else {
                $out[] = (string) ($map[strtoupper($item)] ?? $item);
            }
        }
        return implode(',', $out);
    }
}
