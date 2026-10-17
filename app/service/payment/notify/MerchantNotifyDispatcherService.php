<?php
declare(strict_types=1);

namespace app\service\payment\notify;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentResultCode as Result;
use app\common\util\Clock;
use app\common\util\HttpClient;
use app\common\util\HttpTargetPolicy;
use app\common\util\Money;
use app\common\util\SafeLogger;
use app\common\validation\PaymentTypeCode;
use app\exception\BusinessException;
use app\exception\HttpRequestException;
use app\model\payment\NotifyTask;
use app\repository\payment\notify\NotifyAttemptRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\service\payment\epay\EpayV1MessageService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use think\db\exception\DbException;
use Throwable;

/**
 * 直接派发持久商户通知：短事务认领和追加意图，事务外 HTTP，再按原 token/租约回写结果。
 * 工作者只取得 NotifyTask → NotifyAttempt 锁，不反向锁交易，也不改支付状态或重新签名。
 */
final class MerchantNotifyDispatcherService extends BaseService
{
    public function __construct(
        private readonly NotifyTaskRepository $tasks,
        private readonly NotifyAttemptRepository $attempts,
        private readonly NotificationPolicy $policy,
        private readonly EpayV1MessageService $messages,
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly SafeLogger $logger,
    ) {}

    /**
     * 至多认领并发送一条；CLI 常驻与一次批次共用此用例，调用前不能持有外层事务。
     *
     * @return string idle/acknowledged/retry/exhausted/quarantined/lease_lost；失去租约的 ACK 不冒充入库成功。
     * @throws Throwable 认领/回写或数据库故障继续上抛；单次请求失败按原通知策略登记和重试。
     */
    public function dispatchOne(): string
    {
        $claim = $this->claim();
        if (!($claim instanceof NotificationClaim)) {
            return $claim;
        }
        try {
            // 预留两秒提交结果；慢 DNS 结束后 HTTP 客户端会再次检查这个绝对截止时间。
            $started = hrtime(true);
            $response = $this->http->get($claim->target, $claim->leaseUntil->modify('-2 seconds'));
            $durationMs = (int) min(4294967295, (hrtime(true) - $started) / 1000000);
            $acknowledged = $this->policy->acknowledged($response);
            $status = $response->getStatusCode();
            $reason = $acknowledged
                ? Result::ACKNOWLEDGED
                : ($status >= 200 && $status < 300
                    ? Result::INVALID_ACK
                    : 'http_status');
            $body = (string) $response->getBody();
            $result =
                [
                    'http_status' => $status,
                    'duration_ms' => $durationMs,
                    'result_code' => $reason,
                ] + $this->responseEvidence($body, false, $claim);
        } catch (HttpRequestException $e) {
            $acknowledged = false;
            $result =
                [
                    'http_status' => $e->httpStatus,
                    'duration_ms' => $e->durationMs,
                    'result_code' => $e->reasonCode,
                ] + $this->responseEvidence($e->responsePrefix(), true, $claim);
        } catch (DbException | PDOException $e) {
            throw $e;
        } catch (Throwable $e) {
            // 本次请求故障仍明确记为 application_error，沿用通知退避，不阻断其他通知。
            $outcome = $this->complete($claim, false, [
                'http_status' => null,
                'duration_ms' => null,
                'result_code' => Result::APPLICATION_ERROR,
                'response_body' => null,
                'response_truncated' => false,
            ]);
            $this->logger->record(
                'notification.failed',
                [
                    'notify_no' => $claim->notifyNo,
                    'reason_code' => Result::APPLICATION_ERROR,
                ],
                'error',
            );
            $this->logger->exception($e);
            return $outcome;
        }
        return $this->complete($claim, $acknowledged, $result);
    }

