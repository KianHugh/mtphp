<?php
declare(strict_types=1);

namespace Mtphp\Http\Middleware;

use Mtphp\App;
use Mtphp\Http\Request;
use Mtphp\Http\Response;

/**
 * CSRF 防护中间件（框架内置，默认开启）
 *
 * 采用“无状态双提交 Cookie（double-submit cookie）”方案，无需会话（Session）：
 *   - 框架没有内置 Session，因而把随机令牌同时放在
 *       ① HttpOnly 的 Cookie（mtphp_csrf，SameSite=Lax，自动随同源请求带上）
 *       ② 表单隐藏字段 _token / 请求头 X-CSRF-TOKEN（由服务端渲染或前端读取）
 *   - 对“非安全方法”（POST/PUT/DELETE/PATCH）校验两者是否一致，
 *     不一致直接返回 419（请求被拒），从而阻断跨站伪造提交。
 *
 * 为什么 SameSite=Lax + 双提交就够用：
 *   - 跨站 POST 默认不携带 SameSite=Lax 的 Cookie，Cookie 中没有令牌 → 校验失败；
 *   - 即便攻击者能注入表单，也无法读取 HttpOnly Cookie 的值来伪造 _token。
 *
 * 视图侧用法（在模板 / 控制器里）：
 *   csrf_field()   输出 <input type="hidden" name="_token" value="...">
 *   csrf_meta()    输出 <meta name="csrf-token" content="...">（前端 AJAX 读取后放入 X-CSRF-TOKEN 头）
 *   csrf_token()   仅取令牌字符串
 *
 * 关闭 / 放行：config/app.php 的
 *   security.csrf        => false  关闭中间件
 *   security.csrf_except => ['/api']  这些路径前缀跳过校验（如开放 API）
 */
class Csrf
{
    /** Cookie 名（令牌的权威存储位置） */
    public const COOKIE = 'mtphp_csrf';

    /** 表单隐藏字段名 */
    public const TOKEN_FIELD = '_token';

    /** 请求头名（AJAX 提交） */
    public const TOKEN_HEADER = 'X-CSRF-TOKEN';

    /** 安全（只读）方法，不校验 */
    protected const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** 当前请求内使用的令牌（由 handle() 在放行前确定，供 csrf_token() 取用） */
    protected static ?string $requestToken = null;

    /**
     * 中间件入口
     */
    public function handle(Request $request, callable $next): Response
    {
        $method = strtoupper($request->method());

        if (in_array($method, self::SAFE_METHODS, true)) {
            // 安全方法：确保令牌存在（读 Cookie 或新生成），渲染期可取，响应里下发 Cookie
            $token          = $this->ensureToken($request);
            self::$requestToken = $token;
            $response       = $next($request);
            $this->issueCookie($response, $token);
            return $response;
        }

        // 非安全方法：放行名单或校验令牌
        if ($this->isExcepted($request)) {
            return $next($request);
        }

        $expected = $this->cookieToken($request);
        $sent     = $this->sentToken($request);

        if ($expected === null || $sent === null || !hash_equals($expected, $sent)) {
            return $this->deny();
        }

        return $next($request);
    }

    /**
     * 取当前请求令牌（供 csrf_token() 等助手使用）
     *
     * 优先级：本次请求已确定的令牌 → Cookie 中的令牌 → 现场新生成（并缓存）。
     */
    public static function token(): string
    {
        if (self::$requestToken !== null) {
            return self::$requestToken;
        }
        $fromCookie = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($fromCookie) && $fromCookie !== '') {
            self::$requestToken = $fromCookie;
            return $fromCookie;
        }
        self::$requestToken = bin2hex(random_bytes(32));
        return self::$requestToken;
    }

    /**
     * 确定令牌：Cookie 已有则复用，否则新生成（幂等）
     */
    protected function ensureToken(Request $request): string
    {
        $fromCookie = $this->cookieToken($request);
        if ($fromCookie !== null) {
            return $fromCookie;
        }
        return bin2hex(random_bytes(32));
    }

    /**
     * 从 Cookie 读取令牌（空则 null）
     */
    protected function cookieToken(Request $request): ?string
    {
        $v = $_COOKIE[self::COOKIE] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * 从请求体 / 请求头读取提交过来的令牌
     */
    protected function sentToken(Request $request): ?string
    {
        // 表单字段优先
        $field = $request->post(self::TOKEN_FIELD);
        if (is_string($field) && $field !== '') {
            return $field;
        }
        // AJAX / fetch：X-CSRF-TOKEN 头（兼容 X-XSRF-TOKEN）
        $header = $request->header(self::TOKEN_HEADER);
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $header2 = $request->header('X-XSRF-TOKEN');
        if (is_string($header2) && $header2 !== '') {
            return $header2;
        }
        return null;
    }

    /**
     * 是否命中“跳过校验”的路径前缀（config: security.csrf_except）
     */
    protected function isExcepted(Request $request): bool
    {
        $except = $this->exceptPaths();
        if ($except === []) {
            return false;
        }
        $path = $request->path();
        foreach ($except as $prefix) {
            if (str_starts_with($path, (string) $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    protected function exceptPaths(): array
    {
        $app = App::instance();
        if ($app === null) {
            return [];
        }
        $list = $app->config()->get('security.csrf_except', []);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * 下发令牌 Cookie（HttpOnly + SameSite=Lax；HTTPS 下追加 Secure）
     */
    protected function issueCookie(Response $response, string $token): void
    {
        $response->cookie(self::COOKIE, $token, time() + 60 * 60 * 24 * 365, [
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * 校验失败时返回 419 响应
     */
    protected function deny(): Response
    {
        return (new Response('CSRF token mismatch', 419, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]));
    }

    protected function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
    }
}
