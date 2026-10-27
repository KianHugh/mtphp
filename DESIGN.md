# mtphp 框架设计方案

> 一份面向高性能、高并发的现代 PHP 框架的总体设计（本仓库根目录即 `mtphp/`）。本文档与目录骨架同步维护。
>
> **版权所有 (c) 武汉麦麦心选网络科技有限公司**。设计原则：使用简单、配置简单，核心组件零配置即可用，并配有详尽中文注释。

---

## 一、定位与设计目标

`mtphp` 是一款面向现代 PHP 应用的轻量、高性能、高并发 Web 框架。它在易用性上对齐主流 PHP 框架（RESTful 路由、查询构造器、模板引擎、中间件等设计，与 ThinkPHP / Laravel 等成熟框架思路一致），同时在架构层面对性能与并发做专门设计。

| 目标 | 说明 |
| --- | --- |
| **高性能** | 路由编译缓存、模板预编译、容器反射缓存、OPcache/JIT 友好、懒加载、零冗余反射 |
| **高并发** | 原生支持 Swoole / Workerman 常驻内存 + 协程 + 连接池运行模式 |
| **清晰分层** | 核心 `mtphp/`、应用 `app/`、配置 `config/`、运行时 `runtime/` 严格分离 |
| **易用** | 链式 API、注解/配置双路由、门面静态调用、异常友好 |

---

## 二、整体架构

### 2.1 两种运行模式

框架同时支持传统与常驻两种模式，业务代码无需改动：

1. **FPM 模式（兼容共享主机）**
   - 每次 HTTP 请求独立引导 → 处理 → 销毁。
   - 无额外扩展依赖，部署简单。
2. **常驻内存模式（高并发核心，基于 Swoole）**
   - 进程启动期只引导一次（容器、配置、路由编译、连接池初始化）。
   - `Worker` 常驻，每个请求分配独立的**请求上下文（Context）**，避免跨请求状态污染。
   - 数据库 / Redis 走**连接池**，IO 走**协程**，单机能承载数万并发连接。

### 2.2 请求生命周期（FPM）

```
请求 → public/index.php
  → App::bootstrap()    加载配置 / 注册路由 / 绑定容器
  → Request::capture()  封装请求
  → Router::dispatch()  匹配路由（命中编译缓存）
  → Middleware 管道      全局 + 路由级中间件（洋葱模型）
  → Controller 执行      依赖由容器注入
  → Response::send()     输出（含视图编译结果）
```

### 2.3 分层依赖

```
App(引导) → Container(IOC) → Router / Config / DB / View / Log / Cache
                                ↑
                          Facade(静态代理)
                                ↑
                        Controller / Model(业务)
```

---

## 三、目录结构

