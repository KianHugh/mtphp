<?php
declare(strict_types=1);

namespace App\Controllers;

use Mtphp\Http\Request;

/**
 * 示例控制器
 */
class HomeController
{
    /**
     * 首页：渲染模板
     */
    public function index()
    {
        return view('home', [
            'title'     => 'mtphp 首页',
            'framework' => 'mtphp',
            'features'  => [
                'RESTful 路由（含参数与正则约束、分组、编译缓存）',
                'PDO 数据库 + 链式查询构造器',
                '事务（含嵌套 SAVEPOINT）+ 持久连接',
                '模板引擎（编译缓存 + 自动转义防 XSS）',
                '中间件（洋葱模型）',
                'IoC 容器与依赖注入（PHP 8 联合类型安全）',
                'Swoole 常驻内存 + 协程连接池（已实现，需 Linux/WSL 运行）',
            ],
        ]);
    }

    /**
     * 路由参数示例：/hello/世界
     */
    public function hello(Request $request, string $name)
    {
        return response()->json([
            'hello'  => $name,
            'method' => $request->method(),
        ]);
    }

    /**
     * 数据库示例（演示查询构造器；无可用库时优雅返回错误）
     */
    public function demoDb()
    {
        try {
            $rows = \Mtphp\Database\DB::table('mendian')
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get();
            return response()->json([
                'ok'     => true,
                'count'  => count($rows),
                'sample' => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API 分组示例
     */
    public function ping()
    {
        return response()->json(['pong' => true, 'time' => date('Y-m-d H:i:s')]);
    }

    /**
     * 用户表示例（演示 migrate → seed → 读取闭环；无可用库时优雅降级）
     */
    public function demoUsers()
    {
        try {
            $rows = \Mtphp\Database\DB::table('users')
                ->orderBy('id', 'asc')
                ->limit(20)
                ->get();
            return response()->json([
                'ok'     => true,
                'count'  => count($rows),
                'sample' => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'hint'  => '请先执行 `php bin/mtphp migrate` 与 `php bin/mtphp seed`',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 缓存组件演示（无需数据库）：访问多次可见 visits 自增；hot_list 命中缓存
     */
    public function demoCache()
    {
        $c = cache();
        // 计数器：每次访问 +1，缓存 60 秒
        $visits = (int) $c->get('visits', 0) + 1;
        $c->set('visits', $visits, 60);

        // remember：首次计算并写入，之后直接读缓存（模拟“昂贵”的计算/查询）
        $hot = $c->remember('hot_list', 30, function () {
            return ['iPhone 17', '小米 15', '华为 Mate 80'];
        });

        return response()->json([
            'visits'    => $visits,
            'hot_list'  => $hot,
            'has_visits' => $c->has('visits'),
        ]);
    }

    /**
     * 日志组件演示（无需数据库）：把日志写到 runtime/logs/app.log
     */
    public function demoLog()
    {
        logger()->info('演示日志写入', ['ip' => '127.0.0.1', 'ua' => 'mtphp-demo']);
        logger()->warning('这是一条 warning 级日志');

        return response()->json([
            'ok'   => true,
            'file' => app()->root() . '/runtime/logs/app.log',
            'tip'  => '日志已写入上述文件，可打开查看',
        ]);
    }

    /**
     * 布局模板演示：渲染 page.tpl（继承 layouts.app，并演示 @parent）
     */
    public function demoLayout()
    {
        return view('page', [
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 模板指令全集演示：渲染 ui.tpl，集中展示 $loop / @forelse / @switch /
     * @component / @include / @json / @stack / @verbatim / @php 等主流指令。
     * 访问 /demo/ui 查看效果。
     */
    public function demoUi()
    {
        return view('ui', [
            'products' => [
                ['name' => 'iPhone 17', 'price' => 5999],
                ['name' => '小米 15',   'price' => 4499],
                ['name' => '华为 Mate 80', 'price' => 6499],
            ],
            'emptyList' => [],
            'status'    => 'ok',
        ]);
    }
}
