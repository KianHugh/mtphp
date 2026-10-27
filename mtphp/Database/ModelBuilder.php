<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database;

use Mtphp\Database\Relations\BelongsToMany;
use Mtphp\Database\Relations\BelongsTo;
use Mtphp\Database\Relations\HasMany;
use Mtphp\Database\Relations\HasManyThrough;
use Mtphp\Database\Relations\HasOne;
use Mtphp\Database\Relations\MorphMany;
use Mtphp\Database\Relations\MorphOne;
use Mtphp\Database\Relations\MorphTo;
use Mtphp\Database\Relations\MorphToMany;
use Mtphp\Database\Scopes\Scope;

/**
 * 模型查询构造器（零依赖，思想借鉴主流框架的 Eloquent Builder）
 *
 * 在 Query 的链式能力之上，增加 with() 关联预加载：一次查询拿到父表结果后，
 * 按关联定义批量取回子数据并挂到结果行上（避免 N+1）。
 *
 * 由 Model::query() / Model::with() 返回，因此 Model 的 where/get/find 都能直接链式。
 */
class ModelBuilder extends Query
{
    protected string $modelClass;

    /** @var list<string> 待预加载的关联（支持 'a.b' 嵌套写法） */
    protected array $with = [];

    /** @var array<string,callable> 预加载约束闭包（with 的 'rel' => fn 形式，作用于关联查询） */
    protected array $withConstraints = [];

    /** @var list<string> 待统计数量的关联（hasMany / hasOne / morphMany / morphOne，附加 {name}_count 列） */
    protected array $withCount = [];

    /** @var array<string,callable> 计数约束闭包（withCount 的 'rel' => fn 形式，作用于计数子查询） */
    protected array $countConstraints = [];

    /** @var list<string> 已被 withoutGlobalScope 移除的全局作用域标识 */
    protected array $removedGlobalScopes = [];

    /** 是否移除全部全局作用域 */
    protected bool $removeAllGlobalScopes = false;

    /** 全局作用域是否已应用（避免重复应用 / 重复 compile 时叠加） */
    protected bool $globalScopesApplied = false;

    public function __construct(Connector $connection, string $prefix, string $modelClass)
    {
        parent::__construct($connection, $prefix);
        $this->modelClass = $modelClass;
    }

    /**
     * 未定义的构造器方法分发：若模型定义了 scopeXxx 本地作用域则应用之并返回 $this，
     * 否则透传给父类 Query（支持任意其它链式能力）。
     *
     * @param array<int,mixed> $args
     * @return mixed
     */
    public function __call(string $name, array $args)
    {
        $scopeMethod = 'scope' . ucfirst($name);
        if (method_exists($this->modelClass, $scopeMethod)) {
            $model = new $this->modelClass();
            $model->{$scopeMethod}($this, ...$args);
            return $this;
        }
        if (method_exists(parent::class, '__call')) {
            return parent::__call($name, $args);
        }
        throw new \BadMethodCallException("方法不存在：{$name}（模型 {$this->modelClass}）");
    }

    /**
     * 编译前自动应用全局作用域（单次执行路径的唯一切入点，覆盖 get/find/count/exists/paginate...）
     *
     * 全局作用域由 Model::addGlobalScope() 注册，除非被 withoutGlobalScope(s) 移除。
     */
    public function compile(): array
    {
        $this->applyGlobalScopes();
        return parent::compile();
    }

    /**
     * 应用当前模型的全局作用域（幂等）
     */
    protected function applyGlobalScopes(): void
    {
        if ($this->globalScopesApplied) {
            return;
        }
        $this->globalScopesApplied = true;
        if ($this->removeAllGlobalScopes) {
            return;
        }
        foreach ($this->modelClass::getGlobalScopes() as $key => $scope) {
            if (in_array($key, $this->removedGlobalScopes, true)) {
                continue;
            }
            if ($scope instanceof \Closure) {
                $scope($this);
            } elseif ($scope instanceof Scope) {
                $scope->apply($this);
            }
        }
    }

    /**
     * 移除指定全局作用域（按注册标识：字符串键 / Closure / Scope 对象）
     *
     * @param \Closure|Scope|string $scope
     */
    public function withoutGlobalScope($scope): self
    {
        if ($scope instanceof \Closure) {
            $this->removedGlobalScopes[] = \spl_object_hash($scope);
        } elseif ($scope instanceof Scope) {
            $this->removedGlobalScopes[] = get_class($scope);
        } elseif (is_string($scope)) {
            $this->removedGlobalScopes[] = $scope;
        }
        return $this;
    }

