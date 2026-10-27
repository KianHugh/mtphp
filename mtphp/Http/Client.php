<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Http;

/**
 * 轻量 HTTP 客户端（零依赖，底层 curl；思想借鉴主流框架的 HTTP 客户端）
 *
 * 风格：fluent 链式，常用调用开箱即用：
 *   http('https://api.example.com')
 *       ->withToken($token)
 *       ->asJson()
 *       ->get('/users', ['page' => 1]);
 *
 * 测试友好：通过 setTransport() 注入假传输层，无需真实网络即可验证请求构建与响应解析。
 */
class Client
{
    protected string $baseUrl = '';

    /** @var array<string,string> */
    protected array $headers = [];

    /** @var array<string,mixed> 底层 curl 选项（超时、SSL 校验等） */
    protected array $options = [
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
    ];

    /** @var 'json'|'form'|'' */
    protected string $contentType = '';

    /** @var array<string,mixed> 待发送数据（GET 时转 query，其余按 contentType 编码） */
    protected array $pendingData = [];

    /** @var callable|null 传输层（测试可替换） */
    protected $transport = null;

    /** @var callable|null 并发传输层（测试可替换）；签名见 executeMulti() */
    protected $multiTransport = null;

    /** @var int 最大尝试次数（含首次；1 = 不重试） */
    protected int $retries = 1;

    /** @var int 重试之间的延迟（毫秒） */
    protected int $retryDelayMs = 0;

    public function __construct(string $baseUrl = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * 注入自定义传输层（用于离线测试）。
     * 签名：function(string $method, string $url, array $opts): array{0:int,1:array<string,string>,2:string}
     */
    public function setTransport(callable $transport): self
    {
        $this->transport = $transport;
        return $this;
    }

    /**
     * 注入并发传输层（用于离线测试）。生产默认走 curl_multi。
     * 签名：function(array $prepared): array
     *   $prepared 为 [ [$url, $opts], ... ]（与 concurrent() 入参同序）
     *   须返回   [ [$status, $headers, $body], ... ]（同样同序）
     */
    public function setMultiTransport(callable $multiTransport): self
    {
        $this->multiTransport = $multiTransport;
        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function baseUrl(string $url): self
    {
        $this->baseUrl = rtrim($url, '/');
        return $this;
    }

    /**
     * @param array<string,string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function withHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * 携带 Authorization Bearer / Basic 令牌
     */
    public function withToken(string $token, string $type = 'Bearer'): self
    {
        $this->headers['Authorization'] = $type . ' ' . $token;
        return $this;
    }

    /**
     * 声明以 JSON 收发（设置 Content-Type 并标记编码方式）
     */
    public function asJson(): self
    {
        $this->contentType = 'json';
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $this;
    }

    /**
     * 声明以表单（application/x-www-form-urlencoded）发送
     */
    public function asForm(): self
    {
        $this->contentType = 'form';
        $this->headers['Content-Type'] = 'application/x-www-form-urlencoded';
        return $this;
    }

    /**
     * 设置超时（秒）
     */
    public function timeout(int $seconds): self
    {
        $this->options[CURLOPT_TIMEOUT] = $seconds;
        return $this;
    }

    /**
     * 设置连接超时（秒）
     */
    public function connectTimeout(int $seconds): self
    {
        $this->options[CURLOPT_CONNECTTIMEOUT] = $seconds;
        return $this;
    }

    /**
     * 设置失败重试：最多尝试 $attempts 次（含首次），每次间隔 $delayMs 毫秒。
     *
     * 仅对“传输层失败”（curl 返回状态码 0 / 抛异常）重试；HTTP 业务状态码（如 500）不重试。
     */
    public function retry(int $attempts, int $delayMs = 0): self
    {
        $this->retries      = max(1, $attempts);
        $this->retryDelayMs = max(0, $delayMs);
        return $this;
    }

    /**
     * 合并底层 curl 选项
     *
     * @param array<int,mixed> $opts
     */
    public function withOptions(array $opts): self
    {
        $this->options = $opts + $this->options;
        return $this;
    }

    public function get(string $path, array $query = []): ClientResponse
    {
        return $this->send('GET', $path, $query);
    }

    public function post(string $path, array $data = []): ClientResponse
    {
        return $this->send('POST', $path, $data);
    }

    public function put(string $path, array $data = []): ClientResponse
    {
        return $this->send('PUT', $path, $data);
    }

    public function delete(string $path, array $data = []): ClientResponse
    {
        return $this->send('DELETE', $path, $data);
    }

    public function patch(string $path, array $data = []): ClientResponse
    {
        return $this->send('PATCH', $path, $data);
    }

    /**
     * 执行请求；GET 的 $data 作为 query，其余作为 body。
     */
    public function send(string $method, string $path, array $data = []): ClientResponse
    {
        $url = $this->buildUrl($path);
        [$u, $opts] = $this->prepare($method, $url, $data);

        $attempts = max(1, $this->retries);
        $last     = [0, [], ''];

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $last = $this->transport !== null
                    ? ($this->transport)(strtoupper($method), $u, $opts)
                    : $this->curl($u, $opts);
            } catch (\Throwable $e) {
                // 传输层异常（DNS / 连接重置等）→ 视为失败，进入重试
                $last = [0, [], ''];
            }
            // 状态码 > 0 视为成功拿到响应（含 4xx/5xx），不再重试
            if ((int) $last[0] > 0) {
                break;
            }
            if ($i < $attempts && $this->retryDelayMs > 0) {
                usleep($this->retryDelayMs * 1000);
            }
        }

        return new ClientResponse($last[0], $last[1], $last[2]);
    }

