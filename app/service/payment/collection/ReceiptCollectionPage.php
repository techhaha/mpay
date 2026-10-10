<?php
declare(strict_types=1);

namespace app\service\payment\collection;

/** 服务内部的已校验分页结构；记录授权由回调服务核对，完整窗口与单页成功分开。 */
final readonly class ReceiptCollectionPage
{
    // 通用单页资源上限；平台实际页大小由各自 SDK 决定，不在调度层写死。
    // 这是内存载体上限；运行时仍另行检查整轮 max_events、页数和租约，不代表千条流水可在期限内处理完。
    public const MAX_EVENTS = 1000;

    /**
     * @param list<array<string,mixed>> $records 已归一化并通过平台合同核验的收款事实，最多 MAX_EVENTS 条。
     * @param bool $complete 仅平台分页合同证明此窗口没有后续页时为 true。
     * @param array<string,scalar|null>|null $nextPage complete=false 必须提供非空续页参数；仅保存在本轮内存。
     * @param array<string,mixed>|null $session 新登录/刷新且经真实查询验证的会话；null 表示保留原会话。
     */
    public function __construct(public array $records, public bool $complete, public ?array $nextPage = null, #[\SensitiveParameter] public ?array $session = null)
    {
        if (!array_is_list($records) || count($records) > self::MAX_EVENTS || ($complete && $nextPage !== null)
            || (!$complete && ($nextPage === null || $nextPage === []))) {
            throw new \InvalidArgumentException('采集页没有明确的分页结束合同');
        }
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new \InvalidArgumentException('采集页含未归一化流水');
            }
        }
        foreach ($nextPage ?? [] as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new \InvalidArgumentException('续页参数必须是本插件的标量对象');
            }
        }
        if ($session !== null && ($session === [] || array_is_list($session))) {
            throw new \InvalidArgumentException('新采集会话必须为非空对象，未刷新时应返回 null');
        }
        if (strlen(json_encode($nextPage, JSON_THROW_ON_ERROR)) > 8192
            || strlen(json_encode($session, JSON_THROW_ON_ERROR)) > 65536) {
            throw new \InvalidArgumentException('采集续页或会话内容超出边界');
        }
    }
}
