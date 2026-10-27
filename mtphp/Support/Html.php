<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * HTML 工具（零依赖）
 *
 * 覆盖：转义（防 XSS）、去标签取纯文本、纯文本摘要。
 *
 * 用法：
 *   Html::escape($userInput);        // 防 XSS
 *   Html::stripTags($html);          // 去标签
 *   Html::excerpt($html, 200);       // 去标签并截断
 */
class Html
{
    /**
     * HTML 转义（引号一并转义）
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 去除 HTML 标签（可保留白名单标签）
     */
    public static function stripTags(string $html, ?string $allowed = null): string
    {
        return strip_tags($html, $allowed ?? '');
    }

    /**
     * 去标签后按字符数截断，附省略号（适合列表摘要）
     */
    public static function excerpt(string $html, int $limit = 200, string $end = '...'): string
    {
        $text = self::stripTags($html);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit) . $end;
    }
}