    /**
     * 候选扫描不持锁，逐项当前读再次核对。旧租约先登记“结果未知”，按退避恢复或停止自动发送。
     *
     * @return NotificationClaim|string 已持久认领，或 idle/retry/exhausted；不返回闭包/任务类名。
     */
    private function claim(): NotificationClaim|string
    {
        $numbers = $this->tasks->dueNumbers($this->clock->now()->format('Y-m-d H:i:s'));
        foreach ($numbers as $number) {
            $claimed = $this->transaction(function () use (
                $number,
            ): NotificationClaim|string|array|null {
                $task = $this->tasks->lock($number);
                // 等锁也可能消耗时间，取锁之后重读时钟，不能以扫描时刻创建租约。
                $now = $this->clock->now();
                $time = $now->format('Y-m-d H:i:s');
                if ($task === null) {
                    return null;
                }
                $state = (int) $task['status'];
                $created = new DateTimeImmutable($task['released_at'] ?? $task['created_at']);
                $used = (int) $task['retry_count'];
                // 先排除扫描后被别人正常认领的任务；不能因尚未结束的有效尝试而错误隔离。
                if (
                    ($state === NotifyConstant::STATUS_SENDING &&
                        $task['lease_until'] !== null &&
                        $task['lease_until'] > $time) ||
                    ($state === NotifyConstant::STATUS_PENDING &&
                        $task['next_retry_at'] !== null &&
                        $task['next_retry_at'] > $time) ||
                    !in_array(
                        $state,
                        [NotifyConstant::STATUS_PENDING, NotifyConstant::STATUS_SENDING],
                        true,
                    )
                ) {
                    return null;
                }
                $damage = $this->damageReason($task);
                $target = null;
                if ($damage === null) {
                    try {
                        // 本次认领只构造一次完整 URL；网络目标检查仍在事务外的 HTTP 层执行。
                        $target = $this->messages->targetUrl(
                            $task['notify_url'],
                            $task['notify_data'],
                        );
                        HttpTargetPolicy::uri($target, 16384);
                    } catch (InvalidArgumentException | BusinessException) {
                        $damage = 'frozen_payload_invalid';
                    }
                }
                if ($damage !== null) {
                    $this->tasks->quarantine($task, $damage, $time);
                    return ['notify_no' => $number, 'reason_code' => $damage];
                }
                if ($state === NotifyConstant::STATUS_SENDING) {
                    $this->attempts->finish(
                        $number,
                        (int) $task['retry_count'],
                        $task['claim_token'],
                        [
                            'http_status' => null,
                            'duration_ms' => null,
                            'result_code' => Result::LEASE_EXPIRED_UNKNOWN,
                            'response_body' => null,
                            'response_truncated' => false,
                        ],
                        $time,
                    );
                    $next =
                        $task['manual_attempt_no'] !== null
                            ? null
                            : $this->policy->nextAttempt($created, $used, $now);
                    $this->tasks->recoverExpired($task, $next?->format('Y-m-d H:i:s'), $time);
                    return $next === null ? 'exhausted' : 'retry';
                }
                $manual = $task['manual_attempt_no'] !== null;
                if (!$manual && !$this->policy->canAttempt($created, $used, $now)) {
                    $this->tasks->exhaust($task, $time);
                    return 'exhausted';
                }
                // 通过结构/身份检查后继续使用原参数；没有重新验签或用活动 KEY 重签旧责任。
                $parameters = $task['notify_data'];
                $token = bin2hex(random_bytes(16));
                $until = $now->modify('+' . $this->policy->leaseSeconds . ' seconds');
                $this->tasks->claim($task, $token, $used + 1, $time, $until->format('Y-m-d H:i:s'));
                $this->attempts->begin($number, $used + 1, $token, $time);
                return new NotificationClaim(
                    $number,
                    $token,
                    $used + 1,
                    $target,
                    $parameters['sign'],
                    $created,
                    $until,
                    $manual,
                );
            });
            if (is_array($claimed)) {
                // 隔离提交后才记录技术事件；原持久责任的 last_response 同时保留原因，日志失败也不丢隔离结果。
                $this->logger->record('notification.quarantined', $claimed, 'error');
                return 'quarantined';
            }
            if ($claimed !== null) {
                return $claimed;
            }
        }
        return 'idle';
    }

