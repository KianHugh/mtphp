<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Http;

use Mtphp\Image\ImageCompressor;

/**
 * 上传文件封装（零依赖）
 *
 * 思想借鉴主流框架的 UploadedFile，独立实现；框架层面不依赖任何扩展。
 * 从 $_FILES 条目（或 Swoole 注入的结构）构造，提供统一的移动、校验、元信息接口。
 *
 * 用法（控制器里）：
 *   $file = $request->file('avatar');     // 返回 Mtphp\Http\UploadedFile 或 null
 *   if ($file !== null && $file->isValid()) {
 *       $file->move('uploads/avatar', uniqid() . '.' . $file->getExtension());
 *   }
 *   // 或带白名单校验：
 *   $file->validate(['jpg', 'png'], 2 * 1024 * 1024);
 */
class UploadedFile
{
    protected string $path;          // 临时 / 源文件路径

    protected string $originalName;  // 客户端原始文件名

    protected ?string $clientMime;   // 客户端声明的 MIME

    protected int $error;            // UPLOAD_ERR_* 常量

    protected int $size;             // 字节数

    /** @var string|null 最近一次压缩失败原因（压缩失败不丢原文件，仅记录） */
    protected ?string $lastError = null;

    /** @var array<string,mixed>|null 无 App 配置环境（或测试）下的程序化默认压缩参数 */
    protected static ?array $globalCompressOptions = null;

