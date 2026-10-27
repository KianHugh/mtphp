<?php
/**
 * mtphp 应用入口（唯一对外访问文件）
 */

// 自动加载：优先 Composer，未安装时回退到内置 PSR-4 加载器，便于免安装直接演示
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        $map = [
            'Mtphp\\' => __DIR__ . '/../mtphp/',
            'App\\'    => __DIR__ . '/../app/',
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

use Mtphp\App;

$app = new App(realpath(__DIR__ . '/..'));
$app->run();
