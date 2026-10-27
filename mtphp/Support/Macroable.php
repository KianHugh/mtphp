<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 宏系统（零依赖，借鉴主流框架的 Macroable）
 *
 * 允许在运行时给静态工具类（Str / Arr）或集合（Collection）动态挂载方法，
 * 便于业务侧扩展而不改框架源码。
 *
 * 用法：
 *   Str::macro('alternate', fn (string $s) => strtolower($s) ^ strtoupper($s)); // 举例
 *   Str::alternate('abc');
 *   collect([1,2])->macro('sum2', fn () => $this->sum() * 2);
 */
trait Macroable
{
    /** @var array<class-string,array<string,callable>> */
    protected static array $macros = [];

    /**
     * 注册一个宏
     *
     * @param callable $macro 静态宏接收静态调用参数；实例宏首个参数为实例本身
     */
    public static function macro(string $name, callable $macro): void
    {
        self::$macros[static::class][$name] = $macro;
    }

    /**
     * 是否已注册该宏
     */
    public static function hasMacro(string $name): bool
    {
        return isset(self::$macros[static::class][$name]);
    }

    /**
     * 静态宏转发
     */
    public static function __callStatic(string $name, array $args): mixed
    {
        if (!isset(self::$macros[static::class][$name])) {
            throw new \BadMethodCallException(sprintf('未定义的宏：%s::%s', static::class, $name));
        }
        return (self::$macros[static::class][$name])(...$args);
    }

    /**
     * 实例宏转发（首个参数为实例自身）
     */
    public function __call(string $name, array $args): mixed
    {
        if (!isset(self::$macros[static::class][$name])) {
            throw new \BadMethodCallException(sprintf('未定义的宏：%s::%s', static::class, $name));
        }
        return (self::$macros[static::class][$name])($this, ...$args);
    }
}
