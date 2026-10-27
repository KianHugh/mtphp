<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Testing;

use Mtphp\Http\Response;

/**
 * 测试用响应断言器（思想借鉴主流框架的 TestResponse）
 *
 * 包装一次 HTTP 响应（Http\Response），提供可读的断言方法，
 * 让 HTTP / 控制器功能测试写起来更像“人话”：
 *
 *   $r = new TestResponse($response);
 *   $r->assertStatus(200)->assertJson(['ok' => true])->assertSee('hello');
 *
 * 也可不经真实 HTTP，直接对控制器返回的 Response 做断言。
 */
class TestResponse
{
    protected Response $response;

    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    public function response(): Response
    {
        return $this->response;
    }

    /** 取状态码 */
    public function status(): int
    {
        return $this->response->getStatus();
    }

    /** 取原始响应体 */
    public function body(): mixed
    {
        return $this->response->getBody();
    }

    /**
     * 把响应体当 JSON 解码（非 JSON 时断言失败）
     *
     * @return mixed
     */
    public function json()
    {
        $body  = (string) $this->response->getBody();
        $decoded = json_decode($body, true);
        if ($decoded === null && $body !== 'null' && $body !== '') {
            Assert::fail('响应体不是合法 JSON：' . $body);
        }
        return $decoded;
    }

    // ---- 断言（链式返回自身）----

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status());
        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    /**
     * 断言响应头存在且等于期望值
     */
    public function assertHeader(string $key, string $expected): self
    {
        $headers = $this->response->getHeaders();
        $key = strtolower($key);
        $found = null;
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $key) {
                $found = $v;
                break;
            }
        }
        Assert::assertNotNull($found, "响应缺少头：{$key}");
        Assert::assertSame($expected, $found);
        return $this;
    }

    /**
     * 断言响应体（字符串）包含给定子串
     */
    public function assertSee(string $needle): self
    {
        Assert::assertContains((string) $this->response->getBody(), $needle);
        return $this;
    }

    /**
     * 断言 JSON 响应包含期望的键（支持嵌套数组子集）
     *
     * @param array<string,mixed> $expected
     */
    public function assertJson(array $expected): self
    {
        $actual = $this->json();
        if (!is_array($actual)) {
            Assert::fail('JSON 响应不是数组，无法做子集断言');
        }
        $this->assertArraySubset($expected, $actual);
        return $this;
    }

    /**
     * 递归断言 $expected 是 $actual 的子集
     *
     * @param array<string,mixed> $expected
     * @param mixed $actual
     */
    protected function assertArraySubset(array $expected, $actual): void
    {
        foreach ($expected as $k => $v) {
            Assert::assertNotNull($actual[$k] ?? null, "JSON 缺少键：{$k}");
            if (is_array($v)) {
                $this->assertArraySubset($v, $actual[$k]);
            } else {
                Assert::assertEquals($v, $actual[$k]);
            }
        }
    }
}
