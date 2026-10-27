<?php
declare(strict_types=1);

namespace Mtphp\Database;

/**
 * 链式查询构造器
 *
 * 使用：DB::table('user')->where('id', 4)->orderBy('id','desc')->limit(10)->get()
 * 全部参数化，防 SQL 注入。
 */
class Query
{
    protected Connector $connection;

    protected string $prefix = '';

    protected ?string $table = null;

    /** @var list<string>|array<string> */
    protected array $fields = ['*'];

    /** @var list<array{0:string,1:string,2:mixed,3:string}> */
    protected array $wheres = [];

    /** @var list<array{0:string,1:string}> */
    protected array $orders = [];

    /** @var list<array{0:string,1:string,2:string}> */
    protected array $joins = [];

    /** @var list<string> */
    protected array $groups = [];

    /** @var list<array{0:string,1:string,2:mixed,3:string}> */
    protected array $havings = [];

    /** @var list<array{query:Query|string,all:bool}> 集合操作 UNION / UNION ALL 的分支 */
    protected array $unions = [];

    /**
     * FROM 派生表：子查询（Query|Closure|string）或原生 SQL，配合别名使用
     * @var array{sql:string,bindings:list<mixed>,alias:?string}|null
     */
    protected ?array $fromSub = null;

    protected ?int $limit = null;

    protected int $offset = 0;

    protected bool $distinct = false;

    /** @var 'UPDATE'|'SHARE'|null 悲观锁模式（仅 SELECT 生效，须置于事务内） */
    protected ?string $lockMode = null;

    public function __construct(Connector $connection, string $prefix = '')
    {
        $this->connection = $connection;
        $this->prefix     = $prefix;
    }

    public function table(string $table): self
    {
        // 重置构造器状态：支持复用同一 Query 实例发起多条独立查询（避免状态串味）
        $this->table    = $this->prefix . $table;
        $this->fields   = ['*'];
        $this->wheres   = [];
        $this->orders   = [];
        $this->joins    = [];
        $this->groups   = [];
        $this->havings  = [];
        $this->unions   = [];
        $this->fromSub  = null;
        $this->limit    = null;
        $this->offset   = 0;
        $this->distinct = false;
        $this->lockMode = null;
        return $this;
    }

    /**
     * from() 是 table() 的同义别名（FROM 子句的自然命名，Laravel 风格），二者等价。
     */
    public function from(string $table): self
    {
        return $this->table($table);
    }

    /**
     * 当前 FROM 表名（已含前缀），供关联子查询构造时引用外层父表列
     */
    public function getTable(): string
    {
        return $this->table ?? '';
    }

    /**
     * FROM 派生表：以子查询结果作为查询源表（派生表），须指定别名
     *
     *   DB::table('tmp')->fromSub(
     *       fn ($q) => $q->from('orders')->select('user_id', 'amount'),
     *       'o'
     *   )->where('o.amount', '>', 100);
     *
     * 也支持直接传入 Query 实例或原生 SQL 串。
     */
    public function fromSub(Query|\Closure|string $sub, ?string $alias = null): self
    {
        $normalized = $this->normalizeSub($sub);
        if (is_string($normalized)) {
            $this->fromSub = ['sql' => $normalized, 'bindings' => [], 'alias' => $alias];
        } else {
            [$sql, $bindings]   = $normalized->compile();
            $this->fromSub = ['sql' => $sql, 'bindings' => $bindings, 'alias' => $alias];
        }
        return $this;
    }

    /**
     * FROM 原生派生表（逃生舱）：手写 SQL 作为源表，参数化防注入
     *
     *   DB::table('tmp')->fromRaw('(SELECT user_id, SUM(amount) AS total FROM orders GROUP BY user_id)', [], 'o');
     */
    public function fromRaw(string $sql, array $bindings = [], ?string $alias = null): self
    {
        $this->fromSub = ['sql' => $sql, 'bindings' => $bindings, 'alias' => $alias];
        return $this;
    }

    public function select(array|string $fields = ['*']): self
    {
        $this->fields = is_array($fields) ? $fields : func_get_args();
        return $this;
    }

    /**
     * 在现有 SELECT 基础上追加列（与 select() 的“替换”不同）
     *
     *   ->select('id')->addSelect('name')          // id, name
     *   ->select('id')->addSelect(DB::raw('...'))  // 亦可用于追加表达式列
     */
    public function addSelect(string|array $fields): self
    {
        $list = is_array($fields) ? $fields : func_get_args();
        foreach ($list as $f) {
            if ((string) $f !== '') {
                $this->fields[] = $f;
            }
        }
        return $this;
    }

    public function where(mixed $column, mixed $op = null, mixed $value = null): self
    {
        if ($column instanceof \Closure) {
            return $this->group($column, 'AND');
        }
        if ($value === null && $op !== null) {
            $value = $op;
            $op    = '=';
        }
        $this->wheres[] = [(string) $column, (string) $op, $value, 'AND'];
        return $this;
    }

    public function orWhere(mixed $column, mixed $op = null, mixed $value = null): self
    {
        if ($column instanceof \Closure) {
            return $this->group($column, 'OR');
        }
        if ($value === null && $op !== null) {
            $value = $op;
            $op    = '=';
        }
        $this->wheres[] = [(string) $column, (string) $op, $value, 'OR'];
        return $this;
    }

    /**
     * 嵌套条件分组：闭包内构造的条件整体用括号包裹，再与外层用 $boolean 连接
     *
     *   DB::table('u')
     *     ->where('age', '>', 18)
     *     ->where(function ($q) {
     *         $q->where('vip', 1)->orWhere('score', '>', 100);
     *     });
     *   // WHERE age > ? AND (vip = ? OR score > ?)
     */
    public function group(\Closure $callback, string $boolean = 'AND'): self
    {
        $sub            = new self($this->connection, $this->prefix);
        $callback($sub);
        $this->wheres[] = ['group' => $sub->wheres, 'boolean' => $boolean];
        return $this;
    }

    /**
     * whereIn：列值落在给定集合（数组）或子查询结果集内（防注入）
     *
     *   DB::table('u')->whereIn('id', [1, 2, 3]);
     *   DB::table('u')->whereIn('id', fn ($q) => $q->table('vip')->select('user_id'));
     *   DB::table('u')->whereIn('id', DB::table('vip')->select('user_id'));
     *
     * @param array<int,mixed>|Query|\Closure|string $values
     */
    public function whereIn(string $column, array|Query|\Closure|string $values): self
    {
        if (is_array($values)) {
            $this->wheres[] = [$column, 'IN', $values, 'AND'];
            return $this;
        }
        $this->wheres[] = [$column, 'IN_SUB', $this->normalizeSub($values), 'AND'];
        return $this;
    }