    /**
     * 移除全部全局作用域
     */
    public function withoutGlobalScopes(): self
    {
        $this->removeAllGlobalScopes = true;
        return $this;
    }

    /**
     * 声明要预加载的关联
     *
     * 支持两种写法：
     *   ->with('posts')                         // 简单关联
     *   ->with(['posts', 'comments'])            // 多个
     *   ->with(['posts' => fn ($q) =>           // 带约束的关联（过滤/排序/限量）
     *       $q->where('published', 1)->orderBy('id')
     *   ])
     *
     * 约束闭包作用于“关联模型”的查询构造器（ModelBuilder），不影响父查询。
     *
     * @param string|list<string>|array<string,callable> $relations
     */
    public function with(string|array $relations): self
    {
        $list = is_array($relations) ? $relations : explode(',', $relations);
        foreach ($list as $key => $value) {
            if (is_string($value)) {
                $r = trim($value);
                if ($r !== '') {
                    $this->with[] = $r;
                }
            } elseif (is_callable($value)) {
                // ['posts' => fn ($q) => ...] / ['posts.comments' => fn] 约束形式：键是关系名
                $name = (string) $key;
                $this->with[] = $name;
                $this->withConstraints[$name] = $value;
            }
        }
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getWith(): array
    {
        return $this->with;
    }

    /**
     * 声明要统计数量的关联（仅 hasMany / hasOne 有意义）
     *
     * 用法：User::withCount('posts')->get(); 结果行会多出 posts_count 字段。
     * 亦支持约束闭包：User::withCount(['posts' => fn ($q) => $q->where('published', 1)])
     * （约束作用于计数子查询，仅对 hasMany / hasOne / morphMany / morphOne 生效）。
     *
     * @param string|list<string>|array<string,callable> $relations
     */
    public function withCount(string|array $relations): self
    {
        $list = is_array($relations) ? $relations : explode(',', $relations);
        foreach ($list as $key => $value) {
            if (is_string($value)) {
                $r = trim($value);
                if ($r !== '') {
                    $this->withCount[] = $r;
                }
            } elseif (is_callable($value)) {
                // ['posts' => fn ($q) => ...] 约束形式：键是关系名
                $name = (string) $key;
                $this->withCount[] = $name;
                $this->countConstraints[$name] = $value;
            }
        }
        return $this;
    }

    /**
     * 按主键查一条（可传 id，等价于 ->where(主键,'=',id)->find()）
     * 覆盖 Query::find()，使其能接收主键，支持 withTrashed()->find($id) 这类链式。
     *
     * @return array<string,mixed>|null
     */
    public function find(mixed $id = null): ?array
    {
        if ($id !== null) {
            $pk = $this->modelClass::primaryKey();
            $this->where($pk, '=', $id);
        }
        return parent::find();
    }

    /**
     * 执行查询：先应用类型转换，再统计关联数量，最后预加载关联
     *
     * @return array<int,array<string,mixed>>
     */
    public function get(): array
    {
        $rows = parent::get();

        $modelClass = $this->modelClass;
        foreach ($rows as &$row) {
            $row = $modelClass::castRow($row);
        }
        unset($row);

        if ($this->withCount !== []) {
            $this->eagerLoadCounts($rows, $this->withCount);
        }
        if ($this->with !== []) {
            $this->eagerLoadRelations($rows, $this->with);
        }
        return $rows;
    }

    /**
     * 批量统计关联数量，并把 {name}_count 挂到父行
     *
     * 支持 hasMany / hasOne / morphMany / morphOne（子表外键）以及 morphToMany（中间表计数）。
     * belongsTo 语义不同，跳过。
     *
     * @param array<int,array<string,mixed>> $rows
     * @param list<string>                  $relations
     */
    public function eagerLoadCounts(array &$rows, array $relations): void
    {
        foreach ($relations as $name) {
            $model    = new $this->modelClass();
            if (!method_exists($model, $name)) {
                continue;
            }
            $relation = $model->{$name}();
            // 仅对“一对多 / 一对一 / 多态”做计数（belongsTo / hasManyThrough 语义不同，跳过）
            if (!$relation instanceof HasMany
                && !$relation instanceof HasOne
                && !$relation instanceof MorphMany
                && !$relation instanceof MorphOne
                && !$relation instanceof MorphToMany) {
                continue;
            }

            $relatedClass = $relation->getRelated();
            // 父表本地键：多数关联用 localKey；多态多对多用 parentKey（MorphToMany 不继承 localKey）
            $lk           = $relation instanceof MorphToMany
                ? $relation->getParentKey()
                : $relation->getLocalKey();

            // 计数外键与额外过滤：
            //  - 普通关联（hasMany/hasOne）：子表外键列
            //  - 多态（morphMany/morphOne）：多态外键列 + 类型列过滤
            //  - 多态多对多（morphToMany）：经中间表按「多态外键列」分组计数 + 类型列过滤
            $morphFilter = null;
            $usePivot    = false;
            $pivotTable  = null;
            if ($relation instanceof MorphToMany) {
                $usePivot   = true;
                $pivotTable = $relation->getTable();
                $fk         = $relation->getMorphId();
                $morphFilter = [$relation->getMorphType(), '=', $relation->getMorphClass()];
            } elseif ($relation instanceof MorphMany || $relation instanceof MorphOne) {
                $fk          = $relation->getMorphId();
                $morphFilter = [$relation->getMorphType(), '=', $relation->getMorphClass()];
            } else {
                $fk = $relation->getForeignKey();
            }

            $parentKeys = [];
            foreach ($rows as $row) {
                if (isset($row[$lk])) {
                    $parentKeys[] = $row[$lk];
                }
            }
            $parentKeys = array_values(array_unique($parentKeys, SORT_REGULAR));

            $counts = [];
            if ($parentKeys !== []) {
                if ($usePivot) {
                    $q = DB::table($pivotTable)
                        ->select(["`{$fk}` AS _fk", 'COUNT(*) AS _c']);
                    if ($morphFilter !== null) {
                        $q->where($morphFilter[0], $morphFilter[1], $morphFilter[2]);
                    }
                    $agg = $q->whereIn($fk, $parentKeys)->groupBy($fk)->get();
                } else {
                    $q = $relatedClass::query()
                        ->select(["`{$fk}` AS _fk", 'COUNT(*) AS _c'])
                        ->whereIn($fk, $parentKeys);
                    if (isset($this->countConstraints[$name])) {
                        ($this->countConstraints[$name])($q);
                    }
                    if ($morphFilter !== null) {
                        $q->where($morphFilter[0], $morphFilter[1], $morphFilter[2]);
                    }
                    $agg = $q->groupBy($fk)->get();
                }
                foreach ($agg as $a) {
                    $counts[$a['_fk']] = (int) $a['_c'];
                }
            }

            $countKey = $name . '_count';
            foreach ($rows as &$row) {
                $row[$countKey] = $counts[$row[$lk] ?? null] ?? 0;
            }
            unset($row);
        }
    }

    /**
     * 对“已经取出”的结果集追加预加载（供 Model::load 静态懒加载调用）
     *
     * @param array<int,array<string,mixed>> $rows
     * @param list<string>                  $relations
     * @param string                        $path 嵌套路径前缀（内部递归用，如 'posts'）
     */
    public function eagerLoadRelations(array &$rows, array $relations, string $path = ''): void
    {
        if ($rows === [] || $relations === []) {
            return;
        }

        foreach ($relations as $name) {
            [$first, $rest] = $this->splitNested($name);
            $model    = new $this->modelClass();
            if (!method_exists($model, $first)) {
                continue;
            }
            $relation = $model->{$first}();

            // 应用约束闭包：优先匹配全路径（'a.b.c'），回退到单段名（'posts'）
            $full = $path === '' ? $first : $path . '.' . $first;
            $cb   = $this->withConstraints[$full] ?? $this->withConstraints[$first] ?? null;
            if ($cb !== null && method_exists($relation, 'constrain')) {
                $relation->constrain($cb);
            }

            $relation->attach($rows, $first);

            // 嵌套预加载：把子结果集摊平后再递归（多态反向 morphTo 关联类动态，不递归）
            if ($rest !== null && !($relation instanceof MorphTo) && isset($rows[0][$first])) {
                $relatedClass = $relation->getRelated();
                $subRows      = [];
                foreach ($rows as &$row) {
                    if (!isset($row[$first]) || !is_array($row[$first])) {
                        continue;
                    }
                    if (array_is_list($row[$first])) {
                        foreach ($row[$first] as &$sr) {
                            $subRows[] = &$sr;
                        }
                        unset($sr);
                    } else {
                        $subRows[] = &$row[$first];
                    }
                }
                unset($row);

                if ($subRows !== []) {
                    $subBuilder = new self(
                        DB::connection(),
                        DB::connection()->getPrefix(),
                        $relatedClass
                    );
                    // 把约束表透传给子构造器，支持嵌套路径约束（'a.b.c' => fn）
                    $subBuilder->withConstraints = $this->withConstraints;
                    $subBuilder->eagerLoadRelations($subRows, [$rest], $full);
                }
            }
        }
    }

    /**
     * 拆分 'a.b.c' 为 ['a', 'b.c']
     *
     * @return array{0:string,1:?string}
     */
    protected function splitNested(string $name): array
    {
        $pos = strpos($name, '.');
        if ($pos === false) {
            return [$name, null];
        }
        return [substr($name, 0, $pos), substr($name, $pos + 1)];
    }

    // ---------------- 关联存在性约束（whereHas / has / doesntHave） ----------------

    /**
     * 关联存在性约束（含可选计数比较）
     *
     *   User::has('posts')                                                 有文章的用户
     *   User::has('posts', '>', 3)                                         文章数 > 3 的用户
     *   User::whereHas('posts', fn ($q) => $q->where('published', 1))       有“已发布”文章的用户
     *
     * 支持 hasMany / hasOne / belongsTo / belongsToMany 四种关联；
     * belongsToMany 经中间表存在性（嵌套 EXISTS）判定。
     *
     * @param \Closure|null $callback 作用于「关联模型」构造器的约束闭包
     */
    public function has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'AND', ?\Closure $callback = null): self
    {
        $parentTable = $this->getTable();
        $sub         = $this->newRelationSubquery($relation, $parentTable, $callback);

        // 纯存在性（无计数比较）走 EXISTS，避免无意义地计数
        if ($operator === '>=' && $count === 1) {
            return $boolean === 'OR'
                ? $this->orWhereExists($sub)
                : $this->whereExists($sub, $boolean);
        }

        // 计数比较：(SELECT COUNT(*) FROM ...) $operator $count
        [$subSql, $subBinds] = $sub->compile();
        $subSql  = (string) preg_replace('/^SELECT\s+.*?\s+FROM\s/i', 'SELECT COUNT(*) FROM ', $subSql, 1);
        $raw     = '(' . $subSql . ') ' . $operator . ' ?';
        $binds   = array_merge($subBinds, [$count]);
        return $boolean === 'OR'
            ? $this->orWhereRaw($raw, $binds)
            : $this->whereRaw($raw, $binds, $boolean);
    }

