<?php
declare(strict_types=1);

namespace app\service\payment\collection;


/** 一次已持久认领的采集快照，内部使用；会话、原完成游标及执行身份不作为公开页面数据。 */
final readonly class ReceiptCollectionClaim
{
    /**
     * @param array<string,mixed>|null $session 同插件登录身份的共享会话；独立于通道配置版本。
     * @param array{version:int,identity:string,scopes:array<string,array{pay_type:string}>,
     *     coverage_from:int,completed_through:int} $cursor 本轮开始前的完成进度及范围证明。
     */
    public function __construct(public ReceiptCollectionContext $context, public string $token,
        #[\SensitiveParameter] public ?array $session, public array $cursor, public int $previousFailures)
    {
    }
}
