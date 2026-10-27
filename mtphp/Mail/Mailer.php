<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Mail;

/**
 * 待发送邮件构建器（流畅风格）
 *
 *   Mail::to('a@b.com')->subject('Hi')->text('hello')->send();
 *
 * 链式方法转发到 Message，最终 send() 交给 Transport 真正投递。
 */
class Mailer
{
    protected Message $message;

    public function __construct(string $to)
    {
        $this->message = new Message($to);
    }

    public function from(string $address): self
    {
        $this->message->from($address);
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->message->subject($subject);
        return $this;
    }

    public function text(string $body): self
    {
        $this->message->text($body);
        return $this;
    }

    public function html(string $body): self
    {
        $this->message->html($body);
        return $this;
    }

    /**
     * 附加自定义头（如 CC、Reply-To）
     *
     * @param array<string,string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $k => $v) {
            $this->message->headers[$k] = $v;
        }
        return $this;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    /**
     * 投递；不传 transport 时使用 Mail::transport() 解析的默认渠道
     */
    public function send(?Transport $transport = null): bool
    {
        $t = $transport ?? Mail::transport();
        return $t->send($this->message);
    }
}
