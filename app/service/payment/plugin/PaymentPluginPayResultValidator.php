<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use think\Validate;

/** 服务调用插件后校验标准结果；此处失败不证明平台未受理，派发服务按结果未知处理。 */
final class PaymentPluginPayResultValidator extends Validate
{
    protected $rule = ['status' => 'require|in:pending,success', 'pay_no' => 'require|max:64'];

    /** @return array<string,mixed> 保留经过检查的标准字段，平台额外原文不会进入订单快照。 */
    public function validateResult(array $result): array
    {
        if (!$this->check($result) || !is_string($result['pay_no'] ?? null)
            || !array_key_exists('paid_amount', $result)
            || ($result['status'] === 'success' && (!is_int($result['paid_amount']) || $result['paid_amount'] < 1))
            || ($result['status'] === 'pending' && $result['paid_amount'] !== null)) {
            throw new \UnexpectedValueException('插件下单结果不符合标准字段');
        }
        foreach (['chan_order_no', 'chan_trade_no'] as $key) {
            if (!array_key_exists($key, $result) || ($result[$key] !== null && (!is_string($result[$key]) || $result[$key] === '' || strlen($result[$key]) > 128))) {
                throw new \UnexpectedValueException('插件下单渠道引用无效');
            }
        }
        if ($result['status'] === 'pending') {
            $view = $result['presentation'] ?? null;
            if (!is_array($view) || ($view['pay_page'] ?? null) !== 'page' || ($result['pay_product'] ?? null) !== 'receipt'
                || ($result['pay_action'] ?? null) !== 'qrcode' || !\app\common\validation\PaymentTypeCode::valid($result['pay_type'] ?? null)
                || !is_array($view['pay_params'] ?? null) || ($view['pay_params']['_page'] ?? null) !== 'receiptQrcode') {
                throw new \UnexpectedValueException('插件付款材料不是受支持的静态码页面');
            }
        }
        return array_intersect_key($result, array_flip(['status', 'pay_no', 'paid_amount', 'chan_order_no', 'chan_trade_no', 'pay_type', 'pay_product', 'pay_action', 'presentation', 'receipt', 'paid_at']));
    }
}
