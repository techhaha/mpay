<?php
declare(strict_types=1);

namespace app\common\interface;

use think\Request;
use think\Response;

/**
 * V2 风格支付动作接口：插件解释平台协议，服务校验标准数组并负责最终状态转换。
 * 金额均为整数分；输入由服务按订单快照生成，不向插件暴露数据库锁、租约或核心证据对象。
 */
interface PaymentInterface
{
    /** @param array<string,mixed> $channelConfig 原版本配置及服务补充的通道参数，不接受请求直接覆盖。 */
    public function init(array $channelConfig): void;

    public function getCode(): string;
    public function getName(): string;
    public function getVersion(): string;

    /** 插件使用说明，纯文本；空字符串表示不显示说明区域。 */
    public function getDescription(): string;

    /** @return list<string> 支付方式编码。 */
    public function getEnabledPayTypes(): array;

    /** @return list<array{type:string,field:string,title:string,validate:array,value?:mixed,options?:array,secret?:bool,help?:string,placeholder?:string,rows?:int,visible_when?:array{field:string,value:mixed}}> 详见 docs/plugin-config-schema.md。 */
    public function getConfigSchema(): array;

    /** pay 请求是否可安全重放；只有无外部副作用或平台以原单号严格幂等时才能返回 true。 */
    public function canRetryPay(): bool;

    /**
     * @param array{pay_no:string,biz_no:string,pay_type_code:string,amount:int,expire_at:string,extra:array<string,mixed>} $order
     * pending 必须包含 pay_type/pay_product/pay_action 和 presentation；当前支持静态码 receiptQrcode 页面。
     * paid_amount 为通知业务的原金额（分），实收证据在 receipt 中保存；pending 为 null。
     *
     * @return array{status:string,pay_no:string,paid_amount:?int,chan_order_no:?string,chan_trade_no:?string,
     *     pay_type?:string,pay_product?:string,pay_action?:string,
     *     presentation?:array{pay_page:string,pay_params:array<string,mixed>},receipt?:array,paid_at?:?string}
     * @throws \app\exception\PaymentDefinitiveException 渠道确定拒绝。
     * @throws \app\exception\PaymentUncertainException 请求结果未知，服务不能另建订单重试。
     */
    public function pay(array $order): array;

    /**
     * @param array<string,mixed> $order 服务提供的原订单参数。
     * @return array<string,mixed> 标准状态，不直接写订单。
     */
    public function query(array $order): array;

    /**
     * @param array<string,mixed> $order 本地关闭仍由服务落库，不能据此声称静态二维码失效。
     * @return array<string,mixed>
     */
    public function close(array $order): array;

    /**
     * @param array{refund_no:string,pay_no:string,refund_amount:int,chan_order_no:?string,chan_trade_no:?string,extra:array} $order 原订单及本次退款身份；金额为分。
     * @return array{status:string,refund_no:string,pay_no:string,refund_amount:int,chan_refund_no:?string} status 为 success/pending/unknown/failed。
     * @throws \app\exception\UnsupportedPaymentOperationException 插件没有退款接口。
     * @throws \app\exception\PaymentDefinitiveException 平台明确拒绝，且未退款。
     */
    public function refund(array $order): array;

    /** @return array<string,mixed> 平台验签和语义转换后的结果；公共字段和订单归属由服务复核。 */
    public function notify(Request $request): array;

    public function notifySuccess(): string|Response;
    public function notifyFail(): string|Response;
}
