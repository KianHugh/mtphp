<?php
declare(strict_types=1);

namespace Mtphp\Http;

use Mtphp\Validation\ValidationException;
use Mtphp\Validation\Validator;

/**
 * 请求封装：统一 method / uri / path / query / post / header / json
 */
class Request
{
    protected string $method = 'GET';

    protected string $uri = '/';

    protected string $path = '/';

    /** @var array<string,mixed> */
    protected array $query = [];

    /** @var array<string,mixed> */
    protected array $post = [];

    /** @var array<string,string> */
    protected array $headers = [];

    protected ?string $body = null;

    /** @var array<string,mixed>|null Cookie（懒加载自 $_COOKIE） */
    protected ?array $cookies = null;

    /** @var \Mtphp\Http\Session|null 绑定到本次请求的会话（由 StartSession 中间件注入） */
    protected ?object $session = null;

    /** @var array<string,mixed>|null 已规范化的上传文件（懒加载自 $_FILES 或注入包） */
    protected ?array $uploadedFiles = null;

    /** @var array<string,mixed>|null 注入的文件包（Swoole 等场景使用；null=读 $_FILES） */
    protected ?array $injectedFiles = null;

    /** @var array<string,mixed>|null 经 AuthApi 中间件挂载的已鉴权用户载荷 */
    protected ?array $authUser = null;

    /** @var string|null 请求唯一 ID（取自 X-Request-Id 头，否则自动生成） */
    protected ?string $requestId = null;

    public static function capture(): self
    {
        $r = new self();
        $r->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $r->uri    = $_SERVER['REQUEST_URI'] ?? '/';
        // 路径可能含百分号编码（如中文），按原始规则解码，便于路由匹配与参数取值
        $r->path   = rawurldecode(parse_url($r->uri, PHP_URL_PATH) ?: '/');
        $r->query  = $_GET;
        $r->post   = $_POST;
        $r->headers = self::extractHeaders();
        $body      = file_get_contents('php://input');
        $r->body   = $body === false ? null : $body;
        $r->initRequestId();
        return $r;
    }

    /**
     * 从 Swoole HTTP 请求对象构造（用于 Swoole 常驻服务器）
     *
     * 不 type-hint Swoole\Http\Request 具体类，以便在无 Swoole 环境也能加载本文件。
     *
     * @param object $req Swoole\Http\Request 实例
     */
    public static function fromSwoole(object $req): self
    {
        $r = new self();
        $sv = $req->server ?? [];
        $r->method = (string) ($sv['request_method'] ?? 'GET');
        $uri       = (string) ($sv['request_uri'] ?? '/');
        if (!empty($sv['query_string'])) {
            $uri .= '?' . $sv['query_string'];
        }
        $r->uri  = $uri;
        $r->path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
        $r->query  = is_array($req->get ?? null) ? $req->get : [];
        $r->post   = is_array($req->post ?? null) ? $req->post : [];
        $hd        = $req->header ?? [];
        foreach ($hd as $k => $v) {
            if (is_string($k)) {
                $r->headers[strtolower($k)] = is_string($v) ? $v : (string) $v;
            }
        }
        $body      = method_exists($req, 'rawContent') ? $req->rawContent() : '';
        $r->body   = is_string($body) ? $body : null;
        $r->initRequestId();
        return $r;
    }

    /** @return array<string,string> */
    protected static function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && strpos($k, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = is_string($v) ? $v : (string) $v;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    /**
     * 统一取参：post > query > json body
     *
     * @return mixed
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->query, $this->post);
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $this->json()[$key] ?? $default;
    }

    /**
     * 解析 JSON 请求体
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        $ct = $this->header('content-type');
        if ($this->body !== null && is_string($ct) && stripos($ct, 'application/json') !== false) {
            $d = json_decode($this->body, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    public function isAjax(): bool
    {
        $xrw = $this->header('x-requested-with');
        return is_string($xrw) && strtolower($xrw) === 'xmlhttprequest';
    }

    /**
     * 全部输入：query + post + JSON 体合并（后者覆盖前者）
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json());
    }

    /**
     * 按规则校验请求输入
     *
     * 用法（控制器内）：
     *   $data = $request->validate([
     *       'name' => 'required|max:50',
     *       'email'=> 'required|email',
     *   ]);
     * 校验失败抛出 ValidationException（由 App 统一渲染为 422）；
     * 通过则返回经白名单过滤的合法数据数组。
     *
     * @param array<string,string|array> $rules
     * @param array<string,string> $messages
     * @return array<string,mixed>
     */
    public function validate(array $rules, array $messages = []): array
    {
        $validator = Validator::make($this->all(), $rules, $messages);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }
        return $validator->validated();
    }

