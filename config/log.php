<?php
/**
 * 日志配置（多通道，支持 single / daily 驱动，PSR-3 八级）
 *
 * default  ：默认使用哪个通道（logger() 不指定通道时取它）
 * channels ：各通道定义
 *   driver ：single = 单文件；daily = 按日切分（<path>-YYYY-MM-DD.log）
 *   path   ：single 为完整文件路径；daily 为基础路径（自动追加日期）
 *   level  ：最低记录级别（debug|info|notice|warning|error|critical|alert|emergency）
 *
 * 兼容旧扁平配置：若只写了 path / level，会被当作名为 single 的通道。
 */
return [
    'default'  => 'single',

    'channels' => [
        'single' => [
            'driver' => 'single',
            'path'   => 'runtime/logs/app.log',
            'level'  => 'info',
        ],
        'daily'  => [
            'driver' => 'daily',
            'path'   => 'runtime/logs/app',
            'level'  => 'debug',
        ],
    ],
];
