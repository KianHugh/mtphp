<?php
declare(strict_types=1);

namespace Mtphp;

use Mtphp\Database\Pool;
use Mtphp\Database\CoroutineConnection;
use Mtphp\Database\DB;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Exception\HttpException;

/**
 * Swoole 常驻 HTTP 服务器引导（高并发模式）
 *
 * 与 FPM 的本质区别：进程常驻，App 实例与连接池在 worker 启动时一次性构建，
 * 之后每请求只做「取连接 → 处理 → 还连接」，避免反复引导与建连开销。
 *
 * 运行前提：安装 Swoole 扩展，并在 Linux/WSL 上执行（Windows 不支持 Swoole）。
 *   php bin/server.php            # 默认 0.0.0.0:9501
 *   APP_HOST=127.0.0.1 APP_PORT=9502 php bin/server.php
 *
 * 请求级隔离：每个请求从连接池借用一条连接、存入「协程级上下文」，请求结束归还，
 * 因此同一 worker 的多条并发协程互不干扰（连接不跨协程共享）。
 */
class Server
{
    protected string $root;

    protected App $app;

    /** @var array<string,Pool> 连接池（按连接名） */
    protected array $pools = [];

    /** @var array<int,mixed> 协程级当前连接（cid => Connector） */
    protected static array $coroutineConn = [];

    /** 服务器对象（不 type-hint Swoole 类，便于无 Swoole 环境加载本文件） */
    protected $server = null;

    protected string $host;

    protected int $port;

    public function __construct(string $root, string $host = '0.0.0.0', int $port = 9501)
    {
        if (!extension_loaded('swoole')) {
            throw new \RuntimeException('Swoole 扩展未安装，无法启动常驻服务器（请在 Linux/WSL 上安装 swoole 扩展）。');
        }
        $this->root = rtrim($root, '/\\');
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * 启动服务器（阻塞）
     */
    public function start(): void
    {
        $this->server = new \Swoole\Http\Server($this->host, $this->port);

        $this->server->on('workerStart', function () {
            $this->onWorkerStart();
        });

        $this->server->on('request', function ($req, $resp) {
            $this->onRequest($req, $resp);
        });

        fwrite(STDERR, sprintf(
            "[mtphp] Swoole HTTP Server listening on http://%s:%d (PHP %s, Swoole %s)\n",
            $this->host, $this->port, PHP_VERSION, swoole_version()
        ));
        $this->server->start();
    }

    /**
     * Worker 启动：构建 App、连接池、DB 解析器（每个 worker 独立一份，常驻复用）
     */
    protected function onWorkerStart(): void
    {
        // 常驻 App：配置/路由/容器只构建一次
        $this->app = new App($this->root);

        $config = $this->app->config()->get('database.connections', []);
        foreach ($config as $name => $cfg) {
            $pool = new Pool($cfg);
            $pool->fill((int) ($cfg['pool']['idle'] ?? 10));
            $this->pools[$name] = $pool;
        }

        // 注册 DB 解析器：从「协程级当前连接」取连（连接池供给，不跨协程缓存）
        DB::setResolver(function (): CoroutineConnection {
            $cid = \Swoole\Coroutine::getCid();
            $conn = self::$coroutineConn[$cid] ?? null;
            if (!$conn instanceof CoroutineConnection) {
                throw new \RuntimeException('当前协程未借用数据库连接');
            }
            return $conn;
        });
    }

    /**
     * 处理单个请求（协程内）
     */
    protected function onRequest(object $req, object $resp): void
    {
        \Swoole\Coroutine\run(function () use ($req, $resp) {
            $cid = \Swoole\Coroutine::getCid();
            $pool = $this->pools[$this->app->config()->get('database.default', 'mysql')] ?? null;

            $raw = $pool !== null ? $pool->get(2.0) : null;
            if ($raw === null) {
                $resp->status(503);
                $resp->end('Service Unavailable: no database connection available');
                return;
            }

            $prefix = (string) ($pool->config()['prefix'] ?? '');
            $conn   = new CoroutineConnection($raw, $prefix);
            self::$coroutineConn[$cid] = $conn;

            try {
                $request  = Request::fromSwoole($req);
                $response = $this->app->handle($request);
                $this->emit($resp, $response);
            } catch (\Throwable $e) {
                $this->emit($resp, $this->toErrorResponse($e));
            } finally {
                unset(self::$coroutineConn[$cid]);
                $pool->put($raw);
            }
        });
    }

    /**
     * 把框架 Response 写到 Swoole 响应对象
     */
    protected function emit(object $resp, Response $response): void
    {
        $resp->status($response->getStatus());
        foreach ($response->getHeaders() as $k => $v) {
            $resp->header($k, $v);
        }
        $body = $response->getBody();
        $resp->end(is_string($body) ? $body : (string) $body);
    }

    protected function toErrorResponse(\Throwable $e): Response
    {
        $code  = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $debug = (bool) $this->app->config()->get('app.debug', false);
        if ($debug) {
            $body = $e->getMessage() . "\n\n" . $e->getTraceAsString();
        } else {
            $body = $code === 404 ? '404 Not Found' : 'Internal Server Error';
        }
        return (new Response($body, $code))
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
