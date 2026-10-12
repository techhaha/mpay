<?php
declare(strict_types=1);

namespace app\repository\payment\receipt;

use app\common\base\BaseRepository;
use app\model\payment\ReceiptScope;
use RuntimeException;

/** @extends BaseRepository<ReceiptScope> 配置与支付方式的业务锁域，不解释平台账号。 */
final class ReceiptScopeRepository extends BaseRepository
{
    public function __construct(ReceiptScope $model) { parent::__construct($model); }

    /** 同配置/方式只登记一次，由唯一约束保护；调用方拥有配置事务。 */
    public function create(string $key, string $payTypeId, string $configurationId): string
    {
        return $this->insert(['receipt_scope_key' => $key, 'pay_type_id' => $payTypeId, 'api_config_id' => $configurationId],
            ['receipt_scope_key', 'pay_type_id', 'api_config_id']);
    }

    /** 在业务单/支付单之后取锁，串行分配本配置的金额或备注。 */
    public function lock(string $scopeKey): ReceiptScope
    {
        $this->requireTransaction();
        return $this->model->where('receipt_scope_key', $scopeKey)->lock(true)->find() ?? throw new RuntimeException('配置匹配范围不存在');
    }

    public function find(string $scopeKey): ?ReceiptScope
    {
        return $this->model->where('receipt_scope_key', $scopeKey)->find();
    }

    public function forConfiguration(string $id, string $payTypeId): ?ReceiptScope
    {
        return $this->model->where('api_config_id', $id)->where('pay_type_id', $payTypeId)->find();
    }

    /** @return list<ReceiptScope> 配置业务范围，锁顺序按键稳定排列。 */
    public function configurationScopes(string $id, bool $lock): array
    {
        $query = $this->model->where('api_config_id', $id)->order('receipt_scope_key');
        if ($lock) { $this->requireTransaction(); $query->lock(true); }
        return $query->select()->all();
    }
}
