<?php
declare(strict_types=1);

namespace Mtphp;

/**
 * 门面基类：静态代理到容器中的服务实例
 *
 * 子类实现 getFacadeAccessor() 返回容器抽象名，即可用 DB::table() 这类静态风格调用。
 */
abstract class Facade
{
    protected static function getFacadeAccessor(): string
    {
        throw new \RuntimeException('Facade 必须实现 getFacadeAccessor()');
    }

    public static function __callStatic(string $method, array $args): mixed
    {
        $accessor = static::getFacadeAccessor();
        $instance = App::instance()->container()->make($accessor);
        return $instance->$method(...$args);
    }
}
