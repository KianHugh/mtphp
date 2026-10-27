<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;

/**
 * serve —— 启动 PHP 内建开发服务器
 *
 * 仅用于本地开发，不要用于生产（生产请用 Nginx/FPM 或 Swoole 常驻）。
 */
class Serve extends Command
{
    public function configure(): void
    {
        $this->name        = 'serve';
        $this->description = '启动 PHP 内建开发服务器（本地调试用）';
    }

    public function handle(): int
    {
        $host    = (string) $this->option('host', '127.0.0.1');
        $port    = (string) $this->option('port', '8000');
        $docroot = $this->getRoot() . '/public';
        if (!is_dir($docroot)) {
            $docroot = $this->getRoot();
        }

        $php = PHP_BINARY ?: 'php';
        $this->info("mtphp 开发服务器已启动：http://{$host}:{$port}  （Ctrl+C 退出）");
        $this->info("文档根目录：{$docroot}");

        $cmd = escapeshellarg($php) . ' -S ' . $host . ':' . $port . ' -t ' . escapeshellarg($docroot);
        $code = 0;
        passthru($cmd, $code);
        return $code;
    }
}
