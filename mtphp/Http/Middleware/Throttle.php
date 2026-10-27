<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Http\Middleware;

use Mtphp\Cache\CacheInterface;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Support\Ip;
use Mtphp\Support\RateLimiter;

/**
 * 限流中间件（固定窗口，零依赖，借助缓存实现）
 *
 * 用途：API 防刷、登录防爆破、保护高频接口。
 *  - 按“客户端 IP + 路由”生成限流键；窗口内请求数超过 max 直接返回 429（带 Retry-After）。
 *  - 默认限制读 config('throttle')：max（次数）/ decay（窗口秒数）。
 *  - 构造函数可覆盖默认值，便于按路由定制；也可注入缓存（测试用）。
 *
 * 用法（注册到路由或全局）：
 *   $mw->add(\Mtphp\Http\Middleware\Throttle::class);
 *   // 或自定义：new Throttle(10, 60) 表示 60 秒内最多 10 次
 */
class Throttle
{
    protected int $max;

    protected int $decay;

    protected ?CacheInterface $store;

    /**
     * @param int             $max   最大请求数（0 = 用配置）
     * @param int             $decay 窗口秒数（0 = 用配置）
     */
    public function __construct(int $max = 0, int $decay = 0, ?CacheInterface $store = null)
    {
        $cfg = [];
        if (function_exists('config') && \Mtphp\App::instance() !== null) {
            $cfg = (array) \config('throttle', []);
        }
        $this->max   = $max > 0 ? $max : (int) ($cfg['max'] ?? 60);
        $this->decay = $decay > 0 ? $decay : (int) ($cfg['decay'] ?? 60);
        $this->store = $store;
    }

    /**
     * 中间件入口
     */
    public function handle(Request $request, callable $next): Response
    {
        $key     = 'throttle:' . Ip::realClientIp() . ':' . $request->path();
        $limiter = new RateLimiter($this->cache());
        $hits    = $limiter->attempts($key);

        if ($limiter->tooManyAttempts($key, $this->max, $this->decay)) {
            return (new Response())
                ->json([
                    'message'      => '请求过于频繁，请稍后再试',
                    'retry_after'  => $this->decay,
                ], 429)
                ->header('Retry-After', (string) $this->decay);
        }

        $limiter->hit($key, $this->decay);

        $response = $next($request);
        if ($response instanceof Response) {
            $response->header('X-RateLimit-Limit', (string) $this->max)
                     ->header('X-RateLimit-Remaining', (string) max(0, $this->max - $hits - 1));
        }
        return $response;
    }

    protected function cache(): CacheInterface
    {
        if ($this->store !== null) {
            return $this->store;
        }
        return \cache();
    }
}
