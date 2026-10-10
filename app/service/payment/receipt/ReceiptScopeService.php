<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\model\payment\PaymentPluginConf;
use app\model\payment\ReceiptScope;
use app\repository\payment\config\PaymentPluginConfScopeRepository;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;

/** 注册、读取及核对插件流水范围；以插件身份和支付方式建立锁域，不读取平台账号字段。 */
final class ReceiptScopeService extends BaseService
{
    public function __construct(private readonly PaymentCatalogRepository $catalog, private readonly ReceiptScopeRepository $scopes,
        private readonly PaymentPluginConfScopeRepository $bindings) {}

    /** 调用方拥有事务；相同插件流水身份共用识别值占用和到账幂等。 */
    public function registerInTransaction(string $payType, string $accountKey): string
    {
        $this->requireTransaction();
        $type = $this->catalog->lockType($payType) ?? throw new \InvalidArgumentException('支付方式不存在');
        $existing = $this->scopes->forAccount($accountKey, $type['id']);
        if ($existing !== null) { return $existing['receipt_scope_key']; }
        $key = 'scope_' . substr(hash('sha256', $accountKey . ':' . $payType), 0, 58);
        $this->scopes->create($key, $type['id'], $accountKey);
        return $key;
    }

    /**
     * 调用方已锁定配置；lock=true 时，还须先取该用例需要的通道、目录及运行态锁。
     * lock=false 只供候选/展示读取，不能替代建单最终范围锁内复核。
     *
     * @return array<string,ReceiptScope> 当前版本授权的范围，按键排序；缺失绑定直接失败。
     */
    public function current(PaymentPluginConf $configuration, bool $lock): array
    {
        $keys = $this->bindings->keys($configuration['id'], (int) $configuration['config_version'], true);
        if ($keys === [] || count($keys) > 128) { throw new \RuntimeException('配置版本缺少有界授权范围'); }
        $result = [];
        foreach ($keys as $key) {
            $scope = ($lock ? $this->scopes->lock($key) : $this->scopes->find($key))
                ?? throw new \RuntimeException('配置匹配范围缺失');
            if ($scope['account_key'] !== $configuration['account_key']) { throw new \RuntimeException('匹配范围不属于当前配置'); }
            $result[$key] = $scope;
        }
        return $result;
    }

    /**
     * 将服务器读取的范围描述转换成可交给插件的不可变集合；不允许从消息正文生成授权集合。
     *
     * @param array<string,ReceiptScope> $records 需要强一致时必须是 current(..., true) 的锁定结果。
     * @return ReceiptScopeSet 范围必须具有完整支付方式关联，数据损坏明确报错。
     */
    public function describe(array $records): ReceiptScopeSet
    {
        $entries = [];
        foreach ($records as $key => $scope) {
            $type = $this->catalog->typeCode($scope['pay_type_id']);
            if ($scope['receipt_scope_key'] !== $key || !\app\common\validation\PaymentTypeCode::valid($type)) {
                throw new \RuntimeException('收款范围的身份键或支付方式不一致');
            }
            $entries[$key] = ['pay_type' => $type];
        }
        return new ReceiptScopeSet($entries);
    }

}
