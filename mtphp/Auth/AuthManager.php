<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Auth;

/**
 * 认证守卫管理器（多 Guard 注册表）
 *
 * 统一管理多个命名守卫（web / admin / api …），每个守卫是独立的 Auth 实例，
 * 拥有专属会话键与记住我 Cookie，互不干扰。读取 config/auth.php 的 guards 配置
 * 作为可扩展点；即便未配置，默认 'web' 守卫始终可用，保持向后兼容。
 *
 * 用法：auth('admin') 取 admin 守卫；auth() 取默认守卫（config 的 defaults.guard 或 'web'）。
 *
 * 注：无状态 API 令牌守卫已由 Mtphp\Http\Middleware\AuthApi（配合 Mtphp\Auth\Token）
 * 单独提供，可视为“api”守卫的实现；本管理器聚焦会话型守卫的隔离与注册。
 */
class AuthManager
{
    protected static ?self $instance = null;

    /** @var array<string,Auth> 已实例化的守卫（按名缓存，单请求内复用） */
    protected array $guards = [];

    /** @var array<string,mixed> auth 配置 */
    protected array $config;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 测试隔离：清空单例与已缓存守卫
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function __construct()
    {
        $this->config = [];
        if (function_exists('config')) {
            try {
                $cfg = \config('auth', []);
                if (is_array($cfg)) {
                    $this->config = $cfg;
                }
            } catch (\Throwable $e) {
                // 应用未启动（CLI / 测试无 App 实例）时配置不可用，退回默认 'web' 守卫
            }
        }
    }

    /**
     * 取指定守卫（不传则返回默认守卫）
     */
    public function guard(?string $name = null): Auth
    {
        $name ??= $this->config['defaults']['guard'] ?? 'web';
        if (!isset($this->guards[$name])) {
            $this->guards[$name] = new Auth($name);
        }
        return $this->guards[$name];
    }

    /**
     * 列出当前已实例化的守卫名
     *
     * @return list<string>
     */
    public function guards(): array
    {
        return array_keys($this->guards);
    }
}
