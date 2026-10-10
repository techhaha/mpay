<?php
declare(strict_types=1);

namespace app\exception;

/**
 * 输入校验、权限或业务条件冲突的公开异常合同，供 HTTP/协议边界转换为稳定错误响应。
 * 只接收可公开的说明和字段错误；底层异常放入 previous，不拼进 message。
 */
class BusinessException extends \RuntimeException
{
    /**
     * @param string $message 可公开的中文说明，不能拼入 SQL、秘密字段或上游原始响应。
     * @param int $code ApiResponseConstant 中已定义的应用码，不传 HTTP 状态裸值。
     * @param array<string, list<string>> $errors 字段到公开错误列表的映射，无字段错误时为空。
     */
    public function __construct(
        string $message,
        int $code,
        public readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
