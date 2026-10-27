<?php
declare(strict_types=1);

namespace Mtphp;

use Mtphp\Http\Request;
use Mtphp\Http\Response;

/**
 * 中间件管道（洋葱模型）
 *
 * 中间件签名：function(Request $request, callable $next): Response
 *  - 调用 $next($request) 将请求向内传递；返回 Response 即短路。
 *  - 可先处理后 $next，再对响应做后置加工。
 */
class Middleware
{
    /** @var array<int,callable> 中间件可调用列表 */
    protected array $stack = [];

    /**
     * 添加一个中间件
     *  - callable           ：直接使用
     *  - "Class@method"      ：解析为 [实例, 方法]
     *  - "ClassName"         ：解析为 [实例, 'handle']
     */
    public function add(string|callable $middleware): void
    {
        if (is_string($middleware)) {
            if (strpos($middleware, '@') !== false) {
                [$c, $m] = explode('@', $middleware, 2);
                $middleware = [App::instance()->container()->make($c), $m];
            } elseif (class_exists($middleware)) {
                $middleware = [App::instance()->container()->make($middleware), 'handle'];
            }
        }

        if (!is_callable($middleware)) {
            throw new \InvalidArgumentException('Middleware 不可调用：' . var_export($middleware, true));
        }

        $this->stack[] = $middleware;
    }

    public function run(Request $request, \Closure $core): Response
    {
        // 由内向外逐层包裹，形成洋葱
        $next = $core;
        foreach (array_reverse($this->stack) as $mw) {
            $current = $next;
            $next    = function (Request $request) use ($mw, $current) {
                $result = $mw($request, $current);
                return $result instanceof Response ? $result : new Response((string) $result);
            };
        }
        return $next($request);
    }
}
