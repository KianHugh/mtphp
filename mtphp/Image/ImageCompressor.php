<?php
declare(strict_types=1);

namespace Mtphp\Image;

/**
 * 图片压缩 / 缩放服务（零依赖，底层 GD；独立实现，思想借鉴主流框架的 Image 组件）
 *
 * 设计原则：
 *  - 可选扩展：用 extension_loaded('gd') 守卫，GD 缺失时抛 RuntimeException，不破坏框架零依赖安装；
 *    GD 是 PHP 图片处理的事实标准，绝大多数运行环境已内置，属"软依赖"。
 *  - opt-in：默认不调用，仅在 UploadedFile::move() 传入压缩参数或 config('upload.compress.enabled') 开启时触发。
 *  - 安全降级：
 *      · 动画 GIF（format=keep）默认跳过压缩，原样保留（imagegif 只存首帧会丢动画）；
 *      · WebP 仅在 imagewebp 可用时生效，否则回退 jpeg；
 *      · EXIF 自动转正仅在 exif_read_data 可用且源为 jpeg 时处理；
 *      · GD 重新编码天然不保留 EXIF/IPTC，元数据总是被剥离。
 */
class ImageCompressor
{
    public const FORMAT_KEEP  = 'keep';
    public const FORMAT_JPEG = 'jpeg';
    public const FORMAT_PNG  = 'png';
    public const FORMAT_WEBP = 'webp';

