<?php
declare(strict_types=1);

namespace Mtphp\Exception;

/**
 * 业务异常：携带「业务码 + HTTP 状态码 + 可选附带数据」，供 App 统一渲染为 JSON 信封。
 *
 * 与框架内置的 HttpException（仅状态码 + 消息）的区别：
 *  - HttpException 的「码」即 HTTP 状态码（如 404/500）；
 *  - ApiException 额外区分「业务码」(code，前端用来分支判断) 与「HTTP 状态码」(status)。
 *
 * 用法：
 *   throw new ApiException('余额不足', 4001, 400, ['need' => 100]);
 *   // → App 渲染为 {"code":4001,"message":"余额不足","data":{"need":100},"request_id":"...", 状态码 400
 *
 * 若未显式给业务码，默认取 HTTP 状态码（getCode()）。
 */
class ApiException extends HttpException
{
    /** @var mixed 随异常返回的额外数据（如缺额、跳转地址等） */
    protected mixed $data;

    /**
     * @param string          $message      业务消息
     * @param int             $businessCode 业务码（前端分支用），默认等于 $httpStatus
     * @param int             $httpStatus   HTTP 状态码（最终响应行）
     * @param mixed           $data         随响应返回的附加数据
     * @param \Throwable|null $previous
     */
    public function __construct(
        string     $message = '',
        int        $businessCode = 0,
        int        $httpStatus = 400,
        mixed      $data = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($httpStatus, $message, $businessCode === 0 ? $httpStatus : $businessCode, $previous);
        $this->data = $data;
    }

    /** 业务码（前端用来分支判断，区别于 HTTP 状态码） */
    public function getBusinessCode(): int
    {
        return $this->getCode();
    }

    /** 随异常返回的附加数据 */
    public function getData(): mixed
    {
        return $this->data;
    }
}
