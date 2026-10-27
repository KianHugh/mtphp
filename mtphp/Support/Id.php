<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 唯一数 / 序号生成器（零依赖，纯 PHP 实现）
 *
 * 覆盖：UUID v4 / v7、ULID、NanoID、短码、分布式雪花 ID、业务流水号。
 * 不依赖任何扩展（random_bytes 是 PHP 内置）。
 *
 * 用法：
 *   Id::uuid4();                         // 36 位随机 UUID
 *   Id::uuid7();                         // 时间有序 UUID（适合做主键，避免索引碎片）
 *   Id::ulid();                          // 26 位可排序 ULID
 *   Id::nanoid(21);                      // URL 安全随机串
 *   Id::shortId(12);                     // base62 短码
 *   Id::snowflake(1, 1);                 // 分布式 64 位 ID（需 worker/datacenter id）
 *   Id::serial('ORD-', 1);               // ORD-20261015-000001 业务流水号
 */
class Id
{
    /** ULID / NanoID 使用的 Crockford Base32 字母表 */
    public const ULID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** NanoID 默认字母表（URL 安全） */
    public const NANOID_ALPHABET = '_-0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** 短码字母表（base62） */
    public const SHORT_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** 雪花 ID 自定义纪元（2023-11-14T22:13:20Z，毫秒），避免时间戳过大 */
    public const SNOWFLAKE_EPOCH = 1700000000000;

    // ------------------------------------------------------------------
    // UUID
    // ------------------------------------------------------------------

    /**
     * UUID v4（随机）
     */
    public static function uuid4(): string
    {
        $b          = random_bytes(16);
        $b[6]       = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8]       = chr((ord($b[8]) & 0x3f) | 0x80);
        return self::formatUuid($b);
    }

    /**
     * UUID v7（RFC 9562：48 位毫秒时间戳 + 74 位随机）
     *
     * @param \DateTimeInterface|null $ts 指定时间（默认当前时刻）
     */
    public static function uuid7(?\DateTimeInterface $ts = null): string
    {
        $ms = $ts instanceof \DateTimeInterface
            ? (int) round(((float) $ts->format('U.u')) * 1000)
            : (int) round(microtime(true) * 1000);

        $timeHex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
        $b       = hex2bin($timeHex) . random_bytes(10);

        $b[6]    = chr((ord($b[6]) & 0x0f) | 0x70);
        $b[8]    = chr((ord($b[8]) & 0x3f) | 0x80);
        return self::formatUuid($b);
    }

    protected static function formatUuid(string $bytes): string
    {
        $h = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            substr($h, 12, 4),
            substr($h, 16, 4),
            substr($h, 20, 12)
        );
    }

    // ------------------------------------------------------------------
    // ULID
    // ------------------------------------------------------------------

    /**
     * ULID（26 位 Crockford Base32，时间有序）
     *
     * @param \DateTimeInterface|null $ts 指定时间（默认当前时刻）
     */
    public static function ulid(?\DateTimeInterface $ts = null): string
    {
        $ms = $ts instanceof \DateTimeInterface
            ? (int) round(((float) $ts->format('U.u')) * 1000)
            : (int) round(microtime(true) * 1000);

        // 48 位时间戳 → 6 字节；补足 6 字节（pack('J') 为 8 字节大端，取后 6 字节）
        $timeBytes = substr(pack('J', $ms), 2);
        $randBytes = random_bytes(10);

        return self::base32($timeBytes, self::ULID_ALPHABET)
            . self::base32($randBytes, self::ULID_ALPHABET);
    }

    /**
     * 把字节流按 5 位一组编码为指定字母表的字符串（用于 ULID）
     */
    protected static function base32(string $bytes, string $alphabet): string
    {
        $bits = '';
        foreach (unpack('C*', $bytes) as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        $n   = strlen($bits);
        for ($i = 0; $i < $n; $i += 5) {
            $chunk = substr($bits, $i, 5);
            if ($chunk === '') {
                break;
            }
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 随机串
    // ------------------------------------------------------------------

    /**
     * NanoID（URL 安全随机串，默认 21 位）
     */
    public static function nanoid(int $size = 21): string
    {
        return self::randomString($size, self::NANOID_ALPHABET);
    }

    /**
     * Base62 短码（默认 12 位）
     */
    public static function shortId(int $length = 12): string
    {
        return self::randomString($length, self::SHORT_ALPHABET);
    }

    protected static function randomString(int $length, string $alphabet): string
    {
        $max = strlen($alphabet) - 1;
        $out = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[ord($bytes[$i]) % ($max + 1)];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 分布式 ID（雪花）
    // ------------------------------------------------------------------

    /**
     * 雪花 ID（64 位：41 位毫秒时间戳 + 10 位 worker/datacenter + 12 位序列）
     *
     * 同一毫秒内序列用随机数填充（单进程内足够；多进程建议传稳定 workerId）。
     * 返回值在 64 位 PHP 上为 int（符号位为 0，落在有符号范围内）。
     */
    public static function snowflake(?int $workerId = null, ?int $datacenterId = null): int
    {
        $workerId     = $workerId ?? 1;
        $datacenterId = $datacenterId ?? 1;

        $ts     = (int) round(microtime(true) * 1000) - self::SNOWFLAKE_EPOCH;
        $worker = (($workerId & 0x1f) << 5) | ($datacenterId & 0x1f);
        $seq    = random_int(0, 4095);

        return ($ts << 22) | ($worker << 12) | $seq;
    }

    // ------------------------------------------------------------------
    // 业务流水号
    // ------------------------------------------------------------------

    /**
     * 生成业务流水号：前缀 + 日期 + 零填充序号
     *
     *   Id::serial('ORD-', 1);            // ORD-20261015-000001
     *   Id::serial('BILL', 42, 'YmdHis'); // BILL20261015100042-000042
     *
     * @param string $prefix    前缀（可自带分隔符）
     * @param int    $seq       序号（会被零填充）
     * @param string $dateFormat 日期格式（默认 Ymd）
     * @param int    $width     序号宽度（默认 6）
     */
    public static function serial(string $prefix = '', int $seq = 0, string $dateFormat = 'Ymd', int $width = 6): string
    {
        $date = date($dateFormat);
        $num  = str_pad((string) $seq, $width, '0', STR_PAD_LEFT);
        return $prefix . $date . '-' . $num;
    }
}
