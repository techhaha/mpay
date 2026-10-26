<?php
declare(strict_types=1);

namespace app\service\system\config;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/**
 * 系统设置的固定原生场景；字段规则声明必填、类型和范围，所有组共用同一校验流程。
 * 只接收已定义字段，不提供任意配置键、类名、路径或 PHP 内容的编辑入口。
 */
final class SystemConfigValidator extends Validate
{
    protected $rule = [
        'order_valid_seconds' => ['require', 'jsonInteger', 'between' => [1, 86400]],
        'technical_log_days' => ['require', 'jsonInteger', 'between' => [7, 365]],
        'home_enabled' => ['require', 'jsonInteger', 'in' => [0, 1]],
        'about_enabled' => ['require', 'jsonInteger', 'in' => [0, 1]],
        'ttshitu_username' => ['requireString', 'max' => 128],
        'ttshitu_password' => ['requireString', 'max' => 256],
    ];
    protected $scene = [
        'payment_defaults' => ['order_valid_seconds'],
        'maintenance' => ['technical_log_days'],
        'page_display' => ['home_enabled', 'about_enabled'],
        'captcha' => ['ttshitu_username', 'ttshitu_password'],
    ];

    /** @return list<string> 只由本程序固定的组名组成，调用者不能按请求拼接数据库键。 */
    public function groups(): array
    {
        return array_keys($this->scene);
    }

    /**
     * @param array<string,mixed> $values 完整组对象；按字段声明校验原始类型，不补值或隐式转换。
     * @return array<string,int|string> 已核验原始类型的设置值，不隐式转换数值。
     */
    public function values(string $group, array $values): array
    {
        $fields =
            $this->scene[$group] ??
            throw new BusinessException(
                '不支持的系统设置组',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        if (
            array_diff(array_keys($values), $fields) !== [] ||
            array_diff($fields, array_keys($values)) !== []
        ) {
            throw new BusinessException(
                '请提交该设置组的完整字段，不能包含额外配置',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $validator = (clone $this)->scene($group)->batch()->failException(false);
        $validator->message(array_fill_keys($fields, '字段类型或范围无效'));
        if (!$validator->check($values)) {
            $errors = [];
            foreach ($validator->getError(true) as $field => $message) {
                $errors[$field] = [$message];
            }
            throw new BusinessException(
                '系统设置参数无效',
                ApiResponseConstant::VALIDATION_FAILED,
                $errors,
            );
        }
        return $values;
    }

    protected function jsonInteger(mixed $value): bool
    {
        return is_int($value);
    }

    /** 允许空字符串；require 前缀使框架对 null/空串也执行类型检查，不按具体配置项分支。 */
    protected function requireString(mixed $value): bool
    {
        return is_string($value);
    }
}
