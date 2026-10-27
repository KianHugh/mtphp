<?php
declare(strict_types=1);

namespace Mtphp\Auth;

use Mtphp\App;

/**
 * 标准 JWT（JSON Web Token）签发 / 校验（零依赖）
 *
 * 支持算法：
 *   HS256 / HS384 / HS512 —— 对称（同一 secret 签发与校验）
 *   RS256 / RS384 / RS512 —— 非对称（私钥签发、公钥校验，适合微服务）
 *
 * 标准声明自动校验：exp（过期）/ nbf（生效前）/ iat（签发时间，容忍 60s 时钟偏移）。
 * 任何失败（格式错 / 签名错 / 过期 / 未生效）decode 一律返回 null，不抛异常。
 *
 * 密钥解析优先级：encode/decode 显式传入 > config('jwt.secret') > config('app.key')。
 * 注意 RS 系列：encode 传【私钥】(PEM)，decode 传【公钥】(PEM)。
 *
 * 用法：
 *   $t = jwt()->encode(['uid' => 1, 'role' => 'admin']);     // 默认 HS256
 *   $p = jwt()->decode($t);                                    // 成功返回载荷数组，失败 null
 *   $t = jwt()->encode($p, $privateKey, 'RS256');               // 非对称签发
 *   $p = jwt()->decode($t, $publicKey,  'RS256');               // 非对称校验
 */
class Jwt
{
    /** @var list<string> 支持的算法 */
    protected const SUPPORTED = ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512'];

    /** 算法 → 哈希算法名（openssl / hash_hmac 共用） */
    protected const ALG_HASH = [
        'HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512',
        'RS256' => 'sha256', 'RS384' => 'sha384', 'RS512' => 'sha512',
    ];

    /**
     * 签发令牌
     *
     * @param array<string,mixed> $payload 载荷（自定义声明随意加；exp/iat/nbf 可选）
     * @param string|null         $key     密钥（HS 为 secret；RS 为【私钥】PEM）
     * @param string|null         $alg     算法，默认取 config('jwt.alg')
     */
    public function encode(array $payload, ?string $key = null, ?string $alg = null): string
    {
        $alg = $alg ?? $this->configAlg();
        if (!in_array($alg, self::SUPPORTED, true)) {
            throw new \InvalidArgumentException("不支持的 JWT 算法：{$alg}");
        }

        $header = ['typ' => 'JWT', 'alg' => $alg];
        $seg1   = $this->b64((string) json_encode($header));
        $seg2   = $this->b64((string) json_encode($payload));
        $data   = $seg1 . '.' . $seg2;
        $sig    = $this->b64($this->sign($data, $this->resolveKey($key), $alg));

        return $data . '.' . $sig;
    }

    /**
     * 校验令牌：成功返回载荷数组，失败（任意原因）返回 null
     *
     * @return array<string,mixed>|null
     */
    public function decode(string $token, ?string $key = null, ?string $alg = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $header  = json_decode($this->b64d($h), true);
        $payload = json_decode($this->b64d($p), true);
        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        $alg = $alg ?? ($header['alg'] ?? $this->configAlg());
        if (!in_array($alg, self::SUPPORTED, true)) {
            return null;
        }

        // 验签（RS 时 $key 为【公钥】PEM）
        if (!$this->verify($h . '.' . $p, $this->b64d($s), $this->resolveKey($key), $alg)) {
            return null;
        }

        // 标准声明校验
        if (!$this->validateClaims($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * 标准时间类声明校验
     *
     * @param array<string,mixed> $payload
     */
    protected function validateClaims(array $payload): bool
    {
        $now = time();
        if (isset($payload['exp']) && (int) $payload['exp'] < $now) {
            return false;
        }
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now) {
            return false;
        }
        if (isset($payload['iat']) && (int) $payload['iat'] > $now + 60) {
            return false; // 容忍 60s 时钟偏移
        }
        return true;
    }

    protected function sign(string $data, string $key, string $alg): string
    {
        if (str_starts_with($alg, 'HS')) {
            return hash_hmac(self::ALG_HASH[$alg], $data, $key, true);
        }
        $sig = '';
        if (!openssl_sign($data, $sig, $key, self::ALG_HASH[$alg])) {
            throw new \RuntimeException('JWT RS 签名失败（私钥无效？）');
        }
        return $sig;
    }

    protected function verify(string $data, string $sig, string $key, string $alg): bool
    {
        if (str_starts_with($alg, 'HS')) {
            $expected = $this->sign($data, $key, $alg);
            return hash_equals($expected, $sig);
        }
        return openssl_verify($data, $sig, $key, self::ALG_HASH[$alg]) === 1;
    }

    protected function resolveKey(?string $key): string
    {
        if ($key !== null && $key !== '') {
            return $key;
        }
        $secret = '';
        if (function_exists('config') && App::instance() !== null) {
            $secret = (string) \config('jwt.secret', '');
            if ($secret === '') {
                $secret = (string) \config('app.key', '');
            }
        }
        return $secret;
    }

    protected function configAlg(): string
    {
        if (function_exists('config') && App::instance() !== null) {
            $alg = \config('jwt.alg', 'HS256');
            if (is_string($alg) && $alg !== '') {
                return $alg;
            }
        }
        return 'HS256';
    }

    protected function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    protected function b64d(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($s, true);
        return is_string($decoded) ? $decoded : '';
    }
}
