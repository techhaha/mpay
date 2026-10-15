<?php
declare(strict_types=1);

namespace app\common\interface;

use think\Request;
use think\Response;

/** HTTP 推送合同；插件验签、解析和定位，服务提交交易后才返回成功 ACK，并原样透传插件应答。 */
interface ChannelNotifyInterface extends PaymentInterface
{
    /** @return array<string,mixed> 已认证的标准结果；服务继续复核公共字段、订单归属和流水幂等。 */
    public function notify(Request $request): array;

    /** 仅在交易确认完成后调用；ACK 格式由对应平台协议决定。 */
    public function notifySuccess(): string|Response;

    /**
     * 业务拒绝的协议应答；格式及 HTTP 状态由插件决定，程序故障仍交统一异常处理。
     * @param string $message 可公开的业务错误说明，不得传入堆栈、SQL、密钥或上游原始响应。
     */
    public function notifyFail(string $message): string|Response;
}
