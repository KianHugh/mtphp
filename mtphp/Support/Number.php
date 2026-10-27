<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 数字 / 金额工具（零依赖）
 *
 * 覆盖：千分位、货币、百分比、安全除法、中文单位（万/亿）、中文大写金额。
 *
 * 用法：
 *   Number::thousands(1234567.891);              // 1,234,567.89
 *   Number::currency(1234.5);                    // ¥1,234.50
 *   Number::percent(0.1234);                     // 12.34%
 *   Number::wan(123456789);                      // 1.23亿
 *   Number::rmbUpperCase(1234.56);               // 壹仟贰佰叁拾肆圆伍角陆分
 */
class Number
{
    /**
     * 千分位格式化
     */
    public static function thousands(float|int $value, int $decimals = 2, string $decPoint = '.', string $thousandsSep = ','): string
    {
        return number_format((float) $value, $decimals, $decPoint, $thousandsSep);
    }

    /**
     * 货币格式化（默认 ¥ 前缀）
     */
    public static function currency(float|int $value, string $symbol = '¥', int $decimals = 2): string
    {
        return $symbol . self::thousands($value, $decimals);
    }

    /**
     * 百分比（默认入参为 0~1 的比例；传 isRatio=false 表示已是 0~100 的数值）
     */
    public static function percent(float|int $value, int $decimals = 2, bool $isRatio = true): string
    {
        $v = $isRatio ? (float) $value * 100 : (float) $value;
        return self::thousands($v, $decimals) . '%';
    }

    /**
     * 安全除法（分母为 0 返回默认值）
     */
    public static function safeDivide(float|int $a, float|int $b, float|int $default = 0): float|int
    {
        if ((float) $b == 0.0) {
            return $default;
        }
        return $a / $b;
    }

    /**
     * 中文单位（万/亿）展示，自动保留两位小数并去掉多余的 0
     */
    public static function wan(float|int $value): string
    {
        $value = (float) $value;
        $abs   = abs($value);
        if ($abs >= 1e8) {
            return self::trimNum($value / 1e8) . '亿';
        }
        if ($abs >= 1e4) {
            return self::trimNum($value / 1e4) . '万';
        }
        return self::trimNum($value);
    }

    /**
     * 中文大写金额（财务票据场景）
     *
     * @param float|int|string $amount 金额（元）
     */
    public static function rmbUpperCase(float|int|string $amount): string
    {
        $amount = round((float) $amount, 2);
        $sign   = '';
        if ($amount < 0) {
            $sign   = '负';
            $amount = -$amount;
        }

        $cents = (int) round($amount * 100);
        $yuan  = intdiv($cents, 100);
        $jiao  = intdiv($cents % 100, 10);
        $fen   = $cents % 10;

        $intStr = $yuan === 0 ? '零' : self::rmbInteger($yuan);
        $result = $sign . $intStr . '圆';

        if ($jiao === 0 && $fen === 0) {
            $result .= '整';
            return $result;
        }

        if ($jiao > 0) {
            $result .= self::rmbDigit($jiao) . '角';
        } else {
            // 角为 0、分不为 0：需要补“零”（如 100.03 → 壹佰圆零叁分）
            if ($fen > 0 && $yuan !== 0) {
                $result .= '零';
            }
        }
        if ($fen > 0) {
            $result .= self::rmbDigit($fen) . '分';
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    protected static function trimNum(float $value): string
    {
        $s = number_format($value, 2, '.', '');
        // 去掉末尾多余的 0 与小数点
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        return $s;
    }

    protected static function rmbDigit(int $d): string
    {
        return ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'][$d];
    }

    /**
     * 整数部分（0~...）转中文大写（含 万/亿/兆）
     */
    protected static function rmbInteger(int $num): string
    {
        if ($num === 0) {
            return '零';
        }
        $smallUnits  = ['', '拾', '佰', '仟'];
        $groupUnits  = ['', '万', '亿', '兆'];

        $groups = [];
        $n      = $num;
        while ($n > 0) {
            $groups[] = $n % 10000;
            $n        = intdiv($n, 10000);
        }

        $result = '';
        $len    = count($groups);
        for ($i = $len - 1; $i >= 0; $i--) {
            $g = $groups[$i];
            if ($g === 0) {
                if ($result !== '' && !str_ends_with($result, '零')) {
                    $result .= '零';
                }
                continue;
            }
            $result .= self::rmbGroup($g) . $groupUnits[$i];
        }
        return $result === '' ? '零' : $result;
    }

    /**
     * 0~9999 转中文（带组内零处理）
     */
    protected static function rmbGroup(int $g): string
    {
        $digits      = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        $smallUnits  = ['', '拾', '佰', '仟'];
        $out         = '';
        $needZero    = false;

        for ($j = 3; $j >= 0; $j--) {
            $w   = 10 ** $j;
            $d   = intdiv($g, $w);
            if ($d === 0) {
                if ($out !== '' && !$needZero) {
                    $needZero = true;
                }
            } else {
                if ($needZero) {
                    $out      .= '零';
                    $needZero  = false;
                }
                $out .= $digits[$d] . $smallUnits[$j];
            }
            $g = $g % $w;
        }
        return $out;
    }
}