    public function orHas(string $relation, string $operator = '>=', int $count = 1, ?\Closure $callback = null): self
    {
        return $this->has($relation, $operator, $count, 'OR', $callback);
    }

    public function whereHas(string $relation, ?\Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        return $this->has($relation, $operator, $count, 'AND', $callback);
    }

    public function orWhereHas(string $relation, ?\Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        return $this->has($relation, $operator, $count, 'OR', $callback);
    }

    /**
     * 关联不存在约束（NOT EXISTS）
     *
     *   User::doesntHave('posts')                                           没有文章的用户
     *   User::whereDoesntHave('posts', fn ($q) => $q->where('blocked', 1))   没有“被封禁”文章的用户
     */
    public function doesntHave(string $relation, string $boolean = 'AND', ?\Closure $callback = null): self
    {
        $parentTable = $this->getTable();
        $sub         = $this->newRelationSubquery($relation, $parentTable, $callback);
        return $boolean === 'OR'
            ? $this->orWhereNotExists($sub)
            : $this->whereNotExists($sub, $boolean);
    }

    public function orDoesntHave(string $relation, ?\Closure $callback = null): self
    {
        return $this->doesntHave($relation, 'OR', $callback);
    }

    public function whereDoesntHave(string $relation, ?\Closure $callback = null): self
    {
        return $this->doesntHave($relation, 'AND', $callback);
    }

