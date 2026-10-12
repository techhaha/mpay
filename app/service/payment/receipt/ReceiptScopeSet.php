<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\validation\PaymentTypeCode;
use InvalidArgumentException;

/**
 * 服务器授权的配置/支付方式范围集合，以内部 scope_key 为键。
 * 保留订单和配置之间的业务关联，不表达平台真实账号或登录状态。
 */
final readonly class ReceiptScopeSet
{
    /** @var array<string,array{pay_type:string}> */
    public array $entries;

    /**
     * @param array<string,array{pay_type:string}> $entries 来自配置绑定和范围记录。
     * @throws InvalidArgumentException 为空、越界、字段含糊或出现未支持的支付方式。
     */
    public function __construct(array $entries)
    {
        if ($entries === [] || count($entries) > 128) { throw new InvalidArgumentException('授权范围数量无效'); }
        foreach ($entries as $key => $scope) {
            if (!is_string($key) || $key === '' || strlen($key) > 64 || !mb_check_encoding($key, 'UTF-8')
                || !is_array($scope) || array_diff(array_keys($scope), ['pay_type']) !== []
                || !PaymentTypeCode::valid($scope['pay_type'] ?? null)) {
                throw new InvalidArgumentException('授权范围需要明确的身份键、支付方式');
            }
        }
        ksort($entries, SORT_STRING);
        $this->entries = $entries;
    }

}
