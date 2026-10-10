<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use think\Validate;

/** 监听插件通知结构由调用服务统一检查；平台来源认证和字段解释仍由具体插件完成。 */
final class PaymentPluginNotifyResultValidator extends Validate
{
    protected $rule = ['status' => 'require|in:success', 'pay_no' => 'require|max:64', 'paid_amount' => 'require|integer|gt:0'];

    /** @return array<string,mixed> 仅成功来源事实；匹配、数据库状态及重复责任不由验证器决定。 */
    public function validateResult(array $result): array
    {
        if (!$this->check($result) || !is_string($result['pay_no'] ?? null) || !is_int($result['paid_amount'] ?? null)
            || !is_array($result['receipt'] ?? null)) {
            throw new \UnexpectedValueException('插件通知结果不符合标准字段');
        }
        return $result;
    }
}
