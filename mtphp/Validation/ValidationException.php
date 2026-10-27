<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Validation;

use RuntimeException;

/**
 * 校验失败异常（validate() 助手在校验不通过时抛出）
 */
class ValidationException extends RuntimeException
{
    /** @var array<string,list<string>> */
    protected array $errors;

    /**
     * @param array<string,list<string>> $errors
     */
    public function __construct(array $errors, string $message = '校验失败')
    {
        $this->errors = $errors;
        parent::__construct($message);
    }

    /**
     * @return array<string,list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 首条错误信息
     */
    public function firstError(): ?string
    {
        foreach ($this->errors as $list) {
            if ($list !== []) {
                return $list[0];
            }
        }
        return null;
    }
}
