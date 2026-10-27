<?php
/**
 * 认证配置
 *
 * defaults.guard：auth() 不传守卫名时使用的默认守卫（多 Guard 场景在此切换）。
 * token_ttl：API 令牌默认有效期（秒），0 表示不过期（不推荐）。
 *
 * 多 Guard：auth('web') / auth('admin') 取各自独立守卫（会话键、记住我 Cookie 均按名隔离）。
 * 各守卫默认行为一致；如需差异化（不同用户表 / 不同 session 驱动），可在业务侧
 * 通过 auth($name)->setUserProvider(...) 等按需配置。
 */
return [
    'defaults' => [
        'guard' => 'web',
    ],
    'token_ttl' => 86400,
];
