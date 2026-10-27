<?php
declare(strict_types=1);

namespace Mtphp;

use Mtphp\Support\Reflect;

/**
 * 轻量 IoC 容器 + 依赖注入
 *
 * 性能要点：构造器反射结果按类缓存，避免每次请求重复反射。
 */
class Container
{
    /** @var array<string,mixed> 抽象名 => 工厂闭包 */
    protected array $bindings = [];

    /** @var array<string,mixed> 抽象名 => 已解析单例/实例 */
    protected array $instances = [];

    /** @var array<string,\ReflectionMethod|null> 构造器反射缓存 */
    protected array $reflectionCache = [];

    /**
     * 绑定原型（每次 make 新建）
     */
    public function bind(string $abstract, \Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract]);
    }

    /**
     * 绑定单例（首次解析后复用）
     */
    public function singleton(string $abstract, \Closure $factory): void
    {
        $this->bind($abstract, function (Container $container) use ($factory) {
            static $inst;
            if ($inst === null) {
                $inst = $factory($container);
            }
            return $inst;
        });
    }

    /**
     * 直接注册一个已构建好的实例（单例）
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        unset($this->bindings[$abstract]);
    }

    /**
     * 解析一个抽象（实例优先，其次工厂，其次反射自动构建）
     */
    public function make(string $abstract, array $params = []): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]($this);
        }
        return $this->reflectMake($abstract, $params);
    }

    /**
     * 反射自动构建（带缓存）
     */
    protected function reflectMake(string $class, array $params): mixed
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Class not found: {$class}");
        }
        if (!isset($this->reflectionCache[$class])) {
            $this->reflectionCache[$class] = (new \ReflectionClass($class))->getConstructor();
        }
        $ctor = $this->reflectionCache[$class];

        if ($ctor === null) {
            return new $class();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();
            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
                continue;
            }
            $type = $p->getType();
            if ($type !== null) {
                $typeName = Reflect::name($type);
                if ($typeName !== null && !Reflect::isBuiltinName($typeName) && class_exists($typeName)) {
                    $args[] = $this->make($typeName);
                    continue;
                }
            }
            $args[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
        }
        return new $class(...$args);
    }
}
