<?php
declare(strict_types=1);

namespace Mtphp;

use Mtphp\Config;
use Mtphp\Container;
use Mtphp\Database\DB;
use Mtphp\Router;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Middleware;
use Mtphp\Exception\HttpException;
use Mtphp\Exception\ApiException;
use Mtphp\Log\Logger;
use Mtphp\Http\Middleware\HealthCheck;
use Mtphp\Support\Reflect;

/**
 * 应用引导与请求生命周期管理
 *
 * 两种运行模式：
 *  - fpm  ：每次请求独立引导 → 处理 → 销毁（默认，兼容共享主机）
 *  - swoole：常驻内存（见 Mtphp\Server），本类接口保持不变
 */
class App
{
    protected string $root;

    protected ?Container $container = null;

    protected ?Config $config = null;

    protected ?Router $router = null;

    protected ?Middleware $middleware = null;

    /** @var static|null 全局实例（供 Route / Facade / 助手的静态访问） */
    protected static ?self $instance = null;

    /** @var object|null 当前请求的会话实例（由 StartSession 注入，供 redirect()->with() 等使用） */
    protected ?object $session = null;

    /** @var Request|null 当前正在处理的请求（供 request() 访问，便于助手函数取请求 ID 等） */
    protected ?Request $currentRequest = null;

