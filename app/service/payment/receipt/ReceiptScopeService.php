<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\common\validation\PaymentTypeCode;
use app\model\payment\PaymentPluginConf;
use app\model\payment\ReceiptScope;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use InvalidArgumentException;
use RuntimeException;

/** 配置与支付方式的业务锁域；不参与插件的登录身份或采集账号分组。 */
final class ReceiptScopeService extends BaseService
{
    public function __construct(private readonly PaymentCatalogRepository $catalog, private readonly ReceiptScopeRepository $scopes) {}

    /** 调用方拥有事务；相同配置及支付方式共用识别值占用。 */
    public function registerInTransaction(string $payType, string $configurationId): string
    {
        $this->requireTransaction();
        $type = $this->catalog->lockType($payType) ?? throw new InvalidArgumentException('支付方式不存在');
        $existing = $this->scopes->forConfiguration($configurationId, $type['id']);
        if ($existing !== null) { return $existing['receipt_scope_key']; }
        $key = 'scope_' . substr(hash('sha256', $configurationId . ':' . $payType), 0, 58);
        $this->scopes->create($key, $type['id'], $configurationId);
        return $key;
    }

    /**
     * 调用方已锁定配置；lock=true 时，须先取配置用例需要的通道及目录锁。
     * lock=false 只供候选/展示读取，不能替代建单最终范围锁内复核。
     *
     * @return array<string,ReceiptScope> 配置对应的业务范围，按键排序；缺失关联直接失败。
     */
    public function current(PaymentPluginConf $configuration, bool $lock): array
    {
        $result = [];
        foreach ($this->scopes->configurationScopes($configuration['id'], $lock) as $scope) {
            $result[$scope['receipt_scope_key']] = $scope;
        }
        if ($result === []) { throw new RuntimeException('配置缺少业务范围'); }
        return $result;
    }

    /**
     * 将服务器读取的范围转换成回调服务内部授权集合；不允许从消息正文生成授权集合。
     *
     * @param array<string,ReceiptScope> $records 需要强一致时必须是 current(..., true) 的锁定结果。
     * @return ReceiptScopeSet 范围必须具有完整支付方式关联，数据损坏明确报错。
     */
    public function describe(array $records): ReceiptScopeSet
    {
        $entries = [];
        foreach ($records as $key => $scope) {
            $type = $this->catalog->typeCode($scope['pay_type_id']);
            if ($scope['receipt_scope_key'] !== $key || !PaymentTypeCode::valid($type)) {
                throw new RuntimeException('收款范围的身份键或支付方式不一致');
            }
            $entries[$key] = ['pay_type' => $type];
        }
        return new ReceiptScopeSet($entries);
    }

}
