<?php
declare(strict_types=1);

namespace app\service\payment\notify;

use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\common\util\BusinessNumber;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\notify\NotifyTaskRepository;
use app\service\payment\config\PaymentAppCredentialService;
use app\service\payment\epay\EpayV1MessageService;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * 在首次业务成功事务内创建唯一通知责任及冻结签名内容，不派发 HTTP。
 * 原目标、业务金额和凭证版本一起保存，后续改配置或重试不得悄悄替换。
 */
final class NotifyService
{
    public function __construct(
        private readonly NotifyTaskRepository $tasks,
        private readonly PaymentAppCredentialService $credentials,
        private readonly EpayV1MessageService $messages,
        private readonly BusinessNumber $numbers,
    ) {
    }

    /**
     * 调用方持有业务单和支付单锁，已写成功并传入更新后的模型；此方法共用外层事务。
     * 已存在责任必须在生命周期的幂等分支处理，不 catch 唯一异常后伪装本次首次成功。
     *
     * @param bool $notifyMerchant 人工确认可明确暂缓；自动平台确认沿用默认发送。
     * @return string|null 新通知号，不是 HTTP 派发结果；未提供通知地址时返回 null。
     * @throws LogicException 调用顺序错误或业务/支付身份不一致。
     */
    public function createPaySuccess(BizOrder $biz, PayOrder $pay, string $now, bool $notifyMerchant = true): ?string
    {
        if ((int) $biz['status'] !== TradeConstant::ORDER_STATUS_SUCCESS
            || (int) $pay['status'] !== TradeConstant::ORDER_STATUS_SUCCESS
            || $pay['biz_no'] !== $biz['biz_no'] || $biz['active_pay_no'] !== $pay['pay_no']) {
            throw new LogicException('只有一致的业务成功才能建立通知责任');
        }
        // 所有订单统一按通知地址决定责任，不通过商品名称或入口类型区别对待。
        if ($biz['notify_url'] === null) { return null; }
        $parameters = $this->messages->signForTarget($biz['notify_url'],
            $this->messages->successParameters($biz, $pay, $biz['pid']),
            $this->credentials->secretForVersion($biz['app_id'], $biz['credential_version_id']));
        $notifyNo = $this->numbers->insert('N', new DateTimeImmutable($now, new DateTimeZone('Asia/Shanghai')), fn (string $notifyNo) => $this->tasks->create([
            'created_at' => $now, 'notify_no' => $notifyNo, 'event_type' => NotifyConstant::EVENT_PAY_SUCCESS, 'ref_no' => $pay['pay_no'],
            'biz_no' => $biz['biz_no'], 'pay_no' => $pay['pay_no'], 'notify_url' => $biz['notify_url'],
            'notify_data' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'credential_version_id' => $biz['credential_version_id'],
            // 不通知也建立原责任，以免重复成功回调补造一份待发送任务。
            'status' => $notifyMerchant ? NotifyConstant::STATUS_PENDING : NotifyConstant::STATUS_HELD,
            'next_retry_at' => $notifyMerchant ? $now : null,
        ]));
        return $notifyNo;
    }
}
