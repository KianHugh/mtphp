<?php
/**
 * 应用配置
 */
return [
    'name'       => 'mtphp',
    'timezone'   => 'Asia/Shanghai',
    'debug'      => false,  // 生产建议 false（不泄露错误栈）；排障时临时改 true
    'mode'       => 'fpm',   // fpm | swoole（常驻内存 + 协程，见 bin/server.php，需 Linux/WSL + swoole 扩展）
    'charset'    => 'utf-8',

    // 健康检查 / 探针端点：GET /healthz（存活）、GET /ready（就绪）。
    // 默认开启；网关/负载均衡/k8s 探活用。设为 false 关闭。
    'health_probe' => true,

    // 应用密钥（AES 加密用）：留空则 Session/Cookie 不加密（明文）。
    // 推荐执行 `php bin/mtphp key:generate` 生成并写入 .env（APP_KEY），引导期会自动读取。
    'key'        => '',

    // 全局中间件（类名，按数组顺序执行，洋葱模型）
    'middleware' => [
        // App\Middleware\Cors::class,
        // App\Middleware\Auth::class,
    ],

    // ── 框架内置安全中间件（默认全部开启，上线可按需关闭/放行）──
    'security'   => [
        // 1) 安全响应头：统一补 X-Content-Type-Options / X-Frame-Options /
        //    Referrer-Policy / Content-Security-Policy / Permissions-Policy 等。
        //    设为 false 可整体关闭；也可在 security.headers_list 里覆盖/新增/置 null 关闭单项。
        'headers'        => true,

        // 2) CSRF 防护（无状态双提交 Cookie 方案，无需 Session）。
        //    设为 false 关闭；csrf_except 里的路径前缀（如 /api）跳过校验。
        'csrf'           => true,
        'csrf_except'    => [
            // '/api',
        ],

        // 3) CORS 跨域（默认关闭；设为 true 并配置 origins 后生效，预检 OPTIONS 自动放行）。
        'cors'           => false,
        'cors_origins'   => ['*'],
        'cors_methods'   => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'cors_headers'   => ['Content-Type', 'X-CSRF-TOKEN', 'X-Requested-With', 'Authorization'],
        'cors_max_age'   => 86400,

        // 覆盖/新增安全响应头（值 null = 关闭该项）。示例：
        // 'headers_list' => [
        //     'Content-Security-Policy' => "default-src 'self'; script-src 'self'",
        //     'X-Frame-Options'         => null,
        // ],
        'headers_list'   => [],
    ],
];
