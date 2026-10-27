<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Http;

/**
 * HTTP 客户端响应封装
 */
class ClientResponse
{
    protected int $status;

    /** @var array<string,string> */
    protected array $headers;

    protected string $body;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status  = $status;
        $this->headers = $headers;
        $this->body    = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * 2xx 视为成功
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function successful(): bool
    {
        return $this->ok();
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * 解析 JSON 响应体
     *
     * @return mixed
     */
    public function json($default = null)
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
