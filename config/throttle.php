<?php
/**
 * 限流配置（Throttle 中间件）
 *
 * max   ：单个窗口内允许的最大请求数
 * decay：窗口长度（秒），到期自动重置计数
 */
return [
    'max'   => 60,
    'decay' => 60,
];
