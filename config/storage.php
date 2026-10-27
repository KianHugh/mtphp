<?php
/**
 * 存储配置（可选；不配置也能用，框架回退到内置 local 盘）
 *
 * 通过 storage() 助手读取。业务层只依赖 StorageInterface，切换驱动零改动。
 *
 * 云盘（七牛 / 阿里云 OSS / 腾讯云 COS）不在框架内内置——
 * 先 composer require 对应 SDK，再用 StorageManager::extend('qiniu', fn($cfg)=>new XxxDriver($cfg))
 * 注册一个实现 StorageInterface 的驱动，即可 storage('qiniu')->put(...) 使用。
 */
return [
    // 默认盘
    'default' => 'local',

    // 盘定义
    'disks' => [
        'local' => [
            'driver' => 'local',
            // 根目录（相对路径以应用根目录为基准；也可写绝对路径）
            'root'   => 'runtime/storage',
            // 公开访问的基础 URL（配合 Web 服务器把该目录暴露为静态资源）
            'url'    => '/storage',
        ],

        // 云盘示例（需先 extend 注册驱动）：
        // 'qiniu' => ['driver' => 'qiniu', 'access_key' => '', 'secret_key' => '', 'bucket' => '', 'domain' => ''],
        // 'oss'   => ['driver' => 'oss',   'access_id'  => '', 'access_secret' => '', 'bucket' => '', 'endpoint' => ''],
        // 'cos'   => ['driver' => 'cos',   'secret_id'  => '', 'secret_key' => '', 'bucket' => '', 'region' => '', 'domain' => ''],
    ],
];
