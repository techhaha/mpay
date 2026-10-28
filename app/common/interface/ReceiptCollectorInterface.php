<?php
declare(strict_types=1);

namespace app\common\interface;

use DateTimeImmutable;

/** 主动监听能力；服务交付订单和通道快照，账号解释、合并和平台状态由插件负责。 */
interface ReceiptCollectorInterface extends PaymentInterface
{
    /**
     * 读取代码元信息 prelogin，未声明返回 false；只接受布尔值，不做字符串或数值转换。
     * 这是调度选项，不是通道表单字段；具体账号是否到期仍由插件判断。
     */
    public function isPreloginEnabled(): bool;

    /**
     * 每账号每轮最多一次带时间的流水请求，取最新最多 200 条，不翻页。
     * 输入只读，不依赖单通道 init 的残留状态，也不确认本系统订单。
     * records 每条除标准匹配字段外，必须保留该条来源 raw；仅匹配项交给 notify 返回并入库。
     * @param array<string,array{id:string,pay_no:string,channel_id:string,channel_revision_id:string,pay_type:string,pay_amount:int,ext_json:array<string,mixed>,request_at:?string,expire_at:string,created_at:string}> $orders 按支付号索引；插件检查自己的准备快照后查询，金额为整数分。
     * @param array<string,array<string,mixed>> $channels 按通道版本 ID 索引，含冻结配置和有版本的当前配置。
     * 错误说明由插件提取并脱敏，平台错误码与 message 原意保持不变；公共服务不解释第三方协议。
     * @return iterable<array{channel_revision_ids:list<string>,records:list<array<string,mixed>>,limit_reached:bool,error:?array{reason:string,retry_after:int,source?:string,stage?:string,platform_code?:?string,message?:string,http_status?:?int,message_truncated?:bool}}>
     */
    public function collect(array $orders, array $channels, DateTimeImmutable $deadline): iterable;

    /**
     * prelogin=true 时才调度；插件合并账号、判断间隔，正式查单已处理的账号不再探测。
     * 无登录态的平台返回空迭代结果；本方法不消费流水或确认订单。
     *
     * @param array<string,array<string,mixed>> $channels 启用通道及待核对订单依赖的版本。
     * error=null 表示本次认证验证成功；运行展示状态由服务映射，不要求插件返回重复布尔值或状态码。
     * @return iterable<array{channel_revision_ids:list<string>,error:?array{reason:string,retry_after:int,source?:string,stage?:string,platform_code?:?string,message?:string,http_status?:?int,message_truncated?:bool}}>
     */
    public function prelogin(array $channels, DateTimeImmutable $deadline): iterable;
}
