<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

/**
 * 反向一对一/多对一关联（零依赖，思想借鉴主流框架的 belongsTo）
 *
 * 例：Post::author() => $this->belongsTo(User::class)
 *   - 本表（posts）持有外键 user_id（默认 = 相关表名 + _id）；
 *   - 用外键值去相关表按主键（默认 id）查找单条。
 */
class BelongsTo extends Relation
{
    public function __construct(string $related, string $foreignKey, string $localKey)
    {
        parent::__construct($related, $foreignKey, $localKey);
        $this->parentKey = $foreignKey;
    }

    public function getResults(array $parent)
    {
        $key = $parent[$this->foreignKey] ?? null;
        if ($key === null) {
            return null;
        }
        $q = ($this->related)::query()->where($this->localKey, '=', $key);
        $this->applyConstraints($q);
        return $q->find();
    }

    public function getEager(array $parents): array
    {
        $keys = $this->collectKeys($parents, $this->foreignKey);
        if ($keys === []) {
            return [];
        }
        $query = ($this->related)::query()->whereIn($this->localKey, $keys);
        $this->applyConstraints($query);
        $rows  = $query->get();
        $map   = [];
        foreach ($rows as $r) {
            $map[$r[$this->localKey]] = $r;
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
