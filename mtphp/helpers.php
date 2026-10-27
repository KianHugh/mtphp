<?php
declare(strict_types=1);

/**
 * 全局助手函数（由 App 引导时按需加载）
 */

use Mtphp\App;
use Mtphp\Http\Response;
use Mtphp\View\Template;

if (!function_exists('response')) {
    /**
     * 创建响应（链式：response()->json($data) / response($html)）
     */
    function response(mixed $body = '', int $code = 200, array $headers = []): Response
    {
        return new Response($body, $code, $headers);
    }
}

if (!function_exists('view')) {
    /**
     * 渲染视图模板
     */
    function view(string $template, array $data = []): Response
    {
        return Template::render($template, $data);
    }
}

if (!function_exists('abort')) {
    /**
     * 抛出 HTTP 异常（由 App 统一渲染为错误响应）
     *
     *   abort(404);
     *   abort(403, '无权限');
     */
    function abort(int $code, string $message = ''): never
    {
        throw new \Mtphp\Exception\HttpException($code, $message);
    }
}

if (!function_exists('abort_if')) {
    /**
     * 条件成立时抛出 HTTP 异常
     *
     *   abort_if($user === null, 401, '请登录');
     */
    function abort_if(mixed $boolean, int $code, string $message = ''): void
    {
        if ($boolean) {
            abort($code, $message);
        }
    }
}

if (!function_exists('abort_unless')) {
    /**
     * 条件不成立时抛出 HTTP 异常
     *
     *   abort_unless($logged, 401, '请登录');
     */
    function abort_unless(mixed $boolean, int $code, string $message = ''): void
    {
        if (!$boolean) {
            abort($code, $message);
        }
    }
}

if (!function_exists('redirect')) {
    /**
     * 重定向（302 默认）
     *
     *   return redirect('/login');            // 直接跳转
     *   return redirect('/login')->with('msg','请登录');  // 带一次性 flash
     *   return redirect('/ok', 301);           // 永久跳转
     */
    function redirect(string $url, int $code = 302): Response
    {
        return response()->redirect($url, $code);
    }
}

if (!function_exists('back')) {
    /**
     * 返回上一页（取 HTTP Referer，缺失时回落到根路径）
     */
    function back(int $code = 302): Response
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        return redirect($referer !== '' ? $referer : '/', $code);
    }
}

if (!function_exists('config')) {
    /**
     * 读取配置（无参返回 Config 实例）
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        $cfg = App::instance()->config();
        return $key === null ? $cfg : $cfg->get($key, $default);
    }
}

if (!function_exists('app')) {
    /**
     * 获取应用实例
     */
    function app(): App
    {
        return App::instance();
    }
}

if (!function_exists('route')) {
    /**
     * 由命名路由生成 URL
     *
     *   route('users.show', ['id' => 5]);          // → /users/5
     *   route('users.index', ['page' => 2]);        // → /users?page=2
     */
    function route(string $name, array $params = []): string
    {
        $app = App::instance();
        if ($app === null) {
            throw new \RuntimeException('App 尚未引导，无法生成路由 URL');
        }
        return $app->router()->route($name, $params);
    }
}

if (!function_exists('request')) {
    /**
     * 取得当前请求（惰性：首次调用时从 $_SERVER / $_FILES 等捕获并缓存）
     *
     * 用法：request()->input('name');  request()->file('avatar');
     */
    function request(): \Mtphp\Http\Request
    {
        static $r = null;
        if ($r === null) {
            $r = \Mtphp\Http\Request::capture();
        }
        return $r;
    }
}

if (!function_exists('logger')) {
    /**
     * 获取默认日志器（懒加载单例，零配置即可用）
     *
     * 默认写到 <应用根>/runtime/logs/app.log，级别 info；
     * 也可在 config/log.php 里覆盖 path / level。
     *
     * 用法：logger()->info('消息', ['k' => 'v']);
     */
    function logger(): \Mtphp\Log\LogManager
    {
        static $log = null;
        if ($log === null) {
            $app = App::instance();
            $cfg = $app !== null ? $app->config()->get('log', []) : [];
            $cfg = is_array($cfg) ? $cfg : [];
            $log = new \Mtphp\Log\LogManager($cfg);
        }
        return $log;
    }
}

if (!function_exists('request_id')) {
    /**
     * 取当前请求 ID（贯穿日志链路与响应头 X-Request-Id）。
     *
     * 优先返回当前请求对象的 ID（由 App 在入口注入，可能来自上游 X-Request-Id）；
     * 无请求上下文（如 CLI）时返回空串。
     */
    function request_id(): string
    {
        $app = App::instance();
        $req = $app !== null ? $app->request() : null;
        return $req !== null ? $req->requestId() : '';
    }
}