    public function orWhereDoesntHave(string $relation, ?\Closure $callback = null): self
    {
        return $this->doesntHave($relation, 'OR', $callback);
    }

    /**
     * 构造「关联存在性」子查询（返回子查询构造器），供 has / doesntHave 复用。
     *
     * 父表列用「完整表名 + 列名」限定（如 users.id），关联侧用关联表完整表名限定，
     * 避免多表场景下的列歧义；belongsToMany 通过“中间表 + 嵌套 EXISTS”连接关联表。
     *
     * @return \Mtphp\Database\Query
     */
    protected function newRelationSubquery(string $relation, string $parentTable, ?\Closure $callback): Query
    {
        $model = new $this->modelClass();
        if (!method_exists($model, $relation)) {
            throw new \RuntimeException("未定义关联方法：{$relation}（模型 {$this->modelClass}）");
        }
        $rel     = $model->{$relation}();
        $related = $rel->getRelated();

        if ($rel instanceof MorphTo) {
            // 多态反向：子侧持有「类型 + 外键」，无法从父侧反向推断父表，不支持 has()
            throw new \RuntimeException(
                "morphTo 关联（{$relation}）不支持 has()/whereHas()（多态反向无确定父表）"
            );
        }

        if ($rel instanceof MorphToMany) {
            $pivotTable = \Mtphp\Database\DB::table($rel->getTable())->getTable();
            $pq = \Mtphp\Database\DB::table($rel->getTable());
            $pq->where($rel->getMorphType(), '=', $rel->getMorphClass());
            $pq->whereRaw($pivotTable . '.' . $rel->getMorphId() . ' = ' . $parentTable . '.' . $rel->getParentKey());

            $inner      = $related::query();
            $innerTable = $inner->getTable();
            $inner->whereRaw($innerTable . '.' . $rel->getRelatedKey() . ' = ' . $pivotTable . '.' . $rel->getForeignPivotKey());
            if ($callback !== null) {
                $callback($inner);
            }
            $pq->whereExists($inner);
            return $pq;
        }

        if ($rel instanceof \Mtphp\Database\Relations\BelongsToMany) {
            $pivotTable = \Mtphp\Database\DB::table($rel->getTable())->getTable();
            $pq = \Mtphp\Database\DB::table($rel->getTable());
            // 关联侧/父侧列须用「完整表名.列」限定；whereRaw 不经 quoteColumn（避免被整体反引号）
            $pq->whereRaw($pivotTable . '.' . $rel->getForeignPivotKey() . ' = ' . $parentTable . '.' . $rel->getParentKey());

            $inner      = $related::query();
            $innerTable = $inner->getTable();
            $inner->whereRaw($innerTable . '.' . $rel->getRelatedKey() . ' = ' . $pivotTable . '.' . $rel->getRelatedPivotKey());
            if ($callback !== null) {
                $callback($inner);
            }
            $pq->whereExists($inner);
            return $pq;
        }

        $sub       = $related::query();
        $subTable  = $sub->getTable();

        if ($rel instanceof \Mtphp\Database\Relations\BelongsTo) {
            // 本表持有外键：related.localKey = parent.foreignKey
            $sub->whereRaw($subTable . '.' . $rel->getLocalKey() . ' = ' . $parentTable . '.' . $rel->getForeignKey());
        } elseif ($rel instanceof HasManyThrough) {
            // 远程一对多：父 -> 中间表 -> 关联（嵌套 EXISTS）
            $through      = $rel->getThrough();
            $throughQuery = $through::query();
            $throughTable = $throughQuery->getTable();
            $throughQuery->whereRaw($throughTable . '.' . $rel->getFirstKey() . ' = ' . $parentTable . '.' . $rel->getLocalKey());

            $inner      = $related::query();
            $innerTable = $inner->getTable();
            $inner->whereRaw($innerTable . '.' . $rel->getSecondKey() . ' = ' . $throughTable . '.' . $rel->getSecondLocalKey());
            if ($callback !== null) {
                $callback($inner);
            }
            $throughQuery->whereExists($inner);
            return $throughQuery;
        } elseif ($rel instanceof MorphMany || $rel instanceof MorphOne) {
            // 多态一对多/一对一：related.morphType = '<类型>' AND related.morphId = parent.localKey
            $sub->where($rel->getMorphType(), '=', $rel->getMorphClass());
            $sub->whereRaw($subTable . '.' . $rel->getMorphId() . ' = ' . $parentTable . '.' . $rel->getLocalKey());
        } else {
            // hasMany / hasOne：related.foreignKey = parent.localKey
            $sub->whereRaw($subTable . '.' . $rel->getForeignKey() . ' = ' . $parentTable . '.' . $rel->getLocalKey());
        }

        if ($callback !== null) {
            $callback($sub);
        }
        return $sub;
    }
}
