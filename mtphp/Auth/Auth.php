<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Auth;

use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Http\Session;

/**
 * 轻量认证守卫（零依赖，思想借鉴主流框架的 Auth/Guard）
 *
 * 设计目标：以“会话 + 用户提供者”的方式提供最常用的登录态管理——
 *   login/attempt/logout/check/user/id，不涉及具体存储，用户模型由“提供者回调”注入，
 *   从而与 ORM / 任意数据源解耦。
 *
 * 守卫隔离：每个守卫名（web / admin / api …）拥有独立的会话键与记住我 Cookie，
 * 互不干扰；多守卫由 AuthManager 统一管理（见 auth() 助手）。
 *
 * 记住我：登录时可选 $remember=true，框架生成并下发加密的长期 Cookie
 * （“用户id|remember_token”），下次访问若会话已失效，则凭该 Cookie 自动重新登录。
 *
 * 用法：
 *   auth()->setUserProvider(fn ($id) => User::find($id));   // 注册“按 id 取用户”
 *   auth()->setRememberResolver(fn ($id, $token) => User::findByRemember($id, $token));
 *   auth()->attempt(fn ($creds) => User::findBy('email', $creds['email']), $input); // 登录
 *   if (auth()->check()) { $u = auth()->user(); }
 *   auth()->logout();
 */
class Auth
{
    protected string $name = 'web';

    protected string $idColumn = 'id';

    protected string $rememberColumn = 'remember_token';

    protected int $rememberTtl = 2592000; // 30 天

    /** @var callable(mixed):?array|null 按 id 取用户 */
    protected $provider = null;

    /** @var callable(int,mixed):?array|null 按 id + remember_token 校验用户 */
    protected $rememberResolver = null;

    /** @var callable(int,string):void|null 持久化 remember_token（写入用户表等） */
    protected $rememberPersister = null;

    /** @var callable():string 生成 remember_token */
    protected $tokenGenerator;

    /** @var array<string,mixed>|null 最近一次解析出的用户（缓存，避免重复查库） */
    protected $lastUser = null;

    /** @var Request|null 用于读取记住我 Cookie（可选注入，便于测试 / CLI） */
    protected ?Request $request = null;

    /** @var Response|null 用于下发记住我 Cookie（可选注入） */
    protected ?Response $response = null;

    /** @var callable():?string|null 记住我 Cookie 读取器 */
    protected $rememberReader = null;

    /** @var callable(string,int):void|null 记住我 Cookie 写入器（第二参为绝对过期时间戳） */
    protected $rememberWriter = null;

    public function __construct(string $name = 'web')
    {
        $this->name          = $name;
        $this->tokenGenerator = static fn (): string => bin2hex(random_bytes(16));
    }

    /**
     * 当前守卫名（web / admin …）
     */
    public function name(): string
    {
        return $this->name;
    }

    public function idColumn(string $column): self
    {
        $this->idColumn = $column;
        return $this;
    }

    public function rememberColumn(string $column): self
    {
        $this->rememberColumn = $column;
        return $this;
    }

    public function rememberTtl(int $seconds): self
    {
        $this->rememberTtl = $seconds;
        return $this;
    }

    /**
     * 注册“按 id 取用户”的提供者（通常指向 Model::find）
     *
     * @param callable(mixed):?array $byId
     */
    public function setUserProvider(callable $byId): self
    {
        $this->provider = $byId;
        return $this;
    }

    /**
     * 注册“按 id + remember_token 校验用户”的解析器（记住我续期时调用）
     *
     * @param callable(int,mixed):?array $resolver
     */
    public function setRememberResolver(callable $resolver): self
    {
        $this->rememberResolver = $resolver;
        return $this;
    }

    /**
     * 注册 remember_token 持久化回调（生成新令牌时写入用户表等）
     *
     * @param callable(int,string):void $persister
     */
    public function setRememberPersister(callable $persister): self
    {
        $this->rememberPersister = $persister;
        return $this;
    }

    public function setTokenGenerator(callable $generator): self
    {
        $this->tokenGenerator = $generator;
        return $this;
    }

    /**
     * 注入请求对象：自动从请求读取记住我 Cookie（无需再手动 setRememberReader）
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;
        if ($this->rememberReader === null) {
            $name = $this->rememberCookieName();
            $this->rememberReader = static fn (): ?string => $request->cookie($name);
        }
        return $this;
    }

    /**
     * 注入响应对象：自动经加密 Cookie 下发记住我令牌（无需再手动 setRememberWriter）
     */
    public function setResponse(Response $response): self
    {
        $this->response = $response;
        if ($this->rememberWriter === null) {
            $name = $this->rememberCookieName();
            $this->rememberWriter = static fn (string $value, int $expireAt): bool
                => $response->eCookie($name, $value, $expireAt);
        }
        return $this;
    }

