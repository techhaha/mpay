<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\util\Money;

/**
 * 回调服务核对插件标准结果后构造的内部到账输入；插件无需依赖此对象，构造本身不证明验签。
 * 范围和账号必须来自服务端授权上下文，不能直接反序列化匿名 HTTP 正文。
 * 只保存核对需要的字段及摘要，不携带凭证、完整报文或任意平台扩展数组。
 */
final readonly class ReceiptEvent
{
    /**
     * @param string $eventKey 对应插件的事件引用；手机兼容编号包含通知 timestamp，重投需保留原字段才能同键去重。
     * @param int $amount 来源合同定义的真实收款交易金额，整数分且大于零。
     * @param \DateTimeImmutable|null $paidAt 按具体插件 V2 时间口径归一化的时点；缺失为 null。
     * @param string|null $platformTradeNo 平台交易号或 V2 监听生成的渠道引用；没有时为 null。
     * @param string $sourcePayloadDigest 服务按标准流水字段计算的 SHA-256，不保存平台原文或秘密。
     * @param string $pluginCode 服务端授权的插件编码，不能由平台正文指定。
     * @param string $pluginVersion 实际解析本次事件的已安装代码版本，供来源审计回溯。
     * @param string $configurationId PaymentPluginConf 的数据库 ID；configVersion 是该配置下的版本号。
     * @throws \InvalidArgumentException 归一化字段超出持久化合同。
     */
    public function __construct(
        public string $scopeKey,
        public string $accountKey,
        public int $configVersion,
        public string $payType,
        public string $eventKey,
        public int $amount,
        public ?\DateTimeImmutable $paidAt,
        public ?string $platformTradeNo,
        public ?string $remark,
        public string $sourcePayloadDigest,
        public string $pluginCode,
        public string $pluginVersion,
        public string $configurationId,
        public ?string $locatedPayNo = null,
    ) {
        Money::assertRange($amount);
        if ($amount === 0 || $configVersion < 1 || $configVersion > 4294967295
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $pluginCode)
            || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}$/D', $pluginVersion)
            || !preg_match('/^[1-9][0-9]{0,19}$/D', $configurationId)
            || (strlen($configurationId) === 20 && strcmp($configurationId, '18446744073709551615') > 0)
            || $scopeKey === '' || strlen($scopeKey) > 64 || $accountKey === '' || strlen($accountKey) > 64
            || !\app\common\validation\PaymentTypeCode::valid($payType)
            || $eventKey === '' || strlen($eventKey) > 128
            || ($paidAt !== null && (!preg_match('/^[0-9]{4}-/', $this->paymentTime())
                || (int) substr($this->paymentTime(), 0, 4) < 1000))
            || ($platformTradeNo !== null && ($platformTradeNo === '' || strlen($platformTradeNo) > 128))
            || ($remark !== null && mb_strlen($remark, 'UTF-8') > 256)
            || !preg_match('/^[a-f0-9]{64}$/D', $sourcePayloadDigest)
            || !mb_check_encoding([$scopeKey, $accountKey, $eventKey, $platformTradeNo ?? '', $remark ?? ''], 'UTF-8')) {
            throw new \InvalidArgumentException('到账事件尚未完成有效归一化');
        }
    }

    /**
     * 固定来源引用白名单。config_revision_id 是 api_config_id 下的配置版本号（P1 config_version），
     * 不是通道快照行 ID；主动采集没有单一通道，不能替它伪造 PaymentChannelRevision 引用。
     *
     * @return array{plugin_code:string,plugin_version:string,api_config_id:string,config_revision_id:int}
     */
    public function sourceReference(): array
    {
        return ['plugin_code' => $this->pluginCode, 'plugin_version' => $this->pluginVersion,
            'api_config_id' => $this->configurationId, 'config_revision_id' => $this->configVersion];
    }

    /**
     * 内容摘要与稳定事件键分开保存；同键不同内容必须形成冲突，不能覆盖首次事实。
     * 接收时间、认证配置/插件版本引用不参与内容摘要，正常重投/密钥轮换不产生伪造的新到账。
     *
     * @param string|null $originalAccountKey 仅比较已按 scope/plugin_code/event_key 定位的旧事实时使用其原流水身份。
     *     摘要含流水身份，配置交接后仍以原键比较其余全部内容，避免改写历史摘要或制造伪冲突。
     */
    public function digest(?string $originalAccountKey = null): string
    {
        if ($originalAccountKey !== null && ($originalAccountKey === '' || strlen($originalAccountKey) > 64
            || !mb_check_encoding($originalAccountKey, 'UTF-8'))) { throw new \InvalidArgumentException('原事实流水身份无效'); }
        return hash('sha256', json_encode([
            'version' => 2, 'account_key' => $originalAccountKey ?? $this->accountKey, 'pay_type' => $this->payType,
            'amount' => $this->amount,
            'paid_at' => $this->paymentTime(),
            'platform_trade_no' => $this->platformTradeNo, 'remark' => $this->remark,
            'source_payload_digest' => $this->sourcePayloadDigest,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** 数据库统一采用 Asia/Shanghai 秒精度；null 保留为未知，不能补成当前时间。 */
    public function paymentTime(): ?string
    {
        return $this->paidAt?->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    }
}
