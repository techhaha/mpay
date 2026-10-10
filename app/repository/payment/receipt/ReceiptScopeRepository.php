<?php
declare(strict_types=1);

namespace app\repository\payment\receipt;

use app\common\base\BaseRepository;
use app\model\payment\ReceiptScope;

/**
 * 插件给出的流水身份与支付方式组成匹配锁域。
 * @extends BaseRepository<ReceiptScope>
 */
final class ReceiptScopeRepository extends BaseRepository
{
    public function __construct(ReceiptScope $model) { parent::__construct($model); }

    /** 范围键与流水身份/支付方式组合分别由数据库唯一约束保护。 */
    public function create(string $key, string $payTypeId, string $accountKey): string
    {
        return $this->insert(['receipt_scope_key' => $key, 'pay_type_id' => $payTypeId, 'account_key' => $accountKey],
            ['receipt_scope_key', 'pay_type_id', 'account_key']);
    }

    /** 在业务单/支付单之后取范围锁，串行分配同一收款范围内的识别值。 */
    public function lock(string $scopeKey): ReceiptScope
    {
        $this->requireTransaction();
        return $this->model->where('receipt_scope_key', $scopeKey)->lock(true)->find() ?? throw new \RuntimeException('配置匹配范围不存在');
    }

    public function find(string $scopeKey): ?ReceiptScope
    {
        return $this->model->where('receipt_scope_key', $scopeKey)->find();
    }

    public function forAccount(string $accountKey, string $payTypeId): ?ReceiptScope
    {
        return $this->model->where('account_key', $accountKey)->where('pay_type_id', $payTypeId)->find();
    }
}
