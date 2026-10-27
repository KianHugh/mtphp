<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Mail;

/**
 * 内存传输（测试 / 本地开发用）
 *
 * 不真正发送，仅把邮件收进静态数组，便于单元测试断言
 * “是否发送、收件人 / 主题 / 正文是否正确”。
 */
class ArrayTransport implements Transport
{
    /** @var list<array{to:string,subject:string,body:string,raw:string}> */
    public static array $sent = [];

    public function send(Message $message): bool
    {
        self::$sent[] = [
            'to'      => $message->to,
            'subject' => $message->subject,
            'body'    => $message->body,
            'raw'     => $message->toRaw(),
        ];
        return true;
    }

    /**
     * 清空已发送记录（测试之间隔离用）
     */
    public static function flush(): void
    {
        self::$sent = [];
    }
}
