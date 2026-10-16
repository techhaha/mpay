<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentType;

/**
 * 支付方式字典与开关读取；是否允许分配订单由建单服务综合判断。
 *
 * @extends BaseRepository<PaymentType>
 */
final class PaymentCatalogRepository extends BaseRepository
{
    public function __construct(
        PaymentType $model,
        private readonly \app\model\payment\ReceiptScope $scopes,
        private readonly \app\model\payment\PaymentPollGroup $groups,
        private readonly \app\model\payment\PayOrder $orders,
    ) {
        parent::__construct($model);
    }

    /** 建单事务内读取当前支付方式；null 表示未安装，enabled 仍由服务核对。 */
    public function lockType(string $code): ?PaymentType
    {
        $this->requireTransaction();
        return $this->model->where('code', $code)->lock(true)->find();
    }

    /**
     * 只读取方式开关，供选择页列出可申请的方式，不证明当前存在健康的通道。
     *
     * @return list<PaymentType>
     */
    public function enabledTypes(): array
    {
        return $this->model->where('enabled', 1)->order('sort_order,id')->select()->all();
    }

    /** 真实范围对应的稳定方式编码；关闭新建单开关不妨碍补查历史到账。 */
    public function typeCode(string $id): ?string
    {
        return $this->model->where('id', $id)->value('code');
    }

    /** @return array<int|string,string> 管理范围列表批量展示方式，包含已停用方式，避免逐行查询且不隐藏历史授权。 */
    public function typeCodes(): array
    {
        return $this->model->column('code', 'id');
    }

    /** @return list<PaymentType> 停用条目仍供管理筛选与历史记录展示使用。 */
    public function all(): array
    {
        return $this->model->order('sort_order,id')->select()->all();
    }

    public function find(string $id, bool $lock = false): ?PaymentType
    {
        $query = $this->model->where('id', $id);
        if ($lock) {
            $this->requireTransaction();
            $query->lock(true);
        }
        return $query->find();
    }

    public function findCode(string $code): ?PaymentType
    {
        return $this->model->where('code', $code)->find();
    }

    /** 字典改码/删除前保留范围、轮询组或支付历史的引用；业务策略由服务决定。 */
    public function referenced(string $id, ?string $exceptGroup = null): bool
    {
        return $this->scopes->where('pay_type_id', $id)->value('id') !== null ||
            $this->groups
                ->where('pay_type_id', $id)
                ->where('id', '<>', $exceptGroup ?? '0')
                ->value('id') !== null ||
            $this->orders->where('pay_type_id', $id)->value('id') !== null;
    }

    /** @param array<string,scalar|null> $values 经验证的目录字段，不接收原始请求。 */
    public function save(?PaymentType $row, array $values): string
    {
        $this->requireTransaction();
        $allowed = ['code', 'name', 'enabled', 'sort_order', 'icon_asset_id', 'logo_asset_id'];
        if (array_diff(array_keys($values), $allowed)) {
            throw new \InvalidArgumentException('支付方式字段无效');
        }
        if ($row === null) {
            return $this->insert($values, $allowed);
        }
        $this->model
            ->where('id', $row['id'])
            ->where('revision', $row['revision'])
            ->update($values + ['revision' => (int) $row['revision'] + 1]);
        return $row['id'];
    }

    /** 调用服务持有条目锁并确认没有引用，数据库外键继续提供最终保护。 */
    public function delete(PaymentType $row): void
    {
        $this->requireTransaction();
        $this->model->where('id', $row['id'])->where('revision', $row['revision'])->delete();
    }

    /** 仅供默认路由编排；调用方持有支付方式锁，不按组名推断身份。 */
    public function setDefaultGroup(string $typeId, string $groupId): void
    {
        $this->requireTransaction();
        $this->model->where('id', $typeId)->update(['default_poll_group_id' => $groupId]);
    }

    /** @return list<string> 内部稳定指针；停用支付方式的默认组仍受保护。 */
    public function defaultGroupIds(): array
    {
        return array_map(
            'strval',
            $this->model->whereNotNull('default_poll_group_id')->column('default_poll_group_id'),
        );
    }
}
