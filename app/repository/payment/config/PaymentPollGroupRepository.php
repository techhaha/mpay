<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPollGroup;
use app\model\payment\PaymentPollGroupBind;
use app\model\payment\PaymentPollGroupChannel;

/**
 * 轮询组及成员/绑定持久化；选择策略与事务归服务。
 *
 * @extends BaseRepository<PaymentPollGroup>
 */
final class PaymentPollGroupRepository extends BaseRepository
{
    public function __construct(
        PaymentPollGroup $model,
        private readonly PaymentPollGroupChannel $members,
        private readonly PaymentPollGroupBind $bindings,
    ) {
        parent::__construct($model);
    }

    /** @return list<PaymentPollGroup> */
    public function all(): array
    {
        return $this->model->order('id', 'desc')->select()->all();
    }
    public function find(string $id): ?PaymentPollGroup
    {
        return $this->model->find($id);
    }

    public function lock(string $id): PaymentPollGroup
    {
        $this->requireTransaction();
        return $this->model->where('id', $id)->lock(true)->find() ??
            throw new \RuntimeException('轮询组不存在');
    }

    /** @return list<PaymentPollGroupChannel> 组锁保护成员集合；排序相同时按稳定主键选取。 */
    public function members(string $id, bool $lock = false): array
    {
        $query = $this->members->where('poll_group_id', $id)->order('sort_no,id');
        if ($lock) {
            $this->requireTransaction();
            $query->lock(true);
        }
        return $query->select()->all();
    }

    /** @return list<PaymentPollGroupBind> */
    public function bindings(?string $appId = null): array
    {
        $query = $this->bindings->order('app_id,pay_type_id');
        if ($appId !== null) {
            $query->where('app_id', $appId);
        }
        return $query->select()->all();
    }

    /** 管理员锁串行化组编辑与通道删除；有成员引用时由服务拒绝删除，不悄悄更改轮询配置。 */
    public function groupsUsingChannel(string $channelId): array
    {
        $this->requireTransaction();
        return $this->members
            ->alias('member')
            ->join('ma_payment_poll_group group_row', 'group_row.id=member.poll_group_id')
            ->where('member.channel_id', $channelId)
            ->field('group_row.id,group_row.name')
            ->order('group_row.id')
            ->select()
            ->toArray();
    }

    public function binding(
        string $appId,
        string $typeId,
        bool $lock = false,
    ): ?PaymentPollGroupBind {
        $query = $this->bindings->where('app_id', $appId)->where('pay_type_id', $typeId);
        if ($lock) {
            $this->requireTransaction();
            $query->lock(true);
        }
        return $query->find();
    }

    public function create(string $name, string $typeId, int $mode, string $remark): string
    {
        return $this->insert(
            ['name' => $name, 'pay_type_id' => $typeId, 'route_mode' => $mode, 'remark' => $remark],
            ['name', 'pay_type_id', 'route_mode', 'remark'],
        );
    }

    /** 类型创建后固定，避免修改组类型使现有应用绑定被重新解释。 */
    public function update(
        PaymentPollGroup $group,
        string $name,
        int $mode,
        bool $enabled,
        string $remark,
        string $now,
    ): void {
        $this->requireTransaction();
        $this->model->where('id', $group['id'])->update([
            'name' => $name,
            'route_mode' => $mode,
            'status' => (int) $enabled,
            'remark' => $remark,
            'revision' => (int) $group['revision'] + 1,
            'updated_at' => $now,
        ]);
    }

    /** @param list<array{channel_id:string,sort_no:int,weight:int,is_default:bool,enabled:bool}> $members 已由服务核对支付方式及唯一默认。 */
    public function replaceMembers(string $groupId, array $members): void
    {
        $this->requireTransaction();
        $this->members->where('poll_group_id', $groupId)->delete();
        foreach ($members as $member) {
            $this->insert(
                [
                    'poll_group_id' => $groupId,
                    'channel_id' => $member['channel_id'],
                    'sort_no' => $member['sort_no'],
                    'weight' => $member['weight'],
                    'is_default' => (int) $member['is_default'],
                    'status' => (int) $member['enabled'],
                ],
                ['poll_group_id', 'channel_id', 'sort_no', 'weight', 'is_default', 'status'],
                $this->members,
            );
        }
    }

