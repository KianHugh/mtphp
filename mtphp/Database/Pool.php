<?php
declare(strict_types=1);

namespace Mtphp\Database;

/**
 * Swoole 协程 MySQL 连接池（常驻内存模式的核心）
 *
 *  - 仅在 Swoole 扩展存在时可用（available()===true）。
 *  - 基于 Swoole\Coroutine\Channel 实现有界队列：连接复用是高并发的关键，
 *    避免每请求新建连接带来的握手开销与数据库侧连接数爆炸。
 *  - FPM/CLI 模式下 available()===false，连接获取交由 Connection(PDO) 处理，
 *    此时本池不参与。
 *
 * 设计要点：
 *  - max    ：池上限（超过则请求协程阻塞等待空闲连接）。
 *  - idle   ：预热时建立的空闲连接数。
 *  - get()  ：池空且未达上限则即时新建，否则从队列 pop（带超时）。
 *  - put()  ：归还连接。
 */
class Pool
{
    /** @var array<string,mixed> */
    protected array $config;

    protected int $max;

    protected int $idle;

    /** 协程通道（Swoole 下才有值） */
    protected $channel = null;

    protected int $created = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->max  = (int) ($config['pool']['size'] ?? 16);
        $this->idle = (int) ($config['pool']['idle'] ?? 10);

        if (extension_loaded('swoole')) {
            // 不 type-hint Swoole 类，保证无 Swoole 环境也能加载本文件
            $this->channel = new \Swoole\Coroutine\Channel($this->max);
        }
    }

    /**
     * 当前进程是否处于可用（Swoole）连接池模式
     */
    public function available(): bool
    {
        return $this->channel !== null;
    }

    public function maxSize(): int
    {
        return $this->max;
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * 预热：预先建立若干空闲连接，降低首请求延迟
     */
    public function fill(int $n): void
    {
        if (!$this->available()) {
            return;
        }
        for ($i = 0; $i < $n && $this->created < $this->max; $i++) {
            $conn = $this->create();
            if ($conn !== null) {
                $this->channel->push($conn);
                $this->created++;
            }
        }
    }

    /**
     * 取一个连接：池空且未达上限则即时新建；否则从队列 pop（带超时）
     *
     * @return object|null 底层 Swoole\Coroutine\MySQL 连接，或 null（不可用/超时）
     */
    public function get(float $timeout = 1.0)
    {
        if (!$this->available()) {
            return null;
        }
        if ($this->channel->isEmpty() && $this->created < $this->max) {
            $conn = $this->create();
            if ($conn !== null) {
                $this->created++;
                return $conn;
            }
        }
        return $this->channel->pop($timeout);
    }

    /**
     * 归还连接（回收至队列）
     */
    public function put($conn): void
    {
        if ($this->available() && $conn) {
            $this->channel->push($conn);
        }
    }

    /**
     * 新建一个底层协程 MySQL 连接
     *
     * @return object|null
     */
    protected function create()
    {
        $mysql = new \Swoole\Coroutine\MySQL();
        $ok = $mysql->connect([
            'host'     => $this->config['host'] ?? '127.0.0.1',
            'port'     => (int) ($this->config['port'] ?? 3306),
            'user'     => $this->config['username'] ?? 'root',
            'password' => $this->config['password'] ?? '',
            'database' => $this->config['dbname'] ?? '',
            'charset'  => $this->config['charset'] ?? 'utf8mb4',
        ]);
        return $ok ? $mysql : null;
    }
}