```
mtphp/                          # 框架根目录
├── DESIGN.md                   # 本文档：总体设计方案
├── README.md                   # 快速开始
├── composer.json               # PSR-4 自动加载（Mtphp\ → mtphp/，App\ → app/）
├── .gitignore
│
├── mtphp/                      # 框架核心源码（命名空间 Mtphp\）
│   ├── App.php                 # 应用引导 / 生命周期
│   ├── Container.php           # IoC 容器 + 依赖注入（反射缓存）
│   ├── Route.php               # 路由静态代理（门面式注册）
│   ├── Router.php              # 路由收集 / 匹配 / 编译缓存
│   ├── Middleware.php          # 中间件管道（洋葱模型）
│   ├── Config.php              # 配置加载 / 多级合并 / 环境
│   ├── Facade.php              # 门面基类
│   ├── Http/
│   │   ├── Request.php         # 请求封装（method/uri/query/post/header/param）
│   │   ├── Response.php        # 响应封装（status/header/body/cookie/send）
│   │   └── Middleware/         # 框架内置安全中间件（默认自动注册）
│   │       ├── SecurityHeaders.php  # 安全响应头（X-Content-Type-Options/CSP/HSTS…）
│   │       └── Csrf.php            # CSRF 防护（无状态双提交 Cookie）
│   ├── Database/
│   │   ├── Connection.php      # PDO 连接（懒加载、读写分离）
│   │   ├── Query.php           # 链式查询构造器
│   │   ├── Transaction.php     # 事务（含嵌套 savepoint）
│   │   ├── Model.php           # 轻量模型（ORM-lite，静态 CRUD，零配置）
│   │   └── Pool.php            # Swoole 协程连接池（运行时可选）
│   ├── View/
│   │   ├── Template.php        # 模板引擎入口
│   │   └── Compiler.php        # 模板编译（{{ }}、@if、@foreach → PHP）
│   ├── Markdown/
│   │   └── Markdown.php        # 轻量 Markdown → HTML 渲染器（零依赖，文档站用）
│   ├── Cache/
│   │   └── Cache.php           # 极简文件缓存（TTL + remember，零配置）
│   ├── Log/
│   │   └── Logger.php          # 极简文件日志器（级别过滤，零配置）
│   └── Exception/
│       └── HttpException.php   # 统一异常
│
├── app/                        # 业务应用（命名空间 App\）
│   ├── Controllers/            # 控制器
│   │   └── DocController.php   # 文档站控制器（解析使用手册为章节 + 左菜单）
│   ├── Models/                 # 数据模型 / ORM
│   ├── Middleware/             # 业务中间件
│   ├── Views/                  # 业务视图模板（layouts/app.tpl、home.tpl、page.tpl、docs/ 文档站）
│   └── Routes/
│       └── routes.php          # 路由定义入口
│
├── config/                     # 配置文件（PHP 数组返回）
│   ├── app.php                 # 应用（名称、时区、模式、中间件）
│   ├── database.php            # 数据库连接 / 读写分离 / 池
│   ├── route.php               # 路由全局配置（缓存开关等）
│   ├── log.php                 # 日志（可选，不配置有默认）
│   └── cache.php               # 缓存（可选，不配置有默认）
│
├── public/                     # Web 根目录（唯一对外入口）
│   └── index.php               # 引导脚本
│
├── runtime/                    # 运行时生成（可写，建议 gitignore）
│   ├── cache/                  # 路由编译缓存
│   ├── views/                  # 模板编译产物
│   └── logs/                   # 日志
│
├── tests/                      # 单元测试（tests/*Test.php + run.php 运行器，零依赖）
│   ├── bootstrap.php            # 测试引导（自动加载 + 夹具）
│   ├── fixtures.php             # 内存 SQLite 连接器 + resolver 夹具
│   ├── run.php                  # 测试运行器（发现并执行 *Test.php）
│   ├── DbTest.php               # 数据库层测试
│   ├── ComponentsTest.php       # 组件层测试（Model/Cache/Logger）
│   └── LayoutTest.php           # 布局模板测试
│
└── bin/
    ├── server.php               # Swoole 常驻服务器入口
    └── bench.php                # 零依赖 HTTP 压测工具（curl_multi）
```

---

## 四、核心模块设计

### 4.1 容器与依赖注入（`Container`）
- 绑定类型：单例（`singleton`）、原型（`bind`）、实例（`instance`）。
- 解析时通过反射提取构造器参数类型，递归自动注入（**反射结果按类缓存**，避免每次请求重复反射）。
- 支持 `\Closure` 工厂与上下文绑定。
- 容器是全局服务的唯一来源（config / router / db / view 均存于容器）。

### 4.2 路由（`Router` + `Route`）
- 注册方式：`Route::get/post/put/delete/group(...)`，支持前缀、中间件、命名空间分组。
- 参数：`/user/{id}`、`/post/{slug}`，参数支持正则约束 `/user/{id:\d+}`。
- 动作支持：`Controller@method`、 `[Controller::class,'method']`、闭包。
- **路由编译缓存**：首次匹配后将「路由 → 正则 + 参数名」编译为 PHP 数组文件落地 `runtime/cache/routes.php`，后续请求直接 `include` 做 O(1) 查表，避免每次正则遍历。
- 生产环境可关闭缓存自动重建，开发环境每次重建。

### 4.3 请求 / 响应（`Http\Request` / `Http\Response`）
- `Request`：从 `$_SERVER/$_GET/$_POST/php://input` 统一封装，提供 `method()`、`path()`、`query()`、`post()`、`header()`、`param()`、`isAjax()`。
- `Response`：状态行、headers、`body`（支持字符串 / 数组 JSON / 视图），`send()` 一次性输出，OPcache 友好。

