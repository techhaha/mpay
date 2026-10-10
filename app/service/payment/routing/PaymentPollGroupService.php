<?php
declare(strict_types=1);

namespace app\service\payment\routing;


use app\service\system\audit\ConfigurationAuditService;
use app\service\system\audit\ConfigurationActionContext;
use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupRepository;

/** 组、成员与应用绑定的管理用例；保持版本竞争检查，预览复用选路且不推进游标。 */
final class PaymentPollGroupService extends BaseService
{
    public function __construct(private readonly PaymentPollGroupRepository $groups, private readonly PaymentAppRepository $apps,
        private readonly PaymentChannelRepository $channels, private readonly PaymentCatalogRepository $catalog,
        private readonly PaymentRouteService $routes, private readonly ConfigurationAuditService $audit, private readonly Clock $clock) {}

    /** @return array{items:list<array>,bindings:list<array>,types:array} 管理投影，不含平台配置秘密。 */
    public function listing(): array
    {
        return $this->transaction(function (): array {
            $items = [];
            foreach ($this->groups->all() as $group) {
                $members = [];
                foreach ($this->groups->members($group['id']) as $member) {
                    $members[] = ['channel_id' => $member['channel_id'], 'sort_no' => (int) $member['sort_no'],
                        'weight' => (int) $member['weight'], 'is_default' => (int) $member['is_default'] === 1, 'enabled' => (int) $member['status'] === 1];
                }
                $items[] = ['id' => $group['id'], 'name' => $group['name'], 'pay_type_id' => $group['pay_type_id'],
                    'is_default' => in_array($group['id'], $this->catalog->defaultGroupIds(), true),
                    'route_mode' => $group['route_mode'], 'enabled' => (int) $group['status'] === 1,
                    'revision' => (int) $group['revision'], 'remark' => $group['remark'], 'members' => $members];
            }
            return ['items' => $items, 'bindings' => array_map(static fn ($row): array => $row->toArray(), $this->groups->bindings()),
                'types' => $this->catalog->typeCodes()];
        });
    }

    /**
     * 创建固定支付方式的组；成员和有效默认数量在同一事务内核对。
     *
     * @param list<array{channel_id:string,sort_no:int,weight:int,is_default:bool,enabled:bool}> $members
     * @return array{id:string} 新组身份。
     */
    public function create(string $name, string $payType, int $mode, string $remark, array $members, ConfigurationActionContext $actor): array
    {
        return $this->transaction(function () use ($name, $payType, $mode, $remark, $members, $actor): array {
            $this->audit->authorize($actor);
            $type = $this->catalog->lockType($payType) ?? throw new BusinessException('支付方式不存在', ApiResponseConstant::VALIDATION_FAILED);
            $this->assertMembers($members, $payType);
            $id = $this->groups->create($name, $type['id'], $mode, $remark);
            $this->groups->replaceMembers($id, $members);
            $this->audit->record($actor, 'poll_group.create', 'payment_poll_group', $id, 'created', []);
            return ['id' => $id];
        });
    }

    /** @param list<array{channel_id:string,sort_no:int,weight:int,is_default:bool,enabled:bool}> $members */
    public function save(string $id, int $revision, string $name, int $mode, bool $enabled, string $remark,
        array $members, ConfigurationActionContext $actor): void
    {
        $this->transaction(function () use ($id, $revision, $name, $mode, $enabled, $remark, $members, $actor): void {
            $this->audit->authorize($actor);
            $observed = $this->groups->find($id) ?? throw new BusinessException('轮询组不存在', ApiResponseConstant::NOT_FOUND);
            $paymentType = $this->catalog->find($observed['pay_type_id'], true) ?? throw new \RuntimeException('支付方式缺失');
            $group = $this->groups->lock($id);
            if ($paymentType['default_poll_group_id'] === $id) {
                if (!$enabled) { throw new BusinessException('默认轮询组不能单独停用，可停用支付方式或成员', ApiResponseConstant::CONFLICT); }
                $currentIds = array_column(array_map(static fn ($row): array => $row->toArray(), $this->groups->members($id, true)), 'channel_id');
                $nextIds = array_column($members, 'channel_id'); sort($currentIds); sort($nextIds);
                if ($currentIds !== $nextIds) { throw new BusinessException('默认组成员随通道自动维护，请刷新后调整参与开关', ApiResponseConstant::CONFLICT); }
            }
            if ((int) $group['revision'] !== $revision) { throw new BusinessException('轮询组已修改，请刷新', ApiResponseConstant::CONFLICT); }
            $type = $this->catalog->typeCode($group['pay_type_id']) ?? throw new \RuntimeException('轮询组支付方式缺失');
            $this->assertMembers($members, $type);
            $this->groups->replaceMembers($id, $members);
            $this->groups->update($group, $name, $mode, $enabled, $remark, $this->clock->now()->format('Y-m-d H:i:s'));
            $this->audit->record($actor, 'poll_group.save', 'payment_poll_group', $id, 'updated', ['enabled' => $enabled]);
        });
    }

