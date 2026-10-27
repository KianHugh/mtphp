<?php
declare(strict_types=1);

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Http\UploadedFile;
use Mtphp\Image\ImageCompressor;

/**
 * 图片上传自动压缩 / 缩放测试（GD 可用时真实压缩，不可用时用例跳过）
 */
class ImageCompressTest extends TestCase
{
    private string $outDir;

    /** @var array<int,string> 需清理的源文件 */
    private array $trash = [];

    protected function setUp(): void
    {
        $this->outDir = sys_get_temp_dir() . '/mtphp_ic_' . uniqid();
        @mkdir($this->outDir, 0755, true);
    }

    protected function tearDown(): void
    {
        UploadedFile::setGlobalCompressOptions(null);
        foreach ($this->trash as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->rmdir($this->outDir);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $p = $dir . '/' . $it;
            if (is_dir($p)) {
                $this->rmdir($p);
            } elseif (is_file($p)) {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private function gd(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    private function makeJpeg(int $w, int $h, string $path): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 210, 120, 60));
        for ($i = 0; $i < 50; $i++) {
            imagefilledrectangle(
                $im,
                rand(0, $w - 1), rand(0, $h - 1), rand(0, $w - 1), rand(0, $h - 1),
                imagecolorallocate($im, rand(0, 255), rand(0, 255), rand(0, 255))
            );
        }
        imagejpeg($im, $path, 95);
        imagedestroy($im);
    }

    private function makeGif(int $w, int $h, string $path): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 0, 128, 255));
        imagegif($im, $path);
        imagedestroy($im);
    }

    /** @return array{0:int,1:int} */
    private function dims(string $path): array
    {
        $s = getimagesize($path);
        return [$s[0], $s[1]];
    }

    private function srcJpeg(int $w = 1000, int $h = 1000): string
    {
        $src = $this->outDir . '/src_' . uniqid() . '.jpg';
        $this->makeJpeg($w, $h, $src);
        $this->trash[] = $src;
        return $src;
    }

    public function testMoveNoCompressionByDefault(): void
    {
        if (!$this->gd()) {
            return;
        }
        $src  = $this->srcJpeg(1000, 1000);
        $file = new UploadedFile($src, 'photo.jpg', 'image/jpeg', 0, filesize($src));
        $dest = $file->move($this->outDir);

        [$w, $h] = $this->dims($dest);
        Assert::assertSame(1000, $w);
        Assert::assertSame(1000, $h);
        Assert::assertNull($file->getLastError());
    }

    public function testMoveCompressExplicit(): void
    {
        if (!$this->gd()) {
            return;
        }
        $src  = $this->srcJpeg(1000, 1000);
        $file = new UploadedFile($src, 'photo.jpg', 'image/jpeg', 0, filesize($src));
        $dest = $file->move($this->outDir, null, ['max_width' => 100, 'max_height' => 100, 'quality' => 70]);

        [$w, $h] = $this->dims($dest);
        Assert::assertTrue($w <= 100 && $h <= 100, "期望缩放后 <=100，实际 {$w}x{$h}");
        Assert::assertNull($file->getLastError());
        Assert::assertTrue(str_ends_with($dest, '.jpg'));
    }

    public function testMoveExplicitFalseOverridesGlobal(): void
    {
        if (!$this->gd()) {
            return;
        }
        UploadedFile::setGlobalCompressOptions(['enabled' => true, 'max_width' => 50, 'max_height' => 50]);
        $src  = $this->srcJpeg(1000, 1000);
        $file = new UploadedFile($src, 'photo.jpg', 'image/jpeg', 0, filesize($src));
        $dest = $file->move($this->outDir, null, false); // 强制关闭

        [$w, $h] = $this->dims($dest);
        Assert::assertSame(1000, $w);
        Assert::assertSame(1000, $h);
    }

    public function testMoveUsesGlobalOptions(): void
    {
        if (!$this->gd()) {
            return;
        }
        UploadedFile::setGlobalCompressOptions(['enabled' => true, 'max_width' => 50, 'max_height' => 50, 'quality' => 60]);
        $src  = $this->srcJpeg(1000, 1000);
        $file = new UploadedFile($src, 'photo.jpg', 'image/jpeg', 0, filesize($src));
        $dest = $file->move($this->outDir); // 不传第三参，走全局

        [$w, $h] = $this->dims($dest);
        Assert::assertTrue($w <= 50 && $h <= 50, "期望全局配置缩放后 <=50，实际 {$w}x{$h}");
        Assert::assertNull($file->getLastError());
    }

    public function testAnimatedGifSkippedWhenKeep(): void
    {
        if (!$this->gd()) {
            return;
        }
        $src  = $this->outDir . '/src_' . uniqid() . '.gif';
        $this->makeGif(200, 200, $src);
        $this->trash[] = $src;

        $file = new UploadedFile($src, 'anim.gif', 'image/gif', 0, filesize($src));
        $dest = $file->move($this->outDir, null, ['format' => 'keep']);

        Assert::assertTrue(str_ends_with($dest, '.gif'), '动画 GIF 应保持原格式');
        [$w, $h] = $this->dims($dest);
        Assert::assertSame(200, $w);
        Assert::assertNull($file->getLastError());
    }

    public function testNonImagePassthroughOnCompressFailure(): void
    {
        $src  = $this->outDir . '/doc_' . uniqid() . '.txt';
        file_put_contents($src, 'not an image');
        $this->trash[] = $src;

        $file = new UploadedFile($src, 'doc.txt', 'text/plain', 0, filesize($src));
        $dest = $file->move($this->outDir, null, ['max_width' => 10]);

        Assert::assertTrue(is_file($dest), '非图片压缩失败应保留原文件');
        Assert::assertNotNull($file->getLastError(), '压缩失败应记录 lastError');
    }

    public function testCompressorDirectWebp(): void
    {
        if (!$this->gd() || !function_exists('imagewebp')) {
            return;
        }
        $src = $this->srcJpeg(800, 600);
        $c   = new ImageCompressor();
        $out = $this->outDir . '/webp_' . uniqid() . '.webp';
        $result = $c->compress($src, $out, ['format' => 'webp', 'max_width' => 50]);

        Assert::assertTrue($result !== false, 'WebP 压缩应返回最终路径');
        Assert::assertTrue(is_file($out));
        [$w, $h] = $this->dims($out);
        Assert::assertTrue($w <= 50 && $h <= 50);
    }

    public function testCompressorMissingFileThrows(): void
    {
        $c = new ImageCompressor();
        Assert::assertException(
            fn () => $c->compress('/no/such/file.jpg', '/tmp/x.jpg'),
            \RuntimeException::class
        );
    }

    public function testCompressorBadTypeThrows(): void
    {
        $src = $this->outDir . '/bad_' . uniqid() . '.txt';
        file_put_contents($src, 'hello');
        $this->trash[] = $src;
        $c = new ImageCompressor();
        Assert::assertException(
            fn () => $c->compress($src, $this->outDir . '/x.jpg'),
            \RuntimeException::class
        );
    }
}
