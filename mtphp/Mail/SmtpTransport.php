<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Mail;

/**
 * SMTP 传输（纯 PHP socket，零扩展依赖）
 *
 * 支持：
 *  - 明文（25/587 等）与 TLS（STARTTLS，端口 587/465 可协商）
 *  - AUTH LOGIN 账号密码鉴权（可选）
 *  - 多收件人（to 以逗号分隔）
 *
 * 设计上不引入任何 PEAR / SwiftMailer / Symfony 依赖，完全自实现，
 * 以兼容“零依赖、可上 Swoole 协程”的框架定位。
 *
 * 底层 socket 读写收敛到 rawOpen / rawRead / rawWrite / rawCrypto / rawClose
 * 五个受保护方法，便于在测试中用脚本化响应替换（无需真实网络）。
 */
class SmtpTransport implements Transport
{
    /** @var array<string,mixed> 邮件配置 */
    protected array $config;

    /** @var resource|null 当前连接句柄 */
    protected $conn = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function send(Message $message): bool
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 25);
        $enc  = (string) ($this->config['encryption'] ?? '');
        $user = (string) ($this->config['username'] ?? '');
        $pass = (string) ($this->config['password'] ?? '');
        $timeout = (int) ($this->config['timeout'] ?? 10);

        $this->rawOpen($host, $port, $timeout > 0 ? $timeout : 10, $enc === 'ssl');

        try {
            // 读取服务就绪横幅
            $this->expect(220);

            $localhost = (string) ($this->config['local_domain'] ?? 'localhost');
            $this->talk('EHLO ' . $localhost, [250]);

            // 协商 STARTTLS（显式加密）
            if ($enc === 'tls') {
                $this->talk('STARTTLS', [220]);
                if (!$this->rawCrypto()) {
                    throw new \RuntimeException('SMTP STARTTLS 协商失败');
                }
                // TLS 重握手后再 EHLO 一次，获取鉴权能力
                $this->talk('EHLO ' . $localhost, [250]);
            }

            // 账号密码鉴权（AUTH LOGIN）
            if ($user !== '' && $pass !== '') {
                $this->talk('AUTH LOGIN', [334]);
                $this->talk(base64_encode($user), [334]);
                $this->talk(base64_encode($pass), [235]);
            }

            // 发件人
            $from = $message->from !== '' ? $message->from : ((string) ($this->config['from'] ?? 'no-reply@localhost'));
            $this->talk('MAIL FROM:<' . $from . '>', [250]);

            // 逐收件人
            $recipients = preg_split('/[,;]\s*/', $message->to) ?: [];
            foreach ($recipients as $rcpt) {
                $rcpt = trim($rcpt);
                if ($rcpt === '') {
                    continue;
                }
                $this->talk('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }

            // 邮件正文
            $this->talk('DATA', [354]);
            $this->rawWrite($message->toRaw());
            $this->talk('.', [250]);

            $this->talk('QUIT', [221]);
        } finally {
            $this->rawClose();
        }

        return true;
    }

    /**
     * 发送一行命令并校验期望响应码
     *
     * @param list<int> $expect
     */
    protected function talk(string $cmd, array $expect): string
    {
        $this->rawWrite($cmd . "\r\n");
        return $this->expect($expect);
    }

    /**
     * 读取 SMTP 响应（支持多行），校验首个三位码是否命中期望集合
     *
     * @param list<int>|int $expect
     */
    protected function expect($expect): string
    {
        $expect = (array) $expect;
        $data   = '';
        while (true) {
            $line = $this->rawRead();
            if ($line === false || $line === '') {
                break;
            }
            $data .= $line;
            // 响应码后跟空格表示是最后一行；跟 "-" 表示还有续行
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr(trim($data), 0, 3);
        if (!in_array($code, $expect, true)) {
            throw new \RuntimeException("SMTP 响应异常：期望 " . implode('/', $expect) . "，实际 {$code}：" . trim($data));
        }
        return $data;
    }

    // ---- 底层 IO 接缝（子类可覆盖以脚本化响应，便于测试）----

    /**
     * 建立连接；失败抛异常
     */
    protected function rawOpen(string $host, int $port, int $timeout, bool $ssl): void
    {
        $target = $ssl ? 'ssl://' . $host : $host;
        $fp = @fsockopen($target, $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            throw new \RuntimeException("SMTP 连接失败：{$host}:{$port} ({$errno} {$errstr})");
        }
        $this->conn = $fp;
    }

    /**
     * @return string|false
     */
    protected function rawRead()
    {
        return @fgets($this->conn, 515);
    }

    protected function rawWrite(string $data): void
    {
        $len = strlen($data);
        $off = 0;
        while ($off < $len) {
            $w = @fputs($this->conn, substr($data, $off));
            if ($w === false || $w === 0) {
                break;
            }
            $off += $w;
        }
    }

    protected function rawCrypto(): bool
    {
        return (bool) @stream_socket_enable_crypto($this->conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    protected function rawClose(): void
    {
        if (is_resource($this->conn)) {
            @fclose($this->conn);
        }
        $this->conn = null;
    }
}
