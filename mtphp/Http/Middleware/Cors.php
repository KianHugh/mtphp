<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Http\Middleware;

use Mtphp\App;
use Mtphp\Http\Request;
use Mtphp\Http\Response;

/**
 * CORS 跨域中间件（框架内置，默认关闭，可由 config/app.php 的 security.cors 开启）
 *
 * 能力：
 *   - 按 security.cors_origins 回写 Access-Control-Allow-Origin（'*' 或用请求 Origin 反射）；
 *   - 回写 Allow-Methods / Allow-Headers / Max-Age；
 *   - 对预检（OPTIONS）请求直接返回 204，不进入后续处理。
 *
 * 开启方式：config/app.php 里把 security.cors 设为 true，并配置 origins / methods / headers。
 * 也可不全局开启，仅在某组 API 路由上单独挂载本中间件（更精细）。
 */
class Cors
{
    /** @var array<string,mixed> 注入配置（无 App 上下文时使用，便于测试/独立调用） */
    protected array $config = [];

    /**
     * @param array<string,mixed> $config 覆盖配置（键名同 security.cors_* 的末段，如 cors_origins）
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function handle(Request $request, callable $next): Response
    {
        $app = App::instance();

        $origins = $this->cfg('cors_origins', ['*']);
        $methods = $this->cfg('cors_methods', ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS']);
        $headers = $this->cfg('cors_headers', ['Content-Type', 'X-CSRF-TOKEN', 'X-Requested-With', 'Authorization']);
        $maxAge  = (int) $this->cfg('cors_max_age', 86400);

        $method = strtoupper($request->method());
        $reqOrigin = $request->header('origin');

        // 计算允许的来源（'*' 直接放行；否则仅当请求 Origin 在白名单内才回写，避免泄露）
        $allowOrigin = '*';
        if (!in_array('*', (array) $origins, true)) {
            $allowOrigin = is_string($reqOrigin) && in_array($reqOrigin, (array) $origins, true)
                ? $reqOrigin
                : '';
        }

        // 预检请求：直接 204，附带 CORS 头
        if ($method === 'OPTIONS') {
            $resp = new Response('', 204);
            $this->applyHeaders($resp, $allowOrigin, $methods, $headers, $maxAge);
            return $resp;
        }

        $response = $next($request);
        $this->applyHeaders($response, $allowOrigin, $methods, $headers, $maxAge);
        return $response;
    }

    /**
     * 读取配置：优先 App（security.cors_*），否则用注入配置，再否则用默认值
     *
     * @return mixed
     */
    protected function cfg(string $key, $default)
    {
        $app = App::instance();
        if ($app !== null) {
            return $app->config()->get('security.' . $key, $default);
        }
        return $this->config[$key] ?? $default;
    }

    /**
     * 统一下发 CORS 响应头
     *
     * @param list<string> $methods
     * @param list<string> $headers
     */
    protected function applyHeaders(Response $response, string $allowOrigin, array $methods, array $headers, int $maxAge): void
    {
        if ($allowOrigin === '') {
            return; // 非白名单来源：不回写，保持静默拒绝
        }
        $response->header('Access-Control-Allow-Origin', $allowOrigin);
        $response->header('Access-Control-Allow-Methods', implode(', ', $methods));
        $response->header('Access-Control-Allow-Headers', implode(', ', $headers));
        $response->header('Access-Control-Max-Age', (string) $maxAge);
        if ($allowOrigin !== '*') {
            $response->header('Vary', 'Origin');
        }
    }
}
