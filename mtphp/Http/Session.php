<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Http;

use Mtphp\Encryption\Crypt;

/**
 * 轻量会话（零依赖，思想借鉴主流框架的 Session 组件）
 *
 * 设计目标：提供最常用、够用的会话能力——get/set/forget/flush + 一次性 flash 闪存 +
 * 会话重生成；支持两种驱动：'file'（落地到磁盘，跨请求持久）与 'array'（内存，仅当前进程，
 * 适合 CLI / 测试 / 无状态场景）。
 *
 * 用法（Web，配合 StartSession 中间件自动生效）：
 *   session()->set('cart', $items);
 *   $items = session()->get('cart');
 *   session()->flash('status', '保存成功');   // 仅下一次请求可读
 *
 * 说明：本类只管“id + 数据”的存取与持久化；Cookie 的收发由 StartSession 中间件负责，
 * 这样会话与传输解耦，既可在 Web 用，也可在 CLI/测试里直接用内存会话。
 */
class Session
{
    /** 当前请求期内的会话实例（供 session() 助手 / Auth 取用） */
    protected static ?self $current = null;

    /** 内存单例（供 session()/Auth 在无运行中会话时稳定复用） */
    protected static ?self $default = null;

    protected string $driver;

    protected string $path;

    protected string $id;

    /** @var array<string,mixed> 持久化数据 */
    protected array $data = [];

    /** @var array<string,mixed> 待下轮请求可读的一次性数据 */
    protected array $nextFlash = [];

    /** @var array<string,mixed> 本轮请求可读的一次性数据（由 start 时从 nextFlash 搬入） */
    protected array $flashed = [];

    public function __construct(string $driver = 'array', string $path = '')
    {
        $this->driver = $driver;
        $this->path   = $path;
        $this->id     = $this->generateId();
    }

    /**
     * 工厂（供 session() 助手在无运行中会话时创建/复用内存会话）
     *
     * 缓存为单例：同一进程内多次调用返回同一实例，保证 session()/Auth 跨调用数据一致。
     */
    public static function instance(string $driver = 'array', string $path = ''): self
    {
        if (self::$default === null) {
            self::$default = new self($driver, $path);
        }
        return self::$default;
    }

    public static function setCurrent(self $session): void
    {
        self::$current = $session;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function clearCurrent(): void
    {
        self::$current = null;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    /**
     * 启动会话：加载持久化数据，并把上一轮 flash 搬入本轮可读区。
     */
    public function start(): void
    {
        if ($this->driver === 'file') {
            $file = $this->filePath();
            if (is_file($file)) {
                $raw = file_get_contents($file);
                $dec = $raw !== false ? self::unpackSession($raw) : null;
                if (is_array($dec)) {
                    $this->data      = $dec['data'] ?? [];
                    $this->nextFlash = [];
                    $this->flashed   = $dec['flash'] ?? [];
                }
            }
        } else {
            $this->flashed = $this->nextFlash;
            $this->nextFlash = [];
        }
        self::$current = $this;
    }

    /**
     * 保存会话（落地到磁盘或仅停留在内存）
     */
    public function save(): void
    {
        if ($this->driver === 'file') {
            $file = $this->filePath();
            $dir  = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(
                $file,
                self::packSession(['data' => $this->data, 'flash' => $this->nextFlash]),
                LOCK_EX
            );
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function flush(): void
    {
        $this->data      = [];
        $this->nextFlash = [];
        $this->flashed   = [];
    }

    /**
     * 写入一次性数据：仅“下一轮”请求可读（本轮不可读）。
     */
    public function flash(string $key, mixed $value): void
    {
        $this->nextFlash[$key] = $value;
    }

    /**
     * 读取一次性数据（本轮由 flash 注入的部分）
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flashed[$key] ?? $this->data[$key] ?? $default;
    }

    /**
     * 把本轮可读的 flash 再保留一轮（常用于重定向后依然显示提示）
     */
    public function reflash(): void
    {
        foreach ($this->flashed as $k => $v) {
            $this->nextFlash[$k] = $v;
        }
    }

    /**
     * 重生成会话 ID（登录/提权时调用，降低会话固定攻击风险）
     */
    public function regenerate(bool $destroy = false): void
    {
        if ($destroy && $this->driver === 'file' && is_file($this->filePath())) {
            @unlink($this->filePath());
        }
        $this->id = $this->generateId();
    }

    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function filePath(): string
    {
        return rtrim($this->path, '/') . '/' . $this->id . '.sess';
    }

    /**
     * 会话落盘前的打包：配置了加密密钥时对数据加密（前缀 enc: 标记），否则明文序列化。
     */
    protected static function packSession(array $payload): string
    {
        $key = Crypt::getKey();
        if ($key !== null && $key !== '') {
            return 'enc:' . Crypt::enc($payload);
        }
        return serialize($payload);
    }

    /**
     * 会话读取后的解包：enc: 前缀的密文走解密（失败则视为损坏、返回 null 重新开始）；
     * 其余按旧格式明文反序列化（向后兼容旧会话文件）。
     */
    protected static function unpackSession(string $raw): ?array
    {
        if (str_starts_with($raw, 'enc:')) {
            try {
                $dec = Crypt::dec(substr($raw, 4));
                return is_array($dec) ? $dec : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        $dec = @unserialize($raw);
        return is_array($dec) ? $dec : null;
    }
}
