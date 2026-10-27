<?php
declare(strict_types=1);

namespace Mtphp\Exception;

/**
 * HTTP 异常：携带状态码，供 App 统一转换为响应
 */
class HttpException extends \RuntimeException
{
    protected int $statusCode;

    public function __construct(int $statusCode, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
