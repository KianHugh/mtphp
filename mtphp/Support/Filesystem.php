<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 文件 / 路径工具（零依赖）
 *
 * 覆盖：路径拼接与规范化、按扩展名判定 MIME、人类可读大小、目录确保、读写、临时名。
 * 与框架已有的 UploadedFile / ImageCompressor 互补，统一落盘相关操作。
 *
 * 用法：
 *   Filesystem::join('/var', 'www', 'file.txt');   // /var/www/file.txt
 *   Filesystem::humanSize(1234567);                // 1.18 MB
 *   Filesystem::ensureDir($dir);
 *   Filesystem::put($path, $content);
 *   Filesystem::tempName(sys_get_temp_dir(), 'up');
 */
class Filesystem
{
    /**
     * 拼接路径（统一为正斜杠，保留绝对路径的前导 /）
     */
    public static function join(string ...$parts): string
    {
        $lead  = '';
        $segs  = [];
        foreach ($parts as $p) {
            $p = str_replace('\\', '/', (string) $p);
            if ($p === '') {
                continue;
            }
            if ($lead === '' && str_starts_with($p, '/')) {
                $lead = '/';
            }
            $p = trim($p, '/');
            if ($p !== '') {
                $segs[] = $p;
            }
        }
        return $lead . implode('/', $segs);
    }

    /**
     * 规范化路径（反斜杠转正斜杠，合并连续斜杠）
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return (string) preg_replace('#/{2,}#', '/', $path);
    }

    /**
     * 按扩展名返回 MIME 类型（未知返回 application/octet-stream）
     */
    public static function mimeFromExt(string $ext): string
    {
        $ext = strtolower(ltrim($ext, '.'));
        $map = [
            'txt'  => 'text/plain',
            'htm'  => 'text/html',
            'html' => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            'csv'  => 'text/csv',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'bmp'  => 'image/bmp',
            'ico'  => 'image/x-icon',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            'gz'   => 'application/gzip',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'wav'  => 'audio/wav',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * 把字节数转为人类可读大小（B/KB/MB/GB/TB/PB）
     */
    public static function humanSize(float|int $bytes, int $decimals = 2): string
    {
        $bytes = (float) $bytes;
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i     = min((int) floor(log($bytes, 1024)), count($units) - 1);
        return sprintf('%.' . $decimals . 'f %s', $bytes / (1024 ** $i), $units[$i]);
    }

    /**
     * 确保目录存在（递归创建）
     */
    public static function ensureDir(string $dir, int $mode = 0755): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, $mode, true) || is_dir($dir);
    }

    /**
     * 读取文件（失败返回 false）
     */
    public static function get(string $path): string|false
    {
        if (!is_file($path)) {
            return false;
        }
        return @file_get_contents($path);
    }

    /**
     * 写入文件（自动创建父目录），成功返回 true
     */
    public static function put(string $path, string $contents, int $mode = 0644): bool
    {
        $dir = dirname($path);
        if (!self::ensureDir($dir)) {
            return false;
        }
        if (@file_put_contents($path, $contents) === false) {
            return false;
        }
        @chmod($path, $mode);
        return true;
    }

    /**
     * 生成唯一临时文件路径（不创建文件）
     */
    public static function tempName(?string $dir = null, string $prefix = 'tmp'): string
    {
        $dir = $dir ?? sys_get_temp_dir();
        self::ensureDir($dir);
        return self::normalize($dir . '/' . $prefix . uniqid() . bin2hex(random_bytes(4)));
    }

    public static function exists(string $path): bool
    {
        return file_exists($path);
    }

    public static function isFile(string $path): bool
    {
        return is_file($path);
    }

    public static function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    /**
     * 删除文件或目录（目录递归删除）
     *
     * @param string|string[] $paths
     */
    public static function delete(string|array $paths): bool
    {
        $ok = true;
        foreach ((array) $paths as $path) {
            if (is_dir($path)) {
                $items = scandir($path) ?: [];
                foreach ($items as $it) {
                    if ($it === '.' || $it === '..') {
                        continue;
                    }
                    if (!self::delete($path . '/' . $it)) {
                        $ok = false;
                    }
                }
                if (!@rmdir($path)) {
                    $ok = false;
                }
            } elseif (is_file($path)) {
                if (!@unlink($path)) {
                    $ok = false;
                }
            }
        }
        return $ok;
    }

    /**
     * 复制文件（自动创建目标父目录）
     */
    public static function copy(string $from, string $to): bool
    {
        if (!is_file($from)) {
            return false;
        }
        self::ensureDir(dirname($to));
        return @copy($from, $to);
    }

    /**
     * 移动 / 重命名文件
     */
    public static function move(string $from, string $to): bool
    {
        if (!is_file($from)) {
            return false;
        }
        self::ensureDir(dirname($to));
        return @rename($from, $to);
    }
}
