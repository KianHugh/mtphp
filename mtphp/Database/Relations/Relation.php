<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Relations;

/**
 * 关联基类（零依赖，思想借鉴主流框架的 Eloquent 关联）
 *
 * 约定：关联最终都走“相关模型”的 Model 查询（Model::query → ModelBuilder），
 * 因此与 Query / 连接池 / 防注入策略完全复用。关联定义写在模型实例方法里，
 * 由 ModelBuilder 在预加载时通过模型实例调用得到本对象。
 */
abstract class Relation
{
    /** 相关模型类（App\Models\X 或 Mtphp 风格模型） */
    protected string $related;

    /** 外键列名（子表或本表持有，取决于关联类型） */
    protected string $foreignKey;

    /** 本地键列名（通常指主键） */
    protected string $localKey;

    /** 预加载时，用“哪个键”去父表行里取匹配值（has* 用 localKey，belongsTo 用 foreignKey） */
    protected string $parentKey;

    /** 预加载约束闭包（with(['posts' => fn ($q) => ...]) 注入），作用于“关联模型”查询 */
    /** @var list<callable> */
    protected array $constraints = [];

    public function __construct(string $related, string $foreignKey, string $localKey)
    {
        $this->related    = $related;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;
    }

    public function getRelated(): string
    {
        return $this->related;
    }

    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    /**
     * 追加一个预加载约束闭包（with(['posts' => fn ($q) => $q->where('active', 1)->orderBy('id')])）。
     *
     * 闭包作用于“关联模型”的查询构造器（ModelBuilder），可自由 where/orderBy/limit…，
     * 在 getEager / getResults 取关联数据前被应用，不影响父查询。
     *
     * @return $this
     */
    public function constrain(callable $callback): self
    {
        $this->constraints[] = $callback;
        return $this;
    }

    /**
     * 把已注册的约束闭包依次应用到关联查询上（由各子类 getEager/getResults 调用）
     */
    protected function applyConstraints(object $query): void
    {
        foreach ($this->constraints as $cb) {
            $cb($query);
        }
    }

    /**
     * 取单条父记录的关联结果（懒加载用）
     *
     * @param array<string,mixed> $parent
     * @return mixed
     */
    abstract public function getResults(array $parent);

    /**
     * 批量预加载：返回 匹配键 => 关联数据 的映射
     *
     * @param array<int,array<string,mixed>> $parents
     * @return array<int|string,mixed>
     */
    abstract public function getEager(array $parents): array;

    /**
     * 把关联数据挂到父表行上（按 $name 作为键名）
     *
     * @param array<int,array<string,mixed>> $parents
     */
    public function attach(array &$parents, string $name): void
    {
        $map = $this->getEager($parents);
        foreach ($parents as &$p) {
            $key         = $p[$this->parentKey] ?? null;
            $p[$name]    = $map[$key] ?? $this->defaultValue();
        }
        unset($p);
    }

    /**
     * 无关联数据时的默认值（has* 为 []，belongsTo/hasOne 为 null）
     *
     * @return mixed
     */
    protected function defaultValue()
    {
        return null;
    }
}
