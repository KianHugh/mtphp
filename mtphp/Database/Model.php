<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database;

use Mtphp\Database\Relations\BelongsTo;
use Mtphp\Database\Relations\BelongsToMany;
use Mtphp\Database\Relations\HasMany;
use Mtphp\Database\Relations\HasManyThrough;
use Mtphp\Database\Relations\HasOne;
use Mtphp\Database\Relations\MorphMany;
use Mtphp\Database\Relations\MorphOne;
use Mtphp\Database\Relations\MorphTo;
use Mtphp\Database\Relations\MorphToMany;
use Mtphp\Database\Scopes\Scope;

/**
 * 轻量模型（ORM-lite，零配置即可用）
 *
 * 设计目标：用最简单的静态方法完成最常见的增删改查，无需写 SQL、无需复杂配置。
 *  - 子类只需声明 $table（数据表名，不含前缀）；不声明则按“类名转 snake_case”推导。
 *  - 所有查询最终走 DB::table() → Query 构造器，因此支持 Query 的全部链式能力
 *    （where / orderBy / limit / join / count …）。
 *  - 支持关联与预加载：hasOne / hasMany / belongsTo + with()（思想借鉴主流框架的 ORM，
 *    独立实现；返回数组而非对象，更轻量）。
 *
 * 用法：
 *   class User extends \Mtphp\Database\Model
 *   {
 *       protected static ?string $table = 'user'; // 可省略，默认按类名推导
 *       protected static string $primaryKey = 'id';
 *
 *       public function posts(): HasMany          // 关联定义（实例方法）
 *       {
 *           return $this->hasMany(Post::class);
 *       }
 *   }
 *
 *   User::find(1);                              // 按主键查一条
 *   User::with('posts')->where('age', '>', 18)->get();  // 预加载关联
 *   User::create(['name' => '张三']);            // 插入，返回自增ID
 */
abstract class Model
{
    // 子类覆盖：对应数据表名（不含前缀）；留空则按“类名 snake_case”推导
    protected static ?string $table = null;

    // 主键列名（用于 find / update / delete 的按主键定位）
    protected static string $primaryKey = 'id';

    /**
     * 分库组名（声明后可用 onShard($key) 按分片键路由到具体物理库；留空表示不分库）
     *
     * @var string|null
     */
    protected static ?string $shardGroup = null;

    /**
     * 类型转换声明（思想借鉴主流框架的 $casts，独立实现）
     *
     * 形如 ['is_admin' => 'bool', 'meta' => 'json', 'score' => 'int', 'created_at' => 'datetime']。
     * 支持类型：int/integer、float、bool/boolean、string、json/array、datetime/date/timestamp。
     * 取值（DB→应用）经 castOut 转换；写入（应用→DB）经 castIn 转换。
     *
     * @var array<string,string>
     */
    protected static array $casts = [];

    /**
     * 批量赋值白名单（空数组 = 未声明；未声明时遵循宽松默认：允许全部字段，
     * 除非 $guarded 含 '*'）。声明后仅名单内字段可被 create/update 批量写入。
     *
     * @var list<string>
     */
    protected static array $fillable = [];

    /**
     * 批量赋值黑名单（空数组 = 无额外屏蔽；含 '*' 表示屏蔽全部，除非列入 $fillable）
     *
     * @var list<string>
     */
    protected static array $guarded = [];

    /**
     * 临时放开全部字段（unguard/reguard 切换；单进程全局，测试用）
     */
    protected static bool $unguarded = false;

    /**
     * 全局作用域（按 类名 => 作用域标识 => 作用域 存储；标识可为字符串键、Closure 的
     * spl_object_hash、或 Scope 对象的类名）。每次查询编译前由 ModelBuilder 自动 apply，
     * 除非调用者显式 withoutGlobalScope() / withoutGlobalScopes() 移除。
     *
     * 声明在父类、按类名作键，因此各子类互相隔离（晚静态绑定）。
     *
     * @var array<string,array<string,mixed>>
     */
    protected static array $globalScopes = [];

    /**
     * 多态类型映射（别名 => 真实类名；真实类名 => 别名，反向索引便于 morphTo 反查）
     *
     * @var array<string,string>
     */
    protected static array $morphMap = [];