    /** @var string 当前请求 ID（请求入口注入，错误响应与日志共用） */
    protected string $requestId = '';

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        static::$instance = $this;
        $this->bootstrap();
    }

    public static function instance(): ?App
    {
        return static::$instance;
    }

    /**
     * 加载项目根目录下的 .env 到环境变量（KEY=VALUE 格式，# 开头与空行忽略）。
     * 让 `key:generate` 写入的 APP_KEY 等配置在引导期即可被 getenv() 读到。
     * 已存在的环境变量不覆盖，便于容器/系统级注入优先。
     */
    public static function loadEnv(string $root): void
    {
        $file = rtrim($root, '/\\') . '/.env';
        if (!is_file($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            // 去掉首尾可能存在的引号
            if ((str_starts_with($v, '"') && str_ends_with($v, '"'))
                || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '' && getenv($k) === false) {
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * 注入当前会话实例（由 StartSession 中间件调用）
     */
    public function setSession(object $session): void
    {
        $this->session = $session;
    }

    /**
     * 获取当前会话实例（无则返回 null）
     */
    public function session(): ?object
    {
        return $this->session;
    }

    /**
     * 取当前请求实例（无请求上下文返回 null，如 CLI 命令）
     */
    public function request(): ?Request
    {
        return $this->currentRequest;
    }

    /**
     * 引导：加载配置、初始化容器/路由/中间件、注册路由、加载助手函数
     */
    protected function bootstrap(): void
    {
        self::loadEnv($this->root);

        $this->container  = new Container();
        $this->config     = new Config($this->root . '/config');
        date_default_timezone_set($this->config->get('app.timezone', 'Asia/Shanghai'));

        // 每个请求复位读写分离的 sticky 状态，避免 FPM 复用 worker 时把
        // “写过即读主”泄漏到后续请求（请求级读己写一致性的边界）
        DB::resetSticky();

        $this->router     = new Router($this->config, $this->root);
        $this->middleware = new Middleware();

        $this->container->instance('config', $this->config);
        $this->container->instance('router', $this->router);
        $this->container->instance('app', $this);

        // 全局助手函数（response / view / config / app）
        $helpers = __DIR__ . '/helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }

        // 路由：优先命中编译缓存，否则加载定义并（按需）写缓存
        $routes     = $this->root . '/app/Routes/routes.php';
        $cacheOn    = (bool) $this->config->get('route.cache');
        $cacheFile  = $this->root . '/' . ltrim((string) $this->config->get('route.cache_file'), '/\\');
        $useCache   = $cacheOn
            && is_file($cacheFile)
            && (!is_file($routes) || @filemtime($cacheFile) >= @filemtime($routes));

        if ($useCache) {
            $this->router->setRoutes(require $cacheFile);
        } else {
            if (is_file($routes)) {
                require $routes;
            }
            if ($cacheOn) {
                $this->router->saveCache($cacheFile);
            }
        }

        // 框架内置安全中间件（默认开启，可在 config/app.php 的 security.* 关闭）
        // 注册在应用全局中间件之前 → 处于洋葱最外层，能包裹包括 CSRF 在内的全部响应。
        if ((bool) $this->config->get('security.headers', true)) {
            $this->middleware->add(\Mtphp\Http\Middleware\SecurityHeaders::class);
        }
        if ((bool) $this->config->get('security.csrf', true)) {
            $this->middleware->add(\Mtphp\Http\Middleware\Csrf::class);
        }
        // CORS 跨域（默认关闭；security.cors => true 才注册，避免影响现有站点）
        if ((bool) $this->config->get('security.cors', false)) {
            $this->middleware->add(\Mtphp\Http\Middleware\Cors::class);
        }

        // 健康检查 / 探针端点（/healthz 存活、/ready 就绪），默认开启
        if ((bool) $this->config->get('app.health_probe', true)) {
            // 若配置了默认数据库连接，则把「db 连通性」作为就绪检查之一
            if ((string) $this->config->get('database.default', '') !== '') {
                HealthCheck::check('db', static function (): array {
                    try {
                        $pdo = DB::connection()->getPdo();
                        return ['ok' => $pdo instanceof \PDO];
                    } catch (\Throwable $e) {
                        return ['ok' => false, 'error' => $e->getMessage()];
                    }
                });
            }
            $this->middleware->add(HealthCheck::class);
        }

        // 注册应用全局中间件（来自 config/app.php 的 middleware 数组）
        foreach ($this->config->get('app.middleware', []) as $mw) {
            $this->middleware->add($mw);
        }
    }

    /**
     * 启动并输出响应（FPM / 内置服务器入口）
     */
    public function run(): void
    {
        try {
            $request  = Request::capture();
            $this->currentRequest = $request;
            // 请求 ID：贯穿日志链路与响应头，便于跨服务追踪
            $this->requestId = $request->requestId();
            Logger::setRequestId($this->requestId);

            $response = $this->handle($request);
        } catch (\Throwable $e) {
            $response = $this->toErrorResponse($e);
        }

        // 所有响应都回传 X-Request-Id（错误响应也带上，便于排查）
        if ($this->requestId !== '') {
            $response->header('X-Request-Id', $this->requestId);
        }
        $response->send();
    }

    /**
     * 处理单个请求：路由匹配 → 中间件管道 → 控制器
     */
    public function handle(Request $request): Response
    {
        $match = $this->router->dispatch($request->method(), $request->path());

        if ($match === null) {
            throw new HttpException(404, 'Not Found: ' . $request->path());
        }

        // 组装最终处理器（闭包 / 控制器方法）
        $core = $this->makeHandler($match);

        // 叠加路由级中间件，构成完整管道
        $pipeline = clone $this->middleware;
        foreach ($match['middleware'] as $mw) {
            $pipeline->add($mw);
        }

        return $pipeline->run($request, $core);
    }

    /**
     * 根据路由动作生成处理器闭包
     */
    protected function makeHandler(array $match): \Closure
    {
        return function (Request $request) use ($match) {
            $action = $match['action'];
            $params = $match['params'];

            if ($action instanceof \Closure) {
                return $this->callClosure($action, $request, $params);
            }

            [$class, $method] = $this->parseAction($action);
            $instance = $this->container->make($class);
            $ref      = new \ReflectionMethod($instance, $method);
            $args     = $this->bindParams($ref->getParameters(), $request, $params);
            $result   = $instance->$method(...$args);

            return $this->normalizeResponse($result, $request);
        };
    }

    protected function callClosure(\Closure $closure, Request $request, array $params): Response
    {
        $ref  = new \ReflectionFunction($closure);
        $args = $this->bindParams($ref->getParameters(), $request, $params);
        return $this->normalizeResponse($closure(...$args), $request);
    }

    /**
     * 将路由参数 / Request / 容器服务绑定到方法参数
     *
     * 兼容 PHP 8.1+ 联合类型与交叉类型（通过 Support\Reflect 解析）。
     */
    protected function bindParams(array $parameters, Request $request, array $routeParams): array
    {
        $args  = [];
        $named = $routeParams;

        foreach ($parameters as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // 注入 Request 实例
            if ($type !== null) {
                $typeName = Reflect::name($type);
                if ($typeName === Request::class) {
                    $args[] = $request;
                    continue;
                }
            }

            // 路由命名参数
            if (array_key_exists($name, $named)) {
                $args[] = $named[$name];
                unset($named[$name]);
                continue;
            }

            // 可选参数取默认值
            if ($param->isOptional()) {
                $args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                continue;
            }

            // 类型化且非内置 → 容器解析（依赖注入）
            if ($type !== null) {
                $typeName = Reflect::name($type);
                if ($typeName !== null && !Reflect::isBuiltinName($typeName) && class_exists($typeName)) {
                    $args[] = $this->container->make($typeName);
                    continue;
                }
            }

            $args[] = null;
        }

        return $args;
    }

    /**
     * @param string|array $action
     * @return array{0:string,1:string}
     */
    protected function parseAction(string|array $action): array
    {
        if (is_string($action) && strpos($action, '@') !== false) {
            return explode('@', $action, 2);
        }
        if (is_array($action) && count($action) === 2) {
            return [$action[0], $action[1]];
        }
        throw new \InvalidArgumentException('Invalid route action: ' . var_export($action, true));
    }

    protected function normalizeResponse(mixed $result, Request $request): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result) || is_object($result)) {
            return (new Response())->json($result);
        }
        return new Response((string) $result);
    }

    protected function toErrorResponse(\Throwable $e): Response
    {
        // 业务异常：直接渲染为统一 JSON 信封（业务码 + 数据）
        if ($e instanceof ApiException) {
            return (new Response())->json([
                'code'       => $e->getBusinessCode(),
                'message'    => $e->getMessage(),
                'data'       => $e->getData(),
                'request_id' => $this->requestId,
            ], $e->getStatusCode());
        }

        // 校验失败：422 + 统一信封（data.errors 供前端逐项展示）
        if ($e instanceof \Mtphp\Validation\ValidationException) {
            return (new Response())->json([
                'code'       => 422,
                'message'    => $e->getMessage() ?: '参数校验失败',
                'data'       => ['errors' => $e->errors()],
                'request_id' => $this->requestId,
            ], 422);
        }

        $code  = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $debug = (bool) $this->config->get('app.debug', false);

        // 服务器错误（500）记日志，避免静默吞错；无 logger 时退回 error_log
        if ($code === 500) {
            $context = [
                'exception' => get_class($e),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ];
            if (function_exists('logger')) {
                logger()->error($e->getMessage(), $context);
            } else {
                error_log('[mtphp] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        // API 请求（Accept json / ajax / application/json 体 / /api 前缀）：统一 JSON 信封
        if ($this->currentRequest !== null && $this->wantsJson($this->currentRequest)) {
            $data = [];
            if ($debug) {
                $data['file']  = $e->getFile() . ':' . $e->getLine();
                $data['trace'] = $e->getTraceAsString();
            }
            return (new Response())->json([
                'code'       => $code === 200 ? 1 : $code,
                'message'    => $debug ? $e->getMessage()
                    : ($code === 404 ? 'Not Found' : 'Internal Server Error'),
                'data'       => $data,
                'request_id' => $this->requestId,
            ], $code);
        }

        if ($debug) {
            $body = $e->getMessage() . "\n\n" . $e->getTraceAsString();
            return (new Response($body, $code))
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $message = $code === 404 ? 'Not Found' : 'Internal Server Error';
        $body    = $this->renderErrorPage($code, $message);
        return (new Response($body, $code))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 判断请求是否期望 JSON 响应（用于决定错误渲染格式：JSON 信封 vs HTML 页）
     */
    protected function wantsJson(Request $request): bool
    {
        $accept = $request->header('accept');
        if (is_string($accept) && stripos($accept, 'application/json') !== false) {
            return true;
        }
        if ($request->isAjax()) {
            return true;
        }
        $ct = $request->header('content-type');
        if (is_string($ct) && stripos($ct, 'application/json') !== false) {
            return true;
        }
        return str_starts_with($request->path(), '/api');
    }

    /**
     * 生产环境友好错误页（不含堆栈，避免信息泄露）
     */
    protected function renderErrorPage(int $code, string $message): string
    {
        $title = $code . ' ' . $message;
        $hint  = $code === 404 ? '您访问的页面不存在。' : '服务器开小差了，请稍后再试。';
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $title . '</title></head><body style="font-family:system-ui,sans-serif;'
            . 'padding:48px;color:#333"><h1 style="font-size:48px;margin:0">' . $code . '</h1>'
            . '<p style="font-size:18px">' . $hint . '</p></body></html>';
    }
}