    public function __construct(
        string $path,
        string $originalName = 'unknown',
        ?string $clientMime = null,
        int $error = 0,
        int $size = 0
    ) {
        $this->path         = $path;
        $this->originalName = $originalName;
        $this->clientMime   = $clientMime;
        $this->error        = $error;

        if ($size <= 0 && is_file($path)) {
            $s           = @filesize($path);
            $this->size  = ($s === false) ? 0 : $s;
        } else {
            $this->size = $size;
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getRealPath(): string
    {
        return $this->path;
    }

    public function getClientOriginalName(): string
    {
        return $this->originalName;
    }

    public function getFilename(): string
    {
        return pathinfo($this->originalName, PATHINFO_FILENAME);
    }

    /**
     * 原始扩展名（小写，不含点）
     */
    public function getClientOriginalExtension(): string
    {
        $ext = pathinfo($this->originalName, PATHINFO_EXTENSION);
        return strtolower($ext);
    }

    public function getExtension(): string
    {
        return $this->getClientOriginalExtension();
    }

    public function getSize(): int
    {
        if ($this->size <= 0 && is_file($this->path)) {
            $s          = @filesize($this->path);
            $this->size = ($s === false) ? 0 : $s;
        }
        return $this->size;
    }

    public function getClientMimeType(): ?string
    {
        return $this->clientMime;
    }

    public function getError(): int
    {
        return $this->error;
    }

    /**
     * 是否上传成功（error=0 且文件存在）
     *
     * 注：为提升可测性与对 Swoole 等非标准上传源的兼容，这里不强制 is_uploaded_file，
     *     仅校验 error 与文件存在；如需强制标准上传语义，可在调用处自行加 is_uploaded_file。
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_file($this->path);
    }

    /**
     * 取得真实 MIME（优先 finfo 探测，失败回退客户端声明）
     */
    public function getMimeType(): string
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $m = @finfo_file($finfo, $this->path);
                if (is_string($m) && $m !== '') {
                    return $m;
                }
            }
        }
        return $this->clientMime ?? 'application/octet-stream';
    }

    /**
     * 生成基于内容的随机文件名（扩展名取自原始名）
     */
    public function hashName(): string
    {
        $hash = @md5_file($this->path);
        return ($hash === false ? md5((string) mt_rand()) : $hash) . '.' . $this->getExtension();
    }

    /**
     * 把文件移动到目标目录（支持可选自动压缩 / 缩放）
     *
     * @param string      $targetDir 目标目录（不存在会自动创建）
     * @param string|null $name      目标文件名（默认用 hashName）
     * @param mixed       $compress  压缩开关 / 参数：
     *                                 - null（默认）：若 config('upload.compress.enabled') 开启则用其参数，否则不压缩
     *                                 - false：强制关闭压缩（即使全局开启）
     *                                 - array：逐次覆盖的压缩参数（quality / max_width / max_height / format / auto_rotate）
     *                                 - true：使用全局默认参数（即使全局 enabled=false）
     * @return string 最终写入的完整路径；失败返回空串
     */
    public function move(string $targetDir, ?string $name = null, mixed $compress = null): string
    {
        if (!$this->isValid()) {
            return '';
        }
        $targetDir = rtrim($targetDir, '/\\');
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            return '';
        }
        if (!is_dir($targetDir)) {
            return '';
        }
        $name = $name ?? $this->hashName();
        $name = basename($name); // 安全：去除路径分隔，避免穿越
        $dest = $targetDir . '/' . $name;

        if (is_uploaded_file($this->path)) {
            $ok = @move_uploaded_file($this->path, $dest);
        } else {
            $ok = @rename($this->path, $dest);
            if (!$ok && is_file($this->path)) {
                $ok = @copy($this->path, $dest);
            }
        }
        if (!$ok || !is_file($dest)) {
            return '';
        }

        // 可选：上传后自动压缩 / 缩放（失败不丢原文件，仅记录 lastError）
        $options = $this->resolveCompressOptions($compress);
        if ($options !== null) {
            $dest = $this->applyCompression($dest, $options);
        }
        return $dest;
    }

    /**
     * 设置程序化默认压缩参数（无 App 配置时生效，亦用于测试覆盖）
     *
     * @param array<string,mixed>|null $options 传 null 清除
     */
    public static function setGlobalCompressOptions(?array $options): void
    {
        self::$globalCompressOptions = $options;
    }

    /**
     * 最近一次压缩失败原因；压缩成功或未压缩时为 null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * 解析压缩参数来源（优先级：显式数组 > 显式 false > 显式 true > 全局默认）
     *
     * 全局默认 = config('upload.compress') 与静态 setGlobalCompressOptions() 合并，
     * 后者覆盖前者（便于程序化 / 测试覆盖），且 enabled 为 true 时才生效。
     */
    protected function resolveCompressOptions(mixed $compress): ?array
    {
        if (is_array($compress)) {
            return $compress;
        }
        if ($compress === false) {
            return null; // 显式关闭
        }
        $cfg = null;
        if (function_exists('config') && \Mtphp\App::instance() !== null) {
            $c = config('upload.compress');
            if (is_array($c)) {
                $cfg = $c;
            }
        }
        if (self::$globalCompressOptions !== null) {
            $cfg = $cfg === null ? self::$globalCompressOptions : array_merge($cfg, self::$globalCompressOptions);
        }
        if (!is_array($cfg) || ($cfg['enabled'] ?? false) !== true) {
            return null;
        }
        return $cfg;
    }

    /**
     * 应用压缩：成功则替换原文件并返回最终路径；失败 / 跳过则保留原文件
     */
    protected function applyCompression(string $dest, array $options): string
    {
        if (!class_exists(ImageCompressor::class)) {
            return $dest;
        }
        try {
            $final = (new ImageCompressor())->compress($dest, $dest, $options);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return $dest; // 原样保留（非图片 / GD 缺失 / 内存不足等），不因压缩失败而丢文件
        }
        if ($final === false) {
            return $dest; // 调用方决定跳过（如动画 GIF），保留原文件
        }
        // 目标扩展名与源不同（如 jpg → webp）时，清理旧的原文件
        if ($final !== $dest && is_file($dest)) {
            @unlink($dest);
        }
        return $final;
    }

    /**
     * 校验扩展名与大小（上传白名单）
     *
     * @param list<string> $allowedExtensions 小写扩展名白名单（如 ['jpg','png']）；空数组表示不限制扩展名
     * @param int          $maxSizeBytes      最大字节数（0=不限制）
     */
    public function validate(array $allowedExtensions = [], int $maxSizeBytes = 0): bool
    {
        if (!$this->isValid()) {
            return false;
        }
        if ($allowedExtensions !== [] && !in_array($this->getExtension(), $allowedExtensions, true)) {
            return false;
        }
        if ($maxSizeBytes > 0 && $this->getSize() > $maxSizeBytes) {
            return false;
        }
        return true;
    }
}
