<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 哈希 / 签名 / 口令工具（零依赖，基于 PHP 内置 hash / openssl）
 *
 * 覆盖：HMAC、bcrypt 口令哈希与校验、随机令牌、签名 URL/Query（临时免登录链接）。
 *
 * 用法：
 *   Hash::hmac($data, $key);
 *   $h = Hash::bcrypt('password');  Hash::verify('password', $h);
 *   $token = Hash::token(40);
 *   $signed = Hash::sign(['uid'=>1], $secret, 3600);
 *   $payload = Hash::verifySign($signed, $secret);   // 过期返回 null
 */
class Hash
{
    /**
     * HMAC 签名
     */
    public static function hmac(string $data, string $key, string $algo = 'sha256'): string
    {
        return (string) hash_hmac($algo, $data, $key);
    }

    /**
     * bcrypt 口令哈希
     *
     * @param array<string,mixed> $options
     */
    public static function bcrypt(string $value, array $options = []): string
    {
        $hash = password_hash($value, PASSWORD_BCRYPT, $options);
        if ($hash === false) {
            throw new \RuntimeException('bcrypt 哈希失败');
        }
        return $hash;
    }

    /**
     * 校验口令
     */
    public static function verify(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
    }

    /**
     * 生成随机令牌（默认 40 位十六进制）
     */
    public static function token(int $length = 40): string
    {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }

    /**
     * 对载荷签名（HMAC-SHA256 + base64url），可选有效期（秒）
     *
     * 输出格式：base64url(json).base64url(hmac)
     *
     * @param array<string,mixed> $payload
     */
    public static function sign(array $payload, string $key, int $ttl = 0): string
    {
        if ($ttl > 0) {
            $payload['__exp'] = time() + $ttl;
        }
        $data    = Encoding::base64UrlEncode((string) json_encode($payload));
        $sig     = Encoding::base64UrlEncode(self::hmac($data, $key));
        return $data . '.' . $sig;
    }

    /**
     * 校验签名并返回载荷；失败或已过期返回 null
     *
     * @return array<string,mixed>|null
     */
    public static function verifySign(string $token, string $key): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$data, $sig] = $parts;
        $expected = Encoding::base64UrlEncode(self::hmac($data, $key));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = Encoding::base64UrlDecode($data);
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['__exp']) && (int) $payload['__exp'] < time()) {
            return null;
        }
        unset($payload['__exp']);
        return $payload;
    }

    /**
     * 生成带签名的查询字符串（默认附加 signature / expires 参数）
     *
     * @param array<string,mixed> $params
     */
    public static function signedQuery(array $params, string $key, int $ttl = 0, string $sigKey = 'signature', string $expKey = 'expires'): string
    {
        if ($ttl > 0) {
            $params[$expKey] = time() + $ttl;
        }
        ksort($params);
        $query = http_build_query($params);
        $sig   = self::hmac($query, $key);
        return $query . '&' . $sigKey . '=' . $sig;
    }

    /**
     * 校验签名查询参数；通过返回剔除签名参数的干净数组，否则返回 null
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function verifySignedQuery(array $params, string $key, string $sigKey = 'signature', string $expKey = 'expires'): ?array
    {
        if (!isset($params[$sigKey])) {
            return null;
        }
        $sig   = (string) $params[$sigKey];
        $check = $params;
        unset($check[$sigKey]);
        if (isset($check[$expKey]) && (int) $check[$expKey] < time()) {
            return null;
        }
        ksort($check);
        $query = http_build_query($check);
        if (!hash_equals(self::hmac($query, $key), $sig)) {
            return null;
        }
        unset($check[$sigKey], $check[$expKey]);
        return $check;
    }
}
