<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Mail;

/**
 * 邮件传输契约
 *
 * 任何传输方式（SMTP、Array 内存、日志等）都实现本接口，
 * 让 Mail 门面与具体发送渠道解耦，便于测试与替换。
 */
interface Transport
{
    /**
     * 发送一封邮件，成功返回 true
     */
    public function send(Message $message): bool;
}
