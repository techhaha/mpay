<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\service\payment\order\CreatedOrder;
use app\service\payment\order\CreateOrderInput;
use app\service\payment\order\PayOrderService;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;
use DateTimeImmutable;

/** 后台快捷付款入口：核验管理员和所选应用，组装普通订单输入；不创建独立订单类型或状态规则。 */
final class PaymentChannelTestService extends BaseService
{
    public function __construct(
        private readonly ConfigurationAuditService $audit,
        private readonly PaymentAppRepository $apps,
        private readonly PaymentAppCredentialService $credentials,
        private readonly PaymentChannelRepository $channels,
        private readonly PayOrderService $orders,
    ) {}

    /**
     * 金额单位为分；弹层请求键作为普通外部订单号，复用应用内唯一键及参数指纹。
     * PID 和密钥版本只从锁定应用取得。只固定通道和商品名，其余走普通建单及确认流程。
     *
     * @throws BusinessException 非后台身份、应用不可接入或同单请求参数冲突。
     */
    public function create(
        string $appId,
        string $channelId,
        int $amount,
        string $requestKey,
        ConfigurationActionContext $actor,
        DateTimeImmutable $now,
    ): CreatedOrder {
        if ($actor->origin !== 'admin' || !preg_match('/^[a-f0-9]{32}$/D', $requestKey)) {
            throw new BusinessException('付款请求无效', ApiResponseConstant::FORBIDDEN);
        }
        return $this->transactionRetry(function () use (
            $appId,
            $channelId,
            $amount,
            $requestKey,
            $actor,
            $now,
        ): CreatedOrder {
            $this->audit->authorize($actor);
            $application = $this->apps->lock($appId);
            $credential =
                $this->credentials->active($application) ??
                throw new BusinessException('应用缺少有效凭证', ApiResponseConstant::CONFLICT);
            $channel = $this->channels->find($channelId);
            if ($channel === null || $channel['deleted_at'] !== null) {
                throw new BusinessException('通道不存在', ApiResponseConstant::NOT_FOUND);
            }
            $input = new CreateOrderInput(
                appId: $appId,
                pid: $application['pid'],
                credentialVersionId: $credential['id'],
                merchantOrderNo: $requestKey,
                subject: '通道收款测试',
                amount: $amount,
                requestedPayType: $channel['pay_types'][0],
                notifyUrl: null,
                returnUrl: null,
                clientIp: $actor->clientIp,
                device: null,
                param: '',
                channelId: $channelId,
            );
            $created = $this->orders->createLocked($input, $now);
            if (!$created->reused) {
                $this->audit->record(
                    $actor,
                    'channel.test',
                    'payment_channel',
                    $channelId,
                    'created',
                    ['biz_no' => $created->bizNo, 'amount' => $amount],
                );
            }
            return $created;
        });
    }
}
