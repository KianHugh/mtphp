<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 数据脱敏（零依赖，隐私合规展示）
 *
 * 覆盖：手机号、邮箱、姓名、身份证、银行卡；并提供通用掩码。
 *
 * 用法：
 *   Mask::phone('13800001111');        // 138****1111
 *   Mask::email('zhangsan@example.com'); // z****@example.com
 *   Mask::name('张三丰');               // 张*丰
 *   Mask::idCard('110101199001011234'); // 110101********1234
 *   Mask::bankCard('6222021234567890'); // **** **** **** 7890
 */
class Mask
{
    /**
     * 通用掩码：保留左侧 $start 个字符与右侧 $end 个字符
     */
    public static function mask(string $value, int $start, int $end, string $mask = '*'): string
    {
        return Str::mask($value, $start, $end, $mask);
    }

    /**
     * 手机号脱敏（保留前 3 后 4；位数不足时保留前 1 后 1）
     */
    public static function phone(string $phone, string $mask = '*'): string
    {
        $len = strlen($phone);
        if ($len <= 7) {
            return Str::mask($phone, 1, 1, $mask);
        }
        return Str::mask($phone, 3, 4, $mask);
    }

    /**
     * 邮箱脱敏（保留首字符与完整域名）
     */
    public static function email(string $email, string $mask = '*'): string
    {
        if (!str_contains($email, '@')) {
            return Str::mask($email, 1, 1, $mask);
        }
        [$local, $domain] = explode('@', $email, 2);
        $head = substr($local, 0, 1) . str_repeat($mask, max(1, strlen($local) - 1));
        return $head . '@' . $domain;
    }

    /**
     * 中文姓名脱敏（2 字留首字；3 字及以上首尾保留、中间掩码）
     */
    public static function name(string $name, string $mask = '*'): string
    {
        $len = mb_strlen($name);
        if ($len <= 1) {
            return $name;
        }
        if ($len === 2) {
            return mb_substr($name, 0, 1) . $mask;
        }
        return mb_substr($name, 0, 1)
            . str_repeat($mask, $len - 2)
            . mb_substr($name, $len - 1, 1);
    }

    /**
     * 身份证脱敏（保留前 6 后 4）
     */
    public static function idCard(string $id, string $mask = '*'): string
    {
        $len = strlen($id);
        if ($len <= 10) {
            return Str::mask($id, 1, 1, $mask);
        }
        return Str::mask($id, 6, 4, $mask);
    }

    /**
     * 银行卡脱敏（保留后 4，其余按 4 位分组掩码）
     */
    public static function bankCard(string $card, string $mask = '*'): string
    {
        $len  = strlen($card);
        $tail = substr($card, -4);
        $body = str_repeat($mask, max(0, $len - 4));
        $groups = $body === '' ? [] : str_split($body, 4);
        return implode(' ', $groups) . ' ' . $tail;
    }
}
