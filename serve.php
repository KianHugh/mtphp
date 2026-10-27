<?php
/**
 * 演示用前端控制器（仅用于 php -S 内置服务器 / 快速验证）
 *
 * 生产环境请由 Nginx/Apache 将请求重写到 public/index.php，
 * 或由 Swoole 常驻服务器（见 bin/server.php）接管。
 */
$uri    = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$public = __DIR__ . '/public';
$file   = $public . $uri;

// 命中 public 下的真实静态文件时，交还给内置服务器直接输出
if ($uri !== '/' && is_file($file) && is_readable($file) && !str_ends_with($file, '.php')) {
    return false;
}

require $public . '/index.php';
