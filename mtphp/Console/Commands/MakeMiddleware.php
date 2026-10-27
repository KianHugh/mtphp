<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;

/**
 * make:middleware —— 生成中间件骨架（写入 app/Middleware）
 *
 *   php bin/mtphp make:middleware CheckAge
 *   php bin/mtphp make:middleware Api/AuthToken
 *
 * 生成类实现“类::handle(Request, callable): Response”约定（与框架中间件管道一致），
 * 注册方式：config/app.php 的 app.middleware 数组追加该类名，或路由级 middleware。
 */
class MakeMiddleware extends Command
{
    public function configure(): void
    {
        $this->name        = 'make:middleware';
        $this->description = '生成中间件骨架（写入 app/Middleware）';
    }

    public function handle(): int
    {
        $name = (string) $this->argument(0);
        if ($name === '') {
            $this->error('用法：php bin/mtphp make:middleware <MiddlewareName>');
            return 1;
        }

        $rel   = ltrim(str_replace('\\', '/', $name), '/');
        $parts = explode('/', $rel);
        $base  = array_pop($parts);
        if (!str_ends_with($base, 'Middleware')) {
            $base .= 'Middleware';
        }
        $sub   = $parts !== [] ? implode('/', $parts) . '/' : '';
        $ns    = 'App\\Middleware' . ($parts !== [] ? '\\' . str_replace('/', '\\', implode('\\', $parts)) : '');

        $dir  = $this->getRoot() . '/app/Middleware/' . $sub;
        $file = $dir . $base . '.php';
        if (is_file($file)) {
            $this->warn("已存在，跳过：{$file}");
            return 0;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace {$ns};

use Mtphp\Http\Request;
use Mtphp\Http\Response;

/**
 * {$base}（由 make:middleware 生成）
 *
 * 洋葱模型：先 \$next(\$request) 向内传递拿到响应，可对响应做后置加工；
 * 直接 return 一个 Response 即可短路（不再向内传递）。
 */
class {$base}
{
    public function handle(Request \$request, callable \$next): Response
    {
        // 前置逻辑（在 \$next 之前）……
        \$response = \$next(\$request);
        // 后置逻辑（对 \$response 加工）……
        return \$response;
    }
}
PHP;
        file_put_contents($file, $stub);
        $this->info("已生成：{$file}");
        return 0;
    }
}
