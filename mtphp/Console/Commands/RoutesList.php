<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;

/**
 * routes:list —— 列出已注册路由
 */
class RoutesList extends Command
{
    public function configure(): void
    {
        $this->name        = 'routes:list';
        $this->description = '列出应用已注册的路由';
    }

    public function handle(): int
    {
        $app = \Mtphp\App::instance();
        if ($app === null) {
            $this->error('未检测到已引导的应用实例（请确认在框架项目根目录下运行）。');
            return 1;
        }

        $routes = $app->router()->getRoutes();
        $this->line('方法    URI                        中间件');
        $this->line(str_repeat('-', 60));

        foreach ($routes as $method => $list) {
            foreach ($list as $r) {
                $mws = implode(',', $r['middleware']) ?: '-';
                $this->line(
                    sprintf(
                        '%-7s %-26s %s',
                        $method,
                        $r['uri'],
                        $mws
                    )
                );
            }
        }
        return 0;
    }
}
