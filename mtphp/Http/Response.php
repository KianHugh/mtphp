<?php
declare(strict_types=1);

namespace Mtphp\Http;

use Mtphp\Encryption\Crypt;

/**
 * 响应封装：状态行 / 头 / 体，send() 一次性输出（OPcache 友好）
 */
class Response
{
    protected mixed $body = '';

    protected int $status = 200;

    /** @var array<string,string> */
    protected array $headers;

    /** @var array<string,array{value:string,expire:int,options:array}> 待下发的 Cookie */
    protected array $cookies = [];

    public function __construct(mixed $body = '', int $status = 200, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;

        if (!isset($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = 'text/html; charset=utf-8';
        }
    }

    public function status(int $code): self
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * 下发一个 Cookie（经响应对象统一管理，send() 时随其它头一起发出）。
     *
     * @param array<string,mixed> $options 支持 path / domain / secure / httponly / samesite
     */
    public function cookie(string $name, string $value, int $expire = 0, array $options = []): self
    {
        $this->cookies[$name] = [
            'value'   => $value,
            'expire'  => $expire,
            'options' => $options,
        ];
        return $this;
    }

    /** @return array<string,array{value:string,expire:int,options:array}> */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * 下发“加密 Cookie”：值经 Crypt 加密后以 enc: 前缀存储，读取端自动解密。
     * 适合存敏感信息（令牌、用户标识等）；非敏感 Cookie 仍用 cookie() 明文下发。
     */
    public function eCookie(string $name, string $value, int $expire = 0, array $options = []): self
    {
        return $this->cookie($name, 'enc:' . Crypt::enc($value), $expire, $options);
    }

    public function body(mixed $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * 以 JSON 输出（支持数组 / 对象）
     */
    public function json(mixed $data, int $code = 200): self
    {
        // JSON_INVALID_UTF8_IGNORE：容忍 Windows 下部分扩展返回的 GBK 错误消息，避免编码失败返回空
        $this->body             = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        $this->status           = $code;
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $this;
    }

    /**
     * 重定向（302 默认，可指定 301 等）
     */
    public function redirect(string $url, int $code = 302): self
    {
        $this->status           = $code;
        $this->headers['Location'] = $url;
        $this->body             = '';
        return $this;
    }

    /**
     * 附带一次性 flash 数据（经 Session 落地，下次请求读取）
     */
    public function with(string $key, mixed $value): self
    {
        $app = \Mtphp\App::instance();
        $session = $app !== null ? $app->session() : null;
        if ($session !== null) {
            $session->flash($key, $value);
        }
        return $this;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
            foreach ($this->cookies as $name => $c) {
                $this->emitCookie($name, $c['value'], $c['expire'], $c['options']);
            }
        }
        echo is_string($this->body) ? $this->body : (string) $this->body;
    }

    /**
     * 按 RFC 6265 拼出并下发 Set-Cookie 头
     *
     * @param array<string,mixed> $options
     */
    protected function emitCookie(string $name, string $value, int $expire, array $options): void
    {
        $parts = [$name . '=' . urlencode($value)];
        if ($expire > 0) {
            $parts[] = 'Expires=' . gmdate('D, d-M-Y H:i:s T', $expire);
            $parts[] = 'Max-Age=' . max(0, $expire - time());
        }
        $parts[] = 'Path=' . ($options['path'] ?? '/');
        if (!empty($options['domain'])) {
            $parts[] = 'Domain=' . $options['domain'];
        }
        if (!empty($options['secure'])) {
            $parts[] = 'Secure';
        }
        if (!empty($options['httponly'])) {
            $parts[] = 'HttpOnly';
        }
        $samesite = $options['samesite'] ?? 'Lax';
        if ($samesite !== '') {
            $parts[] = 'SameSite=' . $samesite;
        }
        header('Set-Cookie: ' . implode('; ', $parts));
    }
}
