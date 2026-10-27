<?php
declare(strict_types=1);

namespace Mtphp\Support;

/**
 * 反射类型辅助
 *
 * PHP 8.1+ 引入联合类型（string|int）与交叉类型，其反射对象
 * （ReflectionUnionType / ReflectionIntersectionType）没有 getName() / isBuiltin()，
 * 直接在旧代码里调用会致命。本类统一解析出「可用于容器解析/内置判断」的类型名。
 */
final class Reflect
{
    /** 内置标量/特殊类型白名单（用于判定是否参与容器解析） */
    private const BUILTINS = [
        'int'      => 1, 'float' => 1, 'string' => 1, 'bool' => 1, 'array' => 1,
        'callable' => 1, 'iterable' => 1, 'object' => 1, 'mixed' => 1, 'null' => 1,
        'void'     => 1, 'never' => 1, 'false' => 1, 'true' => 1, 'self' => 1,
        'static'   => 1, 'parent' => 1,
    ];

    /**
     * 解析反射类型为类型名
     *
     *  - 命名类型：直接返回
     *  - 联合/交叉类型：返回首个非 null 的具体类型名（优先类/接口）
     */
    public static function name(\ReflectionType $type): ?string
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $t) {
                if (!$t instanceof \ReflectionNamedType) {
                    continue;
                }
                $n = $t->getName();
                if ($n !== 'null' && $n !== 'mixed') {
                    return $n;
                }
            }
            foreach ($type->getTypes() as $t) {
                if ($t instanceof \ReflectionNamedType) {
                    return $t->getName();
                }
            }
        }
        return null;
    }

    public static function isBuiltinName(string $name): bool
    {
        return isset(self::BUILTINS[$name]);
    }
}
