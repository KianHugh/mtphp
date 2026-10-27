<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 编码转换工具（零依赖）
 *
 * 覆盖：URL 安全的 Base64、HTML 实体编解码。
 *
 * 用法：
 *   Encoding::base64UrlEncode($data);   // 用于签名 / token，URL 安全
 *   Encoding::base64UrlDecode($data);
 *   Encoding::htmlEntities($html);      // 防 XSS 转义
 */
class Encoding
{
    /**
     * URL 安全的 Base64 编码
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL 安全的 Base64 解码
     */
    public static function base64UrlDecode(string $data): string
    {
        $rem = strlen($data) % 4;
        if ($rem !== 0) {
            $data .= str_repeat('=', 4 - $rem);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * HTML 实体转义（防 XSS，引号也转义）
     */
    public static function htmlEntities(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * HTML 实体反转义
     */
    public static function htmlEntityDecode(string $value): string
    {
        return htmlspecialchars_decode($value, ENT_QUOTES | ENT_HTML5);
    }
}
