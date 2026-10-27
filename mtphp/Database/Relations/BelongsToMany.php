<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

use Mtphp\Database\DB;

/**
 * 多对多关联（零依赖，思想借鉴主流框架的 belongsToMany）
 *
 * 经中间表（pivot）连接两个模型。中间表默认名 = 两表名按字母序拼接（如 role + user => role_user），
 * 含两列：指向「本模型」主键的外键（默认 本表名_id）与指向「关联模型」主键的外键（默认 关联表名_id）。
 *
 * 例：
 *   class User extends Model { public function roles(): BelongsToMany { return $this->belongsToMany(Role::class); } }
 *   class Role extends Model { public function users(): BelongsToMany { return $this->belongsToMany(User::class); } }
 *
 * 预加载用 User::with('roles')；嵌套预加载（如 users.roles.permissions）同样支持。
 *
 * 思想借鉴主流框架的 belongsToMany（独立实现）。
 */
class BelongsToMany extends Relation
{
    /** 中间表名（已含前缀由 DB::table 处理） */
    protected string $table;

    /** 中间表指向「本模型」主键的列（如 user_id） */
    protected string $foreignPivotKey;

    /** 中间表指向「关联模型」主键的列（如 role_id） */
    protected string $relatedPivotKey;

    /** 本模型本地键（默认主键），attach() 用它匹配父行 */
    protected string $parentKey;

    /** 关联模型本地键（默认主键） */
    protected string $relatedKey;

    public function __construct(
        string $related,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey
    ) {
        parent::__construct($related, $foreignPivotKey, $relatedPivotKey);
        $this->table            = $table;
        $this->foreignPivotKey  = $foreignPivotKey;
        $this->relatedPivotKey  = $relatedPivotKey;
        $this->parentKey        = $parentKey;
        $this->relatedKey       = $relatedKey;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getForeignPivotKey(): string
    {
        return $this->foreignPivotKey;
    }

    public function getRelatedPivotKey(): string
    {
        return $this->relatedPivotKey;
    }

    public function getParentKey(): string
    {
        return $this->parentKey;
    }

    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    /**
     * 取单条父记录的关联结果（懒加载用）
     *
     * @param array<string,mixed> $parent
     * @return array<int,array<string,mixed>>
     */
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
     * 批量预加载：返回 父键 => [关联行,...] 的映射
     *
     * 实现：① 取中间表(父键, 关联键)对；② 批量取关联模型行；③ 按父键归组。
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
            ->select([$this->foreignPivotKey, $this->relatedPivotKey])
            ->whereIn($this->foreignPivotKey, $parentKeys)
            ->get();

        $relatedIdsByParent = [];
        $relatedIds         = [];
        foreach ($pivotRows as $p) {
            $pk = $p[$this->foreignPivotKey];
            $rk = $p[$this->relatedPivotKey];
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

    /**
     * 无关联数据时的默认值（多对多为空数组）
     *
     * @return array<int,array<string,mixed>>
     */
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
