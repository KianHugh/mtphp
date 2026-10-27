<?php
declare(strict_types=1);

/**
 * 加密 Crypt + Session/Cookie 加密存储测试
 * 运行：php8 tests/run.php CryptTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Encryption\Crypt;
use Mtphp\Http\Session;
use Mtphp\Http\Response;
use Mtphp\Http\Request;

class CryptTest extends TestCase
{
    protected function setUp(): void
    {
        Crypt::setKey('test-app-key-32bytes-long-xxxxxxxxxx');
    }

    protected function tearDown(): void
    {
        Crypt::setKey(null);
    }

    public function testEncDecRoundTripArray(): void
    {
        $data = ['uid' => 1, 'role' => 'admin', 'tags' => ['a', 'b']];
        $s = Crypt::enc($data);
        Assert::assertTrue(is_string($s));
        $back = Crypt::dec($s);
        Assert::assertSame($data, $back);
    }

    public function testEncDecRoundTripScalar(): void
    {
        Assert::assertSame('hello', Crypt::dec(Crypt::enc('hello')));
        Assert::assertSame(123, Crypt::dec(Crypt::enc(123)));
    }

    public function testCiphertextTamperThrows(): void
    {
        $s = Crypt::enc(['a' => 1]);
        $bad = substr($s, 0, -4) . 'AAAA';
        $thrown = false;
        try {
            Crypt::dec($bad);
        } catch (\Throwable $e) {
            $thrown = true;
        }
        Assert::assertTrue($thrown, '篡改密文应抛异常');
    }

    public function testSessionFileEncrypted(): void
    {
        $dir = sys_get_temp_dir() . '/mtphp_crypt_sess_' . uniqid();
        mkdir($dir, 0755, true);

        $s = new Session('file', $dir);
        $s->set('user', ['id' => 7, 'name' => 'neo']);
        $s->save();

        // 落盘内容应以 enc: 开头（密文），而非明文序列化
        $file = $dir . '/' . $s->getId() . '.sess';
        Assert::assertTrue(is_file($file));
        $raw = file_get_contents($file);
        Assert::assertTrue(str_starts_with($raw, 'enc:'));
        // 校验的不是裸串 'neo'（base64 密文可能偶然包含该子串），而是带引号的序列化标记，
        // 明文序列化形如 s:3:"neo"，而 base64 字母表不含双引号，故该检查对密文零误判。
        Assert::assertFalse(str_contains($raw, '"neo"'), '会话文件不应出现明文用户名');

        // 新会话实例应能读回
        $s2 = new Session('file', $dir);
        $s2->setId($s->getId());
        $s2->start();
        Assert::assertSame(7, $s2->get('user')['id']);

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testEncryptedCookieRoundTrip(): void
    {
        $resp = (new Response())->eCookie('token', 's3cr3t');
        $stored = $resp->getCookies()['token']['value'];
        Assert::assertTrue(str_starts_with($stored, 'enc:'));

        $req = new Request();
        $req->setCookies(['token' => $stored]);
        Assert::assertSame('s3cr3t', $req->cookie('token'));
    }
}
