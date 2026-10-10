<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

/**
 * 一次服务器授权的有限范围集合，以内部 scope_key 为键，流水身份与支付方式唯一关联。
 * 只描述插件声明的流水范围，不证明已完成平台认证。
 */
final readonly class ReceiptScopeSet
{
    /** @var array<string,array{pay_type:string}> */
    public array $entries;

    /**
     * @param array<string,array{pay_type:string}> $entries 来自配置绑定和范围记录。
     * @throws \InvalidArgumentException 为空、越界、字段含糊或出现未支持的支付方式。
     */
    public function __construct(array $entries)
    {
        if ($entries === [] || count($entries) > 128) { throw new \InvalidArgumentException('授权范围数量无效'); }
        foreach ($entries as $key => $scope) {
            if (!is_string($key) || $key === '' || strlen($key) > 64 || !mb_check_encoding($key, 'UTF-8')
                || !is_array($scope) || array_diff(array_keys($scope), ['pay_type']) !== []
                || !\app\common\validation\PaymentTypeCode::valid($scope['pay_type'] ?? null)) {
                throw new \InvalidArgumentException('授权范围需要明确的身份键、支付方式');
            }
        }
        ksort($entries, SORT_STRING);
        $this->entries = $entries;
    }

    /** 检查事件是否属于本次授权；插件负责订单定位，交易服务核验金额、时间并确认状态。 */
    public function allows(string $payType, string $scopeKey): bool
    {
        $scope = $this->entries[$scopeKey] ?? null;
        return $scope !== null && $scope['pay_type'] === $payType;
    }

    /** @return list<string> 本次配置内对应支付方式的范围；多于一个属于配置关联异常。 */
    public function keysForType(string $payType): array
    {
        return array_keys(array_filter($this->entries, static fn (array $entry): bool => $entry['pay_type'] === $payType));
    }

    /** @return list<string> 固定排序，供服务统一获取范围锁；本 DTO 不接触数据库。 */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    /**
     * 游标固定内部范围键、方式，不读取插件账号参数。
     *
     * @return array<string,array{pay_type:string}> 排序及字段顺序固定，供游标身份摘要使用。
     */
    public function coverageScopes(): array
    {
        $result = [];
        foreach ($this->entries as $key => $scope) {
            $result[$key] = ['pay_type' => $scope['pay_type']];
        }
        return $result;
    }
}
