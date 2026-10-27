<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Mail;

use Mtphp\App;

/**
 * 邮件发送门面（静态风格，思想借鉴主流框架 Mail 门面）
 *
 *   Mail::to('a@b.com')->subject('Hi')->text('hello')->send();
 *   Mail::raw('a@b.com', 'Hi', 'hello');           // 一行快速发送
 *
 * 无 App 上下文（如纯单元测试）时回退到 ArrayTransport，便于脱离配置验证。
 */
class Mail
{
    /** @var Transport|null 可注入的自定义传输（测试 / 特殊渠道） */
    protected static ?Transport $transport = null;

    /**
     * 创建一封待发送邮件构建器
     */
    public static function to(string $to): Mailer
    {
        return new Mailer($to);
    }

    /**
     * 一行式快速发送（纯文本）
     */
    public static function raw(string $to, string $subject, string $body): bool
    {
        return self::to($to)->subject($subject)->text($body)->send();
    }

    /**
     * 解析默认传输渠道：优先注入值，否则按 mail.php 配置构造（SMTP 或 Array）。
     */
    public static function transport(): Transport
    {
        if (self::$transport !== null) {
            return self::$transport;
        }

        $app = App::instance();
        $cfg = $app !== null ? $app->config()->get('mail', []) : [];
        $cfg = is_array($cfg) ? $cfg : [];

        $driver = (string) ($cfg['driver'] ?? 'array');
        if ($driver === 'smtp') {
            self::$transport = new SmtpTransport($cfg);
        } else {
            self::$transport = new ArrayTransport();
        }
        return self::$transport;
    }

    /**
     * 注入自定义传输（测试 / 特殊渠道）
     */
    public static function setTransport(?Transport $transport): void
    {
        self::$transport = $transport;
    }
}
