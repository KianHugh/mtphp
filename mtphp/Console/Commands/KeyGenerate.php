<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;

/**
 * key:generate —— 生成应用密钥（APP_KEY）并写入 .env
 */
class KeyGenerate extends Command
{
    public function configure(): void
    {
        $this->name        = 'key:generate';
        $this->description = '生成应用密钥并写入 .env（APP_KEY）';
    }

    public function handle(): int
    {
        $key = base64_encode(random_bytes(32));

        $envFile = $this->getRoot() . '/.env';

        if (is_file($envFile)) {
            $content = file_get_contents($envFile);
            if (preg_match('/^APP_KEY=/m', (string) $content)) {
                $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content);
            } else {
                $content .= "\nAPP_KEY=" . $key . "\n";
            }
            file_put_contents($envFile, $content);
        } else {
            file_put_contents($envFile, "APP_KEY=" . $key . "\n");
        }

        $this->info('应用密钥已生成并写入 ' . $envFile);
        $this->line('APP_KEY=' . $key);
        return 0;
    }
}
