<?php
declare(strict_types=1);

namespace app\service\payment\routing;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\model\payment\PaymentType;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 安装和配置保存时维护默认关联；不参与下单补数据，也不识别插件平台账号。
 * 所有写方法复用调用方事务：管理操作先持管理员锁，再按支付方式 ID 顺序锁方式和默认组，最后修改通道。
 */
final class DefaultRoutingService extends BaseService
{
    public function __construct(
        private readonly PaymentCatalogRepository $types,
        private readonly PaymentPollGroupRepository $groups,
        private readonly PaymentAppRepository $apps,
    ) {}

    /** 支付方式行须已锁定；重复执行保留组名称、策略和所有运营配置。 */
    public function ensureGroup(PaymentType $type): string
    {
        $this->requireTransaction();
        $id = $type['default_poll_group_id'];
        if ($id === null) {
            $id = $this->groups->create(
                '默认' . $type['name'] . '组',
                $type['id'],
                RouteConstant::ORDER,
                '',
            );
            $this->types->setDefaultGroup($type['id'], $id);
        }
        $group = $this->groups->lock($id);
        if ($group['pay_type_id'] !== $type['id'] || (int) $group['status'] !== 1) {
            throw new \RuntimeException('默认轮询组关联无效');
        }
        return $id;
    }

    /**
     * 新应用或明确恢复默认时整体绑定；调用方持应用锁。仅配置变化才更新绑定，不推进轮询游标。
     * 包含停用方式，后续启用方式即可使用；实际收款仍由方式、插件、组和通道共同筛选。
     */
    public function applyToApp(string $appId): void
    {
        $this->requireTransaction();
        foreach ($this->orderedTypes() as $row) {
            $type =
                $this->types->find($row['id'], true) ?? throw new \RuntimeException('支付方式缺失');
            $groupId = $this->ensureGroup($type);
            $binding = $this->groups->binding($appId, $type['id'], true);
            if (
                $binding === null ||
                $binding['poll_group_id'] !== $groupId ||
                (int) $binding['status'] !== 1
            ) {
                $this->groups->bind($appId, $type['id'], $groupId, true, date('Y-m-d H:i:s'));
            }
        }
    }

    /** 新方式尚未对其他事务可见，只扩展默认模式应用；自定义应用保持原绑定集合。 */
    public function addType(PaymentType $type): void
    {
        $groupId = $this->ensureGroup($type);
        foreach ($this->apps->all() as $row) {
            $app = $this->apps->lock($row['id']);
            if ((int) $app['use_default_route'] === 1) {
                $this->groups->bind($app['id'], $type['id'], $groupId, true, date('Y-m-d H:i:s'));
            }
        }
    }

    /**
     * 通道编辑前先按方式 ID 锁方式及默认组，与下单的方式→组→配置→通道方向一致。
     * @param list<string> $codes 本次用例会触及的完整方式集合；登记插件范围时包含其全部支持方式。
     */
    public function lockTypes(array $codes): void
    {
        $this->requireTransaction();
        foreach ($this->orderedTypes() as $row) {
            if (in_array($row['code'], $codes, true)) {
                $type =
                    $this->types->find($row['id'], true) ??
                    throw new \RuntimeException('支付方式缺失');
                $this->ensureGroup($type);
            }
        }
    }

    /** 自动成员始终保留；参与开关复用成员 status，通道自身停用不会丢失排序、权重和参与选择。 */
    public function syncChannel(
        string $channelId,
        string $payType,
        ?bool $participates = null,
    ): void {
        $this->requireTransaction();
        $type = $this->types->findCode($payType) ?? throw new \RuntimeException('通道支付方式缺失');
        $target =
            $type['default_poll_group_id'] ?? throw new \RuntimeException('默认轮询组未初始化');
        $defaults = $this->types->defaultGroupIds();
        foreach ($this->groups->groupsUsingChannel($channelId) as $group) {
            if (
                in_array((string) $group['id'], $defaults, true) &&
                (string) $group['id'] !== $target
            ) {
                $this->groups->removeChannel((string) $group['id'], $channelId, date('Y-m-d H:i:s'));
            }
        }
        $this->groups->includeChannel($target, $channelId, $participates, date('Y-m-d H:i:s'));
    }

    /** @return list<array{id:int|string,name:string}> 默认引用自动维护；自定义引用必须由管理员先解除。 */
    public function customGroupsUsingChannel(string $id): array
    {
        $defaults = $this->types->defaultGroupIds();
        return array_values(
            array_filter(
                $this->groups->groupsUsingChannel($id),
                static fn(array $group): bool => !in_array((string) $group['id'], $defaults, true),
            ),
        );
    }

    /** 通道删除已核对无待付款和自定义组引用；调用方预先持方式/组锁。 */
    public function removeChannel(string $id): void
    {
        $this->requireTransaction();
        $defaults = $this->types->defaultGroupIds();
        foreach ($this->groups->groupsUsingChannel($id) as $group) {
            if (in_array((string) $group['id'], $defaults, true)) {
                $this->groups->removeChannel((string) $group['id'], $id, date('Y-m-d H:i:s'));
            }
        }
    }

    /** 管理详情的一致性事务内读取参与开关；不将通道本身的启停状态混入成员设置。 */
    public function participates(string $channelId, string $typeCode): bool
    {
        $type = $this->types->findCode($typeCode);
        if ($type === null || $type['default_poll_group_id'] === null) {
            return false;
        }
        foreach ($this->groups->members($type['default_poll_group_id']) as $member) {
            if ($member['channel_id'] === $channelId) {
                return (int) $member['status'] === 1;
            }
        }
        return false;
    }

    /** @return list<PaymentType> 锁序独立于管理员设置的显示顺序。 */
    private function orderedTypes(): array
    {
        $types = $this->types->all();
        usort(
            $types,
            static fn(PaymentType $a, PaymentType $b): int => strlen($a['id']) <=>
            strlen($b['id']) ?:
            strcmp($a['id'], $b['id']),
        );
        return $types;
    }
}
