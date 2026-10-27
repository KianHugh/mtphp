<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 集合（数组的函数式封装，零依赖）
 *
 * 把数组包成可链式调用的对象，提供 map/filter/reduce/pluck/sum/avg/sort 等常用操作，
 * 让查询结果、配置数组的处理更顺手。思想借鉴主流框架的 Collection（独立实现）。
 *
 * 用法：
 *   collect([1,2,3])->map(fn($x)=>$x*2)->filter(fn($x)=>$x>3)->toArray();  // [4,6]
 *   collect($users)->pluck('id')->implode(',');                            // "1,2,3"
 */
class Collection implements \Countable, \IteratorAggregate, \ArrayAccess
{
    use Macroable;

    /** @var array<int|string,mixed> */
    protected array $items;

    /**
     * @param array<int|string,mixed> $items
     * @param bool $preserveKeys 是否保留传入数组的键（默认重索引，便于链式 map/filter；keyBy 等需用 true）
     */
    public function __construct(array $items = [], bool $preserveKeys = false)
    {
        $this->items = $preserveKeys ? $items : array_values($items);
    }

    /**
     * 工厂（支持 null，便于 collect($maybeNull)）
     *
     * @param array<int|string,mixed>|null $items
     */
    public static function make(?array $items = null): self
    {
        return new self($items ?? []);
    }

    /** @return array<int|string,mixed> */
    public function all(): array
    {
        return $this->items;
    }

