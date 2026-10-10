<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentApp;

/**
 * 应用查询及持久化；凭证、启停和 PID 冲突策略由服务决定。
 *
 * @extends BaseRepository<PaymentApp>
 */
final class PaymentAppRepository extends BaseRepository
{
    public function __construct(PaymentApp $model) { parent::__construct($model); }

    public function find(string $id): ?PaymentApp { return $this->model->find($id); }
    public function findByPid(string $pid): ?PaymentApp { return $this->model->where('pid', $pid)->find(); }

    /** 应用锁串行化凭证变更、绑定管理与建单，调用服务持锁至事务提交。 */
    public function lock(string $id): PaymentApp
    {
        $this->requireTransaction();
        return $this->model->where('id', $id)->lock(true)->find() ?? throw new \RuntimeException('接入应用不存在');
    }

    /** @return list<PaymentApp> 管理端使用；密钥正文不属于应用模型。 */
    public function all(): array { return $this->model->order('id', 'desc')->select()->all(); }

    /** @param list<string> $hosts 已由服务规范化的目标域名，不包含 URL 或端口。 */
    public function create(string $pid, string $name, array $hosts): string
    {
        return $this->insert(['pid' => $pid, 'name' => $name, 'allowed_hosts' => json_encode($hosts, JSON_THROW_ON_ERROR)],
            ['pid', 'name', 'allowed_hosts']);
    }

    /** @param list<string> $hosts 调用方已持应用锁并核对 revision；不改订单冻结信息。 */
    public function update(PaymentApp $app, string $pid, string $name, bool $enabled, array $hosts, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $app['id'])->update(['pid' => $pid, 'name' => $name, 'status' => (int) $enabled,
            'allowed_hosts' => json_encode($hosts, JSON_THROW_ON_ERROR), 'revision' => (int) $app['revision'] + 1, 'updated_at' => $now]);
    }

    /** 新版本与指针必须在同一事务提交；不会覆盖历史 KEY 行。 */
    public function activateKey(PaymentApp $app, string $keyId, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $app['id'])->update(['active_key_id' => $keyId,
            'revision' => (int) $app['revision'] + 1, 'updated_at' => $now]);
    }

    /** 调用方已锁应用；模式变更推进版本，防止旧表单覆盖更新。 */
    public function setDefaultRoute(PaymentApp $app, bool $enabled, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $app['id'])->update(['use_default_route' => (int) $enabled,
            'revision' => (int) $app['revision'] + 1, 'updated_at' => $now]);
    }
}
