<?php
declare(strict_types=1);

namespace Mtphp\Storage;

use Mtphp\App;

/**
 * 存储管理器（驱动工厂 + 盘缓存）
 *
 * 读取 config/storage.php 的 default / disks 配置，按需实例化对应驱动；
 * 未配置时回退到内置 local 盘，保证零配置可用。
 *
 * 云驱动注册示例（引入对应 composer SDK 后）：
 *   StorageManager::extend('qiniu', function (array $cfg) {
 *       return new App\Storage\QiniuDriver($cfg);   // 实现 StorageInterface
 *   });
 *
 * 用法：storage()->put('a.txt', 'hi');  storage('qiniu')->put(...);
 */
class StorageManager
{
    /** @var array<string,callable> 自定义驱动工厂（盘名 => factory($cfg)） */
    protected static array $extended = [];

    protected static ?self $instance = null;

    /** @var array<string,StorageInterface> 已实例化的盘（按名缓存） */
    protected array $disks = [];

    /** @var array<string,mixed> storage 配置 */
    protected array $config;

    public static function resolve(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 测试隔离 / 重置单例
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$extended = [];
    }

    /**
     * 注册自定义驱动（云盘 / 测试替身）
     */
    public static function extend(string $driver, callable $factory): void
    {
        self::$extended[$driver] = $factory;
    }

    public function __construct()
    {
        $this->config = [];
        if (function_exists('config')) {
            try {
                $cfg = App::instance() !== null ? \config('storage', []) : [];
                if (is_array($cfg)) {
                    $this->config = $cfg;
                }
            } catch (\Throwable $e) {
                // 应用未启动（CLI / 测试）时配置不可用，回退默认 local 盘
            }
        }
    }

    /**
     * 取指定盘（不传返回默认盘）
     */
    public function disk(?string $name = null): StorageInterface
    {
        $name ??= $this->config['default'] ?? 'local';
        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }
        if (!isset($this->config['disks'][$name])) {
            $cfg = $name === 'local'
                ? ['driver' => 'local', 'root' => 'runtime/storage', 'url' => '/storage']
                : ['driver' => $name];
        } else {
            $cfg = $this->config['disks'][$name];
        }
        $disk                 = $this->resolveDisk($cfg);
        $this->disks[$name]   = $disk;
        return $disk;
    }

    /**
     * 列出当前已实例化的盘名
     *
     * @return list<string>
     */
    public function disks(): array
    {
        return array_keys($this->disks);
    }

    protected function resolveDisk(array $cfg): StorageInterface
    {
        $driver = $cfg['driver'] ?? 'local';

        if ($driver !== 'local' && isset(self::$extended[$driver])) {
            return (self::$extended[$driver])($cfg);
        }

        if ($driver === 'local') {
            return new LocalDriver(
                $cfg['root'] ?? 'runtime/storage',
                $cfg['url']  ?? '/storage'
            );
        }

        throw new \RuntimeException("未注册的存储驱动：{$driver}（用 StorageManager::extend 注册）");
    }
}
