<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 字符串工具（零依赖，纯 PHP 实现）
 *
 * 覆盖平台常见的字符串处理：唯一 ID 生成（uuid4/uuid7）、随机串、大小写互转、
 * 包含/前缀/后缀判断、截取、替换、前后截取、slug、脱敏掩码、类型判断。
 *
 * 用法：
 *   Str::uuid4();                       // 36 位随机 UUID
 *   Str::uuid7();                       // 时间有序 UUID（适合做主键）
 *   Str::random(32);                    // 32 位随机字符串
 *   Str::camel('hello_world');          // helloWorld
 *   Str::snake('helloWorld');           // hello_world
 *   Str::contains('abc','b');           // true
 *   Str::limit($text, 100);             // 截断
 *   Str::slug('Hello World');           // hello-world
 *   Str::mask('13800001111', 3, 4);     // 138****1111（保留前 3 后 4）
 */
class Str
{
    use Macroable;

    /** 默认随机字符集（字母 + 数字） */
    public const ALNUM = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // ------------------------------------------------------------------
    // 唯一 ID（委托给 Id，避免重复实现）
    // ------------------------------------------------------------------

    /**
     * UUID v4（随机）
     */
    public static function uuid4(): string
    {
        return Id::uuid4();
    }

    /**
     * UUID v7（时间有序，毫秒时间戳前缀）
     */
    public static function uuid7(?\DateTimeInterface $ts = null): string
    {
        return Id::uuid7($ts);
    }

    /**
     * ULID（26 位、字典序可排序）
     */
    public static function ulid(?\DateTimeInterface $ts = null): string
    {
        return Id::ulid($ts);
    }

    // ------------------------------------------------------------------
    // 随机串
    // ------------------------------------------------------------------

    /**
     * 生成指定长度的随机字符串
     *
     * @param int         $length   长度
     * @param string|null $alphabet 字符集（默认字母 + 数字）
     */
    public static function random(int $length = 16, ?string $alphabet = null): string
    {
        $alphabet = $alphabet ?? self::ALNUM;
        $max      = strlen($alphabet) - 1;
        $bytes    = random_bytes($length);
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[ord($bytes[$i]) % ($max + 1)];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 大小写 / 命名风格互转
    // ------------------------------------------------------------------

    /**
     * 驼峰（camelCase）：hello_world → helloWorld
     */
    public static function camel(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))));
    }

    /**
     * 蛇形（snake_case）：helloWorld → hello_world
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/', '', $value);
            $value = preg_replace('/(?<=\w)(?=[A-Z])/', $delimiter, $value);
        }
        $value = preg_replace('/[^A-Za-z0-9]+/', $delimiter, $value);
        return trim(strtolower($value), $delimiter);
    }

    /**
     * 短横线（kebab-case）：helloWorld → hello-world
     */
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    /**
     * 帕斯卡（StudlyCase / PascalCase）：hello_world → HelloWorld
     */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    // ------------------------------------------------------------------
    // 包含 / 前后缀
    // ------------------------------------------------------------------

    /**
     * 是否包含给定子串（或任一子串）
     *
     * @param string|string[] $needles
     */
    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否以给定前缀开头（或任一前缀）
     *
     * @param string|string[] $prefixes
     */
    public static function startsWith(string $haystack, string|array $prefixes): bool
    {
        foreach ((array) $prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($haystack, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否以给定后缀结尾（或任一后缀）
     *
     * @param string|string[] $suffixes
     */
    public static function endsWith(string $haystack, string|array $suffixes): bool
    {
        foreach ((array) $suffixes as $suffix) {
            if ($suffix !== '' && str_ends_with($haystack, $suffix)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // 截取 / 替换 / 前后段
    // ------------------------------------------------------------------

    /**
     * 安全截断（按字符数，不截断多字节字符）
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return mb_substr($value, 0, $limit) . $end;
    }

    /**
     * 替换首次出现的子串
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }
        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    /**
     * 替换最后一次出现的子串
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        $pos = strrpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }
        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    /**
     * 取分隔符之后的内容（不含分隔符）
     */
    public static function after(string $subject, string $search): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }
        return substr($subject, $pos + strlen($search));
    }

    /**
     * 取分隔符之前的内容（不含分隔符）
     */
    public static function before(string $subject, string $search): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }
        return substr($subject, 0, $pos);
    }

    // ------------------------------------------------------------------
    // 转换
    // ------------------------------------------------------------------

    /**
     * 生成 URL 友好的 slug（仅保留字母数字，其余替换为分隔符；中文会被移除）
     */
    public static function slug(string $title, string $separator = '-'): string
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9]+/', $separator, $title);
        $title = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $title);
        return trim($title, $separator);
    }

    /**
     * 脱敏掩码：保留左侧 $start 个字符与右侧 $end 个字符，中间用 $mask 替换
     *
     *   Str::mask('13800001111', 3, 4);  // 138****1111
     */
    public static function mask(string $value, int $start = 0, int $end = 0, string $mask = '*'): string
    {
        $len   = mb_strlen($value);
        $start = max(0, $start);
        $end   = $end < 0 ? $len + $end : min($len, $end);
        if ($start + $end >= $len) {
            return $value;
        }
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($value, $i, 1);
            $out .= ($i >= $start && $i < $len - $end) ? $mask : $ch;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 长度 / 大小写
    // ------------------------------------------------------------------

    public static function length(string $value): int
    {
        return mb_strlen($value);
    }

    public static function lower(string $value): string
    {
        return mb_strtolower($value);
    }

    public static function upper(string $value): string
    {
        return mb_strtoupper($value);
    }

    public static function ucfirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    public static function lcfirst(string $value): string
    {
        return mb_strtolower(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    public static function reverse(string $value): string
    {
        return implode('', array_reverse(mb_str_split($value)));
    }

    // ------------------------------------------------------------------
    // 类型判断
    // ------------------------------------------------------------------

    /**
     * 是否为合法 UUID（v1-v8）
     */
    public static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * 是否为合法 ULID（26 位 Crockford Base32）
     */
    public static function isUlid(string $value): bool
    {
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value);
    }

    /**
     * 是否为合法 JSON 字符串
     */
    public static function isJson(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
