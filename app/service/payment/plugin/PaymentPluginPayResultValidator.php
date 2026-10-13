<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\validation\PaymentTypeCode;
use JsonException;
use UnexpectedValueException;
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
            throw new UnexpectedValueException('插件下单结果不符合标准字段');
        }
        foreach (['chan_order_no', 'chan_trade_no'] as $key) {
            if (!array_key_exists($key, $result) || ($result[$key] !== null && (!is_string($result[$key]) || $result[$key] === '' || strlen($result[$key]) > 128))) {
                throw new UnexpectedValueException('插件下单渠道引用无效');
            }
        }
        if ($result['status'] === 'pending') {
            $view = $result['presentation'] ?? null;
            if (!is_array($view) || !is_string($view['pay_page'] ?? null)
                || !PaymentTypeCode::valid($result['pay_type'] ?? null)
                || !is_string($result['pay_product'] ?? null) || !is_string($result['pay_action'] ?? null)
                || !is_array($view['pay_params'] ?? null)
                || array_diff(array_keys($view), ['pay_page', 'pay_params']) !== []) {
                throw new UnexpectedValueException('插件支付展示结构无效');
            }
            $result['presentation'] = self::presentation($view + [
                'pay_type' => $result['pay_type'], 'pay_product' => $result['pay_product'], 'pay_action' => $result['pay_action'],
            ]);
        }
        return array_intersect_key($result, array_flip(['status', 'pay_no', 'paid_amount', 'chan_order_no', 'chan_trade_no', 'pay_type', 'pay_product', 'pay_action', 'presentation', 'receipt', 'paid_at']));
    }

    /**
     * 标准公开快照的结构边界；不解释平台链接、二维码、备注或页面内部字段。
     * 插件必须主动挑选公开 pay_params；这里保留自定义 JSON，不从整个配置或 ext_json 猜测公开字段。
     * 同一检查也用于读取已保存材料，错误快照不能作为合法页面参数输出。
     *
     * @param array<string,mixed> $view 已带标准支付标识的展示材料。
     * @return array{pay_page:string,pay_type:string,pay_product:string,pay_action:string,pay_params:array<string,mixed>}
     * @throws UnexpectedValueException 结构、标识、JSON 类型/深度或 64 KiB 大小边界不符。
     */
    public static function presentation(array $view): array
    {
        if (array_diff(array_keys($view), ['pay_page', 'pay_type', 'pay_product', 'pay_action', 'pay_params']) !== []
            || !PaymentTypeCode::valid($view['pay_type'] ?? null)) {
            throw new UnexpectedValueException('插件支付展示结构无效');
        }
        foreach (['pay_page', 'pay_product', 'pay_action'] as $key) {
            if (!is_string($view[$key] ?? null) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $view[$key])) {
                throw new UnexpectedValueException('插件支付展示标识无效');
            }
        }
        $params = $view['pay_params'] ?? null;
        if (!is_array($params) || ($params !== [] && array_is_list($params))
            || ($view['pay_page'] === 'page' && (!is_string($params['_page'] ?? null)
                || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $params['_page'])))) {
            throw new UnexpectedValueException('插件支付页面参数无效');
        }
        // 限制为纯 JSON，禁止对象序列化副作用；深度和大小由 JSON 编码器集中检查。
        self::assertJson($params);
        try { $json = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE, 32); }
        catch (JsonException $error) { throw new UnexpectedValueException('插件支付页面参数不是有效 JSON', 0, $error); }
        if (strlen($json) > 65536) { throw new UnexpectedValueException('插件支付页面参数超过 64 KiB'); }
        return $view;
    }

    /** 在遍历对象/资源前拒绝；先限制深度，也能停止循环引用的数组。 */
    private static function assertJson(mixed $value, int $depth = 0): void
    {
        if ($depth > 32 || is_object($value) || is_resource($value)) {
            throw new UnexpectedValueException('插件支付页面参数必须是有界纯 JSON');
        }
        if (is_array($value)) {
            foreach ($value as $item) { self::assertJson($item, $depth + 1); }
        }
    }
}
