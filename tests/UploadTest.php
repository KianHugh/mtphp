<?php
declare(strict_types=1);

/**
 * 文件上传组件测试（零依赖）
 * 运行：php8 tests/run.php UploadTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Http\UploadedFile;
use Mtphp\Http\Request;

class UploadTest extends TestCase
{
    private string $workDir;

    private array $created = [];

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/mtphp_upload_test_' . uniqid('', true);
        @mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // 递归清理
        $rm = function (string $dir) use (&$rm): void {
            if (!is_dir($dir)) {
                return;
            }
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_dir($f)) {
                    $rm($f);
                } else {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        };
        $rm($this->workDir);
    }

    /**
     * 造一个“源文件”，返回其路径
     */
    private function makeSource(string $name, string $content): string
    {
        $p = $this->workDir . '/' . $name;
        file_put_contents($p, $content);
        $this->created[] = $p;
        return $p;
    }

    public function testUploadedFileMetaAndMove(): void
    {
        $src = $this->makeSource('photo.png', 'PNGDATA');
        $file = new UploadedFile($src, 'photo.png', 'image/png', UPLOAD_ERR_OK, filesize($src));

        Assert::assertTrue($file->isValid());
        Assert::assertSame('png', $file->getExtension());
        Assert::assertSame('photo.png', $file->getClientOriginalName());
        Assert::assertSame('image/png', $file->getClientMimeType());
        Assert::assertSame(strlen('PNGDATA'), $file->getSize());

        $destDir = $this->workDir . '/dest';
        $dest = $file->move($destDir, 'stored.png');
        Assert::assertTrue($dest !== '', 'move 应返回目标路径');
        Assert::assertTrue(is_file($dest));
        Assert::assertSame('PNGDATA', file_get_contents($dest));
        // 移动后源文件已被移走（非 is_uploaded_file 走 rename）
        Assert::assertFalse(is_file($src));
    }

    public function testUploadedFileInvalidWhenError(): void
    {
        $src = $this->makeSource('x.txt', 'x');
        $file = new UploadedFile($src, 'x.txt', null, UPLOAD_ERR_NO_FILE, 0);
        Assert::assertFalse($file->isValid());
        Assert::assertSame('', $file->move($this->workDir . '/nope'));
    }

    public function testValidateExtensionAndSize(): void
    {
        $src = $this->makeSource('a.jpg', str_repeat('a', 100));
        $ok  = new UploadedFile($src, 'a.jpg', 'image/jpeg', UPLOAD_ERR_OK, 100);
        Assert::assertTrue($ok->validate(['jpg', 'png'], 0), '白名单命中应通过');
        Assert::assertFalse($ok->validate(['png'], 0), '扩展名不在白名单应失败');
        Assert::assertFalse($ok->validate(['jpg'], 50), '超过大小上限应失败');
    }

    public function testRequestFileAccessorSingle(): void
    {
        $src = $this->makeSource('avatar.png', 'IMG');
        $_FILES = [
            'avatar' => [
                'name'     => 'avatar.png',
                'type'     => 'image/png',
                'tmp_name' => $src,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($src),
            ],
        ];
        $req = Request::capture();
        $file = $req->file('avatar');
        Assert::assertTrue($file instanceof UploadedFile);
        Assert::assertSame('png', $file->getExtension());

        // files() 无参返回全部
        $all = $req->files();
        Assert::assertTrue(isset($all['avatar']));
    }

    public function testRequestFileAccessorMultiple(): void
    {
        $s1 = $this->makeSource('a.png', 'A');
        $s2 = $this->makeSource('b.jpg', 'B');
        $_FILES = [
            'images' => [
                'name'     => ['a.png', 'b.jpg'],
                'type'     => ['image/png', 'image/jpeg'],
                'tmp_name' => [$s1, $s2],
                'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size'     => [filesize($s1), filesize($s2)],
            ],
        ];
        $req = Request::capture();
        $files = $req->files('images');
        Assert::assertTrue(is_array($files));
        Assert::assertSame(2, count($files));
        Assert::assertTrue($files[0] instanceof UploadedFile);
        Assert::assertSame('png', $files[0]->getExtension());
        Assert::assertSame('jpg', $files[1]->getExtension());

        // file() 返回首个
        $first = $req->file('images');
        Assert::assertTrue($first instanceof UploadedFile);
    }

    public function testRequestFileMissingReturnsNull(): void
    {
        $_FILES = [];
        $req = Request::capture();
        Assert::assertNull($req->file('nonexistent'));
    }
}
