<?php
declare(strict_types=1);

namespace Mtphp\Exception;

/**
 * 模型未找到异常
 *
 * 路由模型绑定（Route::bind）解析失败时抛出，由 App 统一映射为 404 响应。
 */
class ModelNotFoundException extends HttpException
{
    public function __construct(string $message = 'Model not found', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, $code, $previous);
    }
}
