<?php
declare(strict_types=1);

namespace Mtphp\Http\Middleware;

use Mtphp\Http\Request;
use Mtphp\Http\Response;

/**
 * 健康检查 / 探针端点（k8s、负载均衡、网关标配）
 *
 * 在洋葱模型最外层拦截 /healthz 与 /ready，短路返回，不经过路由与控制器：
 *  - GET /healthz  → 存活探针（liveness）：进程能响应即 200 {"status":"ok"}
 *  - GET /ready    → 就绪探针（readiness）：执行注册的就绪检查，
 *                    全部通过 200 {"status":"ready","checks":{...}}，否则 503。
 *
 * 注册就绪检查（可选，在 bootstrap 或服务提供处调用）：
 *   HealthCheck::check('db', fn () => DB::connection()->getPdo() instanceof \PDO);
 *   HealthCheck::check('redis', fn () => /* ... *\/ true);
 *
 * 默认仅做应用存活检查；可在 config/app.php 关闭：'health_probe' => false。
 */
class HealthCheck
{
    /** @var array<string,callable> 就绪检查：名称 => 返回 bool 或 ['ok'=>bool,'detail'=>...] */
    protected static array $checks = [];

    /** 注册一个就绪检查 */
    public static function check(string $name, callable $fn): void
    {
        self::$checks[$name] = $fn;
    }

    /** 清空（测试用） */
    public static function reset(): void
    {
        self::$checks = [];
    }

    public function handle(Request $request, callable $next): Response
    {
        $path = $request->path();
        if ($path === '/healthz' || $path === '/ready') {
            return $this->respond($path);
        }
        return $next($request);
    }

    protected function respond(string $path): Response
    {
        if ($path === '/ready') {
            $results = [];
            $ok      = true;
            foreach (self::$checks as $name => $fn) {
                try {
                    $r = $fn();
                } catch (\Throwable $e) {
                    $r = ['ok' => false, 'error' => $e->getMessage()];
                }
                if (is_array($r)) {
                    $pass       = (bool) ($r['ok'] ?? true);
                    $results[$name] = $r;
                } else {
                    $pass            = (bool) $r;
                    $results[$name]  = ['ok' => $pass];
                }
                if (!$pass) {
                    $ok = false;
                }
            }
            return (new Response())->json([
                'status' => $ok ? 'ready' : 'not_ready',
                'checks' => $results,
                'time'   => date('c'),
            ], $ok ? 200 : 503);
        }

        // /healthz 存活探针：能进到这里就说明 PHP 进程健康
        return (new Response())->json([
            'status' => 'ok',
            'time'   => date('c'),
        ], 200);
    }
}
