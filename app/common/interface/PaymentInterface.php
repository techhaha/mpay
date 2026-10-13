<?php
declare(strict_types=1);

namespace app\common\interface;

use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;

/**
 * 通用元信息与 V2 风格支付动作；HTTP 通知和主动采集由各自接口声明。
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

    /**
     * @return list<array{type:string,field:string,title:string,validate:array,value?:mixed,options?:array,
     *     secret?:bool,help?:string,placeholder?:string,rows?:int,
     *     visible_when?:array{field:string,value:mixed}|array{field:string,in:list<mixed>}}> 详见公开 schema 文档。
     */
    public function getConfigSchema(): array;

    /** pay 请求是否可安全重放；只有无外部副作用或平台以原单号严格幂等时才能返回 true。 */
    public function canRetryPay(): bool;

    /**
     * @param array{pay_no:string,biz_no:string,pay_type_code:string,amount:int,expire_at:string,extra:array<string,mixed>} $order
     * pending 必须包含 pay_type/pay_product/pay_action 和 presentation；公开 pay_params 由插件生成并冻结。
     * 平台地址/材料由插件校验，前端按 pay_page/_page 选择已交付组件；核心不注册平台专用展示类。
     * paid_amount 为通知业务的原金额（分），实收证据在 receipt 中保存；pending 为 null。
     *
     * @return array{status:string,pay_no:string,paid_amount:?int,chan_order_no:?string,chan_trade_no:?string,
     *     pay_type?:string,pay_product?:string,pay_action?:string,
     *     presentation?:array{pay_page:string,pay_params:array<string,mixed>},receipt?:array,paid_at?:?string}
     * @throws PaymentDefinitiveException 渠道确定拒绝。
     * @throws PaymentUncertainException 请求结果未知，服务不能另建订单重试。
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
     * @throws UnsupportedPaymentOperationException 插件没有退款接口。
     * @throws PaymentDefinitiveException 平台明确拒绝，且未退款。
     */
    public function refund(array $order): array;
}