    /** 调用方先锁应用；更换组时重置游标，同组启停不消耗或重置序号。 */
    public function bind(
        string $appId,
        string $typeId,
        string $groupId,
        bool $enabled,
        string $now,
    ): void {
        $this->requireTransaction();
        $current = $this->binding($appId, $typeId, true);
        if ($current === null) {
            $this->insert(
                [
                    'app_id' => $appId,
                    'pay_type_id' => $typeId,
                    'poll_group_id' => $groupId,
                    'status' => (int) $enabled,
                ],
                ['app_id', 'pay_type_id', 'poll_group_id', 'status'],
                $this->bindings,
            );
        } else {
            $this->bindings->where('id', $current['id'])->update([
                'poll_group_id' => $groupId,
                'status' => (int) $enabled,
                'cursor' => $current['poll_group_id'] === $groupId ? (int) $current['cursor'] : 0,
                'revision' => (int) $current['revision'] + 1,
                'updated_at' => $now,
            ]);
        }
    }

    /** 调用方已持绑定锁；支付尝试创建失败则同事务回滚，预览禁止调用。 */
    public function advance(PaymentPollGroupBind $binding, int $next, string $now): void
    {
        $this->requireTransaction();
        $this->bindings
            ->where('id', $binding['id'])
            ->update(['cursor' => $next, 'updated_at' => $now]);
    }

    /**
     * 调用方持有组锁；新增成员排到末尾，已有成员只更新参与开关，保留管理员设置的权重和排序。
     * null 表示保留开关；缺失成员默认参与。集合变化推进组版本，旧成员表单会明确冲突。
     */
    public function includeChannel(
        string $groupId,
        string $channelId,
        ?bool $enabled,
        string $now,
    ): void {
        $this->requireTransaction();
        $member = $this->members
            ->where('poll_group_id', $groupId)
            ->where('channel_id', $channelId)
            ->find();
        if ($member === null) {
            $sort = (int) $this->members->where('poll_group_id', $groupId)->max('sort_no') + 1;
            $this->insert(
                [
                    'poll_group_id' => $groupId,
                    'channel_id' => $channelId,
                    'sort_no' => $sort,
                    'weight' => 1,
                    'is_default' => 0,
                    'status' => (int) ($enabled ?? true),
                ],
                ['poll_group_id', 'channel_id', 'sort_no', 'weight', 'is_default', 'status'],
                $this->members,
            );
        } elseif ($enabled !== null && (int) $member['status'] !== (int) $enabled) {
            $this->members->where('id', $member['id'])->update(['status' => (int) $enabled]);
        } else {
            return;
        }
        $this->model
            ->where('id', $groupId)
            ->inc('revision')
            ->update(['updated_at' => $now]);
    }

    /** 只删除指定组中的成员；调用方决定哪些组属于自动维护范围。 */
    public function removeChannel(string $groupId, string $channelId, string $now): void
    {
        $this->requireTransaction();
        if (
            $this->members
                ->where('poll_group_id', $groupId)
                ->where('channel_id', $channelId)
                ->delete() > 0
        ) {
            $this->model
                ->where('id', $groupId)
                ->inc('revision')
                ->update(['updated_at' => $now]);
        }
    }

    /** 删除支付方式时清理其空默认组及自动绑定；业务引用检查由服务先完成。 */
    public function deleteForType(string $typeId, string $groupId): void
    {
        $this->requireTransaction();
        $this->bindings->where('pay_type_id', $typeId)->delete();
        $this->members->where('poll_group_id', $groupId)->delete();
        $this->model->where('id', $groupId)->delete();
    }
}
