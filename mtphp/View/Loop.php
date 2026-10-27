<?php
declare(strict_types=1);

namespace Mtphp\View;

/**
 * 模板循环变量（Blade 风格，思想借鉴、独立实现）$loop
 *
 * 在 @foreach / @forelse 内部会自动可用，提供当前迭代的元信息，
 * 免去手动维护 $i / $total 等计数器，写列表更直观：
 *
 *   @foreach ($users as $u)
 *     @if ($loop->first) <hr> @endif
 *     ({{ $loop->iteration }}/{{ $loop->count }}) {{ $u->name }}
 *   @endforeach
 *
 * 嵌套循环时通过 $loop->parent 可访问外层循环（如 $loop->parent->index）。
 */
class Loop
{
    /** 从 0 开始的下标 */
    public int $index = 0;
    /** 从 1 开始的序号 */
    public int $iteration = 0;
    /** 集合总条数 */
    public int $count = 0;
    /** 是否为第一项 */
    public bool $first = false;
    /** 是否为最后一项 */
    public bool $last = false;
    /** 序号是否为偶数（从 1 计） */
    public bool $even = false;
    /** 序号是否为奇数（从 1 计） */
    public bool $odd = false;
    /** 还剩几项未迭代 */
    public int $remaining = 0;
    /** 嵌套层级，最外层为 1 */
    public int $depth = 1;
    /** 父级循环变量（嵌套时可用），无则 null */
    public ?Loop $parent = null;

    /** @var array<int, mixed> 待遍历的数据（已转为数组，保持原顺序） */
    private array $items;
    private int $pos = 0;

    /**
     * @param iterable<mixed> $items 要遍历的集合（数组或可遍历对象）
     * @param Loop|null       $parent 外层循环变量（嵌套时传入）
     */
    public function __construct(iterable $items, ?Loop $parent = null)
    {
        $this->items = is_array($items) ? $items : iterator_to_array($items);
        $this->count = count($this->items);
        $this->parent = $parent;
        $this->depth = $parent ? $parent->depth + 1 : 1;
    }

    /**
     * 当前要遍历的数组（保持原始顺序，供 foreach 使用）
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * 每迭代一次调用一次：根据当前位置刷新元信息，并返回自身（即模板里的 $loop）。
     */
    public function advance(): Loop
    {
        $this->index = $this->pos;
        $this->iteration = $this->pos + 1;
        $this->first = $this->pos === 0;
        $this->last = $this->thisIsLast();
        $this->remaining = $this->count - $this->iteration;
        $this->even = ($this->iteration % 2) === 0;
        $this->odd = !$this->even;
        $this->pos++;
        return $this;
    }

    private function thisIsLast(): bool
    {
        return $this->count === 0 || $this->pos === $this->count - 1;
    }
}