    /**
     * 显式注入记住我 Cookie 读取器（便于测试或非 HTTP 场景）
     *
     * @param callable():?string $reader
     */
    public function setRememberReader(callable $reader): self
    {
        $this->rememberReader = $reader;
        return $this;
    }

    /**
     * 显式注入记住我 Cookie 写入器（第二参为绝对过期时间戳）
     *
     * @param callable(string,int):void $writer
     */
    public function setRememberWriter(callable $writer): self
    {
        $this->rememberWriter = $writer;
        return $this;
    }

    /**
     * 当前会话（无运行中会话时回落到内存会话）
     */
    protected function session(): Session
    {
        return Session::current() ?? Session::instance();
    }

    /**
     * 本守卫专属会话键（按守卫名隔离，避免 web / admin 登录态串味）
     */
    protected function sessionKey(): string
    {
        return 'mtphp_auth_id_' . $this->name;
    }

    /**
     * 记住我 Cookie 名（按守卫名隔离）
     */
    protected function rememberCookieName(): string
    {
        return 'remember_' . $this->name;
    }

    /**
     * 直接以用户数组登录（写入会话并缓存）；可选下发记住我长期 Cookie
     *
     * @param array<string,mixed> $user
     */
    public function login(array $user, bool $remember = false): void
    {
        $id = $user[$this->idColumn] ?? null;
        $this->session()->set($this->sessionKey(), $id);
        $this->lastUser = $user;

        if (!$remember) {
            return;
        }
        $token = $user[$this->rememberColumn] ?? null;
        if ($token === null || $token === '') {
            $token = ($this->tokenGenerator)();
            $user[$this->rememberColumn] = $token;
            if ($this->rememberPersister !== null) {
                ($this->rememberPersister)((int) $id, (string) $token);
            }
        }
        $this->issueRemember((int) $id, (string) $token);
        $this->lastUser = $user;
    }

    /**
     * 尝试登录：$resolver($credentials) 返回用户数组或 null；成功则写入会话
     *
     * @param callable(array<string,mixed>):?array $resolver
     * @param array<string,mixed> $credentials
     */
    public function attempt(callable $resolver, array $credentials, bool $remember = false): bool
    {
        $user = $resolver($credentials);
        if ($user === null) {
            return false;
        }
        $this->login($user, $remember);
        return true;
    }

    /**
     * 当前登录用户 id（未登录为 null）
     *
     * @return mixed
     */
    public function id()
    {
        return $this->session()->get($this->sessionKey());
    }

    /**
     * 是否已登录
     */
    public function check(): bool
    {
        return $this->session()->has($this->sessionKey());
    }

    /**
     * 是否游客（未登录）
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * 当前登录用户（经提供者解析；无提供者或未登录返回 null）
     *
     * 若会话已失效，会尝试凭记住我 Cookie 自动重新登录。
     *
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        $id = $this->id();
        if ($id !== null) {
            if ($this->lastUser !== null
                && ($this->lastUser[$this->idColumn] ?? null) == $id) {
                return $this->lastUser;
            }
            if ($this->provider !== null) {
                $user = ($this->provider)($id);
                if ($user !== null) {
                    $this->lastUser = $user;
                    return $user;
                }
            }
            return null;
        }

        // 会话失效：尝试记住我续期
        return $this->resolveRemember();
    }

    /**
     * 凭记住我 Cookie 重新解析用户（成功则刷新会话并续期 Cookie）
     *
     * @return array<string,mixed>|null
     */
    protected function resolveRemember(): ?array
    {
        if ($this->rememberResolver === null || $this->rememberReader === null) {
            return null;
        }
        $raw = ($this->rememberReader)();
        if (!is_string($raw) || !str_contains($raw, '|')) {
            return null;
        }
        [$id, $token] = explode('|', $raw, 2);
        $user = ($this->rememberResolver)((int) $id, $token);
        if ($user === null) {
            return null;
        }
        // 刷新会话 + 续期 Cookie（login 内部会重新下发）
        $this->login($user, true);
        return $user;
    }

    /**
     * 下发记住我 Cookie（“用户id|token”，加密存储）
     */
    protected function issueRemember(int $id, string $token): void
    {
        if ($this->rememberWriter === null) {
            return;
        }
        ($this->rememberWriter)($id . '|' . $token, time() + $this->rememberTtl);
    }

    /**
     * 登出：清除会话中的登录态与缓存用户，并使记住我 Cookie 失效
     */
    public function logout(): void
    {
        $this->session()->forget($this->sessionKey());
        $this->lastUser = null;
        if ($this->rememberWriter !== null) {
            ($this->rememberWriter)('', time() - 3600);
        }
    }
}
