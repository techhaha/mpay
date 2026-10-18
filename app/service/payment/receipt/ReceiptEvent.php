<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

/**
 * 回调服务核对插件标准结果后构造的内部到账输入；插件无需依赖此对象，构造本身不证明验签。
 * 范围和配置版本必须来自服务端上下文，不能直接反序列化匿名 HTTP 正文。
 * 格式与持久化边界由 ReceiptRecordValidator 和服务端配置入口核验；本对象不重复验证。
 * 只保存核对需要的字段及摘要，不携带凭证、完整报文或任意平台扩展数组。
 *
 * @internal 仅由 PayOrderCallbackService 在标准结果验证后构造；插件返回数组，不能直接构造到账事件。
 */
final readonly class ReceiptEvent
{
    /**
     * @param string $eventKey 对应插件的事件引用；手机兼容编号包含通知 timestamp，重投需保留原字段才能同键去重。
     * @param int $amount 来源合同定义的真实收款交易金额，整数分且大于零。
     * @param string|null $paidAt 已验证的上海时区 Y-m-d H:i:s 字符串；缺失为 null，不补接收时间。
     * @param string|null $platformTradeNo 平台交易号或 V2 监听生成的渠道引用；没有时为 null。
     * @param string $sourcePayloadDigest 服务按标准流水字段计算的 SHA-256，不保存平台原文或秘密。
     * @param string $pluginCode 服务端授权的插件编码，不能由平台正文指定。
     * @param string $pluginVersion 实际解析本次事件的已安装代码版本，供来源审计回溯。
     * @param string $configurationId PaymentPluginConf 的数据库 ID；configVersion 是该配置下的版本号。
     */
    public function __construct(
        public string $scopeKey,
        public int $configVersion,
        public string $payType,
        public string $eventKey,
        public int $amount,
        public ?string $paidAt,
        public ?string $platformTradeNo,
        public ?string $remark,
        public string $sourcePayloadDigest,
        public string $pluginCode,
        public string $pluginVersion,
        public string $configurationId,
        public string $payNo,
    ) {}

    /**
     * 固定来源引用白名单。config_revision_id 是 api_config_id 下的配置版本号（P1 config_version），
     * 不是通道快照行 ID；事件已定位到具体订单，来源配置版本单独保留供审计。
     *
     * @return array{plugin_code:string,plugin_version:string,api_config_id:string,config_revision_id:int}
     */
    public function sourceReference(): array
    {
        return [
            'plugin_code' => $this->pluginCode,
            'plugin_version' => $this->pluginVersion,
            'api_config_id' => $this->configurationId,
            'config_revision_id' => $this->configVersion,
        ];
    }

    /**
     * 内容摘要与稳定事件键分开保存；同键不同内容必须形成冲突，不能覆盖首次事实。
     * 接收时间、认证配置/插件版本引用不参与内容摘要，正常重投/密钥轮换不产生伪造的新到账。
     *
     */
    public function digest(): string
    {
        return hash(
            'sha256',
            json_encode(
                [
                    'version' => 3,
                    'plugin_code' => $this->pluginCode,
                    'event_key' => $this->eventKey,
                    'pay_type' => $this->payType,
                    'amount' => $this->amount,
                    'paid_at' => $this->paidAt,
                    'platform_trade_no' => $this->platformTradeNo,
                    'remark' => $this->remark,
                    'source_payload_digest' => $this->sourcePayloadDigest,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
