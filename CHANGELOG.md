# 更新日志 (Changelog)

本文件记录 MTPHP 的所有重要变更。格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本 2.0.0](https://semver.org/lang/zh-CN/)。

## [1.0.0] - 2026-10-27

首个开源正式版本。

### 新增
- 核心框架 `Mtphp\`：服务容器、服务提供者、配置管理、路由、请求/响应、中间件管道。
- 零依赖 ORM 与查询构造器（基于 PDO 多驱动，内置内存 SQLite 测试夹具，测试无需外部数据库）。
- 模板引擎（Blade 风格语法，思想借鉴、独立实现，不含任何第三方商标代码）。
- 队列、事件、缓存、验证、HTTP 客户端、会话、认证等常用组件。
- 命令行工具 `bin/mtphp`，支持 FPM 与 Swoole 常驻内存双运行模式。
- 零依赖测试基座 `tests/run.php`：自动发现并运行 `tests/*Test.php`，具有 CI 友好的退出码。
- 详尽中文文档：`README.md`、约 172KB 的 `使用手册.md`、`DESIGN.md`。
- 开源配套文件：`LICENSE`（MIT）、`CONTRIBUTING.md`、`SECURITY.md`、`COPYRIGHT`。

### 许可证
- 以 **MIT 许可证** 开源，版权归属 **武汉麦麦心选网络科技有限公司**。
