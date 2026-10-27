<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

/**
 * 多态一对多（morphMany，零依赖，思想借鉴主流框架的 morphMany）
 *
 * 多个不同类型父模型共享同一子表，用「类型列 + 外键列」区分归属：
 *   WHERE commentable_type = '<父模型多态类型>' AND commentable_id = 父.<本地键>
 *
 * 例：Post / Video 都可有评论；评论表含 commentable_type / commentable_id。
 */
class MorphMany extends Relation
{
    protected string $morphName;
    protected string $morphType;
    protected string $morphId;
    /** 父（拥有者）模型的“对外多态类型值”（写入/匹配 commentable_type） */
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

    public function getResults(array $parent): array
    {
        $key = $parent[$this->localKey] ?? null;
        if ($key === null) {
            return [];
        }
        $q = ($this->related)::query()
            ->where($this->morphType, '=', $this->morphClass)
            ->where($this->morphId, '=', $key);
        $this->applyConstraints($q);
        return $q->get();
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
            $map[$r[$this->morphId]][] = $r;
        }
        return $map;
    }

    protected function defaultValue(): array
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
