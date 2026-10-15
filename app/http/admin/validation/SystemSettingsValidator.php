<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use stdClass;
use think\Validate;

/** 系统设置 HTTP 外层字段；组内规则由共享 SystemConfigValidator 处理，版本/操作者不会从任意表单字段推导。 */
final class SystemSettingsValidator extends Validate
{
    protected $rule = [
        'group' => ['require', 'string', 'in' => ['payment_defaults', 'maintenance', 'page_display']],
        'expected_version' => ['jsonInteger', 'between' => [0, 4294967294]],
        'values' => ['jsonObject'],
    ];

    /** @param array<string,mixed> $query 无参数读入口也不接受指定环境、任意配置组或文件路径。 */
    public function emptyQuery(array $query): void
    {
        if ($query !== []) { throw new BusinessException('此设置查询不接受额外字段', ApiResponseConstant::VALIDATION_FAILED); }
    }

    /**
     * @param array<string,mixed> $input 原始 JSON 对象的字段，values 仍须为 stdClass。
     * @return array{group:string,expected_version:int,values:array<string,mixed>}
     */
    public function save(array $input): array
    {
        if (array_diff(array_keys($input), array_keys($this->rule)) !== [] || array_diff(array_keys($this->rule), array_keys($input)) !== []) {
            throw new BusinessException('系统设置正文缺少字段或包含未知字段', ApiResponseConstant::VALIDATION_FAILED);
        }
        $validator = clone $this;
        $validator->append(['expected_version' => 'require', 'values' => 'require']);
        if (!$validator->failException(false)->check($input)) { throw new BusinessException('系统设置正文类型或范围无效', ApiResponseConstant::VALIDATION_FAILED); }
        $input['values'] = get_object_vars($input['values']);
        return $input;
    }

    protected function jsonInteger(mixed $value): bool { return is_int($value); }
    protected function jsonObject(mixed $value): bool { return $value instanceof stdClass; }
}