### 4.4 中间件（`Middleware`）
- 洋葱模型管道：全局中间件先于路由中间件执行。
- 每个中间件签名 `function($request, $next)`，可前置 / 后置处理（如鉴权、CORS、日志、限流）。
- 返回 `Response` 即短路。
- **框架内置安全中间件（默认自动开启）**：`App::bootstrap()` 在应用全局中间件之前自动注册 `SecurityHeaders`（统一安全响应头）与 `Csrf`（无状态双提交 Cookie 防跨站伪造），处于洋葱最外层。两者默认开启，可在 `config/app.php` 的 `security` 段关闭（`security.headers` / `security.csrf`）或放行路径（`security.csrf_except`）。详见《使用手册》第 14.1 节。

### 4.5 数据库（`Database`）
- **连接（`Connection`）**：基于 PDO，懒加载（首次查询才建连），原生支持读写分离（写主读从，按语句类型路由）。
- **查询构造器（`Query`）**：链式 `table()->where()->orderBy()->limit()->select()/find()/insert()/update()/delete()`，参数化防注入。
- **事务（`Transaction`）**：`begin/commit/rollback`；`transaction(\Closure)` 自动提交/回滚；支持嵌套 `savepoint`。
- **连接池（`Pool`）**：Swoole 模式下使用 `Swoole\Coroutine\MySQL` 维护固定大小连接池，协程获取/归还，避免每请求建连开销，是高并发关键。
- 抽象连接器接口，便于扩展 MySQL / PostgreSQL / SQL Server（如现有 yh_yyyh 库）。

### 4.6 视图模板（`View`）
- 自研轻量模板引擎，**编译期**把模板转成纯 PHP 文件缓存到 `runtime/views`，运行期直接 `include`（等价手写 PHP，无运行时解析开销）。
- 指令：`{{ $var }}`（自动 `htmlspecialchars` 转义防 XSS）、`{!! $var !!}`（不转义）、`@if/@else/@endif`、`@foreach/@endforeach`、`@include`。
- 布局：`@extends('layout')` 继承布局、`@section('name')...@endsection` 定义区块、`@yield('name'[, '默认'])` 输出区块、`@parent` 在布局默认内容上追加。采用「两遍渲染」：先收集并编译子页区块（编译为 PHP，而非预渲染固化数据），再由 `Compiler::compileLayout` 把 `@yield` 内联成区块/默认已编译 PHP，最终随布局一起 `include` 执行（按当前请求数据求值，缓存与数据无关）。详见 `View\Template::renderRaw` 与 `View\Compiler::compileLayout`。

### 4.7 配置 / 日志 / 缓存 / 模型 / 门面
- `Config`：加载 `config/*.php`，`Config::get('app.name')`，点号多级读取；环境区分（`.env`）。
- `Log`：`Mtphp\Log\Logger` 极简文件日志器，按级别过滤、自动建目录、上下文序列化（数组 / 异常），零配置可用（默认 `runtime/logs/app.log`）。
- `Cache`：`Mtphp\Cache\Cache` 极简文件缓存，TTL 过期 + `remember()` 记忆化，零配置可用（默认 `runtime/cache`）；缓存文件带 `<?php` 安全头。
- `Model`：`Mtphp\Database\Model` 轻量模型（ORM-lite），静态 `find/where/create/update/delete` + `query()` 桥接 `Query`，表名可按类名推导，零配置可用。
- `Facade`：静态代理基类，`Facade::getFacadeAccessor()` 指向容器服务，业务侧可 `DB::table(...)`、`View::render(...)` 风格调用；并配有全局助手 `response/view/config/app/logger/cache`。

---

## 五、高性能策略

1. **路由编译缓存**：正则匹配结果编译为数组文件，避免每次请求遍历 + 正则。
2. **容器反射缓存**：类构造器参数反射结果按类缓存，DI 零重复开销。
3. **模板预编译**：模板在首次渲染编译为 PHP 并缓存，运行期零解析。
4. **懒加载**：数据库连接、重量级服务按需建立。
5. **OPcache + JIT**：所有 PHP 文件纯过程/类，利于 OPcache 缓存字节码；PHP 8+ 开启 JIT 进一步提速。
6. **零魔法开销**：避免 `__call`/`__get` 在热路径滥用，热路径用直接方法调用。
7. **输出一次成型**：`Response::send()` 单次输出，避免多次 `echo`。

---

## 六、高并发策略（Swoole 常驻模式）

