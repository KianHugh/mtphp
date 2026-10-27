<?php
/**
 * 队列配置（可选；不配置也能用，框架有内置默认值）
 *
 * driver: file（默认，零依赖，任务落地 runtime/queue）| database（可靠投递，需数据库）
 * 通过 queue() 助手读取；未配置时使用框架默认。
 *
 * database 驱动表结构由 Mtphp\Queue\DatabaseQueue 自动建（CREATE TABLE IF NOT EXISTS），
 * 跨 mysql / sqlite 方言，无需手动迁移。
 */
return [
    // 队列驱动：file | database
    'driver'       => 'file',

    // 文件驱动：任务存放目录（运行时会自动创建）
    'path'         => 'runtime/queue',

    // 数据库驱动：使用的数据库连接名（config/database.php 中的 connections 键）
    'connection'   => 'mysql',

    // 数据库驱动：任务表 / 失败表名
    'table'        => 'jobs',
    'failed_table' => 'failed_jobs',

    // 数据库驱动：最大尝试次数（达上限移入失败表）；重试延迟秒数
    'max_attempts' => 3,
    'retry_delay'  => 5,

    // 数据库驱动：任务被认领后超过该秒数仍处 reserved（进程崩溃/卡死）即超时回收并重试。
    // 必须大于单任务最大正常处理耗时，否则正在处理的任务会被误回收导致重复消费。
    'retry_after'  => 90,
];
