<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Cache;

/**
 * 极简文件缓存（零配置即可用）
 *
 * 设计目标：开箱即用、配置简单、注释清晰。
 *  - 基于文件存储，无需 Redis 等额外服务，适合中小规模与开发环境。
 *  - 写入时记录过期时间，读取时自动判断是否过期（过期即删除并返回默认值）。
 *  - 提供 remember()：缓存未命中才执行回调并自动写入，这是日常最常用的“记忆化”写法。
 *  - 缓存文件带 `<?php` 安全头，即便误放到 Web 目录也无法被直接执行。
 *
 * 用法一（直接 new）：
 *   $cache = new \Mtphp\Cache\FileCache();    // 默认存到 runtime/cache/，默认 1 小时过期
 *   $cache->set('name', '张三', 600);         // 存 10 分钟
 *   $v = $cache->get('name', '默认值');        // 取；过期或缺失都返回默认值
 *   $list = $cache->remember('hot', 60, fn () => 查库得到热门列表());
 *
 * 用法二（全局助手，更省事）：
 *   cache()->set('k', $v, 600);
 *   cache()->remember('k', 60, fn () => ...);
 */
class FileCache implements CacheInterface
{
    use AbstractCache;

    protected string $path;        // 缓存目录
    protected int $defaultTtl;     // 默认过期秒数（<=0 表示永不过期）

    /**
     * 构造缓存器
     *
     * @param string $path       缓存目录（默认 runtime/cache）
     * @param int    $defaultTtl 默认过期秒数（默认 3600 = 1 小时；0 表示永不过期）
     */
    public function __construct(string $path = 'runtime/cache', int $defaultTtl = 3600)
    {
        $this->path       = rtrim($path, '/\\');
        $this->defaultTtl = $defaultTtl;
    }

    /** 运行中改缓存目录（链式） */
    public function setPath(string $path): self
    {
        $this->path = rtrim($path, '/\\');
        return $this;
    }

    /** 运行中改默认过期秒数（链式） */
    public function setDefaultTtl(int $ttl): self
    {
        $this->defaultTtl = $ttl;
        return $this;
    }

    /**
     * 仅当键不存在时原子写入（set-if-absent）。
     *
     * 用 fopen('x') 独占创建（O_CREAT|O_EXCL），避免“has + set”两步竞态，
     * 是缓存锁（Lock）与并发安全 add 的基础。已存在返回 false。
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        if (!is_dir($this->path)) {
            @mkdir($this->path, 0755, true);
        }
        $fh = @fopen($this->file($key), 'x'); // 原子独占创建；已存在则失败
        if ($fh === false) {
            return false;
        }
        $expire  = $ttl > 0 ? time() + $ttl : 0; // 0 表示永不过期
        $payload = serialize(['exp' => $expire, 'val' => $value]);
        @fwrite($fh, "<?php // mtphp cache\n" . $payload);
        @fclose($fh);
        return true;
    }

    /**
     * 写入缓存
     *
     * @param string $key   缓存键（任意字符串，内部用 md5 生成文件名）
     * @param mixed  $value 任意可序列化的值（字符串 / 数组 / 对象…）
     * @param ?int   $ttl   过期秒数（null 用默认；0 表示永不过期）
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl    = $ttl ?? $this->defaultTtl;
        $expire = $ttl > 0 ? time() + $ttl : 0; // 0 表示永不过期
        $payload = serialize(['exp' => $expire, 'val' => $value]);

        if (!is_dir($this->path)) {
            @mkdir($this->path, 0755, true);
        }
        $file = $this->file($key);
        // 文件头写入 <?php 指令，防止被当作普通文件直接访问执行（安全兜底）
        return @file_put_contents($file, "<?php // mtphp cache\n" . $payload, LOCK_EX) !== false;
    }

    /**
     * 读取缓存
     *  - 键不存在 → 返回 $default
     *  - 已过期   → 删除文件并返回 $default
     *  - 正常     → 返回存入的值（注意：缓存值本身为 null 时也会返回 null，属正常）
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return $default;
        }
        // 去掉安全头，再反序列化
        $content = (string) preg_replace('/^<\?php\s+\/\/\s*mtphp cache\s*\n/', '', $content, 1);
        $data = @unserialize($content);
        if (!is_array($data) || !array_key_exists('exp', $data)) {
            return $default;
        }
        // 过期处理：删除文件并返回默认值
        if ($data['exp'] !== 0 && $data['exp'] < time()) {
            @unlink($file);
            return $default;
        }
        return $data['val'];
    }

    /**
     * 删除单个键（键不存在也返回 true）
     */
    public function delete(string $key): bool
    {
        $file = $this->file($key);
        if (is_file($file)) {
            return @unlink($file);
        }
        return true;
    }

    /**
     * 清空整个缓存目录（仅删 .php 缓存文件）
     */
    public function clear(): bool
    {
        if (!is_dir($this->path)) {
            return true;
        }
        $ok = true;
        foreach (glob($this->path . '/*.php') ?: [] as $f) {
            if (!@unlink($f)) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * 由键生成缓存文件名（用 md5 避免特殊字符 / 超长问题）
     */
    protected function file(string $key): string
    {
        return $this->path . '/' . md5($key) . '.php';
    }
}
