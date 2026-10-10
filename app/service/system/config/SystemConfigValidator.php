<?php
declare(strict_types=1);

namespace app\service\system\config;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 系统设置的固定原生场景；只接收已定义公开字段，不提供任意配置键、类名、路径或 PHP 内容的编辑入口。 */
final class SystemConfigValidator extends Validate
{
    protected $rule = [
        'order_valid_seconds' => ['jsonInteger', 'between' => [1, 86400]],
        'technical_log_days' => ['jsonInteger', 'between' => [7, 365]],
    ];
    protected $scene = [
        'payment_defaults' => ['order_valid_seconds'],
        'maintenance' => ['technical_log_days'],
    ];

    /** @return list<string> 只由本程序固定的组名组成，调用者不能按请求拼接数据库键。 */
    public function groups(): array { return array_keys($this->scene); }

    /**
     * @param array<string,mixed> $values 完整组对象；缺失/null/字符串数字不能隐式补默认。
     * @return array<string,int|list<string>> 已核验原始类型的公开配置；域名只规范为小写，列表顺序保持。
     */
    public function values(string $group, array $values): array
    {
        $fields = $this->scene[$group] ?? throw new BusinessException('不支持的系统设置组', ApiResponseConstant::VALIDATION_FAILED);
        if (array_diff(array_keys($values), $fields) !== [] || array_diff($fields, array_keys($values)) !== []) {
            throw new BusinessException('请提交该设置组的完整字段，不能包含额外配置', ApiResponseConstant::VALIDATION_FAILED);
        }
        $validator = (clone $this)->scene($group)->batch()->failException(false);
        $validator->must = $fields;
        $validator->message(array_fill_keys($fields, '字段类型或范围无效'));
        if (!$validator->check($values)) {
            $errors = [];
            foreach ($validator->getError(true) as $field => $message) { $errors[$field] = [$message]; }
            throw new BusinessException('系统设置参数无效', ApiResponseConstant::VALIDATION_FAILED, $errors);
        }
        return $values;
    }

    protected function jsonInteger(mixed $value): bool { return is_int($value); }

}
