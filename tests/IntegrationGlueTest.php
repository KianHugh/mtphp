<?php
declare(strict_types=1);

use Mtphp\Auth\Jwt;
use Mtphp\Pagination\Paginator;
use Mtphp\Storage\LocalDriver;
use Mtphp\Storage\StorageInterface;
use Mtphp\Storage\StorageManager;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

/**
 * 集成胶水层测试：Storage 抽象 / JWT / 统一 API 响应
 * （纯单元，不依赖 App 引导；云驱动用 extend 注册测试替身隔离）
 */
class IntegrationGlueTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mtphp_glue_' . uniqid('', true);
        mkdir($this->tmp, 0755, true);
        StorageManager::reset();
        StorageManager::extend('test', fn() => new LocalDriver($this->tmp, '/up'));
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmp);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    // ---------------- Storage ----------------

    public function testLocalDriverPutGet(): void
    {
        $disk = new LocalDriver($this->tmp, '/up');
        Assert::assertTrue($disk->put('dir/a.txt', 'hello'));
        Assert::assertSame('hello', $disk->get('dir/a.txt'));
        Assert::assertTrue($disk->exists('dir/a.txt'));
        Assert::assertSame(5, $disk->size('dir/a.txt'));
        Assert::assertSame('/up/dir/a.txt', $disk->url('dir/a.txt'));
        Assert::assertNull($disk->get('missing.txt'));
        Assert::assertFalse($disk->exists('missing.txt'));
        Assert::assertTrue($disk->delete('dir/a.txt'));
        Assert::assertFalse($disk->exists('dir/a.txt'));
    }

    public function testLocalDriverMime(): void
    {
        $disk = new LocalDriver($this->tmp, '/up');
        $disk->put('x.json', '{}');
        Assert::assertNotNull($disk->mime('x.json'));
        Assert::assertNull($disk->mime('nope.json'));
    }

    public function testManagerResolveAndExtend(): void
    {
        $disk = StorageManager::resolve()->disk('test');
        Assert::assertNotNull($disk);
        Assert::assertTrue($disk instanceof StorageInterface);
        $disk->put('b.txt', 'world');
        Assert::assertSame('world', $disk->get('b.txt'));
    }

    public function testStorageHelper(): void
    {
        $disk = storage('test');
        Assert::assertTrue($disk instanceof StorageInterface);
        $disk->put('c.txt', 'via-helper');
        Assert::assertSame('via-helper', storage('test')->get('c.txt'));
    }

    // ---------------- JWT ----------------

    public function testJwtHs256Roundtrip(): void
    {
        $jwt = new Jwt();
        $payload = ['uid' => 7, 'role' => 'admin', 'extra' => ['a' => 1]];
        $token = $jwt->encode($payload, 'secret-key');
        $decoded = $jwt->decode($token, 'secret-key');
        Assert::assertNotNull($decoded);
        Assert::assertSame(7, $decoded['uid']);
        Assert::assertSame('admin', $decoded['role']);
        Assert::assertSame(['a' => 1], $decoded['extra']);
    }

    public function testJwtTamperedSignatureFails(): void
    {
        $jwt = new Jwt();
        $token = $jwt->encode(['uid' => 1], 'secret-key');
        $bad = $token . 'x';
        Assert::assertNull($jwt->decode($bad, 'secret-key'));
    }

    public function testJwtWrongSecretFails(): void
    {
        $jwt = new Jwt();
        $token = $jwt->encode(['uid' => 1], 'secret-key');
        Assert::assertNull($jwt->decode($token, 'another-secret'));
    }

    public function testJwtExpiredFails(): void
    {
        $jwt = new Jwt();
        $token = $jwt->encode(['uid' => 1, 'exp' => time() - 10], 'secret-key');
        Assert::assertNull($jwt->decode($token, 'secret-key'));
    }

    public function testJwtNbfFutureFails(): void
    {
        $jwt = new Jwt();
        $token = $jwt->encode(['uid' => 1, 'nbf' => time() + 60], 'secret-key');
        Assert::assertNull($jwt->decode($token, 'secret-key'));
    }

    public function testJwtInvalidFormatFails(): void
    {
        $jwt = new Jwt();
        Assert::assertNull($jwt->decode('not-a-jwt', 'secret-key'));
        Assert::assertNull($jwt->decode('a.b', 'secret-key'));
    }

    public function testJwtRs256Roundtrip(): void
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha256',
            'bits'             => 2048,
        ]);
        if ($res === false) {
            Assert::assertTrue(true, 'openssl 不可用，跳过 RS256 测试');
            return;
        }
        openssl_pkey_export($res, $private);
        $public = openssl_pkey_get_details($res)['key'];

        $jwt = new Jwt();
        $payload = ['uid' => 99, 'scope' => 'api'];
        $token = $jwt->encode($payload, $private, 'RS256');
        $decoded = $jwt->decode($token, $public, 'RS256');
        Assert::assertNotNull($decoded);
        Assert::assertSame(99, $decoded['uid']);
        Assert::assertSame('api', $decoded['scope']);

        // 用错误公钥校验应失败
        openssl_pkey_export(openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha256',
            'bits'             => 2048,
        ]), $wrongPrivate);
        $wrongPublic = openssl_pkey_get_details(openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha256',
            'bits'             => 2048,
        ]))['key'];
        Assert::assertNull($jwt->decode($token, $wrongPublic, 'RS256'));
    }

    public function testJwtHelper(): void
    {
        $token = jwt()->encode(['uid' => 3], 'secret-key');
        Assert::assertNotNull(jwt()->decode($token, 'secret-key'));
    }

    // ---------------- 统一 API 响应 ----------------

    public function testApiSuccessEnvelope(): void
    {
        $resp = api_success(['name' => '张三'], '获取成功');
        Assert::assertSame(200, $resp->getStatus());
        $arr = json_decode($resp->getBody(), true);
        Assert::assertSame(0, $arr['code']);
        Assert::assertSame('获取成功', $arr['message']);
        Assert::assertSame(['name' => '张三'], $arr['data']);
    }

    public function testApiErrorEnvelope(): void
    {
        $resp = api_error('参数错误', 1001);
        Assert::assertSame(400, $resp->getStatus());
        $arr = json_decode($resp->getBody(), true);
        Assert::assertSame(1001, $arr['code']);
        Assert::assertSame('参数错误', $arr['message']);
    }

    public function testApiErrorCustomHttp(): void
    {
        $resp = api_error('未登录', 401, null, 401);
        Assert::assertSame(401, $resp->getStatus());
        $arr = json_decode($resp->getBody(), true);
        Assert::assertSame(401, $arr['code']);
    }

    public function testApiPaginateEnvelope(): void
    {
        $p = new Paginator(
            [['id' => 1], ['id' => 2]],
            20,
            2,
            1
        );
        $resp = api_paginate($p);
        Assert::assertSame(200, $resp->getStatus());
        $arr = json_decode($resp->getBody(), true);
        Assert::assertSame(0, $arr['code']);
        Assert::assertSame([['id' => 1], ['id' => 2]], $arr['data']);
        Assert::assertSame(20, $arr['meta']['total']);
        Assert::assertSame(2, $arr['meta']['per_page']);
        Assert::assertSame(10, $arr['meta']['last_page']);
    }
}
