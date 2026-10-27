<?php
/**
 * 缓存配置（可选；不配置也能用，框架有内置默认值）
 *
 * 通过 cache() 助手读取；未配置时使用框架默认：
 *   path => <应用根>/runtime/cache
 *   ttl  => 3600（1 小时）
 */
return [
    // 缓存驱动：file（默认，零依赖，文件落地）| redis（纯 PHP RESP 实现，零依赖，无需 ext-redis）
    'driver' => 'file',

    // 文件驱动：缓存目录（运行时会自动创建）
    'path' => 'runtime/cache',

    // 默认过期秒数（0 表示永不过期）
    'ttl'  => 3600,

    // Redis 驱动（driver=redis 时生效；零依赖，纯 PHP RESP 实现，无需 ext-redis / predis）
    'host'     => '127.0.0.1',
    'port'     => 6379,
    'db'       => 0,
    'password' => null,
    'timeout'  => 2.0,
    'prefix'   => '',
];
