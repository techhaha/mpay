<?php
declare(strict_types=1);

namespace app\common\interface;

/** 支持 HTTP 推送的插件标记；notify 一次完成验签、解析和定位，成功后由服务提交并返回插件 ACK。 */
interface ChannelNotifyInterface extends PaymentInterface
{
}