    /** 每应用每方式只有一条绑定；expectedRevision=0 表示尚无绑定，防止旧页面覆盖其他管理操作。 */
    public function bind(string $appId, string $payType, string $groupId, int $expectedRevision, bool $enabled, ConfigurationActionContext $actor): void
    {
        $this->transaction(function () use ($appId, $payType, $groupId, $expectedRevision, $enabled, $actor): void {
            $this->audit->authorize($actor);
            $app = $this->apps->lock($appId);
            $type = $this->catalog->lockType($payType) ?? throw new BusinessException('支付方式不存在', ApiResponseConstant::VALIDATION_FAILED);
            $binding = $this->groups->binding($appId, $type['id'], true);
            if (($binding === null ? 0 : (int) $binding['revision']) !== $expectedRevision) {
                throw new BusinessException('应用绑定已修改，请刷新', ApiResponseConstant::CONFLICT);
            }
            $group = $this->groups->lock($groupId);
            if ($group['pay_type_id'] !== $type['id']) { throw new BusinessException('轮询组支付方式不一致', ApiResponseConstant::VALIDATION_FAILED); }
            $this->groups->bind($appId, $type['id'], $groupId, $enabled, $this->clock->now()->format('Y-m-d H:i:s'));
            // 显式保存某一方式绑定即为自定义意图；CLI 与后台执行同一规则。
            if ((int) $app['use_default_route'] === 1) { $this->apps->setDefaultRoute($app, false, $this->clock->now()->format('Y-m-d H:i:s')); }
            $this->audit->record($actor, 'application.bind', 'payment_app', $appId, 'updated', ['enabled' => $enabled]);
        });
    }

    /** 只返回本地配置选择结果；权重预览本身是一次抽样，不承诺下次实单使用同一通道。 */
    public function preview(string $appId, string $payType, int $amount): array
    {
        return $this->transaction(function () use ($appId, $payType, $amount): array {
            $app = $this->apps->lock($appId);
            if ((int) $app['status'] !== 1) { throw new BusinessException('应用已停用', ApiResponseConstant::CONFLICT); }
            $result = $this->routes->selectFor($appId, $amount, $payType, $this->clock->now(), false);
            return ['poll_group_id' => $result['poll_group_id'], 'route_mode' => $result['route_mode'],
                'channel_id' => $result['channel']['id'], 'channel_name' => $result['channel']['name'], 'cursor_advanced' => false];
        });
    }

    /**
     * 成员参数已由 Validate 核对类型；这里检查跨表支付方式、重复和有效默认数量。
     * 管理操作持有管理员锁，候选通道按 ID 排序锁定，不反向取得收款配置锁。
     * @param list<array{channel_id:string,sort_no:int,weight:int,is_default:bool,enabled:bool}> $members
     */
    private function assertMembers(array $members, string $payType): void
    {
        $ids = array_column($members, 'channel_id');
        if (count($ids) !== count(array_unique($ids))) { throw new BusinessException('组内不能重复添加通道', ApiResponseConstant::VALIDATION_FAILED); }
        if (count(array_filter($members, static fn (array $row): bool => $row['is_default'] && $row['enabled'])) > 1) {
            throw new BusinessException('组内只能有一个有效默认通道', ApiResponseConstant::VALIDATION_FAILED);
        }
        usort($ids, static fn (string $a, string $b): int => (int) $a <=> (int) $b);
        foreach ($ids as $id) {
            $channel = $this->channels->lock($id);
            if ($channel['deleted_at'] !== null) { throw new BusinessException('不能添加已删除通道，请刷新成员列表', ApiResponseConstant::CONFLICT); }
            if (!in_array($payType, $channel['pay_types'], true)) { throw new BusinessException('通道不支持该组的支付方式', ApiResponseConstant::VALIDATION_FAILED); }
        }
    }
}
