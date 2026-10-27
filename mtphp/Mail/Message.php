<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Mail;

/**
 * 邮件消息（零依赖，承载收发件人 / 主题 / 正文）
 */
class Message
{
    public string $from = '';

    public string $to;

    public string $subject = '';

    public string $body = '';

    /** text/plain | text/html */
    public string $contentType = 'text/plain';

    /** @var array<string,string> 额外头（如 CC、自定义头） */
    public array $headers = [];

    public function __construct(string $to)
    {
        $this->to = $to;
    }

    public function from(string $address): self
    {
        $this->from = $address;
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function text(string $body): self
    {
        $this->body        = $body;
        $this->contentType = 'text/plain';
        return $this;
    }

    public function html(string $body): self
    {
        $this->body        = $body;
        $this->contentType = 'text/html';
        return $this;
    }

    /**
     * 组装 RFC 822 邮件原文（供 SMTP DATA 发送）
     */
    public function toRaw(): string
    {
        $from = $this->from !== '' ? $this->from : 'no-reply@localhost';
        $lines = [];
        $lines[] = 'From: ' . $from;
        $lines[] = 'To: ' . $this->to;
        $lines[] = 'Subject: ' . $this->encodeSubject($this->subject);
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: ' . $this->contentType . '; charset=utf-8';
        foreach ($this->headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $lines[] = '';
        $lines[] = $this->body;
        // 统一换行为 CRLF（正文内可能仅含 LF），再转义行首孤点，
        // 避免正文里的“.”被 SMTP 当成 DATA 结束符。
        $raw = implode("\r\n", $lines);
        $raw = str_replace("\n", "\r\n", $raw);
        $raw = preg_replace('/\r\n\./', "\r\n..", $raw);
        return rtrim($raw, "\r\n") . "\r\n";
    }

    /**
     * 主题含非 ASCII 时做 MIME base64 编码（防止乱码）
     */
    protected function encodeSubject(string $subject): string
    {
        if (preg_match('/[^\x20-\x7E]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        return $subject;
    }
}
