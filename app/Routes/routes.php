<?php
/**
 * 路由定义（由 App 引导时加载）
 */

use Mtphp\Route;
use App\Controllers\HomeController;
use App\Controllers\DocController;

// 首页（视图渲染）
Route::get('/', [HomeController::class, 'index']);

// 文档站：左侧菜单 + 右侧内容；首页内容直接作为文档序言
Route::get('/docs', [DocController::class, 'index']);
Route::get('/docs/{slug}', [DocController::class, 'show']);

// 路由参数示例：/hello/世界
Route::get('/hello/{name}', [HomeController::class, 'hello']);

// 数据库查询示例（无可用数据库时返回错误信息，不致命）
Route::get('/db', [HomeController::class, 'demoDb']);

// 示例闭环：迁移建表 + seed 填充后，访问此路由读取演示数据（无库时优雅降级）
Route::get('/demo/users', [HomeController::class, 'demoUsers']);

// 分组示例（前缀 + 中间件占位）
Route::group(['prefix' => '/api'], function () {
    Route::get('/ping', [HomeController::class, 'ping']);
});

// 缓存组件演示（无需数据库）：/demo/cache 访问多次可见计数自增
Route::get('/demo/cache', [HomeController::class, 'demoCache']);

// 日志组件演示（无需数据库）：/demo/log 触发写日志到 runtime/logs/app.log
Route::get('/demo/log', [HomeController::class, 'demoLog']);

// 布局模板演示（继承 layouts.app，并演示 @parent）：/demo/layout
Route::get('/demo/layout', [HomeController::class, 'demoLayout']);

// 模板指令全集演示（Blade 风格，思想借鉴、独立实现的主流写法）：/demo/ui
Route::get('/demo/ui', [HomeController::class, 'demoUi']);