    /**
     * 解析表名：子类显式声明优先；否则取“短类名转 snake_case”。
     * 例如：类 User -> user；类 OrderItem -> order_item。
     */
    public static function tableName(): string
    {
        if (static::$table !== null && static::$table !== '') {
            return static::$table;
        }
        $short = basename(str_replace('\\', '/', static::class));
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    /**
     * 主键列名
     */
    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * 默认外键名：模型短类名转 snake_case 后加 _id（如 User => user_id）。
     * 采用“模型名单数 + _id”而非表名，符合常见约定（表可复数，外键取单数）。
     */
    public static function foreignKeyName(): string
    {
        $short = basename(str_replace('\\', '/', static::class));
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        return $snake . '_id';
    }

    /**
     * 获取一个模型查询构造器（可在其上继续 where / orderBy / join / with 等）。
     * 这是 Model 与底层 Query 的桥梁。
     */
    public static function query(): Query
    {
        $conn   = DB::connection();
        $prefix = $conn->getPrefix();
        return (new ModelBuilder($conn, $prefix, static::class))->table(static::tableName());
    }

    /**
     * 按分片键取「具体分片」上的查询构造器（分库模型专用）
     *
     * 前提：子类声明了 $shardGroup（对应 config/database.php 的 database.sharding.<group>）。
     * 例如：class Order extends Model { protected static ?string $shardGroup = 'orders'; }
     *      Order::onShard($orderId)->where('id', $orderId)->get();
     *
     * 返回的是 ModelBuilder，因此 where / orderBy / with 等链式能力完全可用。
     *
     * @param mixed $key 分片键（user_id / order_id 等）
     */
    public static function onShard(mixed $key): ModelBuilder
    {
        if (static::$shardGroup === null) {
            throw new \RuntimeException(static::class . ' 未配置 $shardGroup，无法按分片路由；请在模型中声明 protected static ?string $shardGroup');
        }
        $conn   = DB::shard(static::$shardGroup, $key);
        $prefix = $conn->getPrefix();
        return (new ModelBuilder($conn, $prefix, static::class))->table(static::tableName());
    }

    /**
     * 起手 where（链式）：User::where('age', '>', 18)->get()
     */
    public static function where(mixed $column, mixed $op = null, mixed $value = null): Query
    {
        return static::query()->where($column, $op, $value);
    }

    /**
     * 静态未定义方法分发（思想借鉴主流框架的本地作用域 + 构造器透传）
     *
     *  - 若模型定义了 scopeXxx($builder, ...$args) 本地作用域，则创建构造器后应用作用域并返回构造器，
     *    以便继续链式：Post::published()->where('views','>',100)->get()；
     *  - 否则透传给 ModelBuilder（支持任意未显式声明的 Query 链式能力，如 ->latest()）。
     *
     * @return mixed
     */
    public static function __callStatic(string $name, array $args)
    {
        $builder    = static::query();
        $scopeMethod = 'scope' . ucfirst($name);
        if (method_exists(static::class, $scopeMethod)) {
            $model = new static();
            $model->{$scopeMethod}($builder, ...$args);
            return $builder;
        }
        return $builder->{$name}(...$args);
    }

    // ---------------- 关联存在性约束（静态代理到 ModelBuilder） ----------------

    /**
     * 关联存在性约束（静态入口）：User::has('posts') / User::has('posts','>',3)
     *
     * @see \Mtphp\Database\ModelBuilder::has()
     */
    public static function has(string $relation, string $operator = '>=', int $count = 1): Query
    {
        return static::query()->has($relation, $operator, $count);
    }

    public static function orHas(string $relation, string $operator = '>=', int $count = 1): Query
    {
        return static::query()->orHas($relation, $operator, $count);
    }

    /**
     * 带约束的关联存在性：User::whereHas('posts', fn ($q) => $q->where('published', 1))
     *
     * @param \Closure|null $callback 作用于「关联模型」构造器的约束闭包
     * @see \Mtphp\Database\ModelBuilder::whereHas()
     */
    public static function whereHas(string $relation, ?\Closure $callback = null, string $operator = '>=', int $count = 1): Query
    {
        return static::query()->whereHas($relation, $callback, $operator, $count);
    }

    public static function orWhereHas(string $relation, ?\Closure $callback = null, string $operator = '>=', int $count = 1): Query
    {
        return static::query()->orWhereHas($relation, $callback, $operator, $count);
    }

    /**
     * 关联不存在约束：User::doesntHave('posts')
     *
     * @param \Closure|null $callback 作用于「关联模型」构造器的约束闭包
     * @see \Mtphp\Database\ModelBuilder::doesntHave()
     */
    public static function doesntHave(string $relation, ?\Closure $callback = null): Query
    {
        return static::query()->doesntHave($relation, 'AND', $callback);
    }

    public static function orDoesntHave(string $relation, ?\Closure $callback = null): Query
    {
        return static::query()->orDoesntHave($relation, $callback);
    }

    public static function whereDoesntHave(string $relation, ?\Closure $callback = null): Query
    {
        return static::query()->whereDoesntHave($relation, $callback);
    }

    public static function orWhereDoesntHave(string $relation, ?\Closure $callback = null): Query
    {
        return static::query()->orWhereDoesntHave($relation, $callback);
    }

    /**
     * 按主键查一条（不存在返回 null）
     */
    public static function find(mixed $id): ?array
    {
        return static::query()->where(static::primaryKey(), '=', $id)->find();
    }

    /**
     * 按主键查一条，不存在则抛出 ModelNotFoundException（供路由模型绑定使用）
     *
     * @throws \Mtphp\Exception\ModelNotFoundException
     */
    public static function findOrFail(mixed $id): array
    {
        $row = static::find($id);
        if ($row === null) {
            throw new \Mtphp\Exception\ModelNotFoundException(
                static::class . ' 记录不存在：' . var_export($id, true)
            );
        }
        return $row;
    }

    /**
     * 按一组主键批量查（返回命中行的数组，按结果顺序；未命中主键自然不出现）。
     *
     *   $users = User::findMany([1, 2, 3]);     // 返回最多 3 行
     *   $users = User::findMany([1,2,3], ['id','name']);
     *
     * 走 ModelBuilder，因此会自动套用全局作用域（含软删除作用域）。
     * 空数组直接返回空数组，避免生成无意义的 WHERE IN ()。
     *
     * @param array<int,mixed> $ids
     * @param array<int,string> $columns
     * @return array<int,array<string,mixed>>
     */
    public static function findMany(array $ids, array $columns = ['*']): array
    {
        $ids = array_values(array_unique($ids, SORT_REGULAR));
        if ($ids === []) {
            return [];
        }
        $q = static::query();
        if ($columns !== ['*']) {
            $q->select($columns);
        }
        return $q->whereIn(static::primaryKey(), $ids)->get();
    }

    /**
     * 按一组属性查第一条，不存在则抛出 ModelNotFoundException
     *
     * @param array<string,mixed> $attributes
     * @throws \Mtphp\Exception\ModelNotFoundException
     */
    public static function firstOrFail(array $attributes): array
    {
        $row = static::firstWhere($attributes);
        if ($row === null) {
            throw new \Mtphp\Exception\ModelNotFoundException(
                static::class . ' 记录不存在：' . var_export($attributes, true)
            );
        }
        return $row;
    }

    /**
     * 按一组属性查“恰好一条”记录；0 条抛 ModelNotFoundException，多于 1 条抛 RuntimeException。
     *
     * 与 firstOrFail 相比多了“唯一性”保护，适合按唯一键（邮箱、手机号、业务单号）查单条：
     *
     *   $user = User::sole(['email' => 'a@b.com']);
     *   $user = User::sole(['email' => 'a@b.com'], ['id','name']);
     *
     * 走 ModelBuilder，自动套用全局作用域（含软删除作用域）。
     *
     * @param array<string,mixed> $attributes
     * @param array<int,string>   $columns
     * @throws \Mtphp\Exception\ModelNotFoundException 0 条
     * @throws \RuntimeException                       多于 1 条
     */
    public static function sole(array $attributes = [], array $columns = ['*']): array
    {
        $q = static::query();
        if ($columns !== ['*']) {
            $q->select($columns);
        }
        foreach ($attributes as $col => $val) {
            $q->where((string) $col, '=', $val);
        }
        return $q->sole();
    }

    /**
     * 按一组属性查第一条（全部相等匹配）；不存在返回 null。
     * 结果经 castRow（同 get/find 的统一转换）。
     *
     * @param array<string,mixed> $attributes
     */
    protected static function firstWhere(array $attributes): ?array
    {
        if ($attributes === []) {
            return null;
        }
        $q = static::query();
        foreach ($attributes as $col => $val) {
            $q->where((string) $col, '=', $val);
        }
        return $q->first();
    }

    /**
     * 查得到就返回，查不到就按 attributes + values 创建并返回（返回的是「行数组」，含主键）。
     *
     *   $user = User::firstOrCreate(['email' => 'a@b.com'], ['name' => 'A']);
     *
     * @param array<string,mixed> $attributes 匹配条件（同时也是创建时的字段）
     * @param array<string,mixed> $values     查不到时额外写入的字段
     */
    public static function firstOrCreate(array $attributes, array $values = []): array
    {
        $row = static::firstWhere($attributes);
        if ($row !== null) {
            return $row;
        }
        $id  = static::create(array_merge($attributes, $values));
        return static::find($id);
    }

    /**
     * 查得到就更新 values 并返回，查不到就按 attributes + values 创建并返回。
     * 复用 create/update（已接通模型事件）。
     *
     *   $user = User::updateOrCreate(['email' => 'a@b.com'], ['name' => 'A']);
     *
     * @param array<string,mixed> $attributes 匹配条件
     * @param array<string,mixed> $values     更新/创建字段
     */
    public static function updateOrCreate(array $attributes, array $values = []): array
    {
        $row = static::firstWhere($attributes);
        if ($row !== null) {
            $pk = static::primaryKey();
            static::update($row[$pk], $values);
            return static::find($row[$pk]);
        }
        $id = static::create(array_merge($attributes, $values));
        return static::find($id);
    }

    /**
     * 查得到就返回行；查不到就返回“未落库”的属性数组（与数组风格 ORM 一致，调用方自行决定何时保存）。
     *
     * @param array<string,mixed> $attributes 匹配条件
     * @param array<string,mixed> $values     查不到时合并进返回数组的字段
     * @return array<string,mixed>
     */
    public static function firstOrNew(array $attributes, array $values = []): array
    {
        $row = static::firstWhere($attributes);
        if ($row !== null) {
            return $row;
        }
        return array_merge($attributes, $values);
    }

    // ---------------- 关联（思想借鉴主流框架的 ORM，独立实现） ----------------

    /**
     * 一对多：当前模型拥有多个 $related
     *
     * 默认外键 = 当前（父）表名 + '_id'（即子表持有父主键）；
     * 默认本地键 = 当前模型主键。
     *
     * @param ?string $foreignKey 子表外键（默认 parent_table_id）
     * @param ?string $localKey   父表匹配键（默认主键）
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $fk = $foreignKey ?? static::foreignKeyName();
        $lk = $localKey ?? static::primaryKey();
        return new HasMany($related, $fk, $lk);
    }

    /**
     * 一对一：当前模型拥有一条 $related
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $fk = $foreignKey ?? static::foreignKeyName();
        $lk = $localKey ?? static::primaryKey();
        return new HasOne($related, $fk, $lk);
    }

    /**
     * 反向：当前模型属于一条 $related（本表持有外键）
     *
     * 默认外键 = 相关模型外键名（related_table_id）；默认本地键 = 相关模型主键。
     *
     * @param ?string $foreignKey 本表外键（默认 related 模型名 + _id）
     * @param ?string $localKey   相关表匹配键（默认相关表主键）
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $localKey = null): BelongsTo
    {
        $fk = $foreignKey ?? $related::foreignKeyName();
        $lk = $localKey ?? $related::primaryKey();
        return new BelongsTo($related, $fk, $lk);
    }

    /**
     * 多对多（belongsToMany）：经中间表（pivot）关联另一模型
     *
     * 默认：中间表名 = 两表名按字母序拼接（如 role + user => role_user）；
     * 中间表默认含两列：指向本模型主键的外键（默认 本表名_id）与指向关联模型主键的外键（默认 关联表名_id）。
     *
     * 例：User::roles() => $this->belongsToMany(Role::class);
     *     Role::users() => $this->belongsToMany(User::class);
     *
     * 支持预加载（User::with('roles')）与嵌套预加载（users.roles.permissions）。
     *
     * @param ?string $table            中间表名（默认两表名字母序拼接）
     * @param ?string $foreignPivotKey  中间表指向「本模型」的列（默认 本表名_id）
     * @param ?string $relatedPivotKey  中间表指向「关联模型」的列（默认 关联表名_id）
     * @param ?string $parentKey        本模型匹配键（默认主键）
     * @param ?string $relatedKey       关联模型匹配键（默认主键）
     */
    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        $parentTable  = static::tableName();
        $relatedTable = $related::tableName();
        $table            = $table ?? static::pivotTableName($related);
        $foreignPivotKey  = $foreignPivotKey ?? static::foreignKeyName();
        $relatedPivotKey  = $relatedPivotKey ?? $related::foreignKeyName();
        $parentKey        = $parentKey ?? static::primaryKey();
        $relatedKey       = $relatedKey ?? $related::primaryKey();
        return new BelongsToMany(
            $related, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey
        );
    }

