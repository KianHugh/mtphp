<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

/**
 * 多态一对一（morphOne，零依赖，思想借鉴主流框架的 morphOne）
 *
 * 与 MorphMany 同源，仅取单条结果（取第一条匹配）。
 */
class MorphOne extends Relation
{
    protected string $morphName;
    protected string $morphType;
    protected string $morphId;
    protected string $morphClass;

    public function __construct(string $related, string $morphName, string $morphType, string $morphId, string $localKey, string $morphClass)
    {
        parent::__construct($related, $morphId, $localKey);
        $this->morphName  = $morphName;
        $this->morphType  = $morphType;
        $this->morphId    = $morphId;
        $this->localKey   = $localKey;
        $this->parentKey  = $localKey;
        $this->morphClass = $morphClass;
    }

    public function getMorphName(): string
    {
        return $this->morphName;
    }

    public function getMorphType(): string
    {
        return $this->morphType;
    }

    public function getMorphId(): string
    {
        return $this->morphId;
    }

    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    public function getResults(array $parent): ?array
    {
        $key = $parent[$this->localKey] ?? null;
        if ($key === null) {
            return null;
        }
        $q = ($this->related)::query()
            ->where($this->morphType, '=', $this->morphClass)
            ->where($this->morphId, '=', $key);
        $this->applyConstraints($q);
        return $q->find();
    }

    public function getEager(array $parents): array
    {
        $keys = $this->collectKeys($parents, $this->localKey);
        if ($keys === []) {
            return [];
        }
        $query = ($this->related)::query()
            ->where($this->morphType, '=', $this->morphClass)
            ->whereIn($this->morphId, $keys);
        $this->applyConstraints($query);
        $rows  = $query->get();
        $map   = [];
        foreach ($rows as $r) {
            $map[$r[$this->morphId]] = $r;
        }
        return $map;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,mixed>
     */
    protected function collectKeys(array $rows, string $key): array
    {
        $keys = [];
        foreach ($rows as $r) {
            if (isset($r[$key])) {
                $keys[] = $r[$key];
            }
        }
        return array_values(array_unique($keys, SORT_REGULAR));
    }
}