    /**
     * 读取 Cookie（默认从 $_COOKIE 懒加载；Swoole 环境下可由 fromSwoole 注入）
     *
     * 若值带 enc: 前缀（由 Response::eCookie 写入），则自动经 Crypt 解密；
     * 其余按明文返回（向后兼容会话 ID 等普通 Cookie）。
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        if ($this->cookies === null) {
            $this->cookies = $_COOKIE ?? [];
        }
        $raw = $this->cookies[$key] ?? null;
        if (is_string($raw) && str_starts_with($raw, 'enc:')) {
            try {
                return \Mtphp\Encryption\Crypt::dec(substr($raw, 4));
            } catch (\Throwable $e) {
                return $default;
            }
        }
        return $raw ?? $default;
    }

    /**
     * 注入本请求的 Cookie 数组（Swoole 等场景使用）
     *
     * @param array<string,mixed> $cookies
     */
    public function setCookies(array $cookies): void
    {
        $this->cookies = $cookies;
    }

    /**
     * 取绑定的会话（若已注入）
     */
    public function session(): ?object
    {
        return $this->session;
    }

    /**
     * 挂载已鉴权用户载荷（由 AuthApi 等中间件调用）
     *
     * @param array<string,mixed> $user
     */
    public function setAuthUser(array $user): void
    {
        $this->authUser = $user;
    }

    /**
     * 取已鉴权用户载荷（未鉴权返回 null）
     *
     * @return array<string,mixed>|null
     */
    public function authUser(): ?array
    {
        return $this->authUser;
    }

    /**
     * 初始化请求 ID：优先复用客户端传来的 X-Request-Id（便于链路追踪透传），
     * 否则生成一个随机 ID。幂等：多次调用不会覆盖已生成的 ID。
     */
    protected function initRequestId(): void
    {
        if ($this->requestId !== null) {
            return;
        }
        $rid = $this->header('x-request-id');
        $this->requestId = (is_string($rid) && $rid !== '')
            ? $rid
            : (function_exists('bin2hex') ? bin2hex(random_bytes(8)) : md5((string) mt_rand()));
    }

    /**
     * 取请求唯一 ID（用于日志链路追踪、响应头回传）
     */
    public function requestId(): string
    {
        if ($this->requestId === null) {
            $this->initRequestId();
        }
        return $this->requestId;
    }

    /**
     * 手动指定请求 ID（一般在入口/测试处调用，用于打通上游链路 ID）
     */
    public function setRequestId(string $id): void
    {
        $this->requestId = $id;
    }

    /**
     * 注入会话（StartSession 中间件调用）
     */
    public function setSession(object $session): void
    {
        $this->session = $session;
    }

    /**
     * 取上传文件
     *
     * 无参：返回全部规范化后的 UploadedFile 树（键为表单字段名，多文件为数组）。
     * 有参：返回该字段对应的 UploadedFile 或数组（多文件），不存在返回 null。
     *
     * @return mixed
     */
    public function files(?string $key = null): mixed
    {
        if ($this->uploadedFiles === null) {
            $this->uploadedFiles = $this->normalizeFiles($this->injectedFiles ?? $_FILES);
        }
        if ($key === null) {
            return $this->uploadedFiles;
        }
        return $this->uploadedFiles[$key] ?? null;
    }

    /**
     * 取单个上传文件（多文件时返回首个）；不存在返回 null
     */
    public function file(string $key): ?UploadedFile
    {
        $f = $this->files($key);
        if ($f === null) {
            return null;
        }
        if (is_array($f)) {
            return $f[0] ?? null;
        }
        return $f;
    }

    /**
     * 注入上传文件包（Swoole 等场景使用，结构同 $_FILES）
     *
     * @param array<string,mixed> $files
     */
    public function setFiles(array $files): void
    {
        $this->injectedFiles  = $files;
        $this->uploadedFiles  = null;
    }

    /**
     * 把 PHP $_FILES 结构规范化为 UploadedFile 对象树
     *
     * @return array<string,mixed>
     */
    protected function normalizeFiles(array $files): array
    {
        // 单文件条目（tmp_name 为标量）
        if (isset($files['tmp_name']) && is_string($files['tmp_name'])) {
            return [new UploadedFile(
                $files['tmp_name'],
                $files['name'] ?? 'unknown',
                $files['type'] ?? null,
                (int) ($files['error'] ?? 0),
                (int) ($files['size'] ?? 0)
            )];
        }
        // 多文件条目（tmp_name 为数组）
        if (isset($files['tmp_name']) && is_array($files['tmp_name'])) {
            $out = [];
            foreach (array_keys($files['tmp_name']) as $i) {
                $out[] = new UploadedFile(
                    $files['tmp_name'][$i] ?? '',
                    $files['name'][$i] ?? 'unknown',
                    $files['type'][$i] ?? null,
                    (int) ($files['error'][$i] ?? 0),
                    (int) ($files['size'][$i] ?? 0)
                );
            }
            return $out;
        }
        // 分组（如 ['avatar'=>[...], 'images'=>[...]] 或嵌套 ['group'=>['avatar'=>[...]]]）
        $out = [];
        foreach ($files as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->normalizeFiles($v);
            }
        }
        return $out;
    }
}
