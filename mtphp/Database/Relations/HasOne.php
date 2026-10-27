<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

/**
 * 一对一关联（零依赖，思想借鉴主流框架的 hasOne）
 *
 * 与 HasMany 同源，只是关联结果取“单条”。
 */
class HasOne extends Relation
{
    public function __construct(string $related, string $foreignKey, string $localKey)
    {
        parent::__construct($related, $foreignKey, $localKey);
        $this->parentKey = $localKey;
    }

    public function getResults(array $parent)
    {
        $key = $parent[$this->localKey] ?? null;
        if ($key === null) {
            return null;
        }
        $q = ($this->related)::query()->where($this->foreignKey, '=', $key);
        $this->applyConstraints($q);
        return $q->find();
    }

    public function getEager(array $parents): array
    {
        $keys = $this->collectKeys($parents, $this->localKey);
        if ($keys === []) {
            return [];
        }
        $query = ($this->related)::query()->whereIn($this->foreignKey, $keys);
        $this->applyConstraints($query);
        $rows  = $query->get();
        $map   = [];
        foreach ($rows as $r) {
            $map[$r[$this->foreignKey]] = $r;
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
