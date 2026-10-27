<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Log;

/**
 * 按日切分日志器
 *
 * 在 Logger 基础上，把日志落到 “<base>-YYYY-MM-DD.log” 文件，
 * 每天自动新开一个文件，避免单文件无限膨胀、便于按天归档与清理。
 *
 *   $log = new DailyLogger('runtime/logs/app', 'debug');
 *   $log->info('按天归档的日志');   // → runtime/logs/app-2025-01-31.log
 */
class DailyLogger extends Logger
{
    /** @var string 基础路径（不含日期后缀） */
    protected string $basePath;

    /**
     * @param string $basePath 基础路径（如 runtime/logs/app，会自动追加 -日期.log）
     * @param string $level    最低记录级别（PSR-3 八级）
     * @param string $format   输出格式：text | json
     */
    public function __construct(string $basePath = 'runtime/logs/app', string $level = 'debug', string $format = 'text')
    {
        $this->basePath = $basePath;
        parent::__construct($this->dailyPath(), $level, $format);
    }

    /**
     * 运行中改基础路径（链式），会同步重算当日文件路径
     */
    public function setBasePath(string $basePath): self
    {
        $this->basePath = $basePath;
        $this->path     = $this->dailyPath();
        return $this;
    }

    /** 运行中改输出格式（链式） */
    public function setFormat(string $format): self
    {
        $this->format = $format === 'json' ? 'json' : 'text';
        return $this;
    }

    /**
     * 运行中改最低级别（链式）
     */
    public function setLevel(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    /**
     * 计算当日文件路径
     */
    protected function dailyPath(): string
    {
        return $this->basePath . '-' . date('Y-m-d') . '.log';
    }
}