    /**
     * 并发执行多个请求（curl_multi），返回与入参同序的 ClientResponse 数组。
     *
     * 每个请求 $requests 元素支持三种形态：
     *   - string：直接作为路径/URL，用 GET 发送（沿用本客户端 headers/options）
     *   - array ：['url'=>, 'method'=>, 'data'=>, 'headers'=>, 'options'=>]（headers/options 局部覆盖）
     *   - Client：取其 baseUrl 作为 URL（仅 GET）
     *
     * 注意：并发请求不支持单请求的 retry/transport（走全局 multiTransport 或 curl_multi）。
     *
     * @return list<ClientResponse>
     */
    public function concurrent(array $requests): array
    {
        $prepared = [];
        foreach ($requests as $req) {
            if (is_string($req)) {
                [$u, $opts] = $this->prepare('GET', $this->buildUrl($req), []);
            } elseif ($req instanceof self) {
                [$u, $opts] = $this->prepare('GET', $req->getBaseUrl(), []);
            } elseif (is_array($req)) {
                $method = $req['method'] ?? 'GET';
                $url    = $req['url'] ?? '';
                $url    = preg_match('#^https?://#i', $url) ? $url : $this->buildUrl($url);
                [$u, $opts] = $this->prepare(
                    $method,
                    $url,
                    $req['data'] ?? [],
                    $req['headers'] ?? [],
                    $req['options'] ?? []
                );
            } else {
                throw new \RuntimeException('concurrent() 入参须为 string / array / Client');
            }
            $prepared[] = [$u, $opts];
        }

        $raw = $this->multiTransport !== null
            ? ($this->multiTransport)($prepared)
            : $this->executeMulti($prepared);

        return array_map(
            static fn ($r) => new ClientResponse($r[0], $r[1], $r[2]),
            $raw
        );
    }

    /**
     * 用 curl_multi 并发跑多个已准备好的请求，返回同序的 [$status,$headers,$body]。
     *
     * @param array<int,array{0:string,1:array<int,mixed>}> $prepared
     * @return array<int,array{0:int,1:array<string,string>,2:string}>
     */
    protected function executeMulti(array $prepared): array
    {
        $mh      = curl_multi_init();
        $handles = [];
        foreach ($prepared as $i => [$u, $opts]) {
            $ch = curl_init($u);
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $i => $ch) {
            $body   = (string) curl_multi_getcontent($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $results[$i] = [$status, [], $body];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    /**
     * 按 method/url/data/headers/options 整备 curl 选项（单请求与并发共用）。
     *
     * @param array<string,string> $extraHeaders
     * @param array<int,mixed>     $extraOptions
     * @return array{0:string,1:array<int,mixed>}
     */
    protected function prepare(string $method, string $url, array $data, array $extraHeaders = [], array $extraOptions = []): array
    {
        $opts    = $extraOptions + $this->options;
        $headers = $extraHeaders === [] ? $this->headers : array_merge($this->headers, $extraHeaders);

        if (strtoupper($method) === 'GET') {
            if ($data !== []) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
            }
        } else {
            $body = $this->encodeBody($data);
            if ($body !== null && $body !== '') {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
        }

        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        if ($hdr !== []) {
            $opts[CURLOPT_HTTPHEADER] = $hdr;
        }
        $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);

        return [$url, $opts];
    }

    /**
     * 拼接完整 URL
     */
    protected function buildUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * 按 contentType 编码请求体
     */
    protected function encodeBody(array $data): ?string
    {
        if ($data === []) {
            return null;
        }
        if ($this->contentType === 'json') {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        if ($this->contentType === 'form') {
            return http_build_query($data);
        }
        // 未显式声明时，默认按 JSON 编码（最常见）
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 默认传输层：curl
     *
     * @param array<int,mixed> $opts
     * @return array{0:int,1:array<string,string>,2:string}
     */
    protected function curl(string $url, array $opts): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $body    = (string) curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $rawHead = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        curl_close($ch);

        // 解析响应头（优先用 CURLOPT_HEADER 捕获；这里简化为从 body 分离）
        $headers = [];
        if (($opts[CURLOPT_HEADER] ?? false) && $body !== '') {
            [$head, $body] = $this->splitHeaders($body);
            $headers = $this->parseHeaders($head);
        }
        return [$status, $headers, $body];
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function splitHeaders(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) {
            return [$raw, ''];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 4)];
    }

    /**
     * @return array<string,string>
     */
    protected function parseHeaders(string $raw): array
    {
        $out = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $out[trim(strtolower($k))] = trim($v);
            }
        }
        return $out;
    }
}
