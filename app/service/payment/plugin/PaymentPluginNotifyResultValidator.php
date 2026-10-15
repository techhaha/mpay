<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use think\Validate;
use UnexpectedValueException;

/** 监听插件通知结构由调用服务统一检查；平台来源认证和字段解释仍由具体插件完成。 */
final class PaymentPluginNotifyResultValidator extends Validate
{
    protected $rule = [
        'status' => ['require', 'string', 'in' => ['success']],
        'pay_no' => ['require', 'string', 'max' => 64],
        'paid_amount' => ['require', 'integerValue', 'gt' => 0],
        'receipt' => ['require', 'array'],
    ];

    /** @return array<string,mixed> 仅成功来源事实；匹配、数据库状态及重复责任不由验证器决定。 */
    public function validateResult(array $result): array
    {
        if (!(clone $this)->failException(false)->check($result)) {
            throw new UnexpectedValueException('插件通知结果不符合标准字段');
        }
        return $result;
    }

    /** 标准插件金额必须为整数分，不能接受可被 integer 规则转换的字符串或浮点。 */
    protected function integerValue(mixed $value): bool { return is_int($value); }
}
