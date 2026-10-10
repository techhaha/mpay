<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPluginConfScope;

/**
 * 版本化范围授权的 ORM 持久化；不决定平台认证或账号启停。
 *
 * @extends BaseRepository<PaymentPluginConfScope>
 */
final class PaymentPluginConfScopeRepository extends BaseRepository
{
    public function __construct(PaymentPluginConfScope $model)
    {
        parent::__construct($model);
    }

    /**
     * 调用方持有配置行锁且已追加该版本正文；只插入集合，不覆盖历史。
     *
     * @param list<string> $scopeKeys 已核实存在/归属、排序且不重复的范围键。
     */
    public function append(string $configurationId, int $version, array $scopeKeys): void
    {
        $this->requireTransaction();
        foreach ($scopeKeys as $key) {
            $this->insert(['api_config_id' => $configurationId, 'config_version' => $version, 'receipt_scope_key' => $key], ['api_config_id', 'config_version', 'receipt_scope_key']);
        }
    }

    /**
     * 配置锁保护当前版本指针，当前读防止 RR 旧视图漏掉刚提交的绑定。
     * 不在此锁定 ReceiptScope，调用方仍须遵守配置/运行态之后的范围锁序。
     *
     * @return list<string> 按范围键排序；空列表表示没有授权，不猜第一个通道。
     */
    public function keys(string $configurationId, int $version, bool $currentRead = false): array
    {
        $query = $this->model->where('api_config_id', $configurationId)->where('config_version', $version)->order('receipt_scope_key');
        if ($currentRead) { $this->requireTransaction(); $query->lock(true); }
        return array_map('strval', $query->column('receipt_scope_key'));
    }
}
