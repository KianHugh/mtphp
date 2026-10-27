<?php
declare(strict_types=1);

namespace Mtphp;

use Mtphp\Config;
use Mtphp\Exception\ModelNotFoundException;

/**
 * 路由收集、匹配与编译缓存
 *
 * 性能要点：匹配用的「正则 + 参数名」可编译为 PHP 数组落地 runtime/cache，
 * 后续请求直接 include 查表，避免每次遍历与正则编译。闭包动作无法序列化，自动跳过缓存。
 */
class Router
{
    /** @var array<string,list<array>> method => [ [uri,regex,params,action,middleware,name], ... ] */
    protected array $routes = [];

    /** @var array<string,array{uri:string,params:list<string>}> 命名路由映射 */
    protected array $named = [];

    /** @var array<int,array> 分组属性栈（前缀 / 中间件 / 命名空间 / 名称前缀） */
    protected array $groupStack = [];

    /** @var array<string,string|callable> 路由模型绑定：参数名 => 模型类或自定义解析器 */
    protected array $bindings = [];

    protected Config $config;

    protected string $root;

    public function __construct(Config $config, string $root)
    {
        $this->config = $config;
        $this->root   = $root;
    }

    public function get(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $uri, $action, $middleware, $name);
    }

    public function post(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $uri, $action, $middleware, $name);
    }

    public function put(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        $this->add('PUT', $uri, $action, $middleware, $name);
    }

    public function delete(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        $this->add('DELETE', $uri, $action, $middleware, $name);
    }

    /**
     * 注册到全部常用方法
     */
    public function any(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $m) {
            $this->add($m, $uri, $action, $middleware, $name);
        }
    }

    /**
     * 注册路由模型绑定（在定义路由之前调用）
     *
     * 两种用法：
     *   Route::bind('user', \App\Models\User::class);            // 自动按主键 findOrFail
     *   Route::bind('user', fn($id) => User::findOrFail($id));   // 自定义解析
     *
     * 当路由参数名与 $key 一致时，自动把原始值解析为模型实例（找不到 → 抛出
     * ModelNotFoundException，由 App 映射为 404）。绑定会随路由一起写入编译缓存，
     * 因此路由缓存开启时同样生效（前提是 bind() 在对应路由注册之前调用）。
     *
     * @param string          $key      路径参数名（如 'user' 对应 {user}）
     * @param string|callable $resolver 模型类名（字符串）或自定义解析闭包
     */
    public function bind(string $key, string|callable $resolver): void
    {
        $this->bindings[$key] = $resolver;
    }

    /**
     * 批量注册 RESTful 资源路由（七条）
     *
     *   Route::resource('users', 'App\Controllers\UserController');
     *   → GET /users            users.index   @index
     *     GET /users/create     users.create  @create
     *     POST /users           users.store   @store
     *     GET /users/{id}       users.show    @show
     *     GET /users/{id}/edit  users.edit    @edit
     *     PUT /users/{id}       users.update  @update
     *     DELETE /users/{id}    users.destroy @destroy
     */
    public function resource(string $name, string $controller, array $middleware = []): void
    {
        $this->get(('/' . ltrim($name, '/')), $controller . '@index', $middleware, $name . '.index');
        $this->get(('/' . ltrim($name, '/') . '/create'), $controller . '@create', $middleware, $name . '.create');
        $this->post(('/' . ltrim($name, '/')), $controller . '@store', $middleware, $name . '.store');
        $this->get(('/' . ltrim($name, '/') . '/{id}'), $controller . '@show', $middleware, $name . '.show');
        $this->get(('/' . ltrim($name, '/') . '/{id}/edit'), $controller . '@edit', $middleware, $name . '.edit');
        $this->put(('/' . ltrim($name, '/') . '/{id}'), $controller . '@update', $middleware, $name . '.update');
        $this->delete(('/' . ltrim($name, '/') . '/{id}'), $controller . '@destroy', $middleware, $name . '.destroy');
    }

    /**
     * 路由分组：支持 prefix / middleware / namespace / as（名称前缀）
     */
    public function group(array $attrs, \Closure $callback): void
    {
        $this->groupStack[] = $attrs;
        $callback($this);
        array_pop($this->groupStack);
    }

    protected function add(string $method, string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        $prefix     = '';
        $namePrefix = '';
        $ns         = '';
        $mw         = $middleware;

        foreach ($this->groupStack as $g) {
            $prefix .= $g['prefix'] ?? '';
            $namePrefix .= $g['as'] ?? '';
            if (!empty($g['middleware'])) {
                $mw = array_merge($mw, (array) $g['middleware']);
            }
            if (!empty($g['namespace'])) {
                $ns = $g['namespace'];
            }
        }

        $uri = $prefix . $uri;

        // 分组命名空间作用于字符串动作（Class@method 形式）
        if ($ns && is_string($action) && strpos($action, '@') !== false) {
            [$c, $m] = explode('@', $action, 2);
            $action = $ns . '\\' . ltrim($c, '\\') . '@' . $m;
        }

        // 命名路由（带分组名称前缀）
        $fullName = $name !== null ? $namePrefix . $name : null;

        [$regex, $paramNames] = $this->compile($uri);

        if ($fullName !== null && $fullName !== '') {
            $this->named[$fullName] = ['uri' => $uri, 'params' => $paramNames];
        }

        $this->routes[$method][] = [
            'uri'        => $uri,
            'regex'      => $regex,
            'params'     => $paramNames,
            'action'     => $action,
            'middleware' => $mw,
            'name'       => $fullName,
            'bindings'   => $this->bindings,
        ];
    }

    /**
     * 由命名路由生成 URL，并代入路径参数（剩余参数作为 query）
     *
     * @param array<string,mixed> $params
     */
    public function route(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new \RuntimeException("未定义命名路由：{$name}");
        }
        $uri = $this->named[$name]['uri'];
        $uri = preg_replace_callback('#\{([^}]+)\}#', function ($m) use (&$params) {
            $inner = $m[1];
            $key   = strpos($inner, ':') !== false ? explode(':', $inner, 2)[0] : $inner;
            if (array_key_exists($key, $params)) {
                $v = (string) $params[$key];
                unset($params[$key]);
                return $v;
            }
            return $m[0];
        }, $uri);

        if ($params !== []) {
            $uri .= (strpos($uri, '?') === false ? '?' : '&') . http_build_query($params);
        }
        return $uri;
    }

    /**
     * 将 /user/{id:\d+} 编译为正则并提取参数名
     *
     * @return array{0:string,1:list<string>}
     */
    protected function compile(string $uri): array
    {
        $paramNames = [];
        $pattern = preg_replace_callback('#\{([^}]+)\}#', function ($m) use (&$paramNames) {
            $inner = $m[1];
            if (strpos($inner, ':') !== false) {
                [$name, $constraint] = explode(':', $inner, 2);
                $paramNames[] = $name;
                return '(?P<' . $name . '>' . $constraint . ')';
            }
            $paramNames[] = $inner;
            return '(?P<' . $inner . '>[^/]+)';
        }, $uri);

        return ['#^' . $pattern . '$#', $paramNames];
    }

    /**
     * 匹配路由；返回 [action, params, middleware] 或 null
     *
     * @return array{action:mixed,params:array,middleware:list<string>}|null
     */
    public function dispatch(string $method, string $path): ?array
    {
        $method = strtoupper($method);

        if (!isset($this->routes[$method])) {
            // HEAD 回退到 GET
            if ($method === 'HEAD' && isset($this->routes['GET'])) {
                $method = 'GET';
            } else {
                return null;
            }
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                $params = [];
                foreach ($route['params'] as $name) {
                    $params[$name] = $matches[$name] ?? null;
                }

                // 路由模型绑定：把路径参数解析为模型实例（找不到 → 404）
                $bindings = $route['bindings'] ?? [];
                foreach ($bindings as $key => $resolver) {
                    if (!array_key_exists($key, $params)) {
                        continue;
                    }
                    $value    = $params[$key];
                    $resolved = null;
                    if (is_string($resolver) && class_exists($resolver)) {
                        $resolved = $resolver::find($value);
                    } elseif (is_callable($resolver)) {
                        $resolved = $resolver($value);
                    }
                    if ($resolved === null) {
                        throw new ModelNotFoundException(
                            "路由模型绑定失败：参数 {$key}={$value} 对应的记录不存在"
                        );
                    }
                    $params[$key] = $resolved;
                }

                return [
                    'action'     => $route['action'],
                    'params'     => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }

        return null;
    }

    public function setRoutes(array $routes): void
    {
        $this->routes = $routes;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 写出路由编译缓存（闭包动作不缓存）
     */
    public function saveCache(string $file): void
    {
        $exportable = [];
        foreach ($this->routes as $method => $list) {
            foreach ($list as $r) {
                if ($r['action'] instanceof \Closure) {
                    continue;
                }
                $exportable[$method][] = $r;
            }
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, '<?php return ' . var_export($exportable, true) . ';' . PHP_EOL);
    }
}
