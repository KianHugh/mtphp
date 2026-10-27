<?php
declare(strict_types=1);

/**
 * API 令牌 Token + AuthApi 中间件测试
 * 运行：php8 tests/run.php TokenTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Auth\Token;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Http\Middleware\AuthApi;
use Mtphp\Cache\CacheInterface;

class TokenArrayCache implements CacheInterface
{
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->data = [];
        return true;
    }

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        return $this->data[$key] = $callback();
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $v = $this->data[$key] ?? $default;
        unset($this->data[$key]);
        return $v;
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (array_key_exists($key, $this->data)) {
            return false;
        }
        $this->data[$key] = $value;
        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    public function increment(string $key, int $step = 1): int
    {
        $next = (int) ($this->data[$key] ?? 0) + $step;
        $this->data[$key] = $next;
        return $next;
    }

    public function decrement(string $key, int $step = 1): int
    {
        return $this->increment($key, -$step);
    }

    public function lock(string $key, int $seconds = 0, ?string $owner = null): \Mtphp\Cache\Lock
    {
        return new \Mtphp\Cache\Lock($this, $key, $seconds, $owner);
    }
}

class TokenTest extends TestCase
{
    protected function setUp(): void
    {
        Token::setStore(new TokenArrayCache());
    }

    protected function tearDown(): void
    {
        Token::setStore(null);
    }

    public function testIssueAndValidate(): void
    {
        $t = Token::mk(1, 3600);
        $p = Token::chk($t);
        Assert::assertNotNull($p);
        Assert::assertSame(1, $p['uid']);
        Assert::assertSame(1, Token::uid($t));
    }

    public function testTamperedTokenFails(): void
    {
        $t = Token::mk(1, 3600);
        $bad = substr($t, 0, -2) . 'XX';
        Assert::assertNull(Token::chk($bad));
    }

    public function testRevokeToken(): void
    {
        $t = Token::mk(1, 3600);
        Assert::assertNotNull(Token::chk($t));
        Token::rm($t);
        Assert::assertNull(Token::chk($t));
    }

    public function testRevokeByUser(): void
    {
        $t1 = Token::mk(7, 3600);
        $t2 = Token::mk(7, 3600);
        Token::rmByUser(7);
        Assert::assertNull(Token::chk($t1));
        Assert::assertNull(Token::chk($t2));
    }

    public function testAuthApiMiddleware(): void
    {
        $token = Token::mk(42, 3600);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $req = Request::capture();

        $hit = false;
        $next = static function (Request $r) use (&$hit): Response {
            $hit = true;
            Assert::assertSame(42, $r->authUser()['uid']);
            return (new Response())->json(['ok' => 1]);
        };

        $resp = (new AuthApi())->handle($req, $next);
        Assert::assertTrue($hit, '鉴权通过应放行');
        Assert::assertSame(200, $resp->getStatus());
    }

    public function testAuthApiRejectsInvalid(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bad.token';
        $req = Request::capture();
        $hit = false;
        $next = static function (Request $r) use (&$hit): Response {
            $hit = true;
            return (new Response())->json(['ok' => 1]);
        };
        $resp = (new AuthApi())->handle($req, $next);
        Assert::assertFalse($hit, '鉴权失败应拦截');
        Assert::assertSame(401, $resp->getStatus());
    }
}
