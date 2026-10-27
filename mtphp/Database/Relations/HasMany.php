<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

/**
 * 一对多关联（零依赖，思想借鉴主流框架的 hasMany）
 *
 * 例：User::posts() => $this->hasMany(Post::class)
 *   - 子表（posts）持有外键 user_id（默认 = 父表名 + _id）；
 *   - 父表用主键（默认 id）匹配。
 */
class HasMany extends Relation
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
            return [];
        }
        $q = ($this->related)::query()->where($this->foreignKey, '=', $key);
        $this->applyConstraints($q);
        return $q->get();
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
            $map[$r[$this->foreignKey]][] = $r;
        }
        return $map;
    }

    protected function defaultValue()
    {
        return [];
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