    /**
     * orWhereIn：同上，逻辑或
     *
     * @param array<int,mixed>|Query|\Closure|string $values
     */
    public function orWhereIn(string $column, array|Query|\Closure|string $values): self
    {
        if (is_array($values)) {
            $this->wheres[] = [$column, 'IN', $values, 'OR'];
            return $this;
        }
        $this->wheres[] = [$column, 'IN_SUB', $this->normalizeSub($values), 'OR'];
        return $this;
    }

    /**
     * 列间比较（WHERE a op b），两侧均为列名/表达式，不做占位符绑定
     *
     *   ->whereColumn('updated_at', '>', 'created_at')
     *   ->whereColumn('parent_id', '=', 'id')
     */
    public function whereColumn(string $column, string $operator, string $otherColumn): self
    {
        $this->wheres[] = [$column, 'COLUMN', [$otherColumn, $operator], 'AND'];
        return $this;
    }

    public function orWhereColumn(string $column, string $operator, string $otherColumn): self
    {
        $this->wheres[] = [$column, 'COLUMN', [$otherColumn, $operator], 'OR'];
        return $this;
    }

    /**
     * whereNull：列值为 NULL（如软删除作用域 deleted_at IS NULL）
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = [$column, 'NULL', null, 'AND'];
        return $this;
    }

    public function orWhereNull(string $column): self
    {
        $this->wheres[] = [$column, 'NULL', null, 'OR'];
        return $this;
    }

    /**
     * whereNotNull：列值非 NULL
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [$column, 'NOTNULL', null, 'AND'];
        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        $this->wheres[] = [$column, 'NOTNULL', null, 'OR'];
        return $this;
    }

    /**
     * whereNotIn：列值不在给定集合（数组）或子查询结果集内
     *
     *   DB::table('u')->whereNotIn('id', [1, 2]);
     *   DB::table('u')->whereNotIn('id', fn ($q) => $q->table('banned')->select('user_id'));
     *
     * @param array<int,mixed>|Query|\Closure|string $values
     */
    public function whereNotIn(string $column, array|Query|\Closure|string $values): self
    {
        if (is_array($values)) {
            $this->wheres[] = [$column, 'NOT IN', $values, 'AND'];
            return $this;
        }
        $this->wheres[] = [$column, 'NOT_IN_SUB', $this->normalizeSub($values), 'AND'];
        return $this;
    }

    /**
     * orWhereNotIn：同上，逻辑或
     *
     * @param array<int,mixed>|Query|\Closure|string $values
     */
    public function orWhereNotIn(string $column, array|Query|\Closure|string $values): self
    {
        if (is_array($values)) {
            $this->wheres[] = [$column, 'NOT IN', $values, 'OR'];
            return $this;
        }
        $this->wheres[] = [$column, 'NOT_IN_SUB', $this->normalizeSub($values), 'OR'];
        return $this;
    }

    /**
     * whereBetween：列值落在 [min, max] 闭区间
     *
     * @param array{0:mixed,1:mixed} $values [min, max]
     */
    public function whereBetween(string $column, array $values): self
    {
        $this->wheres[] = [$column, 'BETWEEN', $values, 'AND'];
        return $this;
    }

    /**
     * orWhereBetween：同上，逻辑或
     *
     * @param array{0:mixed,1:mixed} $values [min, max]
     */
    public function orWhereBetween(string $column, array $values): self
    {
        $this->wheres[] = [$column, 'BETWEEN', $values, 'OR'];
        return $this;
    }

    /**
     * whereNotBetween：值不在 [min, max] 区间内
     */
    public function whereNotBetween(string $column, array $values): self
    {
        $this->wheres[] = [$column, 'NOT BETWEEN', $values, 'AND'];
        return $this;
    }

    /**
     * orWhereNotBetween：同上，逻辑或
     */
    public function orWhereNotBetween(string $column, array $values): self
    {
        $this->wheres[] = [$column, 'NOT BETWEEN', $values, 'OR'];
        return $this;
    }

    /**
     * whereBetweenColumns：(column BETWEEN start AND end)，区间两端为其它列（非绑定值）
     *
     *   ->whereBetweenColumns('created_at', ['start_at', 'end_at'])
     */
    public function whereBetweenColumns(string $column, array $values): self
    {
        $this->wheres[] = [$column, 'BETWEEN_COLS', $values, 'AND'];
        return $this;
    }

    /**
     * orWhereBetweenColumns：同上，逻辑或
     */
    public function orWhereBetweenColumns(string $column, array $values): self
    {
        $this->wheres[] = [$column, 'BETWEEN_COLS', $values, 'OR'];
        return $this;
    }

    public function orderBy(string $column, string $dir = 'ASC'): self
    {
        $this->orders[] = [$column, strtoupper($dir)];
        return $this;
    }

