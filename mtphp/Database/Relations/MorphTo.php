<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

use Mtphp\Database\Model;

/**
 * 多态反向（morphTo，零依赖，思想借鉴主流框架的 morphTo）
 *
 * 子表持有「类型列 + 外键列」（如 commentable_type / commentable_id），反查出真正父模型。
 * 例：Comment::commentable() => $this->morphTo()；type 列值经 Model::getMorphAlias() 反查类名。
 */
class MorphTo extends Relation
{
    protected string $morphName;
    protected string $morphType;
    protected string $morphId;

    /** 运行时解析出的关联父类（随行变化；仅用于 getRelated 兜底） */
    protected ?string $resolvedClass = null;

    public function __construct(string $morphName, string $morphType, string $morphId)
    {
        // 关联类与本地键在读取行后才能确定，先传占位
        parent::__construct('', $morphId, $morphId);
        $this->morphName = $morphName;
        $this->morphType = $morphType;
        $this->morphId   = $morphId;
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

    public function getRelated(): string
    {
        return $this->resolvedClass ?? '';
    }

    /**
     * 从一行解析出（关联类名, 外键值）；任一缺失或类型无法解析则返回 [null, null]
     *
     * @param array<string,mixed> $parent
     * @return array{0:?string,1:?mixed}
     */
    protected function resolve(array $parent): array
    {
        $type = $parent[$this->morphType] ?? null;
        $id   = $parent[$this->morphId] ?? null;
        if ($type === null || $id === null) {
            return [null, null];
        }
        $class = Model::getMorphAlias((string) $type);
        if ($class === null || !class_exists($class)) {
            return [null, null];
        }
        return [$class, $id];
    }

    public function getResults(array $parent): ?array
    {
        [$class, $id] = $this->resolve($parent);
        if ($class === null || $id === null) {
            return null;
        }
        $this->resolvedClass = $class;
        return $class::query()->where($class::primaryKey(), '=', $id)->find();
    }

    /**
     * 批量预加载：先按“类型 => [id]”分组，再逐类型批量取回行，最终返回 类型 => (id => 行) 映射。
     *
     * @param array<int,array<string,mixed>> $parents
     * @return array<string,array<int|string,array<string,mixed>>>
     */
    public function getEager(array $parents): array
    {
        $idsByType = [];
        foreach ($parents as $p) {
            [$class, $id] = $this->resolve($p);
            if ($class === null || $id === null) {
                continue;
            }
            $idsByType[$class][$id] = true;
        }
        $map = [];
        foreach ($idsByType as $class => $idSet) {
            $ids  = array_keys($idSet);
            $rows = $class::query()->whereIn($class::primaryKey(), $ids)->get();
            foreach ($rows as $r) {
                $map[$class][$r[$class::primaryKey()]] = $r;
            }
        }
        return $map;
    }

    /**
     * 多态反向的预加载：按“类型 + id”定位并挂到父行（覆盖基类通用 attach）
     *
     * @param array<int,array<string,mixed>> $parents
     */
    public function attach(array &$parents, string $name): void
    {
        $map = $this->getEager($parents);
        foreach ($parents as &$p) {
            [$class, $id] = $this->resolve($p);
            if ($class !== null && $id !== null && isset($map[$class][$id])) {
                $this->resolvedClass = $class;
                $p[$name]            = $map[$class][$id];
            } else {
                $p[$name] = $this->defaultValue();
            }
        }
        unset($p);
    }

    protected function defaultValue(): ?array
    {
        return null;
    }
}