    /**
     * 对已锁定的到期记录识别确定的数据损坏，不把数据库连接/程序异常归为单记录错误。
     * 检查是冻结载荷形态及持久身份校验，不宣称用历史 KEY 重新完成了密码学验签。
     *
     * @return string|null null 表示这些不变量一致，并不证明远端已接收或旧签名未经任意篡改。
     */
    private function damageReason(NotifyTask $task): ?string
    {
        $sending = (int) $task['status'] === NotifyConstant::STATUS_SENDING;
        if (
            ($sending && ($task['lease_until'] === null || $task['claim_token'] === null)) ||
            (!$sending &&
                ($task['next_retry_at'] === null ||
                    $task['claim_token'] !== null ||
                    $task['lease_until'] !== null))
        ) {
            return 'schedule_invalid';
        }
        $used = (int) $task['retry_count'];
        if (
            ($sending && $used < 1) ||
            (!$sending && $used >= 4294967295) ||
            ($task['manual_attempt_no'] !== null &&
                (int) $task['manual_attempt_no'] !== ($sending ? $used : $used + 1))
        ) {
            return 'schedule_invalid';
        }
        if ($used > 0) {
            $attempt = $this->attempts->current($task['notify_no'], $used);
            if (
                $attempt === null ||
                ($sending &&
                    ($attempt['claim_token'] !== $task['claim_token'] ||
                        $attempt['result_code'] !== Result::CLAIMED ||
                        $attempt['finished_at'] !== null)) ||
                (!$sending &&
                    ($attempt['finished_at'] === null ||
                        $attempt['result_code'] === Result::CLAIMED))
            ) {
                return 'attempt_state_invalid';
            }
        }
        $parameters = $task['notify_data'];
        if (
            $task['event_type'] !== NotifyConstant::EVENT_PAY_SUCCESS ||
            $task['ref_no'] !== $task['pay_no'] ||
            !is_array($parameters)
        ) {
            return 'frozen_payload_invalid';
        }
        foreach ($parameters as $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                return 'frozen_payload_invalid';
            }
        }
        if (
            ($parameters['trade_no'] ?? null) !== $task['pay_no'] ||
            ($parameters['trade_status'] ?? null) !== 'TRADE_SUCCESS' ||
            ($parameters['sign_type'] ?? null) !== 'MD5' ||
            !preg_match('/^[a-f0-9]{32}$/D', $parameters['sign'] ?? '') ||
            !preg_match('/^[1-9][0-9]{0,31}$/D', $parameters['pid'] ?? '') ||
            !PaymentTypeCode::valid($parameters['type'] ?? null) ||
            ($parameters['out_trade_no'] ?? '') === '' ||
            mb_strlen($parameters['out_trade_no'], 'UTF-8') > 64 ||
            ($parameters['name'] ?? '') === '' ||
            strlen($parameters['name']) > 127 ||
            strlen($parameters['param'] ?? '') > 2048
        ) {
            return 'frozen_payload_invalid';
        }
        try {
            $cents = Money::parse($parameters['money'] ?? '');
            if ($cents < 1 || Money::format($cents) !== $parameters['money']) {
                return 'frozen_payload_invalid';
            }
        } catch (InvalidArgumentException | BusinessException) {
            return 'frozen_payload_invalid';
        }
        return null;
    }

    /**
     * 任务调度结果与尝试审计同事务提交；旧 token/过期 ACK 不更新任何当前责任。
     *
     * @param array{http_status:?int,duration_ms:?int,result_code:string,response_body:?string,response_truncated:bool} $result
     */
    private function complete(NotificationClaim $claim, bool $acknowledged, array $result): string
    {
        $outcome = $this->transaction(function () use ($claim, $acknowledged, $result): string {
            $task = $this->tasks->lock($claim->notifyNo);
            $now = $this->clock->now();
            $time = $now->format('Y-m-d H:i:s');
            if (
                $task === null ||
                (int) $task['status'] !== NotifyConstant::STATUS_SENDING ||
                $task['claim_token'] !== $claim->token ||
                (int) $task['retry_count'] !== $claim->attempt ||
                $task['lease_until'] === null ||
                $task['lease_until'] <= $time
            ) {
                return 'lease_lost';
            }
            // 只写首次有效 ACK；后续补发失败仍保留此前商户确认事实。
            $next =
                $acknowledged || $claim->manual
                    ? null
                    : $this->policy->nextAttempt($claim->createdAt, $claim->attempt, $now);
            $state = $acknowledged
                ? NotifyConstant::STATUS_SUCCESS
                : ($next === null
                    ? NotifyConstant::STATUS_EXHAUSTED
                    : NotifyConstant::STATUS_PENDING);
            if (
                !$this->tasks->finish(
                    $claim->notifyNo,
                    $claim->token,
                    $claim->attempt,
                    $state,
                    $next?->format('Y-m-d H:i:s'),
                    $result['result_code'],
                    $time,
                    $task['first_ack_at'] ?? ($acknowledged ? $time : null),
                )
            ) {
                throw new RuntimeException('已锁定通知的回写条件发生变化');
            }
            $this->attempts->finish(
                $claim->notifyNo,
                $claim->attempt,
                $claim->token,
                $result,
                $time,
            );
            return $acknowledged ? Result::ACKNOWLEDGED : ($next === null ? 'exhausted' : 'retry');
        });
        $this->logger->record('notification.attempt', [
            'notify_no' => $claim->notifyNo,
            'reason_code' => $outcome === 'lease_lost' ? $outcome : $result['result_code'],
            'http_status' => $result['http_status'],
            'duration_ms' => $result['duration_ms'],
        ]);
        return $outcome;
    }

    /**
     * 业务排障只留前 4096 个 UTF-8 字节；二进制控制符剔除，冻结签名不随远端回显泄露。
     * HTML/JSON 原样作为文本保存，后台必须转义展示。片段从不参与 ACK 判断或技术日志。
     * @return array{response_body:?string,response_truncated:bool}
     */
    private function responseEvidence(
        ?string $body,
        bool $incomplete,
        NotificationClaim $claim,
    ): array {
        if ($body === null) {
            return ['response_body' => null, 'response_truncated' => false];
        }
        $text = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text);
        $signature = $claim->signature;
        if ($signature !== '') {
            $text = str_replace($signature, '[签名已隐藏]', $text);
        }
        return [
            'response_body' => mb_strcut($text, 0, 4096, 'UTF-8'),
            'response_truncated' => $incomplete || strlen($text) > 4096,
        ];
    }
}
