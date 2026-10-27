<?php
declare(strict_types=1);

namespace Mtphp\Storage;

use Mtphp\App;

/**
 * 本地磁盘驱动（零依赖，框架内置）
 *
 * 相对 root 会自动以应用根目录为基准解析；绝对路径则原样使用。
 * 适合单机部署、或作为对象存储的“本地缓存/私有落盘”盘。
 */
class LocalDriver implements StorageInterface
{
    protected string $root;

    protected string $url;

    public function __construct(string $root, string $url = '/storage')
    {
        if (
            $root !== '' && !str_starts_with($root, '/')
            && !preg_match('#^[A-Za-z]:[\\\\/]#', $root)
            && App::instance() !== null
        ) {
            $root = rtrim(App::instance()->root(), '/\\') . '/' . ltrim($root, '/\\');
        }
        $this->root = rtrim($root, '/\\');
        $this->url  = rtrim($url, '/');
    }

    protected function full(string $path): string
    {
        return $this->root . '/' . ltrim($path, '/\\');
    }

    public function put(string $path, string $contents, array $options = []): bool
    {
        $file = $this->full($path);
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($file, $contents) !== false;
    }

    public function get(string $path): ?string
    {
        $file = $this->full($path);
        return is_file($file) ? file_get_contents($file) : null;
    }

    public function exists(string $path): bool
    {
        return is_file($this->full($path));
    }

    public function delete(string $path): bool
    {
        $file = $this->full($path);
        return is_file($file) ? unlink($file) : false;
    }

    public function size(string $path): ?int
    {
        $file = $this->full($path);
        return is_file($file) ? filesize($file) : null;
    }

    public function mime(string $path): ?string
    {
        $file = $this->full($path);
        if (!is_file($file)) {
            return null;
        }
        $m = @mime_content_type($file);
        return is_string($m) ? $m : null;
    }

    public function url(string $path): string
    {
        return $this->url . '/' . ltrim($path, '/');
    }

    public function tempUrl(string $path, int $ttl): ?string
    {
        // 本地盘无签名外链概念，返回 null；云驱动应实现之
        return null;
    }
}
