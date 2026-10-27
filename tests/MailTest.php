<?php
declare(strict_types=1);

/**
 * 邮件 Mail 组件测试
 * 运行：php tests/run.php MailTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Mail\Message;
use Mtphp\Mail\ArrayTransport;
use Mtphp\Mail\Mail;
use Mtphp\Mail\Mailer;
use Mtphp\Mail\SmtpTransport;

class MailTest extends TestCase
{
    protected function setUp(): void
    {
        ArrayTransport::flush();
        Mail::setTransport(null);
    }

    protected function tearDown(): void
    {
        ArrayTransport::flush();
        Mail::setTransport(null);
    }

    public function testMessageEncodesNonAsciiSubject(): void
    {
        $m = new Message('to@x.com');
        $m->subject('中文主题');
        $m->text('hello');
        $raw = $m->toRaw();

        Assert::assertContains($raw, 'To: to@x.com');
        Assert::assertContains($raw, '=?UTF-8?B?');
        Assert::assertContains($raw, 'Content-Type: text/plain; charset=utf-8');
    }

    public function testMessageEscapesLeadingDotInBody(): void
    {
        $m = new Message('to@x.com');
        $m->text("line\n.one starts with dot\nend");
        $raw = $m->toRaw();
        // 行首的孤点应被转义成双点
        Assert::assertContains($raw, '..one starts with dot');
    }

    public function testArrayTransportCapturesSentMail(): void
    {
        Mail::setTransport(new ArrayTransport());
        $ok = Mail::to('a@b.com')->subject('Hi')->text('world')->send();

        Assert::assertTrue($ok);
        Assert::assertSame(1, count(ArrayTransport::$sent));
        Assert::assertSame('a@b.com', ArrayTransport::$sent[0]['to']);
        Assert::assertSame('Hi', ArrayTransport::$sent[0]['subject']);
        Assert::assertSame('world', ArrayTransport::$sent[0]['body']);
    }

    public function testRawQuickSend(): void
    {
        Mail::setTransport(new ArrayTransport());
        Mail::raw('x@y.com', 'Subj', 'Body');

        Assert::assertSame(1, count(ArrayTransport::$sent));
        Assert::assertSame('x@y.com', ArrayTransport::$sent[0]['to']);
        Assert::assertSame('Subj', ArrayTransport::$sent[0]['subject']);
    }

    public function testHtmlContentType(): void
    {
        $mailer = new Mailer('z@z.com');
        $mailer->subject('s')->html('<b>hi</b>');
        Assert::assertContains($mailer->getMessage()->toRaw(), 'Content-Type: text/html; charset=utf-8');
    }

    public function testFallbackToArrayTransportWithoutApp(): void
    {
        // 无 App 上下文时，Mail::transport() 应回退到 ArrayTransport
        $t = Mail::transport();
        Assert::assertTrue($t instanceof ArrayTransport);
    }

    /**
     * 脚本化 SMTP 响应（无真实网络）：覆盖底层 IO 接缝，按 SMTP 顺序回放响应，
     * 并捕获所有发出的命令，验证命令/响应链路完整正确。
     */
    public function testSmtpTransportConversation(): void
    {
        $t = new ScriptedSmtpTransport([
            'host'       => '127.0.0.1',
            'port'       => 25,
            'encryption' => '',
            'username'   => 'user',
            'password'   => 'pass',
            'from'       => 'from@x.com',
        ]);

        $m = new Message('rcpt@x.com');
        $m->subject('SMTP Test')->text('payload');
        $ok = $t->send($m);

        Assert::assertTrue($ok);

        $w = $t->written();
        // 鉴权链路
        Assert::assertContains($w, 'AUTH LOGIN');
        Assert::assertContains($w, base64_encode('user'));
        Assert::assertContains($w, base64_encode('pass'));
        // 发件 / 收件
        Assert::assertContains($w, 'MAIL FROM:<from@x.com>');
        Assert::assertContains($w, 'RCPT TO:<rcpt@x.com>');
        // DATA 正文应包含主题与内容
        Assert::assertContains($w, 'Subject: SMTP Test');
        Assert::assertContains($w, 'payload');
        Assert::assertTrue($t->closed());
    }

    public function testSmtpTransportThrowsOnConnectFailure(): void
    {
        $t = new SmtpTransport([
            'host'    => '127.0.0.1',
            'port'    => 1,          // 极 unlikely 开放，制造连接失败
            'timeout' => 2,
        ]);
        $m = new Message('a@b.com');
        $m->text('x');
        Assert::assertException(static fn () => $t->send($m), \RuntimeException::class);
    }
}

/**
 * 脚本化 SMTP 传输：把底层 socket 替换为按序回放的内存响应，
 * 让 SmtpTransport 的“命令→响应”逻辑可在单进程内完整验证（无需真实网络 / proc_open）。
 */
class ScriptedSmtpTransport extends SmtpTransport
{
    /** @var list<string> 预先排好序的响应队列 */
    private array $responses = [];

    /** @var string 捕获的全部写出内容 */
    private string $buffer = '';

    private bool $closed = false;

    protected function rawOpen(string $host, int $port, int $timeout, bool $ssl): void
    {
        // 预置整段响应序列（与 SmtpTransport::send 的读取顺序一一对应）
        $this->responses = [
            "220 fake ESMTP mtphp-test\r\n",          // 就绪横幅
            "250-fake\r\n",                            // EHLO 续行
            "250 AUTH LOGIN\r\n",                      // EHLO 末行
            "334 " . base64_encode('Username:') . "\r\n", // AUTH LOGIN 挑战
            "334 " . base64_encode('Password:') . "\r\n", // 用户名后挑战
            "235 Authenticated\r\n",                   // 密码后鉴权成功
            "250 OK\r\n",                              // MAIL FROM
            "250 OK\r\n",                              // RCPT TO
            "354 End data with .\r\n",                 // DATA
            "250 Queued\r\n",                          // 终止点
            "221 Bye\r\n",                             // QUIT
        ];
        $this->conn = fopen('php://memory', 'r+');
    }

    protected function rawRead()
    {
        $next = array_shift($this->responses);
        return $next === null ? false : $next;
    }

    protected function rawWrite(string $data): void
    {
        $this->buffer .= $data;
    }

    protected function rawCrypto(): bool
    {
        return true;
    }

    protected function rawClose(): void
    {
        if (is_resource($this->conn)) {
            fclose($this->conn);
        }
        $this->conn = null;
        $this->closed = true;
    }

    public function written(): string
    {
        return $this->buffer;
    }

    public function closed(): bool
    {
        return $this->closed;
    }
}