    /**
     * 降序排序（orderBy 的常用简写）
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * 原生 ORDER BY 片段（用于排序表达式/聚合/随机等，参数化防注入）
     *
     *   ->orderByRaw('RAND()')
     *   ->orderByRaw('FIELD(id, ?) ASC', [3, 1, 2])
     */
    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $this->orders[] = ['RAW', $sql, $bindings];
        return $this;
    }

    /**
     * 按时间列降序（默认 created_at），等价于 orderBy($column, 'DESC')
     */
    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * 按时间列升序（默认 created_at），等价于 orderBy($column, 'ASC')
     */
    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * 随机排序（跨库兼容：SQLite 用 RANDOM()，其余用 RAND()）
     */
    public function inRandomOrder(): self
    {
        $fn = $this->connection->getDriver() === 'sqlite' ? 'RANDOM()' : 'RAND()';
        return $this->orderByRaw($fn);
    }

    /**
     * 重置排序（清空已设 ORDER BY），可选附带新的排序。
     *
     * 常用于覆盖关联/全局作用域里预设的默认排序，或先按某列排序后改为随机：
     *
     *   ->orderBy('created_at','desc')->reorder();          // 清除排序
     *   ->orderBy('created_at')->reorder('id','asc');        // 改为按 id 升序
     *   ->reorder('views','desc');                           // 直接设置新排序（等价于先清后设）
     */
    public function reorder(?string $column = null, ?string $dir = null): self
    {
        $this->orders = [];
        if ($column !== null) {
            $this->orderBy($column, $dir ?? 'ASC');
        }
        return $this;
    }

    /**
     * DISTINCT 去重查询（作用于 SELECT 字段）
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * whereDate：按日期部分比较（DATE(col) op ?）
     *
     *   ->whereDate('created_at', '2026-10-11')
     *   ->whereDate('created_at', '>=', '2026-01-01')
     */
    public function whereDate(string $column, string $op, ?string $value = null): self
    {
        if ($value === null) {
            $value = $op;
            $op    = '=';
        }
        $this->wheres[] = ['DATE(' . $this->quoteColumn($column) . ')', $op, $value, 'AND'];
        return $this;
    }

    public function orWhereDate(string $column, string $op, ?string $value = null): self
    {
        if ($value === null) {
            $value = $op;
            $op    = '=';
        }
        $this->wheres[] = ['DATE(' . $this->quoteColumn($column) . ')', $op, $value, 'OR'];
        return $this;
    }

    /**
     * 嵌套条件分组：(...) 内部的条件由回调构造
     *
     *   ->where('a', 1)->whereGroup(fn($q) => $q->where('b', 2)->orWhere('c', 3))
     *   // => WHERE a = 1 AND (b = 2 OR c = 3)
     */
    public function whereGroup(callable $callback, string $boolean = 'AND'): self
    {
        $group = new self($this->connection, $this->prefix);
        $callback($group);
        $this->wheres[] = ['group' => $group->wheres, 'boolean' => $boolean];
        return $this;
    }

    /**
     * 嵌套 OR 分组：等价于 whereGroup($cb, 'OR')
     */
    public function orWhereGroup(callable $callback): self
    {
        return $this->whereGroup($callback, 'OR');
    }

    /**
     * JSON 路径提取表达式（跨数据库可移植）
     *
     * MySQL 用 JSON_EXTRACT(col, '$.path')，SQLite 用 json_extract(col, '$.path')；
     * 路径可传 "name"（自动补 $.）或 "$.profile.age"（原样使用）。
     */
    protected function jsonExtract(string $column, string $path): string
    {
        $col  = $this->quoteColumn($column);
        $norm = str_starts_with($path, '$') ? $path : '$.' . $path;
        $norm = str_replace("'", "''", $norm);
        if (strtolower($this->connection->getDriver()) === 'mysql') {
            return "JSON_EXTRACT({$col}, '{$norm}')";
        }
        return "json_extract({$col}, '{$norm}')";
    }

    /**
     * JSON 路径提取表达式（含数值比较的亲和性修正）
     *
     * SQLite 下 json_extract 返回的值会与“字符串绑定参数”发生亲和性误判
     * （INTEGER 视为恒小于 TEXT），故当比较值为数值时包一层 CAST(... AS REAL)，
     * 强制走数值比较；字符串值（含 LIKE / 等值）保持原样。MySQL 同样适用。
     */
    protected function jsonExpr(string $column, string $path, mixed $value): string
    {
        $expr = $this->jsonExtract($column, $path);
        if (is_int($value) || is_float($value)) {
            $expr = "CAST({$expr} AS REAL)";
        }
        return $expr;
    }

    /**
     * 按 JSON 文档内路径比较（whereJson('meta', 'name', '=', 'neo')）
     *
     * 提取路径上的值后应用 $operator 与 $value 比较；路径不存在时提取结果为
     * NULL（可用 whereJsonIsNull 显式判断）。MySQL / SQLite 均可移植。
     */
    public function whereJson(string $column, string $path, mixed $operator = '=', mixed $value = null): self
    {
        if ($value === null && $operator !== '=') {
            $value    = $operator;
            $operator = '=';
        }
        $this->wheres[] = [$this->jsonExpr($column, $path, $value), (string) $operator, $value, 'AND'];
        return $this;
    }

    public function orWhereJson(string $column, string $path, mixed $operator = '=', mixed $value = null): self
    {
        if ($value === null && $operator !== '=') {
            $value    = $operator;
            $operator = '=';
        }
        $this->wheres[] = [$this->jsonExpr($column, $path, $value), (string) $operator, $value, 'OR'];
        return $this;
    }

    /**
     * JSON 路径不存在 / 为 NULL（whereJsonIsNull('meta', 'phone')）
     */
    public function whereJsonIsNull(string $column, string $path): self
    {
        $this->wheres[] = [$this->jsonExtract($column, $path), 'NULL', null, 'AND'];
        return $this;
    }

    public function orWhereJsonIsNull(string $column, string $path): self
    {
        $this->wheres[] = [$this->jsonExtract($column, $path), 'NULL', null, 'OR'];
        return $this;
    }

    public function whereJsonNotNull(string $column, string $path): self
    {
        $this->wheres[] = [$this->jsonExtract($column, $path), 'NOTNULL', null, 'AND'];
        return $this;
    }

    public function orWhereJsonNotNull(string $column, string $path): self
    {
        $this->wheres[] = [$this->jsonExtract($column, $path), 'NOTNULL', null, 'OR'];
        return $this;
    }

    /**
     * EXISTS 子查询（相关子查询可在闭包内手写外部表关联）
     *
     *   ->whereExists(function (Query $q) {
     *       return $q->table('admins')->where('id', 1);
     *   })
     *
     * 子查询可传：闭包（接收同连接的新 Query）、Query 实例、或原生 SQL 字符串。
     */
    public function whereExists(Query|\Closure|string $subquery, string $boolean = 'AND'): self
    {
        $this->wheres[] = ['exists' => $this->normalizeSub($subquery), 'not' => false, 'bool' => $boolean];
        return $this;
    }

    public function orWhereExists(Query|\Closure|string $subquery): self
    {
        return $this->whereExists($subquery, 'OR');
    }

    public function whereNotExists(Query|\Closure|string $subquery, string $boolean = 'AND'): self
    {
        $this->wheres[] = ['exists' => $this->normalizeSub($subquery), 'not' => true, 'bool' => $boolean];
        return $this;
    }

    public function orWhereNotExists(Query|\Closure|string $subquery): self
    {
        return $this->whereNotExists($subquery, 'OR');
    }

    /**
     * 全文检索（MySQL 目标：MATCH(col) AGAINST (? ...)）
     *
     * 注意：全文检索依赖数据库原生能力——MySQL 的 MATCH...AGAINST。SQLite 仅
     * 在 FTS5 虚拟表上支持，普通列不支持，故本项目以 MySQL 为首要目标。调用前
     * 可用 connection()->getDriver() 判断后端；非 MySQL 后端执行会报语法错，
     * 与悲观锁策略一致（toSql() 始终可断言生成的 SQL 形态）。
     *
     *   ->whereFullText(['title','body'], '框架 路由', 'boolean')
     */
    public function whereFullText(string|array $columns, string $value, string $mode = 'natural'): self
    {
        $cols    = is_array($columns) ? $columns : [$columns];
        $quoted  = array_map($this->quoteColumn(...), $cols);
        $colList = implode(', ', $quoted);
        $against = strtolower($mode) === 'boolean' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';
        $sql     = "MATCH ({$colList}) AGAINST (? {$against})";
        $this->wheres[] = [$sql, 'RAW', $value, 'AND'];
        return $this;
    }

    public function orWhereFullText(string|array $columns, string $value, string $mode = 'natural'): self
    {
        $cols    = is_array($columns) ? $columns : [$columns];
        $quoted  = array_map($this->quoteColumn(...), $cols);
        $colList = implode(', ', $quoted);
        $against = strtolower($mode) === 'boolean' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';
        $sql     = "MATCH ({$colList}) AGAINST (? {$against})";
        $this->wheres[] = [$sql, 'RAW', $value, 'OR'];
        return $this;
    }

    /**
     * 原生 WHERE 片段（支持多个 ? 占位符，统一参数化防注入）
     *
     *   DB::table('user')->whereRaw('age > ? AND vip = ?', [18, 1])
     *   DB::table('user')->whereRaw('created_at >= DATE(?)', ['2026-01-01'])
     *
     * 占位符按出现顺序与 $bindings 一一对应；与 whereExists / UNION 等子查询可混用。
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->wheres[] = [$sql, 'RAW', $bindings, $boolean];
        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    /**
     * 集合操作：UNION（去重）/ UNION ALL（保留重复）
     *
     *   DB::table('a')->where('x', 1)->union(
     *       DB::table('b')->where('x', 2)
     *   );
     *   // 或闭包：fn (Query $q) => $q->table('b')->where('x', 2)
     *   // 或原生 SQL：->union('SELECT 2 AS x')
     *
     * 各分支共享同一连接与表前缀；绑定参数按“主查询 → 各 UNION 分支”顺序合并。
     */
    public function union(Query|\Closure|string $query): self
    {
        return $this->addUnion($query, false);
    }

    public function unionAll(Query|\Closure|string $query): self
    {
        return $this->addUnion($query, true);
    }

    protected function addUnion(Query|\Closure|string $query, bool $all): self
    {
        if ($query instanceof \Closure) {
            $sub  = new self($this->connection, $this->prefix);
            $query($sub);
            $query = $sub;
        }
        $this->unions[] = ['query' => $query, 'all' => $all];
        return $this;
    }

    /**
     * 将闭包 / 字符串 / Query 规整为可直接编译的子查询（闭包解析为同连接新 Query）
     */
    protected function normalizeSub(Query|\Closure|string $sub): Query|string
    {
        if ($sub instanceof \Closure) {
            $q = new self($this->connection, $this->prefix);
            $sub($q);
            return $q;
        }
        return $sub;
    }

    /** @return array{0:string,1:list<mixed>} */
    protected function compileSub(Query|string $sub): array
    {
        if (is_string($sub)) {
            return [$sub, []];
        }
        return $sub->compile();
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit  = $limit;
        $this->offset = $offset;
        return $this;
    }

    /**
     * 取前 N 条（limit 别名，保留已有 offset）
     */
    public function take(int $value): self
    {
        $this->limit = $value;
        return $this;
    }

    /**
     * 跳过前 N 条（offset 别名，保留已有 limit）
     */
    public function skip(int $value): self
    {
        $this->offset = $value;
        return $this;
    }

    /**
     * offset 别名（与 skip 等价）
     */
    public function offset(int $value): self
    {
        $this->offset = $value;
        return $this;
    }

    /**
     * 按页码分页（page 从 1 起，perPage 默认 15）
     */
    public function forPage(int $page, int $perPage = 15): self
    {
        $page         = max(1, $page);
        $this->limit  = $perPage;
        $this->offset = ($page - 1) * $perPage;
        return $this;
    }

    /**
     * 悲观写锁：SELECT ... FOR UPDATE（须在事务内使用，否则退化为普通读）
     *
     *   DB::transaction(function () {
     *       $row = DB::table('account')->where('id', 1)->lockForUpdate()->find();
     *       // ... 基于 $row 计算后更新 ...
     *   });
     *
     * @return $this
     */
    public function lockForUpdate(): self
    {
        $this->lockMode = 'UPDATE';
        return $this;
    }

    /**
     * 悲观读锁（共享锁）：SELECT ... FOR SHARE（MySQL 8+；须在事务内使用）
     *
     * @return $this
     */
    public function sharedLock(): self
    {
        $this->lockMode = 'SHARE';
        return $this;
    }

    /**
     * 通用悲观锁：lock('UPDATE') / lock('SHARE')
     *
     * @return $this
     */
    public function lock(string $mode = 'UPDATE'): self
    {
        $this->lockMode = strtoupper($mode) === 'SHARE' ? 'SHARE' : 'UPDATE';
        return $this;
    }

    public function join(string $table, string $on, string $type = 'INNER'): self
    {
        $this->joins[] = [$type, $this->prefix . $table, $on];
        return $this;
    }

    /**
     * 左连接（返回驱动表全部行，右表无匹配则补 NULL）
     *
     *   ->leftJoin('profiles', 'users.id = profiles.user_id')
     */
    public function leftJoin(string $table, string $on): self
    {
        return $this->join($table, $on, 'LEFT');
    }

    public function rightJoin(string $table, string $on): self
    {
        return $this->join($table, $on, 'RIGHT');
    }

    /**
     * 笛卡尔积连接（无 ON 条件）
     *
     *   ->crossJoin('tags')
     */
    public function crossJoin(string $table): self
    {
        $this->joins[] = ['CROSS', $this->prefix . $table, ''];
        return $this;
    }

    /**
     * GROUP BY（支持多个列；纯列名自动加反引号，表达式原样输出）
     */
    public function groupBy(string ...$cols): self
    {
        foreach ($cols as $c) {
            if ($c !== '') {
                $this->groups[] = $c;
            }
        }
        return $this;
    }

    /**
     * HAVING（聚合后过滤，语法同 where）
     */
    public function having(mixed $column, mixed $op = null, mixed $value = null): self
    {
        if ($value === null && $op !== null) {
            $value = $op;
            $op    = '=';
        }
        $this->havings[] = [(string) $column, (string) $op, $value, 'AND'];
        return $this;
    }

    /**
     * HAVING 的“逻辑或”版本（语法同 having）
     */
    public function orHaving(mixed $column, mixed $op = null, mixed $value = null): self
    {
        if ($value === null && $op !== null) {
            $value = $op;
            $op    = '=';
        }
        $this->havings[] = [(string) $column, (string) $op, $value, 'OR'];
        return $this;
    }

    /**
     * HAVING 区间过滤（聚合后过滤列落在 [min, max]）
     *
     *   ->groupBy('user_id')->havingBetween('COUNT(*)', [1, 5])
     */
    public function havingBetween(mixed $column, array $values, string $boolean = 'AND'): self
    {
        $this->havings[] = [(string) $column, 'BETWEEN', $values, $boolean];
        return $this;
    }

    /**
     * HAVING 区间过滤的“逻辑或”版本
     */
    public function orHavingBetween(mixed $column, array $values): self
    {
        return $this->havingBetween($column, $values, 'OR');
    }

    /**
     * 原生 HAVING 片段（逃生舱：HAVING 内写聚合/表达式，参数化防注入）
     *
     *   ->groupBy('user_id')->havingRaw('COUNT(*) > ?', [5])
     */
    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->havings[] = ['RAW', $sql, $bindings, $boolean];
        return $this;
    }

    // -------- 编译 --------

    protected function selectClause(): string
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        if ($this->fields === ['*']) {
            return $distinct . '*';
        }
        return $distinct . implode(', ', array_map(function (string $f): string {
            if ($f === '*') {
                return '*';
            }
            // 纯列名才加反引号；聚合/表达式（含括号、空格、点号等）原样输出，避免 COUNT(*) 被误包裹
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $f)) {
                return "`{$f}`";
            }
            return $f;
        }, $this->fields));
    }

    /**
     * 列名引用：纯列名加反引号；带点号的限定列（如 `o.total`）逐段加反引号；
     * 表达式（含括号/已带反引号）原样输出，避免 COUNT(*) 被误包裹。
     */
    protected function quoteColumn(string $col): string
    {
        if ($col === '*' || str_contains($col, '(') || str_contains($col, '`')) {
            return $col;
        }
        if (str_contains($col, '.')) {
            return implode('.', array_map(fn (string $seg): string => "`{$seg}`", explode('.', $col)));
        }
        return '`' . $col . '`';
    }

    /** @return array{0:string,1:list<mixed>} */
    protected function whereSqlAndBindings(): array
    {
        if (empty($this->wheres)) {
            return ['', []];
        }
        [$sql, $binds] = $this->compileWheres($this->wheres);
        return [' WHERE ' . $sql, $binds];
    }

    /**
     * 递归编译条件（支持嵌套分组）；返回不含 WHERE 前缀的条件串与绑定
     *
     * @param list<mixed> $wheres
     * @return array{0:string,1:list<mixed>}
     */
    protected function compileWheres(array $wheres): array
    {
        $sql   = '';
        $binds = [];
        foreach ($wheres as $w) {
            // EXISTS / NOT EXISTS 子查询
            if (isset($w['exists'])) {
                [$subSql, $subBinds] = $this->compileSub($w['exists']);
                if ($sql !== '') {
                    $sql .= ' ' . $w['bool'] . ' ';
                }
                $sql     .= ($w['not'] ? 'NOT EXISTS (' : 'EXISTS (') . $subSql . ')';
                $binds    = array_merge($binds, $subBinds);
                continue;
            }

            // 嵌套分组
            if (isset($w['group'])) {
                if ($w['group'] === []) {
                    continue;
                }
                [$subSql, $subBinds] = $this->compileWheres($w['group']);
                if ($subSql === '') {
                    continue;
                }
                if ($sql !== '') {
                    $sql .= ' ' . $w['boolean'] . ' ';
                }
                $sql     .= '(' . $subSql . ')';
                $binds    = array_merge($binds, $subBinds);
                continue;
            }

            [$col, $op, $val, $bool] = $w;
            if ($op === 'COLUMN') {
                // 列间比较：两侧均为列名/表达式，不反引号、不绑定占位符
                [$other, $operator] = $val;
                if ($sql !== '') {
                    $sql .= " $bool ";
                }
                $sql     .= "{$col} {$operator} {$other}";
                continue;
            }
            if ($sql !== '') {
                $sql .= " $bool ";
            }
            $col = ($op === 'RAW') ? $col : $this->quoteColumn($col);
            if ($op === 'NULL') {
                $sql .= "$col IS NULL";
                continue;
            }
            if ($op === 'RAW') {
                // 表达式已含占位符（如 MATCH(col) AGAINST(? ...) 或 whereRaw 多占位符），原样透传
                $sql .= $col;
                if (is_array($val)) {
                    foreach ($val as $bv) {
                        $binds[] = $bv;
                    }
                } else {
                    $binds[] = $val;
                }
                continue;
            }
            if ($op === 'NOTNULL') {
                $sql .= "$col IS NOT NULL";
                continue;
            }
            if ($op === 'IN_SUB' || $op === 'NOT_IN_SUB') {
                [$subSql, $subBinds] = $this->compileSub($val);
                if ($sql !== '') {
                    $sql .= " $bool ";
                }
                $sql     .= "$col " . ($op === 'NOT_IN_SUB' ? 'NOT IN' : 'IN') . " ($subSql)";
                $binds    = array_merge($binds, $subBinds);
                continue;
            }
            if ($op === 'IN' || $op === 'NOT IN') {
                $vals = (array) $val;
                if ($vals === []) {
                    // 空集：IN () 非法，用永假条件兜底
                    $sql     .= ($op === 'IN') ? '0=1' : '1=1';
                    continue;
                }
                $placeholders = rtrim(str_repeat('?, ', count($vals)), ', ');
                $sql         .= "$col $op ($placeholders)";
                foreach ($vals as $v) {
                    $binds[] = $v;
                }
                continue;
            }
            if ($op === 'BETWEEN') {
                $vals = (array) $val;
                $sql     .= "$col BETWEEN ? AND ?";
                $binds[] = $vals[0] ?? null;
                $binds[] = $vals[1] ?? null;
                continue;
            }
            if ($op === 'NOT BETWEEN') {
                $vals = (array) $val;
                $sql     .= "$col NOT BETWEEN ? AND ?";
                $binds[] = $vals[0] ?? null;
                $binds[] = $vals[1] ?? null;
                continue;
            }
            if ($op === 'BETWEEN_COLS') {
                [$c1, $c2] = (array) $val;
                $sql .= "$col BETWEEN " . $this->quoteColumn((string) $c1)
                    . ' AND ' . $this->quoteColumn((string) $c2);
                continue;
            }
            $sql     .= "$col $op ?";
            $binds[]  = $val;
        }
        return [$sql, $binds];
    }

    /** @return array{0:string,1:list<mixed>} */
    protected function havingSqlAndBindings(): array
    {
        if (empty($this->havings)) {
            return ['', []];
        }
        $sql   = ' HAVING ';
        $binds = [];
        foreach ($this->havings as $i => $h) {
            // 原生 HAVING 片段
            if (isset($h[0]) && $h[0] === 'RAW') {
                [, $rawSql, $rawBinds, $bool] = $h;
                if ($i > 0) {
                    $sql .= " $bool ";
                }
                $sql     .= "($rawSql)";
                $binds    = array_merge($binds, $rawBinds);
                continue;
            }
            [$col, $op, $val, $bool] = $h;
            if ($i > 0) {
                $sql .= " $bool ";
            }
            // 纯列名才加反引号；聚合/表达式（含括号、星号）原样输出
            $quoted = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col) ? "`{$col}`" : $col;
            if ($op === 'BETWEEN') {
                $vals   = (array) $val;
                $cast1  = (is_int($vals[0] ?? null) || is_float($vals[0] ?? null)) ? 'CAST(? AS INTEGER)' : '?';
                $cast2  = (is_int($vals[1] ?? null) || is_float($vals[1] ?? null)) ? 'CAST(? AS INTEGER)' : '?';
                $sql    .= "$quoted BETWEEN $cast1 AND $cast2";
                $binds[] = $vals[0] ?? null;
                $binds[] = $vals[1] ?? null;
                continue;
            }
            // SQLite 对“HAVING 聚合 > 绑定参数”存在类型亲和性怪癖，数值参数需 CAST 才能正确比较
            $cast   = (is_int($val) || is_float($val)) ? 'CAST(? AS INTEGER)' : '?';
            $sql     .= "$quoted $op $cast";
            $binds[]  = $val;
        }
        return [$sql, $binds];
    }

    /** @return array{0:string,1:list<mixed>} */
    public function compile(): array
    {
        if ($this->fromSub !== null) {
            $alias = $this->fromSub['alias'] !== null ? " AS `{$this->fromSub['alias']}`" : '';
            $from  = "({$this->fromSub['sql']}){$alias}";
            $binds = $this->fromSub['bindings'];
        } else {
            $from  = "`{$this->table}`";
            $binds = [];
        }
        $sql = 'SELECT ' . $this->selectClause() . ' FROM ' . $from;
        foreach ($this->joins as $j) {
            if ($j[0] === 'CROSS') {
                $sql .= " CROSS JOIN `{$j[1]}`";
            } else {
                $sql .= " {$j[0]} JOIN `{$j[1]}` ON {$j[2]}";
            }
        }
        [$whereSql, $whereBinds] = $this->whereSqlAndBindings();
        $sql   .= $whereSql;
        $binds  = array_merge($binds, $whereBinds);

        if (!empty($this->groups)) {
            $parts = array_map(
                fn (string $g): string => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $g) ? "`{$g}`" : $g,
                $this->groups
            );
            $sql .= ' GROUP BY ' . implode(', ', $parts);
        }

        if (!empty($this->havings)) {
            [$havingSql, $havingBinds] = $this->havingSqlAndBindings();
            $sql   .= $havingSql;
            $binds  = array_merge($binds, $havingBinds);
        }

        if (!empty($this->orders)) {
            $parts = [];
            foreach ($this->orders as $o) {
                if ($o[0] === 'RAW') {
                    // 原生 ORDER BY 片段（含可选绑定，并入主查询绑定序列）
                    $parts[] = $o[1];
                    if (isset($o[2]) && is_array($o[2])) {
                        $binds = array_merge($binds, $o[2]);
                    }
                } else {
                    $parts[] = $this->quoteColumn($o[0]) . " {$o[1]}";
                }
            }
            $sql  .= ' ORDER BY ' . implode(', ', $parts);
        }
        if ($this->limit !== null) {
            $sql .= $this->offset ? " LIMIT {$this->offset}, {$this->limit}" : " LIMIT {$this->limit}";
        }
        if ($this->lockMode !== null) {
            $sql .= ' FOR ' . $this->lockMode;
        }

        // 集合操作：UNION / UNION ALL（绑定参数按主查询 → 各分支顺序合并）
        foreach ($this->unions as $u) {
            [$subSql, $subBinds] = $this->compileSub($u['query']);
            $sql   .= ' UNION ' . ($u['all'] ? 'ALL ' : '') . $subSql;
            $binds  = array_merge($binds, $subBinds);
        }
        return [$sql, $binds];
    }

    public function toSql(): string
    {
        return $this->compile()[0];
    }

    /**
     * 导出当前查询编译后的绑定参数序列（与 toSql() 一一对应，便于调试 / 记录）。
     *
     * 等价于 compile()[1]；对 whereRaw() 传入的额外绑定、子查询/union 绑定一并包含。
     *
     * @return array<int,mixed>
     */
    public function getBindings(): array
    {
        return $this->compile()[1];
    }

    // -------- 执行 --------

    /** @return array<int,array<string,mixed>> */
    public function get(): array
    {
        [$sql, $binds] = $this->compile();
        return $this->connection->select($sql, $binds);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->get();
    }

    public function find(): ?array
    {
        $this->limit(1);
        $rows = $this->get();
        return $rows[0] ?? null;
    }

    public function first(): ?array
    {
        return $this->find();
    }

    /**
     * 取第一条，不存在则抛出 ModelNotFoundException
     *
     * @throws \Mtphp\Exception\ModelNotFoundException
     */
    public function firstOrFail(): array
    {
        $row = $this->first();
        if ($row === null) {
            throw new \Mtphp\Exception\ModelNotFoundException('未找到匹配的记录');
        }
        return $row;
    }

    /**
     * 取“恰好一条”匹配记录：0 条抛 ModelNotFoundException，多于 1 条抛 RuntimeException。
     *
     * 适用于“按唯一键查询、期望唯一结果”的场景（如按邮箱查用户、查配置项），
     * 比 first() 多了一层“唯一性”保护，避免脏数据被悄悄当成单条处理。
     *
     * 实现：仅取最多 2 条（LIMIT 2）即可判定“是否有多余记录”，无需全表扫描。
     *
     * @throws \Mtphp\Exception\ModelNotFoundException 0 条
     * @throws \RuntimeException                       多于 1 条
     */
    public function sole(): array
    {
        $rows  = $this->limit(2)->get();
        $count = count($rows);
        if ($count === 0) {
            throw new \Mtphp\Exception\ModelNotFoundException('sole() 未找到匹配的记录');
        }
        if ($count > 1) {
            throw new \RuntimeException('sole() 期望恰好一条记录，实际找到 ' . $count . ' 条');
        }
        return $rows[0];
    }

    /**
     * 分批处理大结果集（按主键游标分页，优于 LIMIT offset 偏移）
     *
     *   DB::table('user')->orderBy('id')->chunk(100, function (array $rows) {
     *       foreach ($rows as $row) { /* ... *\/ }
     *   });
     *
     * 回调返回 false 可提前终止；返回非 false 继续。默认按 `id` 主键游标，
     * 若主键列名不同可传 $column 参数。
     *
     * @param callable(array<int,array<string,mixed>>): (bool|void) $callback
     * @return bool 是否处理完所有行
     */
    public function chunk(int $size, callable $callback, string $column = 'id'): bool
    {
        if ($size < 1) {
            $size = 1;
        }
        $lastId = 0;
        do {
            $rows = (clone $this)
                ->where($column, '>', $lastId)
                ->orderBy($column, 'asc')
                ->limit($size)
                ->get();
            $count = count($rows);
            if ($count === 0) {
                break;
            }
            $result = $callback($rows);
            if ($result === false) {
                return false;
            }
            $lastRow = $rows[$count - 1];
            $lastId  = (int) ($lastRow[$column] ?? $lastId);
        } while ($count === $size);

        return true;
    }

    /**
     * 逐行遍历结果集（基于 chunk 的游标分页，内存友好，适合大表）。
     *
     * 相较于一次性 get() 全部再 foreach，each() 每批只加载 $size 行，
     * 回调对每一行调用一次，避免大结果集把内存打爆。回调无返回值约定
     * （无需像 chunk 那样返回 false 终止）。
     *
     *   DB::table('user')->orderBy('id')->each(function (array $row) {
     *       echo $row['name'];
     *   });
     *
     * @param callable(array<string,mixed>): void $callback
     * @return bool 是否遍历完所有行
     */
    public function each(callable $callback, ?int $size = 100): bool
    {
        return $this->chunk((int) ($size > 0 ? $size : 100), function (array $rows) use ($callback): bool {
            foreach ($rows as $row) {
                $callback($row);
            }
            return true;
        });
    }

    public function value(string $column): mixed
    {
        $row = $this->find();
        return $row[$column] ?? null;
    }

    public function count(string $column = '*'): int
    {
        $this->fields = ["COUNT($column) AS _c"];
        $row = $this->find();
        return (int) ($row['_c'] ?? 0);
    }

    /**
     * 聚合：求和（返回 float，无行时 0）
     */
    public function sum(string $column): float
    {
        $this->fields = ["SUM(`$column`) AS _a"];
        $row = $this->find();
        return (float) ($row['_a'] ?? 0);
    }

    /**
     * 聚合：平均值（返回 float，无行时 0）
     */
    public function avg(string $column): float
    {
        $this->fields = ["AVG(`$column`) AS _a"];
        $row = $this->find();
        return (float) ($row['_a'] ?? 0);
    }

    /**
     * 聚合：最大值（按列类型返回，无行时 null）
     */
    public function max(string $column): mixed
    {
        $this->fields = ["MAX(`$column`) AS _a"];
        $row = $this->find();
        return $row['_a'] ?? null;
    }

    /**
     * 聚合：最小值（按列类型返回，无行时 null）
     */
    public function min(string $column): mixed
    {
        $this->fields = ["MIN(`$column`) AS _a"];
        $row = $this->find();
        return $row['_a'] ?? null;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * 不存在性判断（exists 的反义）：查询结果为空时返回 true
     *
     *   if (DB::table('user')->where('id', 999)->doesntExist()) { /* 记录不存在 *\/ }
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * 抽取单列（或“列→键”映射）为数组
     *
     *   ->pluck('name')            // ['alice','bob',...]
     *   ->pluck('name', 'id')      // [1=>'alice', 2=>'bob', ...]
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $this->get();
        $out  = [];
        foreach ($rows as $row) {
            $value = $row[$column] ?? null;
            if ($key !== null) {
                $out[$row[$key] ?? null] = $value;
            } else {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * 条件式构造：条件为真执行 $callback，否则（可选）执行 $default；始终返回构造器自身
     *
     *   DB::table('u')->when($name, fn($q) => $q->where('name', $name))
     *                 ->when($age,  fn($q) => $q->where('age','>',$age), fn($q)=>$q->where('age','>',0));
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): self
    {
        if ($condition) {
            $callback($this);
        } elseif ($default !== null) {
            $default($this);
        }
        return $this;
    }

    /**
     * 旁路钩子：执行回调（用于副作用/调试/记日志）后返回构造器自身，不改变查询
     *
     *   DB::table('u')->tap(fn($q) => logger($q->toSql()))->get();
     */
    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO `' . $this->table . '` (`'
              . implode('`,`', $cols) . '`) VALUES ('
              . rtrim(str_repeat('?, ', count($cols)), ', ') . ')';
        return $this->connection->insert($sql, array_values($data));
    }

    public function insertGetId(array $data): int
    {
        return $this->insert($data);
    }

    public function update(array $data): int
    {
        $sets  = [];
        $binds = [];
        foreach ($data as $col => $val) {
            $sets[]  = "`$col` = ?";
            $binds[] = $val;
        }
        [$whereSql, $whereBinds] = $this->whereSqlAndBindings();
        $sql   = 'UPDATE `' . $this->table . '` SET ' . implode(', ', $sets) . $whereSql;
        $binds = array_merge($binds, $whereBinds);
        return $this->connection->update($sql, $binds);
    }

    /**
     * 自增：UPDATE `table` SET `col` = `col` + ? WHERE ...（有 WHERE 时才执行，避免全表误操作）
     */
    public function increment(string $column, int $amount = 1): int
    {
        return $this->incrementOrDecrement($column, $amount, '+');
    }

    /**
     * 自减：UPDATE `table` SET `col` = `col` - ? WHERE ...（有 WHERE 时才执行，避免全表误操作）
     */
    public function decrement(string $column, int $amount = 1): int
    {
        return $this->incrementOrDecrement($column, $amount, '-');
    }

    protected function incrementOrDecrement(string $column, int $amount, string $op): int
    {
        if (empty($this->wheres)) {
            throw new \RuntimeException("increment/decrement 必须带 where 条件，避免全表更新");
        }
        [$whereSql, $whereBinds] = $this->whereSqlAndBindings();
        $sql   = "UPDATE `{$this->table}` SET `{$column}` = `{$column}` {$op} ?" . $whereSql;
        $binds = array_merge([$amount], $whereBinds);
        return $this->connection->update($sql, $binds);
    }

    public function delete(): int
    {
        [$whereSql, $binds] = $this->whereSqlAndBindings();
        $sql = 'DELETE FROM `' . $this->table . '`' . $whereSql;
        return $this->connection->delete($sql, $binds);
    }

    /**
     * 原始 SQL（参数化）。返回查询结果（SELECT）或执行结果（写操作）。
     * 用于框架暂未封装的复杂查询，是个“逃生舱”。
     *
     * @return array<int,array<string,mixed>>|bool
     */
    public function raw(string $sql, array $bindings = []): array|bool
    {
        if (preg_match('/^\s*select/i', $sql)) {
            return $this->connection->select($sql, $bindings);
        }
        return $this->connection->statement($sql, $bindings);
    }

    /**
     * 批量插入（一条 INSERT 多行，比逐条 insert 省大量往返）
     *
     * @param array<int,array<string,mixed>> $rows 每行同构（键集合一致）
     * @return int 插入行数
     */
    public function bulkInsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $cols      = array_keys($rows[0]);
        $allBinds  = [];
        $tuples    = [];
        foreach ($rows as $row) {
            $tuples[] = '(' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')';
            foreach ($cols as $c) {
                $allBinds[] = $row[$c] ?? null;
            }
        }
        $sql = 'INSERT INTO `' . $this->table . '` (`'
             . implode('`,`', $cols) . '`) VALUES ' . implode(', ', $tuples);
        $this->connection->statement($sql, $allBinds);
        return count($rows);
    }

    /**
     * 批量插入或更新（UPSERT）
     *
     * MySQL：INSERT ... ON DUPLICATE KEY UPDATE；SQLite：INSERT ... ON CONFLICT(col) DO UPDATE。
     * $uniqueBy 用于判定冲突的列（通常为主键 / 唯一索引）；$update 为冲突时要更新的列，
     * 留空则更新全部可写列。
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>              $uniqueBy
     * @param array<int,string>|null         $update
     * @return int 受影响行数（按插入行数计）
     */
    public function upsert(array $rows, array $uniqueBy, ?array $update = null): int
    {
        if ($rows === []) {
            return 0;
        }
        $cols      = array_keys($rows[0]);
        $allBinds  = [];
        $tuples    = [];
        foreach ($rows as $row) {
            $tuples[] = '(' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')';
            foreach ($cols as $c) {
                $allBinds[] = $row[$c] ?? null;
            }
        }
        $sql = 'INSERT INTO `' . $this->table . '` (`'
             . implode('`,`', $cols) . '`) VALUES ' . implode(', ', $tuples);

        if ($this->connection->getDriver() === 'mysql') {
            $upd  = $update ?? $cols;
            $sets = array_map(fn (string $c): string => "`{$c}` = VALUES(`{$c}`)", $upd);
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        } else {
            // SQLite 旧版本不支持 excluded 关键字；用 INSERT OR REPLACE 实现整行 upsert。
            // 语义：提供整行新值则覆盖，行为与“插入或全量更新”一致（主键/唯一冲突时替换）。
            $sql = preg_replace('/^INSERT INTO/', 'INSERT OR REPLACE INTO', $sql, 1);
        }
        $this->connection->statement($sql, $allBinds);
        return count($rows);
    }

    /**
     * 分页查询：返回 Paginator（零依赖，思想借鉴主流框架的 paginate）
     *
     * 内部用“克隆体”统计总数，避免污染当前构造器的字段/限制状态。
     *
     * @return \Mtphp\Pagination\Paginator
     */
    public function paginate(int $perPage = 15, ?int $page = null): \Mtphp\Pagination\Paginator
    {
        if ($page === null) {
            $page = (int) ($_GET['page'] ?? 1);
        }
        if ($page < 1) {
            $page = 1;
        }

        $counter = clone $this;
        $total   = $counter->count();

        $items   = $this->limit($perPage, ($page - 1) * $perPage)->get();

        return new \Mtphp\Pagination\Paginator($items, $total, $perPage, $page);
    }

    /**
     * 简单分页：不执行 COUNT，靠“多取 1 行”判定是否存在下一页。
     *
     * 适合大表 / 无需精确总数的场景（避免 SELECT COUNT 全表扫描）。
     * 返回的 Paginator 为简单模式：total()/lastPage() 返回 -1（无意义），
     * hasMorePages() / nextPageUrl() / previousPageUrl() / onFirstPage() 仍可正常用。
     *
     * @return \Mtphp\Pagination\Paginator
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null): \Mtphp\Pagination\Paginator
    {
        if ($page === null) {
            $page = (int) ($_GET['page'] ?? 1);
        }
        if ($page < 1) {
            $page = 1;
        }

        $items   = $this->limit($perPage + 1, ($page - 1) * $perPage)->get();
        $hasMore = count($items) > $perPage;
        if ($hasMore) {
            $items = array_slice($items, 0, $perPage);
        }

        return new \Mtphp\Pagination\Paginator($items, 0, $perPage, $page, '', true, $hasMore);
    }
}