1. **常驻内存**：启动期一次性引导（容器、配置、路由、连接池），Worker 不重复初始化。
2. **请求上下文隔离**：每个请求独立的 `Context`，**禁止**在容器/静态变量中存放请求级状态，防止跨请求串数据。
3. **连接池**：MySQL / Redis 使用协程连接池，连接复用，消除建连瓶颈。
4. **协程化 IO**：数据库、HTTP 客户端、文件读写均走协程，单 Worker 即可并发处理大量阻塞 IO。
5. **平滑重启 / 热重载**：通过 Swoole `reload` 平滑更新代码，不丢连接。
6. **进程模型**：`master` + `manager` + 多 `worker` + 可选 `task_worker`（耗时任务异步化）。

> 说明：FPM 模式与 Swoole 模式共用同一套业务/核心代码；连接池与协程仅在检测到 Swoole 扩展时启用，否则自动降级为单连接。

---

## 七、命名与编码规范

- 命名空间：`Mtphp\`（核心）、`App\`（应用），严格 PSR-4。
- 类 `PascalCase`，方法/属性 `camelCase`，常量 `UPPER_SNAKE`。
- 单一职责：每个类只解决一类问题；模块间通过容器/接口解耦。
- 异常优先：错误以异常抛出，由统一异常处理器转换为友好响应。
- 注释：公共 API 必须 PHPDoc；关键性能点注明原因。

---

## 八、实现路线图（分阶段）

| 阶段 | 内容 | 状态 |
| --- | --- | --- |
| **Phase 1** | 骨架 + 容器 + 路由 + 请求/响应 + 中间件 + 配置 + 入口（FPM 可跑） | ✅ 已落地（骨架） |
| **Phase 2** | 数据库：PDO 连接 + 查询构造器 + 事务（读写分离） | ✅ 已落地（骨架） |
| **Phase 3** | 视图模板引擎（编译缓存、指令、转义） | ✅ 已落地（骨架） |
| **Phase 4** | Swoole 常驻模式 + 连接池 + 协程 + 请求上下文隔离 | ✅ 已落地（PHP 8 化 + 高并发核心：强类型 / 联合类型安全 / FPM 持久连接 / Swoole 常驻服务器 + 协程连接池；Windows 仅 FPM 可跑，Swoole 需 Linux/WSL） |
| **Phase 5** | ORM 模型（`Mtphp\Database\Model` / ORM-lite 静态 CRUD）、文件缓存（`Mtphp\Cache\Cache`）、文件日志（`Mtphp\Log\Logger`）、布局模板（`@extends`/`@section`/`@yield`/`@parent`，两遍渲染）；均零配置可用、中文注释详尽 | ✅ 已落地 |
| **Phase 6** | 测试体系（框架自带零依赖 `Mtphp\Testing\Assert`/`TestCase` + `tests/run.php` 运行器，无需 PHPUnit）、基准压测（`bin/bench.php` 零依赖 curl_multi 并发；生产级用 wrk/ab + Swoole 常驻）、文档站、示例工程 | 🔜 部分落地（测试+压测 ✅；文档站待实现） |

---

## 九、快速开始

```bash
# 1. 安装依赖（生成自动加载）
cd <项目根目录>            # 即本仓库根目录（mtphp/）
composer dump-autoload

# 2. 启动（FPM 模式，PHP 内置服务器演示）
php -S 127.0.0.1:8080 -t public

# 3. 访问
#    http://127.0.0.1:8080/             → 示例首页（视图渲染）
#    http://127.0.0.1:8080/hello/世界   → 路由参数示例
```

> 未安装 Composer 时，`public/index.php` 内置了 PSR-4 兜底自动加载，可直接运行演示。

### 定义一个路由（`app/Routes/routes.php`）

```php
use Mtphp\Route;
use App\Controllers\HomeController;

Route::get('/', [HomeController::class, 'index']);
Route::get('/hello/{name}', [HomeController::class, 'hello']);
```

### 写一个控制器

```php
namespace App\Controllers;

use Mtphp\Http\Request;
use Mtphp\View\Template;

class HomeController
{
    public function index()
    {
        return Template::render('home', ['title' => 'mtphp 首页']);
    }

    public function hello(Request $request, $name)
    {
        return response()->json(['hello' => $name]);
    }
}
```

### 数据库查询（Phase 2）

```php
use Mtphp\Database\DB;

$rows = DB::table('mendian')->where('seller_id', 4)->orderBy('id','desc')->limit(10)->select();
DB::transaction(function () {
    DB::table('flag_kf')->where('id', 1)->update(['mini_openid' => 'xxx']);
});
```
