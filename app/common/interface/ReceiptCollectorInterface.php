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
     * @param array<string,array<string,mixed>> $orders 按支付号索引，金额为整数分。
     * @param array<string,array<string,mixed>> $channels 按通道版本 ID 索引，含冻结配置和有版本的当前配置。
     * @return iterable<array{channel_revision_ids:list<string>,records:list<array<string,mixed>>,limit_reached:bool,error:?array{code:int,reason:string,retry_after:int}}>
     */
    public function collect(array $orders, array $channels, DateTimeImmutable $deadline): iterable;

    /**
     * 整批逐笔匹配，不合单、不拆单、不写成功状态；消费标记由服务附加，旧流水不得换绑。
     *
     * @param list<array{record:array<string,mixed>,consumed_pay_no:?string}> $receipts 本次有效记录，最多 200 条。
     * @param array<string,array<string,mixed>> $orders 本批允许匹配的原单快照。
     * @param array<string,array<string,mixed>> $channels 本批覆盖的通道版本。
     * @return list<array<string,mixed>> 标准通知结果；未匹配不返回，每笔订单/流水最多出现一次。
     */
    public function matchReceipts(array $receipts, array $orders, array $channels): array;

    /**
     * prelogin=true 时才调度；插件合并账号、判断间隔，正式查单已处理的账号不再探测。
     * 无登录态的平台返回空迭代结果；本方法不消费流水或确认订单。
     *
     * @param array<string,array<string,mixed>> $channels 启用通道及待核对订单依赖的版本。
     * @return iterable<array{channel_revision_ids:list<string>,verified:bool,error:?array{code:int,reason:string,retry_after:int}}>
     */
    public function prelogin(array $channels, DateTimeImmutable $deadline): iterable;
}