if (!function_exists('csrf_token')) {
    /**
     * 取当前请求的 CSRF 令牌（供表单/前端使用）
     *
     * 需由 Csrf 中间件在请求期确定；若未运行中间件则自动读 Cookie 或新生成。
     */
    function csrf_token(): string
    {
        return \Mtphp\Http\Middleware\Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * 输出 CSRF 隐藏字段（直接放进 <form> 即可）
     */
    function csrf_field(): string
    {
        $name  = \Mtphp\Http\Middleware\Csrf::TOKEN_FIELD;
        $token = csrf_token();
        return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_meta')) {
    /**
     * 输出 CSRF 元信息标签（前端 AJAX 读取后放入 X-CSRF-TOKEN 头）
     */
    function csrf_meta(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('cache')) {
    /**
     * 获取默认缓存器（懒加载单例，零配置即可用）
     *
     * 默认存到 <应用根>/runtime/cache，默认 1 小时过期；
     * 也可在 config/cache.php 里覆盖 path / ttl。
     *
     * 用法：cache()->set('k', $v, 600); cache()->remember('k', 60, fn () => ...);
     */
    function cache(): \Mtphp\Cache\CacheInterface
    {
        return \Mtphp\Cache\CacheManager::resolve();
    }
}

if (!function_exists('validate')) {
    /**
     * 便捷校验：通过则返回已校验字段，否则抛 ValidationException
     *
     * 用法：
     *   $data = validate($input, [
     *       'name'  => 'required|max:50',
     *       'email' => 'required|email',
     *   ]);
     */
    function validate(array $data, array $rules, array $messages = []): array
    {
        $v = new \Mtphp\Validation\Validator($data, $rules, $messages);
        if ($v->fails()) {
            throw new \Mtphp\Validation\ValidationException($v->errors());
        }
        return $v->validated();
    }
}

if (!function_exists('event')) {
    /**
     * 事件分发助手
     *   event()                     返回全局事件分发器
     *   event(new OrderPaid($o))    派发一个事件
     */
    function event(object $event = null, array $payload = [])
    {
        $d = \Mtphp\Events\Dispatcher::instance();
        if ($event === null) {
            return $d;
        }
        return $d->dispatch($event, $payload);
    }
}

if (!function_exists('http')) {
    /**
     * 创建 HTTP 客户端（fluent 链式）
     *
     * 用法：http('https://api.example.com')->withToken($t)->get('/users');
     */
    function http(string $baseUrl = ''): \Mtphp\Http\Client
    {
        return new \Mtphp\Http\Client($baseUrl);
    }
}

if (!function_exists('__')) {
    /**
     * 本地化取词（占位符 :key 替换）
     *
     * 用法：__('welcome', ['name' => '张三'])
     */
    function __(string $key, array $replace = []): string
    {
        return \Mtphp\Translation\Translator::instance()->get($key, $replace);
    }
}

if (!function_exists('trans_choice')) {
    /**
     * 本地化复数取词（按计数挑选形态，:count 自动替换为计数）
     *
     * 用法：trans_choice('apples', 3, ['fruit' => '苹果'])  // 假设文案 '{1} 一个 :fruit|[2,*] :count 个 :fruit'
     */
    function trans_choice(string $key, int $count, array $replace = []): string
    {
        return \Mtphp\Translation\Translator::instance()->choice($key, $count, $replace);
    }
}

if (!function_exists('session')) {
    /**
     * 获取当前会话（无运行中会话时返回内存会话，便于 CLI/测试）
     */
    function session(): \Mtphp\Http\Session
    {
        $s = \Mtphp\Http\Session::current();
        return $s ?? \Mtphp\Http\Session::instance();
    }
}

if (!function_exists('auth')) {
    /**
     * 获取认证守卫（多 Guard：auth('admin') 取 admin 守卫，auth() 取默认守卫）
     *
     * 返回 AuthManager 管理的 Auth 实例——同一请求内按名复用，且各守卫会话/Cookie 隔离。
     */
    function auth(?string $name = null): \Mtphp\Auth\Auth
    {
        return \Mtphp\Auth\AuthManager::instance()->guard($name);
    }
}

if (!function_exists('paginate')) {
    /**
     * 便捷分页：针对某张表分页查询，返回 Paginator
     *
     * 用法：paginate('users', 15, $page)
     */
    function paginate(string $table, int $perPage = 15, int $page = 1): \Mtphp\Pagination\Paginator
    {
        return \Mtphp\Database\DB::table($table)->paginate($perPage, $page);
    }
}

if (!function_exists('queue')) {
    /**
     * 获取队列实例（懒加载单例；应用上下文下自动套用 config('queue.path')）
     *
     * 用法：
     *   queue()->push(['class' => App\Jobs\SendMail::class, 'data' => [...]]);
     *   queue()->push('纯文本或数组任务', 'emails', 60);   // 延迟 60 秒
     */
    function queue(): \Mtphp\Queue\QueueInterface
    {
        return \Mtphp\Queue\QueueManager::resolve();
    }
}

if (!function_exists('collect')) {
    /**
     * 把数组包成集合（Collection），获得 map/filter/reduce/pluck/sum 等链式能力
     *
     * 用法：
     *   collect([1,2,3])->map(fn($x)=>$x*2)->filter(fn($x)=>$x>3)->toArray();
     *   collect($users)->pluck('id')->implode(',');
     */
function collect(?array $items = null): \Mtphp\Support\Collection
{
    return new \Mtphp\Support\Collection($items ?? []);
}

if (!function_exists('retry')) {
    /**
     * 重试：最多尝试 $times 次；仅当抛出 $catch 中的异常类型时重试（其余异常直接抛出）。
     * 可选 $delayMs 毫秒退避；回调会收到当前尝试次数（0 起）。
     *
     * 用法：
     *   $res = retry(3, fn ($attempt) => http()->get('/flaky'), 200);
     */
    function retry(int $times, callable $callback, int $delayMs = 0, array $catch = [\Throwable::class]): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback($attempt);
            } catch (\Throwable $e) {
                $caught = false;
                foreach ($catch as $cls) {
                    if ($e instanceof $cls) {
                        $caught = true;
                        break;
                    }
                }
                if (!$caught) {
                    throw $e;
                }
                $attempt++;
                if ($attempt >= $times) {
                    throw $e;
                }
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
    }
}

}

if (!function_exists('storage')) {
    /**
     * 取存储盘（默认盘或指定盘名）
     *
     * 用法：storage()->put('a.txt', 'hi');
     *       storage('qiniu')->put('b.jpg', $binary);   // 需先用 StorageManager::extend 注册云驱动
     */
    function storage(?string $disk = null): \Mtphp\Storage\StorageInterface
    {
        return \Mtphp\Storage\StorageManager::resolve()->disk($disk);
    }
}

if (!function_exists('jwt')) {
    /**
     * 取得 JWT 实例（签发 / 校验）
     *
     * 用法：jwt()->encode(['uid' => 1]);  jwt()->decode($token);
     *       非对称：jwt()->encode($p, $privateKey, 'RS256') / jwt()->decode($t, $publicKey, 'RS256')
     */
    function jwt(): \Mtphp\Auth\Jwt
    {
        return new \Mtphp\Auth\Jwt();
    }
}

if (!function_exists('api_success')) {
    /**
     * 统一成功响应（JSON 信封：code=0 / message / data）
     *
     * 用法：return api_success($list);
     *       return api_success($row, '获取成功');
     */
    function api_success(mixed $data = null, string $message = 'ok'): \Mtphp\Http\Response
    {
        return response()->json([
            'code'       => 0,
            'message'    => $message,
            'data'       => $data,
            'request_id' => request_id(),
        ], 200);
    }
}

if (!function_exists('api_error')) {
    /**
     * 统一错误响应（JSON 信封：code≠0 / message / data）
     *
     * 用法：return api_error('参数错误', 1001);
     *       return api_error('未登录', 401, null, 401);   // 第四个参数为 HTTP 状态码
     */
    function api_error(string $message = 'error', int $code = 1, mixed $data = null, int $http = 400): \Mtphp\Http\Response
    {
        return response()->json([
            'code'       => $code,
            'message'    => $message,
            'data'       => $data,
            'request_id' => request_id(),
        ], $http);
    }
}

if (!function_exists('api_paginate')) {
    /**
     * 统一分页响应（把 Paginator 包成标准信封，data 为当前页数据，meta 为分页元信息）
     *
     * 用法：return api_paginate(DB::table('users')->paginate(15));
     */
    function api_paginate(\Mtphp\Pagination\Paginator $paginator, string $message = 'ok'): \Mtphp\Http\Response
    {
        return response()->json([
            'code'       => 0,
            'message'    => $message,
            'data'       => $paginator->items(),
            'meta'       => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
            'request_id' => request_id(),
        ], 200);
    }
}