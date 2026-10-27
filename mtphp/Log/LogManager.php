<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Log;

/**
 * 日志管理器（多通道）
 *
 * 按 config/log.php 的 channels 配置构建并缓存各通道日志器，
 * 支持 single（单文件）/ daily（按日切分）两种驱动，并补齐 PSR-3 八级。
 *
 *   logger()->info('默认通道');                 // 默认通道
 *   logger()->channel('daily')->error('xxx');   // 指定通道
 *
 * 配置格式（推荐）：
 *   return [
 *       'default'  => 'single',
 *       'channels' => [
 *           'single' => ['driver'=>'single', 'path'=>'runtime/logs/app.log', 'level'=>'info'],
 *           'daily'  => ['driver'=>'daily',  'path'=>'runtime/logs/app',    'level'=>'debug'],
 *       ],
 *   ];
 *
 * 也兼容旧的扁平配置（只有 path / level），会自动当作 single 通道。
 */
class LogManager
{
    /** @var array<string,mixed> 日志配置 */
    protected array $config;

    /** @var string 默认通道名 */
    protected string $default;

    /** @var array<string,Logger> 通道实例缓存 */
    protected array $channels = [];

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config  = $config;
        $this->default = (string) ($config['default'] ?? 'single');
    }

    /**
     * 取一个通道日志器（默认通道可用 null）
     */
    public function channel(?string $name = null): Logger
    {
        $name = $name ?? $this->default;
        if (!isset($this->channels[$name])) {
            $this->channels[$name] = $this->resolve($name);
        }
        return $this->channels[$name];
    }

    /**
     * 按配置构建通道日志器
     */
    protected function resolve(string $name): Logger
    {
        $channels = $this->config['channels'] ?? null;

        if (is_array($channels) && isset($channels[$name]) && is_array($channels[$name])) {
            $cfg = $channels[$name];
        } else {
            // 兼容旧扁平配置：把整体当作 single 通道
            $cfg = [
                'driver' => 'single',
                'path'   => $this->config['path'] ?? 'runtime/logs/app.log',
                'level'  => $this->config['level'] ?? 'info',
            ];
        }

        $driver = (string) ($cfg['driver'] ?? 'single');
        $path   = (string) ($cfg['path']   ?? 'runtime/logs/app.log');
        $level  = (string) ($cfg['level']  ?? 'info');
        $format = (string) ($cfg['format'] ?? 'text');

        if ($driver === 'daily') {
            return new DailyLogger($path, $level, $format);
        }
        return new Logger($path, $level, $format);
    }

    // ---- 代理到默认通道的 PSR-3 八级方法 ----

    public function debug(string $message, mixed $context = []): void
    {
        $this->channel()->debug($message, $context);
    }

    public function info(string $message, mixed $context = []): void
    {
        $this->channel()->info($message, $context);
    }

    public function notice(string $message, mixed $context = []): void
    {
        $this->channel()->notice($message, $context);
    }

    public function warning(string $message, mixed $context = []): void
    {
        $this->channel()->warning($message, $context);
    }

    public function error(string $message, mixed $context = []): void
    {
        $this->channel()->error($message, $context);
    }

    public function critical(string $message, mixed $context = []): void
    {
        $this->channel()->critical($message, $context);
    }

    public function alert(string $message, mixed $context = []): void
    {
        $this->channel()->alert($message, $context);
    }

    public function emergency(string $message, mixed $context = []): void
    {
        $this->channel()->emergency($message, $context);
    }

    /**
     * 直接指定级别写入（代理到默认通道）
     */
    public function log(string $level, string $message, mixed $context = []): void
    {
        $this->channel()->log($level, $message, $context);
    }
}
