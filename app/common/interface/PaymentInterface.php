<?php
declare(strict_types=1);

namespace app\common\interface;

use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use think\Request;
use think\Response;

/** 支付动作及统一通知合同；金额为整数分，不向插件暴露锁、租约或核心证据对象。 */
interface PaymentInterface extends PayPluginInterface
{
    /**
     * @param array{
     *     pay_no:string,biz_no:string,channel_id:string,pay_type_code:string,amount:int,expire_at:string,
     *     subject:string,client_ip:?string,notify_url:string,return_url:string,_env:string,_submit_type:string,
     *     assets:array<string,?string>,extra:array<string,mixed>
     * } $order notify_url 指向本地支付号入口，return_url 使用本站业务号/token 路径，不是商户回跳地址；平台可追加协议 query。
     * pending 必须包含 pay_type/pay_product/pay_action 和 presentation；产品及动作由插件按实际准备路径填写。
     * 这两个标识用于追踪，不等同于支付成功或采集接口方法；公开 pay_params 由插件生成并冻结。
     * 平台地址/材料由插件校验，前端按 pay_page/_page 选择已交付组件；核心不注册平台专用展示类。
     * paid_amount 为通知业务的原金额（分）；pending 为 null。success 完整使用 notify 的平级成功结果合同。
     * received_amount 为用户实际支付金额；真实流水号未知为 null，原文只供受控回调记录，不进入公开付款材料。
     * pending 的 chan_order_no 缺失时可用 chan_trade_no 补缺；success 必须提供稳定的非空订单引用。
     *
     * @return array{status:string,pay_no:string,paid_amount:?int,chan_trade_no:?string,chan_order_no?:?string,
     *     pay_type?:string,pay_product?:string,pay_action?:string,
     *     presentation?:array{pay_page:string,pay_params:array<string,mixed>},received_amount?:int,
     *     paid_at?:string,event_key?:?string,raw?:array|string}
     * @throws PaymentDefinitiveException 渠道确定拒绝；插件提供已脱敏说明供受限技术日志记录。
     * @throws PaymentUncertainException 请求结果未知，服务不能另建订单重试；说明不得含凭证或完整报文。
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
     * @param array{refund_no:string,pay_no:string,channel_id:string,refund_amount:int,chan_order_no:?string,chan_trade_no:?string,extra:array} $order 原订单及本次退款身份；金额为分，累计额度以已确认实收为上限。
     * @return array{status:string,refund_no:string,pay_no:string,refund_amount:int,chan_refund_no:?string} status 为 success/pending/unknown/failed。
     * @throws UnsupportedPaymentOperationException 插件没有退款接口。
     * @throws PaymentDefinitiveException 平台明确拒绝且未退款；说明由插件脱敏，供受限技术日志记录。
     */
    public function refund(array $order): array;

    /**
     * HTTP 通知使用原始 Request；内部采集使用整批 {records,orders}，不得由 HTTP 载荷冒充。
     * 插件验签、解析并定位订单；服务核对结果后逐笔确认，插件不写成功状态。
     * orders 复用 collect 的只读调度快照，并限定为本次账号批次覆盖的通道版本；不是已匹配订单。
     * 全部候选用于跨通道歧义检查，只返回 context.channel_id 的结果；不再按流水时间扩大查询范围。
     * 每条采集 record 携带对应单条原始信息 raw，未匹配项只在本批内存存在；禁止把整批响应当作单笔原文。
     *
     * @param Request|array{records:list<array{record:array,consumed_pay_no:?string}>,orders:array<string,array{
     *     id:string,pay_no:string,channel_id:string,channel_revision_id:string,pay_type:string,pay_amount:int,
     *     ext_json:array<string,mixed>,request_at:?string,expire_at:string,created_at:string
     * }>} $payload
     * @param array{channel_id:string,received_at:string,pay_no?:string} $context 服务定位的入口及接收时间，不能由外部正文覆盖。
     * @return list<array{status:string,pay_no:string,pay_type:string,paid_amount:int,received_amount:int,
     *     paid_at:string,chan_order_no:string,chan_trade_no:?string,event_key:?string,raw:array|string}>
     * 所有键必须提供；paid_amount 为原业务金额，received_amount 为实际支付金额。原文不得替代交易核验。
     * paid_at 优先来源时间，缺失使用 context.received_at；已成功重报在锁内保留首次时间。
     * chan_order_no 是稳定业务引用，无来源编号由插件根据稳定来源信息生成；chan_trade_no 仅填真实流水号。
     * event_key 标识来源收款事件，监听必填，普通 API 无此事件时为 null，不用接收时间/随机数造流水。
     * 合法采集无匹配，或 HTTP 已认证且明确非入账时返回 []。
     * HTTP 入账无法匹配、认证/格式错误及运行故障必须抛异常，不能当作正常忽略。
     */
    public function notify(Request|array $payload, array $context): array;

    /** 相关结果均确认后，或明确非入账无需确认时返回成功应答；内部采集不调用。 */
    public function notifySuccess(): string|Response;

    /** $message 仅接收可公开业务提示，不能传入 SQL、堆栈或秘密。 */
    public function notifyFail(string $message): string|Response;
}
