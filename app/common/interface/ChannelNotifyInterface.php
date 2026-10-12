<?php
declare(strict_types=1);

namespace app\common\interface;

use think\Request;
use think\Response;

/** HTTP 推送合同；插件验签、解析和定位，服务提交交易后才返回成功 ACK。 */
interface ChannelNotifyInterface extends PaymentInterface
{
    /** @return array<string,mixed> 已认证的标准结果；服务继续复核公共字段、订单归属和流水幂等。 */
    public function notify(Request $request): array;

    /** 仅在交易确认完成后调用；ACK 格式由对应平台协议决定。 */
    public function notifySuccess(): string|Response;

    /** 业务拒绝的协议应答；程序故障仍交统一异常处理，不能伪装接收成功。 */
    public function notifyFail(): string|Response;
}
