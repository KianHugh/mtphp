<?php
/**
 * 会话配置
 *
 * driver：file（落地 runtime/sessions，跨请求持久）/ array（内存，仅当前进程，CLI/测试用）
 * path  ：file 驱动的存储目录（默认 <应用根>/runtime/sessions）
 * lifetime：会话 Cookie 有效期（分钟）
 */
return [
    'driver'   => 'file',
    'path'     => null,   // null 时由 StartSession 中间件按 <应用根>/runtime/sessions 兜底
    'lifetime' => 120,
    'cookie'   => 'mtphp_session',
];
