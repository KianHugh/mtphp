<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Auth;

use Mtphp\Cache\CacheInterface;
use Mtphp\Encryption\Crypt;

/**
 * API 令牌（无状态，零依赖，思想借鉴主流框架的 Token 守卫 / Sanctum 简单令牌）
 *
 * 设计：签发时对“载荷（uid + 过期时间 + 随机 jti）”做 HMAC 签名，令牌形如 base64(payload).签名；
 * 校验时验签 + 查过期 + 查吊销名单。无需数据库表，天然适合前后端分离 / 移动端。
 *  - 吊销名单与“用户→令牌”索引存于缓存（CacheInterface），随缓存过期自动清理。
 *  - 推荐配置 app.key（加密密钥），未配置时退化为内置常量（安全性较弱，仅开发期可用）。
 *
 * 短方法：Token::mk($uid) 签发 / Token::chk($t) 校验 / Token::rm($t) 吊销。
 */
class Token
{
    /** 吊销名单缓存键前缀 */
    protected const REVOKED_PREFIX = 'mtphp:token:revoked:';

    /** 用户→令牌索引前缀 */
    protected const USER_PREFIX = 'mtphp:token:user:';

    /** @var CacheInterface|null 可注入的缓存（测试用）；默认走全局 cache() */
    protected static ?CacheInterface $store = null;

    public static function setStore(?CacheInterface $store): void
    {
        self::$store = $store;
    }

    /**
     * 签发令牌
     *
     * @param int      $uid 用户标识
     * @param int|null $ttl 有效期（秒）；null 用 config('auth.token_ttl')
     */
    public static function mk(int $uid, ?int $ttl = null): string
    {
        if ($ttl === null) {
            $ttl = function_exists('config') && \Mtphp\App::instance() !== null
                ? (int) \config('auth.token_ttl', 86400)
                : 86400;
        }
        $payload = [
            'uid' => $uid,
            'exp' => $ttl > 0 ? time() + $ttl : 0,
            'jti' => bin2hex(random_bytes(12)),
        ];
        $b64 = base64_encode((string) json_encode($payload));
        self::indexUser($uid, $payload['jti']);
        return $b64 . '.' . self::sign($b64);
    }

    /**
     * 校验令牌：成功返回载荷（含 uid），失败返回 null（签名错 / 过期 / 已吊销）
     *
     * @return array<string,mixed>|null
     */
    public static function chk(string $token): ?array
    {
        if (!str_contains($token, '.')) {
            return null;
        }
        [$b64, $sig] = explode('.', $token, 2);
        if (!hash_equals(self::sign($b64), $sig)) {
            return null;
        }
        $payload = json_decode(base64_decode($b64), true);
        if (!is_array($payload) || !isset($payload['uid'], $payload['jti'])) {
            return null;
        }
        if (!empty($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        if (self::isRevoked($payload['jti'])) {
            return null;
        }
        return $payload;
    }

    /**
     * 取令牌中的用户 id（无效返回 null）
     */
    public static function uid(string $token): ?int
    {
        $p = self::chk($token);
        return $p === null ? null : (int) $p['uid'];
    }

    /**
     * 吊销某令牌
     */
    public static function rm(string $token): bool
    {
        $p = self::chk($token);
        if ($p === null) {
            return false;
        }
        self::revoke($p['uid'], $p['jti'], $p['exp'] ?? 0);
        return true;
    }

    /**
     * 吊销某用户的所有令牌
     */
    public static function rmByUser(int $uid): bool
    {
        $jtis = self::store()->get(self::USER_PREFIX . $uid, []);
        if (!is_array($jtis)) {
            $jtis = [];
        }
        foreach ($jtis as $jti) {
            self::store()->set(self::REVOKED_PREFIX . $jti, true, 31536000);
        }
        self::store()->delete(self::USER_PREFIX . $uid);
        return true;
    }

    protected static function revoke(int $uid, string $jti, int $exp): void
    {
        // 吊销名单按令牌剩余有效期留存（永不过期的给 1 年兜底）
        $ttl = ($exp > 0 && $exp > time()) ? $exp - time() : 31536000;
        self::store()->set(self::REVOKED_PREFIX . $jti, true, $ttl);

        // 从用户索引中移除该 jti
        $list = self::store()->get(self::USER_PREFIX . $uid, []);
        if (is_array($list)) {
            $list = array_values(array_diff($list, [$jti]));
            if ($list === []) {
                self::store()->delete(self::USER_PREFIX . $uid);
            } else {
                self::store()->set(self::USER_PREFIX . $uid, $list);
            }
        }
    }

    protected static function isRevoked(string $jti): bool
    {
        return self::store()->has(self::REVOKED_PREFIX . $jti);
    }

    /**
     * 签发时登记“用户→jti”索引（供 rmByUser 使用）
     */
    protected static function indexUser(int $uid, string $jti): void
    {
        $list   = self::store()->get(self::USER_PREFIX . $uid, []);
        $list   = is_array($list) ? $list : [];
        $list[] = $jti;
        self::store()->set(self::USER_PREFIX . $uid, $list);
    }

    protected static function sign(string $b64): string
    {
        return hash_hmac('sha256', $b64, self::key());
    }

    protected static function key(): string
    {
        $k = Crypt::getKey();
        return $k !== null && $k !== '' ? $k : 'mtphp-api-token-default';
    }

    protected static function store(): CacheInterface
    {
        if (self::$store !== null) {
            return self::$store;
        }
        return \cache();
    }
}
