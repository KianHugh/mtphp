<?php
declare(strict_types=1);

/**
 * 限流 Throttle 中间件测试
 * 运行：php8 tests/run.php ThrottleTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Http\Middleware\Throttle;
use Mtphp\Cache\CacheInterface;

class ArrayCache implements CacheInterface
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

    public function lock(string $name, int $seconds = 0, ?string $owner = null): \Mtphp\Cache\Lock
    {
        return new \Mtphp\Cache\Lock($this, $name, $seconds, $owner);
    }
}

class ThrottleTest extends TestCase
{
    public function testAllowsWithinLimit(): void
    {
        $t = new Throttle(3, 60, new ArrayCache());
        $next = static fn (Request $r): Response => (new Response())->json(['ok' => 1]);
        $req  = new Request();

        for ($i = 0; $i < 3; $i++) {
            $resp = $t->handle($req, $next);
            Assert::assertSame(200, $resp->getStatus());
        }
    }

    public function testBlocksAfterLimit(): void
    {
        $t = new Throttle(3, 60, new ArrayCache());
        $next = static fn (Request $r): Response => (new Response())->json(['ok' => 1]);
        $req  = new Request();

        for ($i = 0; $i < 3; $i++) {
            $t->handle($req, $next);
        }
        $resp = $t->handle($req, $next);
        Assert::assertSame(429, $resp->getStatus());
        Assert::assertSame('60', $resp->getHeaders()['Retry-After']);

        $body = $resp->getBody();
        Assert::assertTrue(str_contains((string) $body, '请求过于频繁'));
    }

    public function testRateLimitHeadersOnPass(): void
    {
        $t = new Throttle(5, 60, new ArrayCache());
        $next = static fn (Request $r): Response => (new Response())->json(['ok' => 1]);
        $req  = new Request();

        $resp = $t->handle($req, $next);
        Assert::assertSame('5', $resp->getHeaders()['X-RateLimit-Limit']);
        Assert::assertSame('4', $resp->getHeaders()['X-RateLimit-Remaining']);
    }
}
