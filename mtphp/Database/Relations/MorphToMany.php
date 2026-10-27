<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

use Mtphp\Database\DB;

/**
 * 多态多对多（morphToMany，零依赖，思想借鉴主流框架的 morphToMany）
 *
 * 经多态中间表（pivot）连接另一模型；中间表含「类型列 + 外键列 + 关联外键列」，
 * 例：taggables(taggable_type, taggable_id, tag_id)；Post 经 taggables 拥有多个 Tag。
 */
class MorphToMany extends Relation
{
    protected string $table;
    protected string $foreignPivotKey;  // 关联表主键在中间表的外键（如 tag_id）
    protected string $morphType;        // 中间表类型列（如 taggable_type）
    protected string $morphId;          // 中间表外键列（如 taggable_id）
    protected string $parentKey;        // 本模型本地键（默认主键）
    protected string $relatedKey;       // 关联模型本地键（默认主键）
    protected string $morphClass;       // 本模型“对外多态类型值”

    public function __construct(
        string $related,
        string $table,
        string $foreignPivotKey,
        string $morphType,
        string $morphId,
        string $parentKey,
        string $relatedKey,
        string $morphClass
    ) {
        parent::__construct($related, $morphId, $foreignPivotKey);
        $this->table            = $table;
        $this->foreignPivotKey  = $foreignPivotKey;
        $this->morphType        = $morphType;
        $this->morphId          = $morphId;
        $this->parentKey        = $parentKey;
        $this->relatedKey       = $relatedKey;
        $this->morphClass       = $morphClass;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getForeignPivotKey(): string
    {
        return $this->foreignPivotKey;
    }

    public function getMorphType(): string
    {
        return $this->morphType;
    }

    public function getMorphId(): string
    {
        return $this->morphId;
    }

    public function getParentKey(): string
    {
        return $this->parentKey;
    }

    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    public function getResults(array $parent): array
    {
        $key = $parent[$this->parentKey] ?? null;
        if ($key === null) {
            return [];
        }
        $map = $this->getEager([$parent]);
        return $map[$key] ?? [];
    }

    /**
     * 批量预加载：与 BelongsToMany 同思路，只是中间表用「类型列 + 外键列」筛父键。
     *
     * @param array<int,array<string,mixed>> $parents
     * @return array<int|string,array<int,array<string,mixed>>>
     */
    public function getEager(array $parents): array
    {
        $parentKeys = $this->collectKeys($parents, $this->parentKey);
        if ($parentKeys === []) {
            return [];
        }

        $pivotRows = DB::table($this->table)
            ->select([$this->morphId, $this->foreignPivotKey])
            ->where($this->morphType, '=', $this->morphClass)
            ->whereIn($this->morphId, $parentKeys)
            ->get();

        $relatedIdsByParent = [];
        $relatedIds         = [];
        foreach ($pivotRows as $p) {
            $pk = $p[$this->morphId];
            $rk = $p[$this->foreignPivotKey];
            $relatedIdsByParent[$pk][] = $rk;
            $relatedIds[]              = $rk;
        }
        $relatedIds = array_values(array_unique($relatedIds, SORT_REGULAR));

        $relatedMap = [];
        if ($relatedIds !== []) {
            $relatedQuery = ($this->related)::query()
                ->whereIn($this->relatedKey, $relatedIds);
            $this->applyConstraints($relatedQuery);
            $relatedRows = $relatedQuery->get();
            foreach ($relatedRows as $rr) {
                $relatedMap[$rr[$this->relatedKey]] = $rr;
            }
        }

        $result = [];
        foreach ($relatedIdsByParent as $pk => $rks) {
            $result[$pk] = [];
            foreach ($rks as $rk) {
                if (isset($relatedMap[$rk])) {
                    $result[$pk][] = $relatedMap[$rk];
                }
            }
        }
        return $result;
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
