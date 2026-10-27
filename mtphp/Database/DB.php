<?php
declare(strict_types=1);

namespace Mtphp\Database;

use Mtphp\App;

/**
 * 数据库静态入口（门面风格）
 *
 *   DB::table('user')->where('id', 4)->get();
 *   DB::transaction(function(){ ... });
 *
 * 连接获取策略：
 *  - FPM/CLI 模式：从 config 构建 PDO 版 Connection，按连接名缓存复用。
 *  - Swoole 常驻模式：由 Server 注册 resolver，从协程连接池取连（不跨协程缓存，
 *    连接随协程归还），以此支撑高并发。
 */
class DB
{
    /** @var array<string,Connection> 连接缓存（FPM/CLI 模式有效） */
    protected static array $connections = [];

    /** @var array<string,Connector> 读写分离包装连接缓存（按写库名索引，支持多组/分片独立分离） */
    protected static array $rwConnections = [];

    /** @var callable|null Swoole 模式下由 Server 注入的连接解析器 */
    protected static $resolver = null;

    /**
     * 注册连接解析器（Swoole 常驻模式调用）；传 null 清空（测试/重置用）
     *
     * @param callable(string $name): Connector|null $resolver
     */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function connection(?string $name = null): Connector
    {
        // 未指定连接名时，按配置取默认连接名；仅在需要时读取 App（避免无 App 上下文时崩溃）
        if ($name === null) {
            $app  = App::instance();
            $name = $app !== null ? $app->config()->get('database.default', 'mysql') : 'mysql';
        }

        // Swoole 模式：交由 resolver（连接池）实时供给，不跨协程缓存
        if (self::$resolver !== null) {
            return (self::$resolver)($name);
        }

        // 读连接配置（读写分离）：优先连接级 read，其次默认连接的顶层 read（向后兼容）
        $reads = self::readsFor($name);
        if ($reads !== []) {
            return self::readWriteConnection($name, $reads);
        }

        if (!isset(self::$connections[$name])) {
            $cfg = App::instance()->config()->get('database.connections.' . $name);
            if (empty($cfg)) {
                throw new \RuntimeException("数据库连接未配置：{$name}");
            }
            self::$connections[$name] = new Connection($cfg);
        }
        return self::$connections[$name];
    }

    /**
     * 解析某连接名对应的读库列表（连接级 read 优先，默认连接退化为顶层 database.read）
     *
     * @return list<string>
     */
    protected static function readsFor(string $name): array
    {
        $app = App::instance();
        if ($app === null) {
            return [];
        }
        $cfg   = $app->config()->get('database.connections.' . $name, []);
        $reads = (array) ($cfg['read'] ?? null);
        if ($reads === [] && $name === $app->config()->get('database.default', 'mysql')) {
            $reads = (array) $app->config()->get('database.read', []);
        }
        return $reads;
    }

    /**
     * 构造某写连接的读写分离包装（读配置为读连接名数组）。
     * 包装连接按写库名索引，因此默认连接、各分片连接可各自独立做读写分离。
     *
     * @param list<string> $readNames
     */
    protected static function readWriteConnection(string $writeName, array $readNames): Connector
    {
        if (!isset(self::$rwConnections[$writeName])) {
            $write = self::plainConnection($writeName);
            $reads = [];
            foreach ($readNames as $rn) {
                $reads[] = self::plainConnection((string) $rn);
            }
            self::$rwConnections[$writeName] = new ReadWriteConnection($write, $reads);
        }
        return self::$rwConnections[$writeName];
    }

    /**
     * 取一个「纯」连接（不做读写分离包装），用于读写分离内部的读写端点
     */
    protected static function plainConnection(string $name): Connector
    {
        if (!isset(self::$connections[$name])) {
            $cfg = App::instance()->config()->get('database.connections.' . $name);
            if (empty($cfg)) {
                throw new \RuntimeException("数据库连接未配置：{$name}");
            }
            self::$connections[$name] = new Connection($cfg);
        }
        return self::$connections[$name];
    }

    public static function table(string $table): Query
    {
        $conn   = self::connection();
        $prefix = $conn->getPrefix();
        return (new Query($conn, $prefix))->table($table);
    }

