<?php
declare(strict_types=1);

namespace app\common\interface;

/** CLI 采集的数组流水入口，与 V2 的数组通知职责一致；不需要伪造 HTTP Request。 */
interface ReceiptMatcherInterface
{
    /**
     * 整个有界采集窗口一次输入，由插件选出逐笔匹配结果；不合单、不拆单、不返回无关流水。
     * scope_key 由服务核验来源后装配；采集覆盖配置内全部通道，由插件决定匹配订单。
     * @param list<array{record:array<string,mixed>,scope_key:string}> $receipts
     * @return list<array<string,mixed>> 标准通知结果，同批每笔订单最多出现一次。
     */
    public function matchReceipts(array $receipts): array;

}
