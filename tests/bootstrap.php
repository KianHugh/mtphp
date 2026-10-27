<?php
declare(strict_types=1);

/**
 * 测试引导文件（被 tests/run.php 与各 *Test.php 共同 require）
 *
 * 职责：
 *   1. 注册 PSR-4 自动加载（Mtphp\ → mtphp/，App\ → app/），免 composer 也能跑；
 *   2. 载入测试夹具 tests/fixtures.php（内存 SQLite 连接器 + resolver 助手）。
 *
 * 这样测试既可用 `php8 tests/run.php` 批量跑，也能单独 `php8 tests/DbTest.php` 调试。
 */

$root = dirname(__DIR__);

// 自动加载：优先 Composer，未安装时回退到内置 PSR-4 加载器
if (file_exists($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class) use ($root): void {
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

// 测试夹具（Tests\SqliteConnector + sqliteResolver），供 DB / Model / Query 走内存库
require_once $root . '/tests/fixtures.php';

// 全局助手函数（validate / event / http / __ / session / auth 等，定义后由 function_exists 守卫，可安全重复加载）
require_once $root . '/mtphp/helpers.php';
