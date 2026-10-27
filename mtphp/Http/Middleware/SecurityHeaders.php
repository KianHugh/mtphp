<?php
declare(strict_types=1);

namespace Mtphp\Http\Middleware;

use Mtphp\App;
use Mtphp\Http\Request;
use Mtphp\Http\Response;

/**
 * 安全响应头中间件（框架内置，默认开启）
 *
 * 作用：给所有响应统一补上“防御性 HTTP 头”，收敛常见 Web 攻击面：
 *   - X-Content-Type-Options: nosniff        禁止 MIME 嗅探（防基于类型混淆的 XSS）
 *   - X-Frame-Options: SAMEORIGIN            禁止被其它站点 iframe 嵌套（防点击劫持）
 *   - Referrer-Policy                        控制 Referer 外泄程度
 *   - Content-Security-Policy                限制资源来源，阻断外域脚本注入（默认同域 + 允许内联以兼容现有模板）
 *   - Permissions-Policy                     默认关停地理/麦克风/摄像头等敏感能力
 *   - Strict-Transport-Security              仅 HTTPS 下追加，强制后续走加密通道
 *
 * 设计为“默认即安全、可一行覆盖”：
 *   - 默认集合见 {@see self::DEFAULTS}；
 *   - 应用可在 config/app.php 的 `security.headers_list` 里“覆盖/新增”任意头；
 *   - 把某个头显式设为 null 即可关闭它（例如不想上 CSP 时写 'Content-Security-Policy' => null）。
 *
 * 注意：默认 CSP 为“同域 + 允许内联”，可保证框架自带文档站等开箱即用；
 * 若要彻底杜绝内联脚本风险，请改用基于 nonce 的 CSP（届时模板需配合调整）。
 */
class SecurityHeaders
{
    /**
     * 默认安全头集合（值 null 的项表示“仅在满足条件时才下发”）
     *
     * @var array<string,?string>
     */
    protected const DEFAULTS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; base-uri 'self'; form-action 'self'",
        'Permissions-Policy'     => "geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()",
        // 仅 HTTPS 环境追加，故默认值为 null（运行时判断是否下发）
        'Strict-Transport-Security' => null,
    ];

    /**
     * 中间件入口（洋葱模型：先 $next 拿到响应，再补头）
     */
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $merged = $this->resolveHeaders();

        foreach ($merged as $name => $value) {
            // null 表示此环境/条件下不下发该头
            if ($value === null) {
                continue;
            }
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * 合并“默认值 + 应用配置覆盖”，并补全环境相关的条件项
     *
     * @return array<string,?string>
     */
    protected function resolveHeaders(): array
    {
        $headers = self::DEFAULTS;

        // 应用层覆盖（config/app.php 的 security.headers_list）
        $override = $this->configOverride();
        foreach ($override as $name => $value) {
            $headers[$name] = $value;
        }

        // HSTS 仅在 HTTPS 下追加（避免在纯 HTTP 开发环境被误缓存）
        if (!array_key_exists('Strict-Transport-Security', $override)) {
            $headers['Strict-Transport-Security'] = $this->isHttps()
                ? 'max-age=31536000; includeSubDomains'
                : null;
        }

        return $headers;
    }

    /**
     * 读取 config/app.php 的 security.headers_list（缺省为空数组）
     *
     * @return array<string,mixed>
     */
    protected function configOverride(): array
    {
        $app = App::instance();
        if ($app === null) {
            return [];
        }
        $list = $app->config()->get('security.headers_list', []);
        return is_array($list) ? $list : [];
    }

    protected function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
    }
}
