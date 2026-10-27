<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

/**
 * 远程一对多（hasManyThrough，零依赖，思想借鉴主流框架的 hasManyThrough）
 *
 * 经一张中间表跨两跳取关联：
 *   Country -> hasManyThrough(City, User)
 *     firstKey：中间表(User)持有「父」(Country)的外键，默认 父表名_id（country_id）；
 *     secondKey：关联表(City)持有「中间表」(User)的外键，默认 中间表名_id（user_id）；
 *     localKey：父表本地键（默认主键）；secondLocalKey：中间表本地键（默认主键）。
 */
class HasManyThrough extends Relation
{
    protected string $through;          // 中间模型类
    protected string $firstKey;         // 中间表外键 -> 父
    protected string $secondKey;        // 关联表外键 -> 中间表
    protected string $localKey;         // 父表本地键
    protected string $secondLocalKey;   // 中间表本地键

    public function __construct(string $related, string $through, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey)
    {
        parent::__construct($related, $firstKey, $localKey);
        $this->through          = $through;
        $this->firstKey         = $firstKey;
        $this->secondKey        = $secondKey;
        $this->localKey         = $localKey;
        $this->secondLocalKey   = $secondLocalKey;
        $this->parentKey        = $localKey;
    }

    public function getThrough(): string
    {
        return $this->through;
    }

    public function getFirstKey(): string
    {
        return $this->firstKey;
    }

    public function getSecondKey(): string
    {
        return $this->secondKey;
    }

    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    public function getSecondLocalKey(): string
    {
        return $this->secondLocalKey;
    }

    public function getResults(array $parent): array
    {
        $key = $parent[$this->localKey] ?? null;
        if ($key === null) {
            return [];
        }
        $map = $this->getEager([$parent]);
        return $map[$key] ?? [];
    }

    /**
     * 批量预加载：先取中间表行（按 firstKey 命中父键），再取关联表行（按 secondKey 命中中间表本地键），
     * 最后按「父键 => [关联行]」归组。
     *
     * @param array<int,array<string,mixed>> $parents
     * @return array<int|string,array<int,array<string,mixed>>>
     */
    public function getEager(array $parents): array
    {
        $parentKeys = $this->collectKeys($parents, $this->localKey);
        if ($parentKeys === []) {
            return [];
        }

        // 1) 取命中父键的中间表行
        $throughRows = ($this->through)::query()
            ->whereIn($this->firstKey, $parentKeys)
            ->get();

        // 2) 收集中间表本地键（关联表外键指向它），据此批量取关联行
        $throughLocalKeys = [];
        foreach ($throughRows as $tr) {
            $tk = $tr[$this->secondLocalKey] ?? null;
            if ($tk !== null) {
                $throughLocalKeys[] = $tk;
            }
        }
        $throughLocalKeys = array_values(array_unique($throughLocalKeys, SORT_REGULAR));

        // 3) 关联行按「指向中间表的外键」（secondKey）分组：多个子行可同属一个中间表行
        $relatedByThroughKey = [];
        if ($throughLocalKeys !== []) {
            $relatedQuery = ($this->related)::query()
                ->whereIn($this->secondKey, $throughLocalKeys);
            $this->applyConstraints($relatedQuery);
            $relatedRows = $relatedQuery->get();
            foreach ($relatedRows as $rr) {
                $fk = $rr[$this->secondKey] ?? null;
                if ($fk !== null) {
                    $relatedByThroughKey[$fk][] = $rr;
                }
            }
        }

        // 4) 逐中间表行：父键(firstKey) => 关联行（经 secondLocalKey 反查第二步的分组）
        $result = [];
        foreach ($throughRows as $tr) {
            $pk = $tr[$this->firstKey] ?? null;        // 父键
            $tk = $tr[$this->secondLocalKey] ?? null;   // 中间表本地键
            if ($pk === null || $tk === null) {
                continue;
            }
            if (!isset($result[$pk])) {
                $result[$pk] = [];
            }
            foreach ($relatedByThroughKey[$tk] ?? [] as $rr) {
                $result[$pk][] = $rr;
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
