<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 数组工具（零依赖，纯 PHP 实现）
 *
 * 覆盖点号路径读写、展平/还原、过滤、排序、交叉、洗牌、分组等高频操作。
 *
 * 用法：
 *   Arr::get($data, 'user.profile.name', '默认值');
 *   Arr::set($data, 'user.age', 18);
 *   Arr::dot($data);                       // ['user.name' => '张三']
 *   Arr::only($data, ['a','b']);
 *   Arr::sortBy($rows, 'age', 'desc');
 *   Arr::crossJoin([1,2],[3,4]);           // [[1,3],[1,4],[2,3],[2,4]]
 */
class Arr
{
    use Macroable;

    /**
     * 按点号路径取值（支持数组与对象混合）
     */
    public static function get(array $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        $segments = explode('.', (string) $key);
        $cur      = $array;
        foreach ($segments as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } elseif (is_object($cur) && isset($cur->{$seg})) {
                $cur = $cur->{$seg};
            } else {
                return $default;
            }
        }
        return $cur;
    }

    /**
     * 按点号路径设值（修改并返回原数组引用语义：返回新数组）
     *
     * @return array<int|string,mixed>
     */
    public static function set(array $array, string $key, mixed $value): array
    {
        $segments = explode('.', $key);
        $cur      = &$array;
        $last     = array_pop($segments);
        foreach ($segments as $seg) {
            if (!isset($cur[$seg]) || !is_array($cur[$seg])) {
                $cur[$seg] = [];
            }
            $cur = &$cur[$seg];
        }
        $cur[$last] = $value;
        return $array;
    }

    /**
     * 是否存在点号路径
     */
    public static function has(array $array, string|array $keys): bool
    {
        foreach ((array) $keys as $key) {
            if (!self::exists($array, (string) $key)) {
                return false;
            }
        }
        return true;
    }

    protected static function exists(array $array, string $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }
        $segments = explode('.', $key);
        $cur      = $array;
        foreach ($segments as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return false;
            }
        }
        return true;
    }

    /**
     * 删除点号路径对应的值（返回新数组）
     *
     * @return array<int|string,mixed>
     */
    public static function forget(array $array, string $key): array
    {
        $segments = explode('.', $key);
        $last     = array_pop($segments);
        $cur      = &$array;
        foreach ($segments as $seg) {
            if (!isset($cur[$seg]) || !is_array($cur[$seg])) {
                return $array;
            }
            $cur = &$cur[$seg];
        }
        unset($cur[$last]);
        return $array;
    }

    /**
     * 展平为点号键数组
     *
     * @return array<string,mixed>
     */
    public static function dot(array $array, string $prepend = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $newKey = $prepend === '' ? (string) $key : $prepend . '.' . $key;
            if (is_array($value) && $value !== []) {
                $out += self::dot($value, $newKey);
            } else {
                $out[$newKey] = $value;
            }
        }
        return $out;
    }

    /**
     * 点号键数组还原为多维数组
     *
     * @return array<int|string,mixed>
     */
    public static function undot(array $dotted): array
    {
        $out = [];
        foreach ($dotted as $key => $value) {
            if (!str_contains((string) $key, '.')) {
                $out[$key] = $value;
                continue;
            }
            $out = self::set($out, (string) $key, $value);
        }
        return $out;
    }

    /**
     * 仅保留指定键
     *
     * @return array<int|string,mixed>
     */
    public static function only(array $array, array $keys): array
    {
        $want = array_flip($keys);
        return array_intersect_key($array, $want);
    }

    /**
     * 剔除指定键
     *
     * @return array<int|string,mixed>
     */
    public static function except(array $array, array $keys): array
    {
        $drop = array_flip($keys);
        return array_diff_key($array, $drop);
    }

    /**
     * 拆分出 [键数组, 值数组]
     *
     * @return array{0:list<string|int>,1:list<mixed>}
     */
    public static function divide(array $array): array
    {
        return [array_keys($array), array_values($array)];
    }

    /**
     * 展平嵌套数组（默认全部层级）
     *
     * @return list<mixed>
     */
    public static function flatten(array $array, int|float $depth = PHP_INT_MAX): array
    {
        $out = [];
        foreach ($array as $item) {
            if (is_array($item) && $depth > 0) {
                $out = array_merge($out, self::flatten($item, $depth - 1));
            } else {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * 按元素某键（或回调）排序，返回新数组
     *
     * @return array<int|string,mixed>
     */
    public static function sortBy(array $array, string|callable $key, string $direction = 'asc'): array
    {
        $dir = strtolower($direction) === 'desc' ? -1 : 1;
        usort($array, function (mixed $a, mixed $b) use ($key, $dir): int {
            $va = is_callable($key) ? $key($a) : (is_array($a) ? ($a[$key] ?? 0) : $a);
            $vb = is_callable($key) ? $key($b) : (is_array($b) ? ($b[$key] ?? 0) : $b);
            return $va <=> $vb * $dir;
        });
        return $array;
    }

    /**
     * 笛卡尔积（交叉组合）
     *
     * @return list<list<mixed>>
     */
    public static function crossJoin(array ...$arrays): array
    {
        $result = [[]];
        foreach ($arrays as $arr) {
            $next = [];
            foreach ($result as $combo) {
                foreach ($arr as $item) {
                    $next[] = [...$combo, $item];
                }
            }
            $result = $next;
        }
        return $result;
    }

    /**
     * 洗牌（可选种子，便于测试可复现），返回新数组
     *
     * @return array<int|string,mixed>
     */
    public static function shuffle(array $array, ?int $seed = null): array
    {
        if ($seed !== null) {
            mt_srand($seed);
        }
        $keys = array_keys($array);
        shuffle($keys);
        if ($seed !== null) {
            mt_srand();
        }
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $array[$k];
        }
        return $out;
    }

    /**
     * 提取某列
     *
     * @return list<mixed>
     */
    public static function pluck(array $array, string|int $value, ?string $key = null): array
    {
        $out = [];
        foreach ($array as $item) {
            $v = is_array($item) ? ($item[$value] ?? null) : (is_object($item) ? ($item->{$value} ?? null) : null);
            if ($key !== null) {
                $k    = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null);
                $out[$k] = $v;
            } else {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * 是否为关联数组（含非数字键）
     */
    public static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * 包裹为数组（非数组则包成单元素数组，null 转空数组）
     *
     * @return array<int|string,mixed>
     */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        return is_array($value) ? $value : [$value];
    }
}
