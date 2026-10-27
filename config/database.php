<?php
/**
 * 数据库配置
 *
 * 支持多连接、读写分离与 Swoole 协程连接池。
 *
 * 读写分离（写主读从，按语句类型自动路由）：
 *   在下方顶层增加 `read` 数组（写取默认连接、读走 read 里列出的连接名），
 *   DB::table()/DB::select() 等读操作自动落到读库；insert/update/delete/事务自动走写库；
 *   事务内读也强制走写库（保证读己写可见）。例如：
 *
 *     'read' => ['mysql_read'],          // 可填多个读库名，自动轮询负载
 *     // 'write' => 'mysql',             // 写连接名（默认即 default，可省略）
 *
 *   未配置 read 时退化为单连接（与旧行为一致），不影响现有运行。
 *
 * 连接级读写分离（分片也可用）：在每个 connection 内加 `read` 键，该连接即自动读写分离，
 *   适用于“分片各自配主从”的场景（见下方 connections.mysql_read 同级示例）。
 *
 * 分库（水平拆分）：见文件末尾 `sharding` 配置示例。
 */
return [
    'default'     => 'mysql',

    'connections' => [
        'mysql' => [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'dbname'   => 'test',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8mb4',
            'prefix'   => '',
            // FPM 模式：开启持久连接，降低高频请求下的建连开销
            'persistent' => false,
            // Swoole 协程连接池（仅常驻模式生效）
            'pool'     => [
                'size' => 16,   // 最大连接数
                'idle' => 10,   // 空闲连接数（worker 启动时预热）
            ],
        ],

        // 读库示例（读写分离时通过顶层 read 引用此名；默认不启用）
        // 'mysql_read' => [
        //     'driver'   => 'mysql',
        //     'host'     => '127.0.0.1',
        //     'port'     => 3306,
        //     'dbname'   => 'test',
        //     'username' => 'root',
        //     'password' => '',
        //     'charset'  => 'utf8mb4',
        //     'prefix'   => '',
        //     'persistent' => false,
        // ],
    ],

    // 'read' => ['mysql_read'],   // 读写分离开关（启用后读操作路由到读库）

    // ---- 分库（水平拆分）示例 ----
    // 一张逻辑表（如 orders）按分片键分散到多个物理库：每个分片在上方 connections
    // 里是一个独立连接名。使用时按路由取具体连接：
    //
    //   DB::shard('orders', $orderId)->table('orders')->where('id', $orderId)->get();
    //   // 或模型：class Order extends Model { protected static ?string $shardGroup='orders'; }
    //   //         Order::onShard($orderId)->where('id', $orderId)->get();
    //
    // 'shards'    ：分片连接名列表（顺序即分片编号），需在上文 connections 中定义
    // 'algorithm' ：hash（默认，数字键按值取模、字符串键 crc32 取模）| range（按边界分片）
    // 'boundaries'：仅 range 模式需要，单调递增边界，键 < 边界[i] 落分片 i，超出落最后一片
    //
    // 'sharding' => [
    //     'orders' => [
    //         'shards'     => ['mysql_orders0', 'mysql_orders1', 'mysql_orders2'],
    //         'algorithm'  => 'hash',
    //     ],
    //     'logs' => [
    //         'shards'     => ['mysql_logs0', 'mysql_logs1'],
    //         'algorithm'  => 'range',
    //         'boundaries' => [1000000, 2000000],
    //     ],
    // ],
    //
    // 跨分片聚合：分库后“统计/汇总/取全部”用 DB::across($group, $cb) 逐分片查询，
    // 配合 DB::mergeAcross()（合并多片行）/ DB::sumAcross()（按列求和）聚合：
    //   $per = DB::across('orders', fn($q) => $q->table('orders')->where('status',1)->get());
    //   $rows = DB::mergeAcross($per); $sum = DB::sumAcross($per, 'amount');
    //
    // 跨分片“全局”归并（单库索引无法跨片排序/分页，需应用层二次归并）：
    //   $total = DB::countAcross('orders', 'orders');                       // 逐分片 count 求和
    //   $per   = DB::across('orders', fn($q)=>$q->table('orders')
    //                    ->orderBy('amount','desc')->limit(100)->get());    // 每片先收敛
    //   $sorted = DB::sortAcross(DB::mergeAcross($per), 'amount', 'DESC');   // 再全局稳定排序
    //   $page  = DB::paginateAcross($sorted, 1, 20);                        // 再全局分页
];
