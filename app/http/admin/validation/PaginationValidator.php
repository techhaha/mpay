<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 后台分页使用框架规则执行器；先验证十进制形态再转整数，避免隐式转换扩大查询。 */
final class PaginationValidator extends Validate
{
    /** @var array<string,array> 原生父类属性保持未声明类型，避免破坏框架继承签名。 */
    protected $rule = [
        'page' => ['require', 'formInteger', 'regex' => '/^[1-9][0-9]{0,6}$/D', 'between' => [1, 1000000]],
        'limit' => ['require', 'formInteger', 'regex' => '/^[1-9][0-9]{0,6}$/D', 'between' => [1, 100]],
    ];
    protected $message = ['page' => '必须为 1～1000000 的整数', 'limit' => '必须为 1～100 的整数'];

    /**
     * 只提取 page/limit；其他业务筛选字段由对应入口的校验器处理。
     *
     * @param array<string,mixed> $input 未提供分页值时采用默认值。
     * @return array{page:int,limit:int} 可直接交给查询和响应层的分页参数。
     * @throws BusinessException 形态或范围非法，按字段返回固定公开错误。
     */
    public function validate(array $input): array
    {
        $values = array_intersect_key($input, $this->rule) + ['page' => 1, 'limit' => 20];
        // 容器可复用实例；错误和 batch 状态只属于本次校验，不写回共享验证器。
        $validator = clone $this;
        if (!$validator->batch()->failException(false)->check($values)) {
            $errors = array_map(static fn (string $message): array => [$message], $validator->getError(true));
            throw new BusinessException('请求参数校验失败', ApiResponseConstant::VALIDATION_FAILED, $errors);
        }
        return ['page' => (int) $values['page'], 'limit' => (int) $values['limit']];
    }

    /** 原生 integer 接受可转换数值；分页只接受原始整型或表单字符串，正则再排除符号/浮点/科学计数法。 */
    protected function formInteger(mixed $value): bool
    {
        return is_int($value) || is_string($value);
    }
}
