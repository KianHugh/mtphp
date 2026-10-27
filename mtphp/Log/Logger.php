<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Log;

/**
 * 极简文件日志器（零配置即可用）
 *
 * 设计目标：开箱即用、配置简单、注释清晰。
 *  - 不依赖任何第三方库，纯 PHP 文件写入，适合开发 / 中小规模生产。
 *  - 按日志级别过滤：只记录 >= 设定级别的日志，减少无用磁盘 IO。
 *  - 日志目录不存在时自动创建。
 *  - 支持上下文数组 / 异常对象，自动序列化成可读文本，方便排查问题。
 *
 * 用法一（直接 new）：
 *   $log = new \Mtphp\Log\Logger();          // 默认写到 runtime/logs/app.log，级别 info
 *   $log->info('用户登录', ['uid' => 1]);
 *   $log->error('出错了', $e);               // $e 为 Throwable
 *
 * 用法二（全局助手，更省事）：
 *   logger()->info('hello');
 *   logger()->warning('内存偏高', ['mem' => '80%']);
 */
class Logger
{
    // 日志级别与权重（数值越大表示越严重），对齐 PSR-3 八级
    protected const LEVELS = [
        'debug'     => 100,
        'info'      => 200,
        'notice'    => 250,
        'warning'   => 300,
        'error'     => 400,
        'critical'  => 500,
        'alert'     => 550,
        'emergency' => 600,
    ];

    /** @var string|null 全局请求 ID（由框架在每次请求入口注入，结构化日志自动带上，便于链路追踪） */
    protected static ?string $requestId = null;

    protected string $path;   // 日志文件完整路径
    protected string $level;  // 最低记录级别（低于此级别的日志会被忽略）
    protected string $format; // 输出格式：text（默认，人读）| json（结构化，便于采集/检索）

    /**
     * 构造日志器
     *
     * @param string $path  日志文件路径（默认 runtime/logs/app.log）
     * @param string $level 最低记录级别：debug | info | warning | error
     * @param string $format 输出格式：text | json
     */
    public function __construct(string $path = 'runtime/logs/app.log', string $level = 'info', string $format = 'text')
    {
        $this->path   = $path;
        $this->level  = $level;
        $this->format = $format === 'json' ? 'json' : 'text';
    }

    /** 运行中改日志文件路径（链式） */
    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    /** 运行中改最低记录级别（链式） */
    public function setLevel(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    /** 运行中改输出格式（链式）：text | json */
    public function setFormat(string $format): self
    {
        $this->format = $format === 'json' ? 'json' : 'text';
        return $this;
    }

    /**
     * 设置全局请求 ID（框架在请求入口调用，所有通道的结构化日志都会带上）
     */
    public static function setRequestId(?string $id): void
    {
        self::$requestId = $id;
    }

    /** 取当前全局请求 ID */
    public static function getRequestId(): ?string
    {
        return self::$requestId;
    }

    // ---- 四个常用级别，对应 LEVELS 中的键 ----

    /** 调试信息（最详细，默认不记录） */
    public function debug(string $message, mixed $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** 一般信息（默认级别） */
    public function info(string $message, mixed $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** 警告（需要注意但不影响主流程） */
    public function warning(string $message, mixed $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** 错误（主流程失败） */
    public function error(string $message, mixed $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** 严重错误（需立即处理，但尚可运行） */
    public function critical(string $message, mixed $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** 告警（需运维关注，如磁盘将满） */
    public function alert(string $message, mixed $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /** 紧急（系统不可用，最高级别） */
    public function emergency(string $message, mixed $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /** 通知（值得记录的正常但重要事件） */
    public function notice(string $message, mixed $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * 写入一条日志（级别不足会被静默忽略）
     */
    public function log(string $level, string $message, mixed $context = []): void
    {
        // 级别过滤：低于设定最低级别的日志不写，避免无意义的磁盘写入
        $current = self::LEVELS[$level] ?? null;
        $min     = self::LEVELS[$this->level] ?? 20;
        if ($current === null || $current < $min) {
            return;
        }

        if ($this->format === 'json') {
            $record = [
                'ts'          => date('c'),
                'level'       => strtoupper($level),
                'msg'         => $message,
                'request_id'  => self::$requestId,
                'context'     => $this->normalizeContext($context),
            ];
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
            $this->write(($line === false ? '{}' : $line) . "\n");
            return;
        }

        // 统一格式：[时间] 级别: 消息 上下文
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $this->formatContext($context)
        );

        $this->write($line);
    }

    /**
     * 把上下文归一成数组（Throwable 拆成消息+位置，便于结构化检索）
     *
     * @return array<string,mixed>
     */
    protected function normalizeContext(mixed $context): array
    {
        if ($context === [] || $context === null || $context === '') {
            return [];
        }
        if ($context instanceof \Throwable) {
            return [
                'exception' => get_class($context),
                'message'   => $context->getMessage(),
                'file'      => $context->getFile() . ':' . $context->getLine(),
            ];
        }
        if (is_array($context) || is_object($context)) {
            $arr = is_array($context) ? $context : (array) $context;
            // 把嵌套的 Throwable 也拆开，避免 JSON 递归/不可序列化
            foreach ($arr as $k => $v) {
                if ($v instanceof \Throwable) {
                    $arr[$k] = [
                        'exception' => get_class($v),
                        'message'   => $v->getMessage(),
                        'file'      => $v->getFile() . ':' . $v->getLine(),
                    ];
                }
            }
            return $arr;
        }
        return ['value' => (string) $context];
    }

    /**
     * 把上下文格式化为可读字符串
     *  - 空值：返回空串（不附加任何内容）
     *  - Throwable：输出 消息 + 文件:行号 + 简短堆栈（排查错误最有用的信息）
     *  - 数组 / 对象：JSON 序列化（不可序列化则退化为 print_r）
     *  - 其它标量：直接转字符串
     */
    protected function formatContext(mixed $context): string
    {
        if ($context === [] || $context === null || $context === '') {
            return '';
        }
        if ($context instanceof \Throwable) {
            return ' ' . $context->getMessage()
                 . ' (' . $context->getFile() . ':' . $context->getLine() . ')'
                 . "\n" . $context->getTraceAsString();
        }
        if (is_array($context) || is_object($context)) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
            if ($json === false) {
                $json = print_r($context, true);
            }
            return ' ' . $json;
        }
        return ' ' . (string) $context;
    }

    /**
     * 真正写文件：自动创建目录；写失败不抛异常（日志不应影响主流程）。
     */
    protected function write(string $line): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // 错误抑制：仅开发期可能在权限不足时静默失败，不会中断业务
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
