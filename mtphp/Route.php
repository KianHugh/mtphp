<?php
declare(strict_types=1);

namespace Mtphp;

/**
 * 路由静态代理（门面式注册）
 *
 * 用法（app/Routes/routes.php）：
 *   use Mtphp\Route;
 *   Route::get('/', [App\Controllers\HomeController::class, 'index']);
 *   Route::group(['prefix'=>'/api','middleware'=>[...]], function(){ ... });
 */
class Route
{
    public static function get(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        self::router()->get($uri, $action, $middleware, $name);
    }

    public static function post(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        self::router()->post($uri, $action, $middleware, $name);
    }

    public static function put(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        self::router()->put($uri, $action, $middleware, $name);
    }

    public static function delete(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        self::router()->delete($uri, $action, $middleware, $name);
    }

    public static function any(string $uri, string|array|\Closure $action, array $middleware = [], ?string $name = null): void
    {
        self::router()->any($uri, $action, $middleware, $name);
    }

    /**
     * 注册路由模型绑定（详见 Mtphp\Router::bind）
     *
     * @param string          $key
     * @param string|callable $resolver
     */
    public static function bind(string $key, string|callable $resolver): void
    {
        self::router()->bind($key, $resolver);
    }

    /**
     * 批量注册 RESTful 资源路由
     *
     * @param array<string> $middleware
     */
    public static function resource(string $name, string $controller, array $middleware = []): void
    {
        self::router()->resource($name, $controller, $middleware);
    }

    public static function group(array $attrs, \Closure $callback): void
    {
        self::router()->group($attrs, $callback);
    }

    protected static function router(): Router
    {
        $app = App::instance();
        if ($app === null) {
            throw new \RuntimeException('App 尚未引导，无法注册路由');
        }
        return $app->router();
    }
}
