<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Cache;

/**
 * 纯 PHP Redis 连接（基于 stream_socket，零依赖，无需 ext-redis / predis）
 *
 *  - 懒连接：首次发命令才建连（CacheManager::resolve() 不会主动连）。
 *  - 支持 AUTH / SELECT；连接失败抛 RuntimeException 供上层感知。
 *  - 协议收发走 Resp（RESP），读取时按“不完整响应”增量补齐。
 *
 * 注：在 Swoole 常驻模式下，stream_socket 为阻塞 IO，可用但不享受协程切换；
 * 与框架 HTTP 客户端（curl）取舍一致——优先零依赖与开箱即用。
 */
class RedisConnector
{
    /** @var resource|null */
    protected $sock = null;

    public function __construct(
        protected string $host = '127.0.0.1',
        protected int $port = 6379,
        protected int $db = 0,
        protected ?string $password = null,
        protected float $timeout = 2.0
    ) {
    }

    public function connect(): void
    {
        if ($this->sock !== null) {
            return;
        }
        $sock = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout
        );
        if ($sock === false) {
            throw new \RuntimeException("Redis 连接失败：{$errstr} ({$errno})");
        }
        stream_set_timeout($sock, (int) ceil($this->timeout));
        $this->sock = $sock;

        if ($this->password !== null && $this->password !== '') {
            $this->command('AUTH', $this->password);
        }
        if ($this->db !== 0) {
            $this->command('SELECT', (string) $this->db);
        }
    }

    /**
     * 发送一条命令并解析响应（变长参数即为命令各段）
     *
     * @return mixed
     */
    public function command(string ...$args)
    {
        if ($this->sock === null) {
            $this->connect();
        }
        $wire = Resp::encode($args);
        $written = 0;
        $total = strlen($wire);
        while ($written < $total) {
            $n = @fwrite($this->sock, substr($wire, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Redis 写入失败');
            }
            $written += $n;
        }
        return $this->readReply();
    }

    /**
     * 增量读取直到收齐一条完整响应
     *
     * @return mixed
     */
    protected function readReply()
    {
        $buf = '';
        while (true) {
            $chunk = @fread($this->sock, 4096);
            if ($chunk === false) {
                throw new \RuntimeException('Redis 读取失败');
            }
            $buf .= $chunk;
            try {
                return Resp::decode($buf);
            } catch (RespIncomplete $e) {
                // 数据没收齐：若连接已关闭则报错，否则继续读
                if ($chunk === '') {
                    throw new \RuntimeException('Redis 响应不完整且连接已关闭');
                }
                continue;
            }
        }
    }

    public function close(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->sock !== null;
    }
}
