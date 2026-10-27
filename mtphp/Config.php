<?php
declare(strict_types=1);

namespace Mtphp;

/**
 * 配置管理：加载 config/*.php，支持点号多级读取（app.name）
 */
class Config
{
    /** @var array<string,mixed> 配置树 */
    protected array $items = [];

    public function __construct(string $path)
    {
        if (is_dir($path)) {
            foreach (glob($path . '/*.php') ?: [] as $file) {
                $key            = basename($file, '.php');
                $this->items[$key] = require $file;
            }
        }
    }

    /**
     * 读取配置；支持 "app.timezone" 形式，缺省返回 $default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = $this->items;

        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $arr      = &$this->items;
        foreach ($segments as $seg) {
            if (!isset($arr[$seg]) || !is_array($arr[$seg])) {
                $arr[$seg] = [];
            }
            $arr = &$arr[$seg];
        }
        $arr = $value;
    }

    public function all(): array
    {
        return $this->items;
    }
}
