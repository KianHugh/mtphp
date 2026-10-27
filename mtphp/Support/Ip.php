<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * IP 工具（零依赖）
 *
 * 覆盖：取真实客户端 IP（穿透代理）、IP ↔ 数值、CIDR 匹配、公私网判断。
 * 常用于访问控制、审计、限流键生成。
 *
 * 用法：
 *   Ip::realClientIp();                       // 读 $_SERVER + X-Forwarded-For
 *   Ip::cidrMatch('10.0.0.5', '10.0.0.0/8');  // true
 *   Ip::isPrivate('192.168.1.1');             // true
 */
class Ip
{
    /**
     * 取真实客户端 IP（优先 X-Forwarded-For 首段、X-Real-IP，回退 REMOTE_ADDR）
     *
     * @param array<string,mixed>|null $server $_SERVER 替代（测试用）
     */
    public static function realClientIp(?array $server = null): string
    {
        $server = $server ?? $_SERVER;

        $xff = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($xff) && $xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $xri = $server['HTTP_X_REAL_IP'] ?? null;
        if (is_string($xri) && filter_var($xri, FILTER_VALIDATE_IP)) {
            return $xri;
        }
        $remote = $server['REMOTE_ADDR'] ?? null;
        if (is_string($remote) && $remote !== '') {
            return $remote;
        }
        return '0.0.0.0';
    }

    /**
     * IP → 数值字符串（IPv4 用 ip2long；IPv6 用 inet_pton 展开为大整数串）
     */
    public static function toLong(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $v = ip2long($ip);
            return $v === false ? null : (string) $v;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return null;
            }
            $bytes = unpack('C*', $packed);
            $num   = '0';
            foreach ($bytes as $b) {
                $num = bcadd(bcmul($num, '256', 0), (string) $b, 0);
            }
            return $num;
        }
        return null;
    }

    /**
     * 数值字符串 → IP（支持 IPv4 / IPv6）
     */
    public static function fromLong(string $long): ?string
    {
        if (bccomp($long, '4294967295', 0) <= 0) {
            $ip = long2ip((int) $long);
            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
        }
        // IPv6：逐字节还原
        $bytes = '';
        $n     = $long;
        while (bccomp($n, '0', 0) > 0) {
            $bytes = chr((int) bcmod($n, '256')) . $bytes;
            $n     = bcdiv($n, '256', 0);
        }
        $bytes = str_pad($bytes, 16, "\0", STR_PAD_LEFT);
        $ip    = inet_ntop($bytes);
        return $ip === false ? null : $ip;
    }

    /**
     * 判断 IP 是否落在 CIDR 网段内（支持 IPv4 / IPv6）
     */
    public static function cidrMatch(string $ip, string $cidr): bool
    {
        if (str_contains($cidr, '/')) {
            [$subnet, $bits] = explode('/', $cidr, 2);
            $bits = (int) $bits;
        } else {
            $subnet = $cidr;
            $bits   = null;
        }

        if (!filter_var($subnet, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($bits === null) {
            return self::toLong($ip) === self::toLong($subnet);
        }

        // 同类地址才比较
        $v4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if ($v4) {
            $ipLong    = ip2long($ip);
            $subLong   = ip2long($subnet);
            if ($ipLong === false || $subLong === false) {
                return false;
            }
            $mask     = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1) & 0xFFFFFFFF);
            return ($ipLong & $mask) === ($subLong & $mask);
        }
        if ($v6) {
            $ipNum    = self::toLong($ip);
            $subNum   = self::toLong($subnet);
            if ($ipNum === null || $subNum === null) {
                return false;
            }
            $len      = 128;
            // 取高位 $bits 位比较
            $cmpBits  = $bits;
            $fullGroups = intdiv($cmpBits, 8);
            $rem      = $cmpBits % 8;
            $ipBytes  = self::bcToBytes($ipNum, 16);
            $subBytes = self::bcToBytes($subNum, 16);
            for ($i = 0; $i < $fullGroups; $i++) {
                if ($ipBytes[$i] !== $subBytes[$i]) {
                    return false;
                }
            }
            if ($rem > 0) {
                $mask = ~((1 << (8 - $rem)) - 1) & 0xFF;
                if (($ipBytes[$fullGroups] & $mask) !== ($subBytes[$fullGroups] & $mask)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * 是否私网 / 保留地址（非公网）
     */
    public static function isPrivate(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * 是否公网地址
     */
    public static function isPublic(string $ip): bool
    {
        return !self::isPrivate($ip);
    }

    protected static function bcToBytes(string $num, int $length): array
    {
        $bytes = [];
        $n     = $num;
        while (bccomp($n, '0', 0) > 0) {
            $bytes[] = (int) bcmod($n, '256');
            $n       = bcdiv($n, '256', 0);
        }
        $bytes = array_reverse($bytes);
        return array_pad($bytes, $length, 0);
    }
}