    /** @return array<int,array<string,mixed>> */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::connection()->select($sql, $bindings);
    }

    public static function statement(string $sql, array $bindings = []): bool
    {
        return self::connection()->statement($sql, $bindings);
    }

    /**
     * 事务（FPM/CLI 模式）；Swoole 模式下建议直接用 Connection 的协程连接处理
     *
     * @return mixed
     */
    public static function transaction(\Closure $callback): mixed
    {
        $conn = self::connection();
        if ($conn instanceof Connection) {
            return $conn->transaction($callback);
        }
        // 协程连接 / 读写分离 / 任意 Connector：统一用 Transaction（SAVEPOINT 嵌套）
        return (new Transaction($conn))->run($callback);
    }

    public static function beginTransaction(): bool
    {
        return self::connection()->statement('BEGIN');
    }

    public static function commit(): bool
    {
        return self::connection()->statement('COMMIT');
    }

    public static function rollBack(): bool
    {
        return self::connection()->statement('ROLLBACK');
    }

    /**
     * 开启事务（短别名，契合“关键词尽量短”的偏好）
     */
    public static function startTrans(): bool
    {
        return self::beginTransaction();
    }

    /**
     * 事务闭包（短别名，等同 transaction）
     *
     * @template T
     * @param \Closure $callback
     * @return mixed
     */
    public static function trans(\Closure $callback): mixed
    {
        return self::transaction($callback);
    }

    // -------- 分库（水平拆分） --------

    /**
     * 计算分片键对应的物理连接名（按 config/database.php 的 database.sharding.<group>）
     *
     * @param string $group 分库组名
     * @param mixed  $key   分片键（user_id / order_id 等）
     */
    public static function shardName(string $group, mixed $key): string
    {
        $app = App::instance();
        if ($app === null) {
            throw new \RuntimeException('分库需在应用上下文中使用（请先引导 App 并配置 database.sharding）');
        }
        $cfg = $app->config()->get('database.sharding.' . $group);
        if (empty($cfg) || empty($cfg['shards'])) {
            throw new \RuntimeException("分库组未配置：{$group}");
        }
        $shards     = $cfg['shards'];
        $algorithm  = (string) ($cfg['algorithm'] ?? 'hash');
        $boundaries = (array) ($cfg['boundaries'] ?? []);
        $idx        = ShardManager::index($key, $shards, $algorithm, $boundaries);
        return $shards[$idx];
    }

    /**
     * 取分片键对应的具体物理连接（可继续链式 Query；若该分片配置了 read 会自动读写分离）
     *
     * @param string $group 分库组名
     * @param mixed  $key   分片键
     */
    public static function shard(string $group, mixed $key): Connector
    {
        return self::connection(self::shardName($group, $key));
    }

    /**
     * 取出某分库组下的全部物理分片连接名（顺序即分片编号）
     *
     * @return list<string>
     */
    public static function shardList(string $group): array
    {
        $app = App::instance();
        if ($app === null) {
            throw new \RuntimeException('分库需在应用上下文中使用（请先引导 App 并配置 database.sharding）');
        }
        $cfg = $app->config()->get('database.sharding.' . $group);
        if (empty($cfg)) {
            throw new \RuntimeException("分库组未配置：{$group}");
        }
        return ShardManager::shardsFromConfig((array) $cfg);
    }

    /**
     * 跨分片执行：对组内每个物理连接各跑一次回调，结果以 [shardName => result] 返回。
     *
     * 回调签名：fn(Mtphp\Database\Query $q, string $shardName): mixed
     * 回调里可用完整的 Query 链式能力（where / orderBy / limit / count …）；若分片配置了
     * read，则这里的连接本身已自动读写分离（写主读从 / sticky）。
     *
     * 例：统计各分片订单数并汇总
     *   $per = DB::across('orders', fn($q) => $q->table('orders')->count());
     *   $total = DB::sumAcross($per, ...); // count 返回标量时可直接 array_sum
     *
     * @param string   $group    分库组名
     * @param \Closure $callback 逐分片回调
     * @return array<string,mixed> shardName => 回调返回值
     */
    public static function across(string $group, \Closure $callback): array
    {
        $out = [];
        foreach (self::shardList($group) as $name) {
            $conn = self::connection($name);
            $q    = new Query($conn, $conn->getPrefix());
            $out[$name] = $callback($q, $name);
        }
        return $out;
    }

    /**
     * 合并跨分片结果：把 [shardName => [rows]] 压平成统一的行列表（用于跨片取数后聚合展示）
     *
     * @param array<string,mixed> $perShard 通常为 DB::across() 的返回值（每项是行数组）
     * @return array<int,array<string,mixed>>
     */
    public static function mergeAcross(array $perShard): array
    {
        $merged = [];
        foreach ($perShard as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $merged[] = $row;
            }
        }
        return $merged;
    }

    /**
     * 跨分片按列求和：把 [shardName => [rows]] 中每行的 $column 累加（订单金额汇总等场景）
     *
     * @param array<string,mixed> $perShard 通常为 DB::across() 的返回值（每项是行数组）
     */
    public static function sumAcross(array $perShard, string $column): float
    {
        $sum = 0.0;
        foreach ($perShard as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (is_array($row) && array_key_exists($column, $row)) {
                    $sum += (float) $row[$column];
                }
            }
        }
        return $sum;
    }

    /**
     * 跨分片全局计数：对组内每个分片各 count 一次再求和（总量统计，无需“全局索引”）。
     *
     * 可传入 $modify 回调对各分片的 Query 追加过滤（如按时间窗），签名 fn(Query $q): void。
     *
     * 例：统计 orders 组全部订单数
     *   $total = DB::countAcross('orders', 'orders');
     *   $today = DB::countAcross('orders', 'orders', fn($q) => $q->whereDate('created_at','2026-10-11'));
     *
     * @param string        $group   分库组名
     * @param string        $table   逻辑表名
     * @param \Closure|null $modify  逐分片 Query 修改回调（可选）
     */
    public static function countAcross(string $group, string $table, ?\Closure $modify = null): int
    {
        $per = self::across($group, static function (Query $q) use ($table, $modify): int {
            $q->table($table);
            if ($modify !== null) {
                $modify($q);
            }
            return $q->count();
        });
        return (int) array_sum($per);
    }

    /**
     * 跨分片全局排序（二次归并）：把已合并的跨片行按 $column 稳定排序。
     *
     * 单库索引无法跨片排序，故先在每个分片内 orderBy + limit 收敛，再用本方法做应用层
     * 全局排序。同值元素保持原顺序（稳定排序）。
     *
     * @param array<int,array<string,mixed>> $rows   通常为 DB::mergeAcross() 的返回值
     * @param string                        $column  排序列
     * @param string                        $direction ASC | DESC
     * @return array<int,array<string,mixed>>
     */
    public static function sortAcross(array $rows, string $column, string $direction = 'ASC'): array
    {
        $desc = strtoupper($direction) === 'DESC';
        // 带原始下标，保证稳定性（同值不打乱）
        $indexed = [];
        foreach ($rows as $i => $row) {
            $indexed[] = [$row, $i];
        }
        usort($indexed, static function (array $a, array $b) use ($column, $desc): int {
            $va = $a[0][$column] ?? null;
            $vb = $b[0][$column] ?? null;
            if ($va == $vb) {
                return $a[1] <=> $b[1]; // 稳定：保留合并时的先后
            }
            $cmp = $va <=> $vb;
            return $desc ? -$cmp : $cmp;
        });
        return array_map(static fn (array $x): array => $x[0], $indexed);
    }

    /**
     * 跨分片全局分页（二次归并）：对已完成全局排序的跨片行做分页切片。
     *
     * 返回结构含 items（本页数据）与分页元信息，方便直接交给视图 / API 响应：
     *   ['items'=>...,'total'=>...,'page'=>...,'perPage'=>...,'lastPage'=>...,'hasMore'=>...]
     *
     * @param array<int,array<string,mixed>> $rows   已全局排序（建议经 DB::sortAcross）的跨片行
     * @param int                           $page    页码（从 1 起，非法值回退 1）
     * @param int                           $perPage 每页条数（<1 回退 15）
     * @return array<string,mixed>
     */
    public static function paginateAcross(array $rows, int $page, int $perPage = 15): array
    {
        $total    = count($rows);
        $page     = $page < 1 ? 1 : $page;
        $perPage  = $perPage < 1 ? 15 : $perPage;
        $lastPage = (int) ceil($total / $perPage);
        $offset   = ($page - 1) * $perPage;
        $items    = array_slice($rows, $offset, $perPage);
        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'perPage'  => $perPage,
            'lastPage' => $lastPage,
            'hasMore'  => $page < $lastPage,
        ];
    }

    /**
     * 跨分片“游标分页”（keyset pagination，避免深翻 OFFSET）：基于上一页最大 $column 值
     * 取下一页，比 offset 分页在深翻时更高效，也天然适配分库（每片各自用游标收敛）。
     *
     * 实现：对组内每个分片各取「$column > $after 的前 $perPage 条（按 $column 升序）」，
     * 合并后做二次归并（全局稳定排序 + 全局取前 $perPage），返回本页数据与下一页游标。
     *
     *   $page = DB::cursorAcross('orders', 'orders', 'id', 20);          // 首页（after=null）
     *   $next = DB::cursorAcross('orders', 'orders', 'id', 20, $page['nextCursor']);
     *
     * @param string        $group    分库组名
     * @param string        $table    逻辑表名
     * @param string        $column   游标列（建议用全局唯一且单调的列，如雪花 id；默认 id）
     * @param int           $perPage  每页条数
     * @param mixed         $after    上一页游标值（null 表示首页，从最小起）
     * @param \Closure|null $modify   逐分片 Query 修改回调（可选，如追加 where 过滤）
     * @return array<string,mixed> ['items'=>本页行, 'nextCursor'=>下一页游标|null, 'perPage'=>..., 'count'=>本页条数]
     */
    public static function cursorAcross(
        string $group,
        string $table,
        string $column = 'id',
        int $perPage = 15,
        mixed $after = null,
        ?\Closure $modify = null
    ): array {
        $perPage = max(1, $perPage);
        $per = self::across($group, static function (Query $q) use ($table, $column, $after, $perPage, $modify): array {
            $q->table($table);
            if ($after !== null) {
                $q->where($column, '>', $after);
            }
            if ($modify !== null) {
                $modify($q);
            }
            return $q->orderBy($column, 'ASC')->limit($perPage)->get();
        });
        $merged = self::mergeAcross($per);
        $sorted = self::sortAcross($merged, $column, 'ASC');
        $items  = array_slice($sorted, 0, $perPage);

        $nextCursor = null;
        if (count($items) === $perPage) {
            $last       = $items[$perPage - 1];
            $nextCursor = $last[$column] ?? null;
        }
        return [
            'items'      => $items,
            'nextCursor' => $nextCursor,
            'perPage'    => $perPage,
            'count'      => count($items),
        ];
    }

    // -------- 读写分离：请求级 / 协程级 sticky --------

    /**
     * 复位读写分离的 sticky 状态
     *
     *  - FPM/CLI：每个请求开始时调用（App 引导期已自动调用），复位“写过即读主”，
     *    避免 worker 复用把状态泄漏到下一请求；
     *  - Swoole 常驻：worker 常驻不随请求销毁，应在 onRequest 入口调用本方法按协程复位，
     *    传 $cid 仅清指定协程（不传则清空全部，适合 worker 启动/重启时）。
     *
     * @param int|null $cid 仅 Swoole 模式下有效：指定协程 ID；null 表示清空全部
     */
    public static function resetSticky(?int $cid = null): void
    {
        foreach (self::$rwConnections as $rw) {
            if ($rw instanceof ReadWriteConnection) {
                if ($cid === null) {
                    $rw->resetSticky();
                } else {
                    $rw->resetStickyFor($cid);
                }
            }
        }
    }

    /**
     * 清空连接缓存与解析器（测试隔离 / Swoole 平滑重启用）
     */
    public static function flush(): void
    {
        self::$connections   = [];
        self::$rwConnections = [];
        self::$resolver      = null;
    }
}