    /** @return array<int|string,mixed> */
    public function toArray(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * 取首个元素（可传回调返回首个满足条件的）
     *
     * @return mixed
     */
    public function first(?callable $callback = null): mixed
    {
        foreach ($this->items as $item) {
            if ($callback === null || $callback($item)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 取最后一个元素（可传回调）
     *
     * @return mixed
     */
    public function last(?callable $callback = null): mixed
    {
        $rev = array_reverse($this->items, true);
        foreach ($rev as $item) {
            if ($callback === null || $callback($item)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 遍历（仅副作用，返回自身便于链式）
     */
    public function each(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            $callback($item, $key);
        }
        return $this;
    }

    /**
     * 映射为新集合
     */
    public function map(callable $callback): self
    {
        $out = [];
        foreach ($this->items as $key => $item) {
            $out[$key] = $callback($item, $key);
        }
        return new self($out);
    }

    /**
     * 过滤（默认保留真值元素；传回调则保留回调为真者）
     */
    public function filter(?callable $callback = null): self
    {
        $out = [];
        foreach ($this->items as $key => $item) {
            if ($callback === null ? !empty($item) : $callback($item, $key)) {
                $out[$key] = $item;
            }
        }
        return new self(array_values($out));
    }

    /**
     * 规约
     *
     * @param mixed $initial
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $acc = $initial;
        foreach ($this->items as $item) {
            $acc = $callback($acc, $item);
        }
        return $acc;
    }

    /**
     * 求和（可指定元素键，如 collect($orders)->sum('amount')）
     *
     * @return float|int
     */
    public function sum(string|int|null $key = null): float|int
    {
        return $this->reduce(
            fn ($acc, $item) => $acc + ($key === null ? $item : ($item[$key] ?? 0)),
            0
        );
    }

    /**
     * 平均值
     *
     * @return float|int
     */
    public function avg(string|int|null $key = null): float|int
    {
        $n = $this->count();
        return $n === 0 ? 0 : $this->sum($key) / $n;
    }

    /**
     * 最大值 / 最小值
     *
     * @return mixed
     */
    public function max(string|int|null $key = null): mixed
    {
        return $this->reduce(
            fn ($acc, $item) => $acc === null
                ? ($key === null ? $item : ($item[$key] ?? null))
                : max($acc, $key === null ? $item : ($item[$key] ?? PHP_INT_MIN)),
            null
        );
    }

    public function min(string|int|null $key = null): mixed
    {
        return $this->reduce(
            fn ($acc, $item) => $acc === null
                ? ($key === null ? $item : ($item[$key] ?? null))
                : min($acc, $key === null ? $item : ($item[$key] ?? PHP_INT_MAX)),
            null
        );
    }

    /**
     * 提取某列（pluck），返回新集合
     */
    public function pluck(string|int $key): self
    {
        $out = [];
        foreach ($this->items as $item) {
            if (is_array($item) && array_key_exists($key, $item)) {
                $out[] = $item[$key];
            } elseif (is_object($item) && isset($item->{$key})) {
                $out[] = $item->{$key};
            }
        }
        return new self($out);
    }

    /**
     * 是否包含某值（或满足回调）
     */
    public function contains(mixed $needle): bool
    {
        if ($needle instanceof \Closure) {
            foreach ($this->items as $item) {
                if ($needle($item)) {
                    return true;
                }
            }
            return false;
        }
        return in_array($needle, $this->items, true);
    }

    /**
     * 是否都不包含（contains 的反义，便于语义化）
     */
    public function doesntContain(mixed $needle): bool
    {
        return !$this->contains($needle);
    }

    /**
     * 以指定键（元素某列 / 回调返回值）为索引重组集合（保留键，返回新集合）。
     *
     *   collect($users)->keyBy('id')         // ['1' => user1, '2' => user2]
     *   collect($rows)->keyBy(fn($r) => $r['type'])
     */
    public function keyBy(string|int|callable $key): self
    {
        $out = [];
        foreach ($this->items as $item) {
            $k = is_callable($key)
                ? $key($item)
                : (is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->{$key} ?? null) : null));
            $out[$k] = $item;
        }
        return new self($out, true);
    }

    /**
     * 按元素键 / 值（或回调）过滤，返回新集合。
     *
     *   collect($users)->where('active', 1)              // 元素数组 active===1
     *   collect($users)->where('age', '>', 18)
     *   collect($rows)->where(fn($r) => $r['score'] > 60) // 回调返回真即保留
     *
     * 仅传回调时为回调形式；传 (key, value) 时默认按 == 比较；传 (key, op, value) 时按操作符比较。
     */
    public function where(string|int|callable $key, mixed $operator = null, mixed $value = null): self
    {
        if (is_callable($key)) {
            return new self(array_values(array_filter($this->items, $key)));
        }
        if ($value === null) {
            $value    = $operator;
            $operator = '=';
        }
        $out = [];
        foreach ($this->items as $item) {
            $actual = is_array($item) ? ($item[$key] ?? null)
                : (is_object($item) ? ($item->{$key} ?? null) : null);
            if ($this->compare($actual, (string) $operator, $value)) {
                $out[] = $item;
            }
        }
        return new self($out);
    }

    /**
     * 按元素某键的值是否属于给定集合过滤，返回新集合。
     *
     *   collect($users)->whereIn('id', [1, 2, 3])
     */
    public function whereIn(string|int $key, array $values): self
    {
        $out = [];
        foreach ($this->items as $item) {
            $actual = is_array($item) ? ($item[$key] ?? null)
                : (is_object($item) ? ($item->{$key} ?? null) : null);
            if (in_array($actual, $values, true)) {
                $out[] = $item;
            }
        }
        return new self($out);
    }

    /**
     * 取首个满足 key/value（或回调）条件的元素；无匹配返回 null。
     *
     *   collect($users)->firstWhere('id', 5)            // 首个 id===5 的元素
     *   collect($rows)->firstWhere(fn($r) => $r['age'] > 30)
     */
    public function firstWhere(string|int|callable $key, mixed $operator = null, mixed $value = null): mixed
    {
        foreach ($this->items as $item) {
            if (is_callable($key)) {
                if ($key($item)) {
                    return $item;
                }
                continue;
            }
            if ($value === null) {
                $value    = $operator;
                $operator = '=';
            }
            $actual = is_array($item) ? ($item[$key] ?? null)
                : (is_object($item) ? ($item->{$key} ?? null) : null);
            if ($this->compare($actual, (string) $operator, $value)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 操作符比较（供 where / firstWhere 复用）
     */
    protected function compare(mixed $actual, string $operator, mixed $value): bool
    {
        return match ($operator) {
            '=', '==', 'eq'  => $actual == $value,
            '===', 'seq'     => $actual === $value,
            '!=', '<>', 'neq' => $actual != $value,
            '!=='            => $actual !== $value,
            '>', 'gt'        => $actual > $value,
            '>=', 'gte'      => $actual >= $value,
            '<', 'lt'        => $actual < $value,
            '<=', 'lte'      => $actual <= $value,
            'like'           => is_string($actual) && is_string($value) && str_contains($actual, $value),
            default          => $actual == $value,
        };
    }

    /** @return self 键集合 */
    public function keys(): self
    {
        return new self(array_keys($this->items));
    }

    /** @return self 值集合（重索引） */
    public function values(): self
    {
        return new self(array_values($this->items));
    }

    /**
     * 排序（传回调自定义；不传按值升序）
     */
    public function sort(?callable $callback = null): self
    {
        $arr = $this->items;
        if ($callback === null) {
            sort($arr);
        } else {
            usort($arr, $callback);
        }
        return new self($arr);
    }

    /**
     * 按元素某键排序（dir: asc/desc）
     */
    public function sortBy(string|int $key, string $dir = 'asc'): self
    {
        $arr = $this->items;
        usort($arr, function ($a, $b) use ($key, $dir): int {
            $va = is_array($a) ? ($a[$key] ?? 0) : (is_object($a) ? ($a->{$key} ?? 0) : $a);
            $vb = is_array($b) ? ($b[$key] ?? 0) : (is_object($b) ? ($b->{$key} ?? 0) : $b);
            $cmp = $va <=> $vb;
            return $dir === 'desc' ? -$cmp : $cmp;
        });
        return new self($arr);
    }

    /**
     * 拼接为字符串（可指定元素键）
     */
    public function implode(string $glue, string|int|null $key = null): string
    {
        $vals = $key === null ? $this->items : $this->pluck($key)->all();
        return implode($glue, $vals);
    }

    /** 追加元素（返回新集合） */
    public function push(mixed $value): self
    {
        $items = $this->items;
        $items[] = $value;
        return new self($items);
    }

    /** 合并（返回新集合，重索引） */
    public function merge(array $items): self
    {
        return new self(array_values(array_merge($this->items, array_values($items))));
    }

    /**
     * 去重（可指定元素键），返回新集合
     */
    public function unique(string|int|null $key = null): self
    {
        $seen = [];
        $out  = [];
        foreach ($this->items as $item) {
            $k = $key === null ? $item : (is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null));
            if (!in_array($k, $seen, true)) {
                $seen[] = $k;
                $out[]  = $item;
            }
        }
        return new self($out);
    }

    /**
     * 分组（按回调返回值或元素键），返回以分组键为索引的数组
     *
     * @return array<string,mixed>
     */
    public function groupBy(string|callable $key): array
    {
        $out = [];
        foreach ($this->items as $item) {
            $gk = is_callable($key)
                ? $key($item)
                : (is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null));
            $out[$gk][] = $item;
        }
        return $out;
    }

    /**
     * 分块（每 $size 个一组），返回二维数组
     *
     * @return array<int,array>
     */
    public function chunk(int $size): array
    {
        return array_chunk($this->items, max(1, $size));
    }

    /**
     * 管道：把当前集合交给回调，返回回调结果（便于收尾）
     *
     * @return mixed
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * 只保留指定键对应的元素（键不在名单内的丢弃），返回新集合（保留原键）。
     *
     *   collect(['a'=>1,'b'=>2,'c'=>3])->only(['a','c'])  // ['a'=>1,'c'=>3]
     */
    public function only(array $keys): self
    {
        $want = array_flip($keys);
        $out  = [];
        foreach ($this->items as $k => $v) {
            if (isset($want[$k])) {
                $out[$k] = $v;
            }
        }
        return new self($out, true);
    }

    /**
     * 剔除指定键对应的元素（键在名单内的丢弃），返回新集合（保留原键）。
     *
     *   collect(['a'=>1,'b'=>2,'c'=>3])->except(['b'])  // ['a'=>1,'c'=>3]
     */
    public function except(array $keys): self
    {
        $drop = array_flip($keys);
        $out  = [];
        foreach ($this->items as $k => $v) {
            if (!isset($drop[$k])) {
                $out[$k] = $v;
            }
        }
        return new self($out, true);
    }

    /**
     * 按回调把集合拆成两部分：[通过(真), 未通过(假)]，各为一个 Collection。
     *
     *   [$pass, $fail] = collect($nums)->partition(fn($n) => $n > 3);
     */
    public function partition(callable $callback): array
    {
        $pass = [];
        $fail = [];
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                $pass[$key] = $item;
            } else {
                $fail[$key] = $item;
            }
        }
        return [new self($pass, true), new self($fail, true)];
    }

    /**
     * 与一个或多个数组按元素位置一一配对，返回 [ [$a0,$b0,...], [$a1,$b1,...], ... ]。
     * 以当前集合长度为准；其余数组不足的位置以 null 补齐。
     *
     *   collect([1,2,3])->zip([4,5,6])           // [[1,4],[2,5],[3,6]]
     *   collect([1,2,3])->zip([4,5],[7,8])        // [[1,4,7],[2,5,8],[3,6,null]]
     */
    public function zip(array ...$arrays): self
    {
        $out = [];
        $count = count($this->items);
        $values = array_values($this->items);
        for ($i = 0; $i < $count; $i++) {
            $tuple = [$values[$i]];
            foreach ($arrays as $arr) {
                $tuple[] = $arr[$i] ?? null;
            }
            $out[] = $tuple;
        }
        return new self($out);
    }

    /**
     * 折叠一层嵌套数组（把元素中的子数组展平一级），返回新集合（重索引）。
     *
     *   collect([[1,2],[3],[4,5]])->collapse()  // [1,2,3,4,5]
     */
    public function collapse(): self
    {
        $out = [];
        foreach ($this->items as $item) {
            if (is_array($item)) {
                foreach ($item as $v) {
                    $out[] = $v;
                }
            } else {
                $out[] = $item;
            }
        }
        return new self($out);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}
