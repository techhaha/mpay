<?php
declare(strict_types=1);

namespace app\service\system\install;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 首次 CLI 初始化的原生输入边界；密码通过私有文件提供，不接受已有 KEY、密文或任意数据库参数。 */
final class InitializationValidator extends Validate
{
    protected $rule = [
        'username' => ['require', 'string', 'max' => 64, 'plainText'],
        'password' => ['require', 'string', 'min' => 12, 'passwordBytes'],
        'password_confirmation' => ['require', 'string', 'confirm' => 'password'],
        'app_name' => ['require', 'string', 'max' => 128, 'plainText'],
        'pid' => ['require', 'string', 'regex' => '/^[1-9][0-9]{0,31}$/D'],
    ];

    /**
     * @param array<string,mixed> $input 私有 JSON 根对象；pid 可省略，由服务随机生成，提供时不能为 null。
     * @return array{username:string,password:string,password_confirmation:string,app_name:string,pid?:string}
     */
    public function validateInput(array $input): array
    {
        if (
            array_diff(array_keys($input), array_keys($this->rule)) !== [] ||
            array_diff(
                ['username', 'password', 'password_confirmation', 'app_name'],
                array_keys($input),
            ) !== []
        ) {
            throw new BusinessException(
                '初始化输入字段不完整或包含未知字段',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $validator = (clone $this)->only(array_keys($input))->failException(false);
        if (!$validator->check($input)) {
            throw new BusinessException(
                '初始化参数无效；密码至少 12 个字符且最多 72 字节，确认值须一致',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return $input;
    }

    protected function plainText(string $value): bool
    {
        return trim($value) !== '' &&
            mb_check_encoding($value, 'UTF-8') &&
            !preg_match('/[\x00-\x1f\x7f]/', $value);
    }
    protected function passwordBytes(string $value): bool
    {
        return strlen($value) <= 72 &&
            mb_check_encoding($value, 'UTF-8') &&
            !str_contains($value, "\0");
    }
}
