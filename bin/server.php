<?php
/**
 * Swoole 常驻服务器入口
 *
 * 用法：
 *   composer dump-autoload   # 推荐；未安装 composer 时回退内置加载器
 *   php bin/server.php
 *   APP_HOST=127.0.0.1 APP_PORT=9502 php bin/server.php
 *
 * 注意：需 Swoole 扩展，且仅在 Linux / WSL 上可运行（Windows 不支持 Swoole）。
 */

$root = dirname(__DIR__);

if (file_exists($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        $map = [
            'Mtphp\\' => $root . '/mtphp/',
            'App\\'    => $root . '/app/',
        ];
        foreach ($map as $prefix => $base) {
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });
}

use Mtphp\Server;

$host = getenv('APP_HOST') ?: '0.0.0.0';
$port = (int) (getenv('APP_PORT') ?: 9501);

$server = new Server($root, $host, $port);
$server->start();
