# mtphp

[![CI](https://github.com/KianHugh/mtphp/actions/workflows/ci.yml/badge.svg)](https://github.com/KianHugh/mtphp/actions)
[![Packagist](https://img.shields.io/packagist/v/kianhugh/mtphp.svg)](https://packagist.org/packages/kianhugh/mtphp)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](./LICENSE)

> 版权所有 (c) 武汉麦麦心选网络科技有限公司

高性能、高并发的现代 PHP 框架。路由、数据库连接、事务、视图模板、中间件一应俱全，并内置轻量模型、文件缓存、文件日志，**零配置即可用、配有详尽中文注释**，原生支持 Swoole 常驻内存 + 协程 + 连接池。

> 完整设计见 [DESIGN.md](./DESIGN.md)；**使用手册见 [使用手册.md](./使用手册.md)**。

## 目录速览

```
mtphp/        框架核心源码（命名空间 Mtphp\）
app/          业务应用（命名空间 App\）
config/       配置文件
public/       Web 根目录（唯一入口 index.php）
runtime/      运行时生成（路由缓存 / 模板编译 / 日志）
```

## 环境要求

- **PHP ≥ 8.0**（已全面使用强类型、联合类型等 PHP 8 特性）
- 扩展：`pdo` / `pdo_mysql` / `json` / `mbstring`
- 高并发常驻模式需 `swoole` 扩展（仅 Linux / WSL）

> Windows 开发机请自行安装 PHP ≥ 8.0（建议 8.3，需含 `php.ini` + OPcache/JIT），并确保 `php` 已进入系统 PATH；随后即可用 `php -S` 或 `php bin/server.php` 运行演示。

## 安装

通过 Composer 安装：

```bash
composer require kianhugh/mtphp
```

> 也可直接克隆本仓库源码使用——框架**零依赖**，即使不装 Composer 也能运行演示与测试。

## 快速开始

```bash
cd <项目根目录>   # 即本仓库根目录（mtphp/）
composer dump-autoload          # 生成自动加载（未装 composer 也可直接运行演示）

php -S 127.0.0.1:8080 serve.php # 启动内置服务器（开发演示；serve.php 为前端控制器）

# 访问
#   http://127.0.0.1:8080/
#   http://127.0.0.1:8080/hello/世界
#   http://127.0.0.1:8080/api/ping
```

### Swoole 常驻服务器（高并发，需 Linux / WSL）

```bash
php bin/server.php              # 默认 0.0.0.0:9501
```

## 当前进度

- ✅ Phase 1~3：容器、路由（含编译缓存）、请求/响应、中间件、配置、PDO 数据库（查询构造器 + 事务）、模板引擎（编译缓存）
- ✅ Phase 4：**PHP 8 化 + 高并发核心**——强类型 / 联合类型安全 / FPM 持久连接 / Swoole 常驻服务器 + 协程连接池
- ✅ Phase 5：**轻量模型 / 文件缓存 / 文件日志 / 布局模板**——`Mtphp\Database\Model`（ORM-lite 静态 CRUD）、`Mtphp\Cache\Cache`、`Mtphp\Log\Logger`、`Mtphp\View` 布局（`@extends`/`@section`/`@yield`/`@parent`），均零配置可用、中文注释详尽
- ✅ Phase 6：**单测体系 + 压测基准**——框架自带零依赖测试基座（`Mtphp\Testing\Assert` / `TestCase` + `tests/run.php` 运行器，无需装 PHPUnit）与零依赖 HTTP 压测工具（`bin/bench.php`，curl_multi 并发），均中文注释详尽
- ✅ Phase 7：**文档站点**——用框架自身搭建的文档站（左菜单 + 右内容）：`mtphp/Markdown/Markdown.php` 零依赖 Markdown 渲染器、`app/Controllers/DocController.php` 按《使用手册.md》二级标题切分章节、`app/Views/docs/*`（布局 / 序言 / 章节页）；访问 `/docs` 进入，手册与网站自动同源、无需额外同步

## 设计原则

- **使用简单、配置简单**：核心组件零配置即可用，开箱即跑。
- **中文注释**：公共 API 与关键路径均配有清晰中文注释，便于二次开发与维护。

## 文档站点

框架自带一个用**自身**搭建的文档站，演示“调用简单、功能主流”：左侧目录菜单，右侧正文，当前首页内容直接作为文档序言。

```bash
# 启动后访问
#   http://127.0.0.1:8080/docs        # 序言（即首页欢迎内容）
#   http://127.0.0.1:8080/docs/1      # 章节（slug 为章节编号 1~22）
```

文档源就是根目录的 `使用手册.md`，按二级标题（`##`）自动切分为章节并生成左菜单，手册与网站同源、永不失同步。

