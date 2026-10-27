<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Encryption;

/**
 * 轻量加密工具（零扩展，仅依赖 PHP 内置 openssl）
 *
 * 设计目标：给 Session / Cookie 等敏感数据提供“加密存储”，避免明文落盘。
 *  - 算法：AES-256-CBC 加密 + HMAC-SHA256 签名（encrypt-then-MAC，防篡改）。
 *  - 密钥：取自 config('app.key') 或环境变量 APP_KEY；测试可用 Crypt::setKey() 注入。
 *  - 短方法：Crypt::enc($v) / Crypt::dec($s) —— 可序列化的值（数组/对象/标量）通吃。
 *
 * 用法：
 *   $s = Crypt::enc(['uid' => 1, 'role' => 'admin']);
 *   $v = Crypt::dec($s);                 // 篡改 $s 会抛 RuntimeException
 *
 * 说明：独立实现，思想借鉴主流框架的 Encrypter（AES + 签名），不依赖任何第三方库。
 */
class Crypt
{
    /** 运行时覆盖密钥（测试 / 动态设置用） */
    protected static ?string $runtimeKey = null;

    /**
     * 取当前密钥（config('app.key') 优先，其次 APP_KEY 环境变量）
     */
    public static function getKey(): ?string
    {
        if (self::$runtimeKey !== null) {
            return self::$runtimeKey;
        }
        $fromConfig = '';
        if (function_exists('config') && \Mtphp\App::instance() !== null) {
            $fromConfig = (string) \config('app.key', '');
        }
        if ($fromConfig !== '') {
            return $fromConfig;
        }
        $env = getenv('APP_KEY');
        return ($env === false || $env === '') ? null : (string) $env;
    }

    /** 运行时设置密钥（返回旧值，便于测试还原） */
    public static function setKey(?string $key): ?string
    {
        $old            = self::$runtimeKey;
        self::$runtimeKey = $key;
        return $old;
    }

    /**
     * 加密任意可序列化值，返回 base64 字符串（含 IV 与 HMAC）。
     *
     * @throws \RuntimeException 密钥未配置时
     */
    public static function enc(mixed $value): string
    {
        [$enc, $mac] = self::deriveKeys();
        $iv      = random_bytes(16);
        $cipher  = openssl_encrypt(serialize($value), 'AES-256-CBC', $enc, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('加密失败');
        }
        $macVal  = hash_hmac('sha256', $iv . $cipher, $mac, true);
        return base64_encode($iv . $cipher . $macVal);
    }

    /**
     * 解密 Crypt::enc() 产生的字符串；密文被篡改 / 非法会抛 RuntimeException。
     *
     * @return mixed
     * @throws \RuntimeException
     */
    public static function dec(string $payload): mixed
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 16 + 32) {
            throw new \RuntimeException('非法密文');
        }
        [$enc, $mac] = self::deriveKeys();
        $iv      = substr($raw, 0, 16);
        $macVal  = substr($raw, -32);
        $cipher  = substr($raw, 16, -32);
        $calc    = hash_hmac('sha256', $iv . $cipher, $mac, true);
        if (!hash_equals($calc, $macVal)) {
            throw new \RuntimeException('密文校验失败（可能被篡改）');
        }
        $dec = openssl_decrypt($cipher, 'AES-256-CBC', $enc, OPENSSL_RAW_DATA, $iv);
        if ($dec === false) {
            throw new \RuntimeException('解密失败');
        }
        return unserialize($dec);
    }

    /**
     * 由主密钥派生出“加密密钥(32B)”与“签名密钥”，避免两套操作共用同一把钥匙。
     *
     * @return array{0:string,1:string}
     * @throws \RuntimeException
     */
    protected static function deriveKeys(): array
    {
        $master = self::getKey();
        if ($master === null || $master === '') {
            throw new \RuntimeException('加密密钥未配置（config app.key 或环境变量 APP_KEY）');
        }
        $enc = substr(hash('sha256', 'mtphp|enc|' . $master, true), 0, 32);
        $mac = hash('sha256', 'mtphp|mac|' . $master, true);
        return [$enc, $mac];
    }
}
