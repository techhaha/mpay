<?php
declare(strict_types=1);

namespace app\service\payment\collection;

use app\service\payment\receipt\ReceiptScopeSet;
use app\exception\ReceiptCollectionException;

/** 完整查询窗口的持久进度；不保存主要来源代次，不把平台分页位置当成完成水位。 */
final class ReceiptCollectionCursor
{
    /** @return array{version:int,identity:string,scopes:array,coverage_from:int,completed_through:int} 时间为 Unix 秒。 */
    public static function create(string $account, string $plugin, ReceiptScopeSet $scopes, int $from, int $through): array
    {
        if ($from < 1 || $through < $from) { throw new \InvalidArgumentException('采集覆盖区间无效'); }
        $coverage = $scopes->coverageScopes();
        return ['version' => 1, 'identity' => self::identity($account, $plugin, $coverage),
            'scopes' => $coverage, 'coverage_from' => $from, 'completed_through' => $through];
    }

    /**
     * 只接受当前合同，损坏时明确失败，不截断欠查窗口。
     *
     * @param array<string,mixed> $cursor 持久化的完成水位。
     * @return array<string,mixed> 已核对账号、插件和范围身份的游标。
     */
    public static function read(string $account, string $plugin, array $cursor): array
    {
        if (($cursor['version'] ?? null) !== 1 || !is_array($cursor['scopes'] ?? null) || !is_string($cursor['identity'] ?? null)
            || !is_int($cursor['coverage_from'] ?? null) || !is_int($cursor['completed_through'] ?? null)
            || $cursor['coverage_from'] < 1 || $cursor['completed_through'] < $cursor['coverage_from']) {
            throw new ReceiptCollectionException('protocol_error');
        }
        $entries = [];
        foreach ($cursor['scopes'] as $key => $scope) {
            if (!is_array($scope) || array_keys($scope) !== ['pay_type']) { throw new ReceiptCollectionException('protocol_error'); }
            $entries[$key] = ['pay_type' => $scope['pay_type']];
        }
        try { $set = new ReceiptScopeSet($entries); }
        catch (\InvalidArgumentException) { throw new ReceiptCollectionException('protocol_error'); }
        if (!hash_equals(self::identity($account, $plugin, $set->coverageScopes()), $cursor['identity'])) {
            throw new ReceiptCollectionException('scope_mismatch');
        }
        return self::create($account, $plugin, $set, $cursor['coverage_from'], $cursor['completed_through']);
    }

    private static function identity(string $account, string $plugin, array $scopes): string
    {
        ksort($scopes, SORT_STRING);
        return hash('sha256', json_encode([$account, $plugin, $scopes], JSON_THROW_ON_ERROR));
    }
}