    /**
     * 多态一对多（morphMany）：多个不同类型父模型共享同一子表（按 类型列 + 外键列 区分）
     *
     * 例：Post/Video 都可有评论
     *   class Post extends Model { public function comments(): MorphMany { return $this->morphMany(Comment::class, 'commentable'); } }
     *   class Video extends Model { public function comments(): MorphMany { return $this->morphMany(Comment::class, 'commentable'); } }
     * Comment 表含 commentable_type / commentable_id；type 默认写入本模型的「多态类型」（可被 setMorphMap 改写）。
     *
     * @param string $name 多态名（默认派生 commentable_type / commentable_id 列）
     */
    public function morphMany(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphMany
    {
        $type = $type ?? $name . '_type';
        $id   = $id ?? $name . '_id';
        $lk   = $localKey ?? static::primaryKey();
        return new MorphMany($related, $name, $type, $id, $lk, static::getMorphClass());
    }

    /**
     * 多态一对一（morphOne）：语义同 morphMany，但仅取一条
     */
    public function morphOne(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphOne
    {
        $type = $type ?? $name . '_type';
        $id   = $id ?? $name . '_id';
        $lk   = $localKey ?? static::primaryKey();
        return new MorphOne($related, $name, $type, $id, $lk, static::getMorphClass());
    }

    /**
     * 多态反向（morphTo）：子表持有 type + id 两列，反查出“真正父模型”
     *
     *   class Comment extends Model { public function commentable(): MorphTo { return $this->morphTo(); } }
     * 默认从调用方法名推导多态名（如 commentable() => commentable_type / commentable_id）；
     * 也可显式传入 $name。type 列值经 getMorphAlias() 反查真实类名（未映射时按 FQCN 处理）。
     */
    public function morphTo(?string $name = null, ?string $type = null, ?string $id = null): MorphTo
    {
        $name = $name ?? $this->guessMorphName();
        $type = $type ?? $name . '_type';
        $id   = $id ?? $name . '_id';
        return new MorphTo($name, $type, $id);
    }

    /**
     * 多态多对多（morphToMany）：经多态中间表（pivot）连接另一模型（如标签 taggables）
     *
     *   class Post extends Model { public function tags(): MorphToMany { return $this->morphToMany(Tag::class, 'taggable'); } }
     * 中间表默认名 = $name（如 taggable），含 taggable_type / taggable_id / tag_id 三列。
     *
     * @param string $name 多态名（也是中间表默认名）
     */
    public function morphToMany(string $related, string $name, ?string $table = null, ?string $foreignPivotKey = null, ?string $morphType = null, ?string $morphId = null, ?string $parentKey = null, ?string $relatedKey = null): MorphToMany
    {
        $table           = $table ?? $name;
        $foreignPivotKey = $foreignPivotKey ?? $related::foreignKeyName();
        $morphType       = $morphType ?? $name . '_type';
        $morphId         = $morphId ?? $name . '_id';
        $parentKey       = $parentKey ?? static::primaryKey();
        $relatedKey      = $relatedKey ?? $related::primaryKey();
        return new MorphToMany($related, $table, $foreignPivotKey, $morphType, $morphId, $parentKey, $relatedKey, static::getMorphClass());
    }

    /**
     * 远程一对多（hasManyThrough）：经一张中间表跨两跳取关联
     *
     * 例：Country -> hasManyThrough(City, User)：国家有很多用户，用户有很多城市 => 国家有很多城市（经用户）。
     *   - firstKey：中间表持有「父」的外键（默认 父表名_id）；
     *   - secondKey：关联表持有「中间表」的外键（默认 中间表名_id）；
     *   - localKey：父表本地键（默认主键）；secondLocalKey：中间表本地键（默认主键）。
     *
     * @param class-string<Model> $through 中间模型类
     */
    public function hasManyThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasManyThrough
    {
        $firstKey        = $firstKey ?? static::foreignKeyName();
        $secondKey       = $secondKey ?? $through::foreignKeyName();
        $localKey        = $localKey ?? static::primaryKey();
        $secondLocalKey  = $secondLocalKey ?? $through::primaryKey();
        return new HasManyThrough($related, $through, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * 从调用栈推导 morphTo 的多态名（取“关联方法名”，如 commentable()）
     *
     * 调用链：commentable() -> morphTo() -> guessMorphName()，故关联方法在 frame[2]。
     */
    protected function guessMorphName(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        return $trace[2]['function'] ?? 'morphable';
    }

    /**
     * 多对多中间表默认名：两模型「单数类名 snake」按字母序拼接（与 foreignKeyName 一致，
     * 与主流框架的默认中间表名规则一致）。例如 User + Role => role_user；User + Post => post_user。
     *
     * @param class-string<Model> $related
     */
    protected static function pivotTableName(string $related): string
    {
        $segs = [static::classBasenameSegment(), $related::classBasenameSegment()];
        sort($segs, SORT_STRING);
        return $segs[0] . '_' . $segs[1];
    }

    /**
     * 取「单数类名」snake（用于外键名与中间表名，与 foreignKeyName 同源）：
     * 取短类名转 snake_case 后去尾缀 s（User => user，Categories => categorie）。
     */
    protected static function classBasenameSegment(): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', basename(str_replace('\\', '/', static::class))));
        return preg_replace('/s$/', '', $snake);
    }

    /**
     * 预加载关联后查询（返回带关联数据的数组）
     *
     * 用法：User::with('posts')->where('age','>',18)->get();
     *
     * @param string|list<string> $relations
     */
    public static function with(string|array $relations): ModelBuilder
    {
        return static::query()->with($relations);
    }

    /**
     * 声明要统计数量的关联后查询（返回带 {name}_count 的结果）
     *
     * @param string|list<string> $relations
     */
    public static function withCount(string|array $relations): ModelBuilder
    {
        return static::query()->withCount($relations);
    }

    /**
     * 对“已经取出”的结果集懒加载关联（原地修改）
     *
     * 用法：
     *   $users = User::all();
     *   User::load($users, 'posts');
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string|list<string>            $relations
     */
    public static function load(array &$rows, string|array $relations): void
    {
        if ($rows === []) {
            return;
        }
        $list = is_array($relations) ? $relations : explode(',', $relations);
        $list = array_map('trim', $list);
        $conn   = DB::connection();
        $prefix = $conn->getPrefix();
        $builder = new ModelBuilder($conn, $prefix, static::class);
        $builder->eagerLoadRelations($rows, $list);
    }

    /**
     * 对“已经取出”的结果集追加关联计数（原地修改，等价于 withCount 的懒加载版）
     *
     * 用法：
     *   $users = User::all();
     *   User::loadCount($users, 'posts');   // 每行多出 posts_count
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string|list<string>            $relations
     */
    public static function loadCount(array &$rows, string|array $relations): void
    {
        if ($rows === []) {
            return;
        }
        $list = is_array($relations) ? $relations : explode(',', $relations);
        $list = array_map('trim', $list);
        $conn   = DB::connection();
        $prefix = $conn->getPrefix();
        $builder = new ModelBuilder($conn, $prefix, static::class);
        $builder->eagerLoadCounts($rows, $list);
    }

    /**
     * 按某列查一条（例如按用户名查）
     */
    public static function findBy(string $column, mixed $value): ?array
    {
        return static::query()->where($column, '=', $value)->find();
    }

    /**
     * 取全部（可先链式加条件 / 排序 / 分页）
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * 计数
     */
    public static function count(string $column = '*'): int
    {
        return static::query()->count($column);
    }

    /**
     * 插入一条，返回自增主键 ID
     *
     * @return int 新记录 ID
     */
    public static function create(array $data): int
    {
        if (static::fireModelEvent('saving', $data) === false
            || static::fireModelEvent('creating', $data) === false) {
            return 0;
        }
        $id      = static::query()->insert(static::castForStorage(static::fillableData($data)));
        $payload = $data;
        $payload[static::primaryKey()] = $id;
        static::fireModelEvent('created', $payload);
        static::fireModelEvent('saved', $payload);
        return $id;
    }

    /**
     * 按主键更新，返回影响行数
     */
    public static function update(mixed $id, array $data): int
    {
        $payload = ['id' => $id, 'attributes' => $data];
        if (static::fireModelEvent('saving', $payload) === false
            || static::fireModelEvent('updating', $payload) === false) {
            return 0;
        }
        $affected = static::query()->where(static::primaryKey(), '=', $id)
            ->update(static::castForStorage(static::fillableData($data)));
        static::fireModelEvent('updated', $payload);
        static::fireModelEvent('saved', $payload);
        return $affected;
    }

    /**
     * 按主键更新，影响 0 行（记录不存在）时抛出 ModelNotFoundException
     *
     * @throws \Mtphp\Exception\ModelNotFoundException
     */
    public static function updateOrFail(mixed $id, array $data): int
    {
        $affected = static::update($id, $data);
        if ($affected === 0) {
            throw new \Mtphp\Exception\ModelNotFoundException(
                static::class . ' 更新失败（记录不存在或未变动）：' . var_export($id, true)
            );
        }
        return $affected;
    }

    // ---------------- 类型转换（casts）+ 访问器/修改器 ----------------

    /**
     * 一行结果在“读出”时应用类型转换与访问器（getXxxAttribute）
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function castRow(array $row): array
    {
        if (static::$casts === [] && !static::hasMutators()) {
            return $row;
        }
        foreach ($row as $key => $value) {
            if (!is_string($key)) { // 跳过数字键（连接层可能返回 FETCH_BOTH）
                continue;
            }
            $mutator = static::mutatorName($key, 'get');
            if ($mutator !== null) {
                $row[$key] = static::$mutator($value);
                continue;
            }
            if (isset(static::$casts[$key])) {
                $row[$key] = static::castOut($value, static::$casts[$key]);
            }
        }
        return $row;
    }

    /**
     * 一组字段在“写入”时应用类型转换与修改器（setXxxAttribute）
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function castForStorage(array $data): array
    {
        if (static::$casts === [] && !static::hasMutators()) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $mutator = static::mutatorName($key, 'set');
            if ($mutator !== null) {
                $data[$key] = static::$mutator($value);
                continue;
            }
            if (isset(static::$casts[$key])) {
                $data[$key] = static::castIn($value, static::$casts[$key]);
            }
        }
        return $data;
    }

    /**
     * DB → 应用 方向转换
     */
    protected static function castOut(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'int', 'integer'          => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'bool', 'boolean'         => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string'                  => (string) $value,
            'json', 'array'           => is_string($value) ? json_decode($value, true) : $value,
            'datetime', 'date', 'timestamp' => is_numeric($value)
                ? date('Y-m-d H:i:s', (int) $value)
                : (string) $value,
            default                   => $value,
        };
    }

    /**
     * 应用 → DB 方向转换
     */
    protected static function castIn(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'json', 'array'           => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            'bool', 'boolean'         => $value ? 1 : 0,
            'datetime', 'date', 'timestamp' => is_numeric($value)
                ? date('Y-m-d H:i:s', (int) $value)
                : (string) $value,
            default                   => $value,
        };
    }

    /**
     * 是否存在任意访问器/修改器（避免对每个字段扫描方法名）
     */
    protected static function hasMutators(): bool
    {
        return false; // 默认无；子类若定义 getXxxAttribute/setXxxAttribute 可重写返回 true
    }

    /**
     * 解析 getXxxAttribute / setXxxAttribute 方法名（命中则返回方法名，否则 null）
     */
    protected static function mutatorName(string $key, string $kind): ?string
    {
        if (!static::hasMutators()) {
            return null;
        }
        $name = $kind . str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key))) . 'Attribute';
        return method_exists(static::class, $name) ? $name : null;
    }

    /**
     * 按主键删除，返回影响行数
     */
    public static function delete(mixed $id): int
    {
        $payload = ['id' => $id];
        if (static::fireModelEvent('deleting', $payload) === false) {
            return 0;
        }
        $affected = static::query()->where(static::primaryKey(), '=', $id)->delete();
        static::fireModelEvent('deleted', $payload);
        return $affected;
    }

    /**
     * 按主键删除，影响 0 行（记录不存在）时抛出 ModelNotFoundException
     *
     * @throws \Mtphp\Exception\ModelNotFoundException
     */
    public static function deleteOrFail(mixed $id): int
    {
        $payload = ['id' => $id];
        if (static::fireModelEvent('deleting', $payload) === false) {
            return 0;
        }
        $affected = static::query()->where(static::primaryKey(), '=', $id)->delete();
        static::fireModelEvent('deleted', $payload);
        if ($affected === 0) {
            throw new \Mtphp\Exception\ModelNotFoundException(
                static::class . ' 删除失败（记录不存在）：' . var_export($id, true)
            );
        }
        return $affected;
    }

    /**
     * 按主键批量删除（数组/单个/逗号串），返回影响行数
     *
     *   Post::destroy([1, 2, 3]);
     *   Post::destroy(5);
     *
     * 命中写前事件（deleting）返回 false 时整批中止（返回 0）。
     */
    public static function destroy(array|int|string $ids): int
    {
        if (is_int($ids) || is_string($ids)) {
            $ids = array_map('trim', explode(',', (string) $ids));
            $ids = array_filter($ids, fn ($v) => $v !== '');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $payload = ['ids' => $ids];
        if (static::fireModelEvent('deleting', $payload) === false) {
            return 0;
        }
        $affected = static::query()->whereIn(static::primaryKey(), $ids)->delete();
        static::fireModelEvent('deleted', $payload);
        return $affected;
    }

    // ---------------- 模型事件（思想借鉴主流框架的模型事件，独立实现） ----------------

    /**
     * 受支持的模型事件名（写操作前/后各一对，外加恢复/强删）
     */
    protected const MODEL_EVENTS = [
        'creating', 'created', 'updating', 'updated', 'saving', 'saved',
        'deleting', 'deleted', 'restoring', 'restored', 'forceDeleting', 'forceDeleted',
    ];

    /**
     * 事件监听器：按 类名 => 事件名 => [callable] 存储。
     * 用「类名」作键实现按模型的晚静态绑定隔离（不同模型互不影响）。
     *
     * @var array<string,array<string,array<int,callable>>>
     */
    protected static array $eventListeners = [];

    /**
     * 注册模型事件监听器（按事件名）
     *
     * 监听器签名：function (string $modelClass, mixed $payload): ?bool
     *  - 在「写操作前」事件（creating/updating/saving/deleting/restoring/forceDeleting）
     *    中显式返回 false 可拦截本次写操作（create/update/delete 返回 0）。
     *  - 「写操作后」事件（created/updated/saved/deleted/restored/forceDeleted）的返回值被忽略。
     *
     * @param callable $callback
     */
    public static function registerModelEvent(string $event, callable $callback): void
    {
        if (!in_array($event, static::MODEL_EVENTS, true)) {
            throw new \InvalidArgumentException('不支持的模型事件：' . $event);
        }
        static::$eventListeners[static::class][$event][] = $callback;
    }

    /**
     * 注册观察者：观察者对象/类名上名为 creating/created/... 的方法会被自动绑定到对应事件。
     *
     * @param object|string $observer
     */
    public static function observe(object|string $observer): void
    {
        foreach (static::MODEL_EVENTS as $event) {
            if (method_exists($observer, $event)) {
                static::registerModelEvent($event, [$observer, $event]);
            }
        }
    }

    /**
     * 触发事件；任一监听器显式返回 false 则整体返回 false（用于拦截写操作）。
     *
     * @param mixed $payload
     * @return bool true=放行，false=拦截
     */
    protected static function fireModelEvent(string $event, mixed $payload): bool
    {
        $list = static::$eventListeners[static::class][$event] ?? [];
        foreach ($list as $cb) {
            if ($cb(static::class, $payload) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * 清空当前类的事件监听器（供测试隔离；生产一般无需调用）
     */
    public static function clearModelEvents(): void
    {
        static::$eventListeners[static::class] = [];
    }

    // ---------------- 全局作用域（global scopes） ----------------

    /**
     * 注册全局作用域：每次查询（get/find/count/whereHas 等）编译前自动 apply，
     * 除非调用者显式 withoutGlobalScope() / withoutGlobalScopes() 移除。
     *
     * 三种用法：
     *   User::addGlobalScope('adult', fn ($q) => $q->where('age','>=',18));
     *   User::addGlobalScope(fn ($q) => $q->where('active',1));          // 匿名，spl_object_hash 作标识
     *   User::addGlobalScope(new PublishedScope());                      // Scope 对象，以类名作标识
     *
     * @param \Closure|Scope|string $scope
     * @param \Closure|Scope|null   $implementation 仅当 $scope 为字符串键时提供具体实现
     */
    public static function addGlobalScope($scope, $implementation = null): void
    {
        if ($scope instanceof \Closure) {
            static::$globalScopes[static::class][\spl_object_hash($scope)] = $scope;
        } elseif ($scope instanceof Scope) {
            static::$globalScopes[static::class][get_class($scope)] = $scope;
        } elseif (is_string($scope)) {
            static::$globalScopes[static::class][$scope] = $implementation;
        } else {
            throw new \InvalidArgumentException('addGlobalScope 仅接受 Closure / Scope 对象 / 字符串键');
        }
    }

    /**
     * 取当前类的全局作用域（标识 => 作用域）
     *
     * @return array<string,mixed>
     */
    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }

    /**
     * 清空当前类的全局作用域（供测试隔离）
     */
    public static function clearGlobalScopes(): void
    {
        static::$globalScopes[static::class] = [];
    }

    // ---------------- 批量赋值保护（fillable / guarded） ----------------

    /**
     * 判断字段是否允许批量赋值（宽松默认：未声明 fillable 且 guarded 不含 '*' 时放行全部）
     */
    public static function isFillable(string $key): bool
    {
        if (static::$unguarded) {
            return true;
        }
        if (in_array($key, static::$guarded, true) || in_array('*', static::$guarded, true)) {
            return in_array($key, static::$fillable, true);
        }
        if (static::$fillable !== []) {
            return in_array($key, static::$fillable, true);
        }
        return true;
    }

    /**
     * 过滤出允许批量赋值的字段（落到 create / update 写入前）
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function fillableData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && static::isFillable($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * 临时放开全部字段（测试或导入场景）
     */
    public static function unguard(): void
    {
        static::$unguarded = true;
    }

    /**
     * 恢复字段保护
     */
    public static function reguard(): void
    {
        static::$unguarded = false;
    }

    // ---------------- 多态类型映射（morph map） ----------------

    /**
     * 设置多态类型映射（别名 => 真实类名；并自动维护反向索引供 morphTo 反查）
     *
     *   Model::setMorphMap(['post' => Post::class, 'video' => Video::class]);
     * 之后 morphMany 写入的 commentable_type 为 'post' / 'video'（而非完整类名），
     * morphTo 也能凭 'post' 反查出 Post::class。
     *
     * @param array<string,string> $map
     */
    public static function setMorphMap(array $map, bool $merge = true): void
    {
        if ($merge) {
            static::$morphMap = array_merge(static::$morphMap, $map);
        } else {
            static::$morphMap = $map;
        }
        foreach ($map as $alias => $class) {
            static::$morphMap[$class] = $alias; // 反向索引：类名 => 别名
        }
    }

    /**
     * 取“对外暴露的多态类型值”（写入多态列的值）。若本类登记了别名则返回别名，否则返回类名。
     */
    public static function getMorphClass(): string
    {
        if (isset(static::$morphMap[static::class])) {
            return static::$morphMap[static::class];
        }
        return static::class;
    }

    /**
     * 反向解析多态类型值 => 真实类名（供 morphTo 使用）
     *
     * @return class-string|null
     */
    public static function getMorphAlias(string $alias): ?string
    {
        $mapped = static::$morphMap[$alias] ?? null;
        if (is_string($mapped) && class_exists($mapped)) {
            return $mapped;
        }
        if (class_exists($alias)) {
            return $alias;
        }
        return null;
    }

    /**
     * 清空多态类型映射（供测试隔离）
     */
    public static function clearMorphMap(): void
    {
        static::$morphMap = [];
    }
}
