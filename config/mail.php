<?php
/**
 * 邮件配置（Mail 组件）
 *
 * driver      ：发送渠道，smtp = 真实 SMTP；array = 内存收集（测试 / 本地开发默认）
 * host/port   ：SMTP 服务器地址与端口（tls 常用 587，ssl 常用 465）
 * encryption  ：'' 明文 | 'tls' STARTTLS | 'ssl' 隐式 TLS
 * username/password：SMTP 鉴权账号密码（留空表示匿名）
 * from        ：默认发件人（缺省回退到 no-reply@localhost）
 * timeout     ：socket 连接超时（秒）
 */
return [
    'driver'     => 'array',
    'host'       => '127.0.0.1',
    'port'       => 25,
    'encryption' => '',
    'username'   => '',
    'password'   => '',
    'from'       => 'no-reply@localhost',
    'timeout'    => 10,
];