    /**
     * 压缩 / 缩放源图并写入目标路径
     *
     * @param string $src    源文件路径（必须是 jpeg / png / gif / webp 位图）
     * @param string $dest   目标路径（format=keep 时沿用源扩展名；否则按目标格式改写扩展名）
     * @param array  $options 见 normalizeOptions；常用键：quality / max_width / max_height / format / auto_rotate
     * @return string|false 成功返回最终写出路径（可能因 format 改写扩展名）；动画 GIF（format=keep）跳过时返回 false（调用方据此保留原文件）
     * @throws \RuntimeException 当 GD 不可用、源不是可处理位图或写出失败时抛出
     */
    public function compress(string $src, string $dest, array $options = []): string|false
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('图片压缩需要 GD 扩展，当前环境未加载。');
        }
        if (!is_file($src)) {
            throw new \RuntimeException("源图片不存在：{$src}");
        }

        $opt  = $this->normalizeOptions($options);
        $type = $this->detectType($src);
        if ($type === null) {
            throw new \RuntimeException("无法识别的图片类型：{$src}");
        }

        // 动画 GIF 且保持原格式：跳过压缩，原样保留（避免丢帧）
        if ($type === IMAGETYPE_GIF && $opt['format'] === self::FORMAT_KEEP) {
            return false;
        }

        $srcImage = $this->createFrom($src, $type);
        if ($srcImage === false) {
            throw new \RuntimeException("读取图片失败：{$src}");
        }

        // EXIF 自动转正（仅 jpeg）
        if ($opt['auto_rotate'] && $type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $srcImage = $this->autoRotate($srcImage, $src);
        }

        $srcW = imagesx($srcImage);
        $srcH = imagesy($srcImage);
        [$dstW, $dstH] = $this->fit($srcW, $srcH, $opt['max_width'], $opt['max_height']);

        if ($dstW !== $srcW || $dstH !== $srcH) {
            $dstImage = imagecreatetruecolor($dstW, $dstH);
            if ($this->hasAlpha($type, $opt['format'])) {
                imagealphablending($dstImage, false);
                imagesavealpha($dstImage, true);
            }
            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($srcImage);
        } else {
            $dstImage = $srcImage;
        }

        $format = $opt['format'] === self::FORMAT_KEEP ? $this->typeToFormat($type) : $opt['format'];
        $out    = $this->withExtension($dest, $format);

        // 原子写出：先写临时文件再替换，避免写出失败污染原文件
        $tmp = $out . '.tmp.' . uniqid('', true);
        if (!$this->write($dstImage, $tmp, $format, $opt['quality'])) {
            imagedestroy($dstImage);
            @unlink($tmp);
            throw new \RuntimeException("写入压缩图片失败：{$out}");
        }
        imagedestroy($dstImage);
        @unlink($out);
        if (!@rename($tmp, $out) && is_file($tmp)) {
            @copy($tmp, $out);
        }
        @unlink($tmp);
        return $out;
    }

    /**
     * 合并默认值
     *
     * @return array{quality:int,max_width:int,max_height:int,format:string,auto_rotate:bool}
     */
    protected function normalizeOptions(array $options): array
    {
        $auto = $options['auto_rotate'] ?? true;
        return [
            'quality'     => $this->clamp((int) ($options['quality'] ?? 82), 0, 100),
            'max_width'   => max(0, (int) ($options['max_width'] ?? 0)),
            'max_height'  => max(0, (int) ($options['max_height'] ?? 0)),
            'format'      => strtolower((string) ($options['format'] ?? self::FORMAT_KEEP)),
            'auto_rotate' => $auto === true || $auto === 1 || $auto === '1',
        ];
    }

    protected function clamp(int $v, int $min, int $max): int
    {
        return min($max, max($min, $v));
    }

    /**
     * 探测图片类型（IMAGETYPE_* 常量），无法识别返回 null
     */
    protected function detectType(string $src): ?int
    {
        if (function_exists('exif_imagetype')) {
            $t = @exif_imagetype($src);
            if ($t !== false) {
                return $t;
            }
        }
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? @finfo_file($finfo, $src) : false;
            return match ($mime) {
                'image/jpeg' => IMAGETYPE_JPEG,
                'image/png'  => IMAGETYPE_PNG,
                'image/gif'  => IMAGETYPE_GIF,
                'image/webp' => IMAGETYPE_WEBP,
                default      => null,
            };
        }
        return null;
    }

    /**
     * 按类型创建 GD 资源
     */
    protected function createFrom(string $src, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_GIF  => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default        => false,
        };
    }

    /**
     * 目标是否含透明通道（需保留 alpha）
     */
    protected function hasAlpha(int $srcType, string $format): bool
    {
        $fmt = $format === self::FORMAT_KEEP ? $this->typeToFormat($srcType) : $format;
        return $fmt === self::FORMAT_PNG || $fmt === self::FORMAT_WEBP;
    }

    protected function typeToFormat(int $type): string
    {
        return match ($type) {
            IMAGETYPE_PNG  => self::FORMAT_PNG,
            IMAGETYPE_GIF  => self::FORMAT_JPEG, // GIF 无法无损缩放保留动画，转 jpeg 落盘（仅显式 format 触发）
            IMAGETYPE_WEBP => self::FORMAT_WEBP,
            default        => self::FORMAT_JPEG,
        };
    }

    /**
     * 等比缩放计算（max<=0 表示不限）
     *
     * @return array{0:int,1:int}
     */
    protected function fit(int $w, int $h, int $maxW, int $maxH): array
    {
        if (($maxW <= 0 || $w <= $maxW) && ($maxH <= 0 || $h <= $maxH)) {
            return [$w, $h];
        }
        $scale = 1.0;
        if ($maxW > 0 && $w > $maxW) {
            $scale = min($scale, $maxW / (float) $w);
        }
        if ($maxH > 0 && $h > $maxH) {
            $scale = min($scale, $maxH / (float) $h);
        }
        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    }

    /**
     * 按 EXIF Orientation 自动旋转 / 翻转
     */
    protected function autoRotate($image, string $src)
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($src);
        if (!is_array($exif) || !isset($exif['Orientation'])) {
            return $image;
        }
        $ort = (int) $exif['Orientation'];
        switch ($ort) {
            case 2: imageflip($image, IMG_FLIP_HORIZONTAL); break;
            case 3: $image = $this->rotate($image, 180); break;
            case 4: imageflip($image, IMG_FLIP_VERTICAL); break;
            case 5: imageflip($image, IMG_FLIP_HORIZONTAL); $image = $this->rotate($image, 270); break;
            case 6: $image = $this->rotate($image, 90); break;
            case 7: imageflip($image, IMG_FLIP_HORIZONTAL); $image = $this->rotate($image, 90); break;
            case 8: $image = $this->rotate($image, 270); break;
        }
        return $image;
    }

    protected function rotate($image, int $deg)
    {
        $rotated = @imagerotate($image, $deg, 0);
        if ($rotated !== false) {
            imagedestroy($image);
            return $rotated;
        }
        return $image;
    }

    /**
     * 改写目标扩展名以匹配格式
     */
    protected function withExtension(string $dest, string $format): string
    {
        $ext = match ($format) {
            self::FORMAT_PNG  => 'png',
            self::FORMAT_WEBP => 'webp',
            default           => 'jpg',
        };
        $info = pathinfo($dest);
        $dir  = ($info['dirname'] ?? '.');
        $name = ($info['filename'] ?? 'image');
        return rtrim($dir, '/\\') . '/' . $name . '.' . $ext;
    }

    /**
     * 按格式写出
     */
    protected function write($image, string $dest, string $format, int $quality): bool
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return match ($format) {
            self::FORMAT_PNG  => @imagepng($image, $dest, 9),
            self::FORMAT_WEBP => function_exists('imagewebp') ? @imagewebp($image, $dest, $quality) : false,
            default           => @imagejpeg($image, $dest, $quality),
        };
    }
}
