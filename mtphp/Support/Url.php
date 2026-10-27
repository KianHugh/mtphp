<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * URL 工具（零依赖）
 *
 * 覆盖：构建查询串、解析、取当前/完整 URL、资源 URL、合法性校验。
 *
 * 用法：
 *   Url::buildQuery(['a'=>1,'b'=>2]);
 *   Url::current();                  // 当前请求 URL
 *   Url::asset('/css/app.css');      // 静态资源 URL
 *   Url::isValid('https://x.com');   // true
 */
class Url
{
    /**
     * 构建查询字符串
     *
     * @param array<string,mixed> $params
     */
    public static function buildQuery(array $params, string $numericPrefix = '', string $argSeparator = '&'): string
    {
        return http_build_query($params, $numericPrefix, $argSeparator);
    }

    /**
     * 解析 URL（等同 parse_url，返回关联数组）
     *
     * @return array<string,mixed>|false
     */
    public static function parse(string $url): array|false
    {
        return parse_url($url);
    }

    /**
     * 当前完整请求 URL（读 $_SERVER）
     *
     * @param array<string,mixed>|null $server
     */
    public static function current(?array $server = null): string
    {
        $server = $server ?? $_SERVER;
        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? 'localhost');
        $port   = $server['SERVER_PORT'] ?? 80;
        $uri    = $server['REQUEST_URI'] ?? '/';

        $portPart = '';
        if (!(($scheme === 'http' && (int) $port === 80) || ($scheme === 'https' && (int) $port === 443))) {
            $portPart = ':' . $port;
        }
        return $scheme . '://' . $host . $portPart . $uri;
    }

    /**
     * 当前 URL 附加 / 覆盖查询参数后的完整 URL
     *
     * @param array<string,mixed> $extra
     * @param array<string,mixed>|null $server
     */
    public static function full(array $extra = [], ?array $server = null): string
    {
        $server = $server ?? $_SERVER;
        $url    = self::current($server);
        $parts  = parse_url($url);
        $query  = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query = array_merge($query, $extra);
        $base  = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '/');
        $qs    = $query === [] ? '' : '?' . http_build_query($query);
        return $base . $qs;
    }

    /**
     * 生成静态资源 URL（可传站点基址，默认从当前请求推断）
     */
    public static function asset(string $path, string $base = ''): string
    {
        if ($base === '') {
            $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        $base = rtrim($base, '/');
        $path = '/' . ltrim($path, '/');
        return $base . $path;
    }

    /**
     * 是否为合法 URL
     */
    public static function isValid(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
