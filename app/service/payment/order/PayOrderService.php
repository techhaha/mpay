<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\constant\ApiResponseConstant;
use app\common\base\BaseService;
use app\exception\BusinessException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\service\system\config\SystemConfigService;
use think\db\exception\PDOException;

/**
 * 业务建单及收银台方式选择的事务入口，统一处理业务指纹、随机访问凭证和唯一尝试。
 * 接入验签在协议服务完成；本类不返回 HTTP、不调用平台，因而允许有限重试数据库竞争。
 */
final class PayOrderService extends BaseService
{
    public function __construct(
        private readonly PaymentAppRepository $apps,
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderAttemptService $attempts,
        private readonly SystemConfigService $settings,
        private readonly \app\common\util\HttpTargetPolicy $targets,
        private readonly \app\common\util\BusinessNumber $numbers,
    ) {
    }

    /**
     * 只接受已经认证、规范化的输入；验签所用 PID/活动凭证在应用锁下再次核对。
     * 相同指纹返回原访问凭证及原支付号，轮换后的 KEY 不改旧单的通知签名版本。
     * 未指定方式只保存 CREATED 业务单；已指定方式则与支付单、占用同事务提交。
     *
     * @throws BusinessException 应用停用、凭证已不再允许接入、同号参数冲突或没有可分配的通道。
     * @throws \RuntimeException 部署配置或历史凭证数据损坏；不替换旧上下文继续执行。
     */
    public function create(CreateOrderInput $input, \DateTimeImmutable $now): CreatedOrder
    {
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $validSeconds = $this->settings->values('payment_defaults')['order_valid_seconds'];
        if (!is_int($validSeconds) || $validSeconds < 1 || $validSeconds > 86400) {
            throw new \RuntimeException('业务单有效期配置无效');
        }
        return $this->runCreation(function () use ($input, $now, $validSeconds): CreatedOrder {
            // 同一应用的创建与凭证变更共用应用锁，防止验签后改 PID/KEY 仍以旧身份新建单。
            $app = $this->apps->lock($input->appId);
            if ((int) $app['status'] !== 1) {
                throw new BusinessException('应用已停用', ApiResponseConstant::FORBIDDEN);
            }
            if ($app['active_key_id'] !== $input->credentialVersionId || $app['pid'] !== $input->pid) {
                throw new BusinessException('接入凭证已变更，请重新签名', ApiResponseConstant::UNAUTHENTICATED);
            }
            $biz = $this->bizOrders->lockByAppOrder($input->appId, $input->merchantOrderNo);
            $reused = $biz !== null;
            if ($biz === null) {
                // 域名授权只决定新订单；已验签的同单重投仍使用冻结目标，下面继续核对完整业务指纹。
                $targets = $this->targets->forHosts($app['allowed_hosts']);
                $targets->validate($input->notifyUrl);
                if ($input->returnUrl !== null) { $targets->validate($input->returnUrl); }
                $accessToken = bin2hex(random_bytes(32));
                $bizNo = $this->numbers->insert('B', $now, fn (string $bizNo) => $this->bizOrders->create([
                    'created_at' => $now->format('Y-m-d H:i:s'), 'biz_no' => $bizNo, 'trace_no' => 'T' . bin2hex(random_bytes(16)),
                    'app_id' => $input->appId, 'pid' => $input->pid, 'merchant_order_no' => $input->merchantOrderNo,
                    'subject' => $input->subject, 'order_amount' => $input->amount,
                    'credential_version_id' => $input->credentialVersionId,
                    'access_token_hash' => hash('sha256', $accessToken), 'client_ip' => $input->clientIp,
                    'notify_url' => $input->notifyUrl, 'return_url' => $input->returnUrl,
                    'expire_at' => $now->modify('+' . $validSeconds . ' seconds')->format('Y-m-d H:i:s'),
                    'ext_json' => json_encode([
                        'creation_fingerprint' => $input->fingerprint(), 'requested_pay_type' => $input->requestedPayType,
                        // 自动识别只用于展示，不写入幂等指纹；客户端换环境重投仍复用原上下文。
                        'device' => $input->device ?? $input->detectedDevice, 'param' => $input->param,
                        // 摘要用于访问认证；保存的原凭证供已验签重投取回同一地址，不轮换使旧页面失效。
                        'cashier_token' => $accessToken,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]));
                $biz = $this->bizOrders->lock($bizNo);
            } else {
                $context = $biz['ext_json'] ?? [];
                if (!is_string($context['creation_fingerprint'] ?? null)
                    || !hash_equals($context['creation_fingerprint'], $input->fingerprint())) {
                    throw new BusinessException('该外部订单号的业务参数与原单不一致', ApiResponseConstant::CONFLICT);
                }
                $accessToken = $this->recoverAccessToken($biz);
            }
            $payNo = $biz['active_pay_no'];
            if ($input->requestedPayType !== null) {
                $payNo = $this->attempts->prepare($biz, $input->requestedPayType, $now)['pay_no'];
            }
            return new CreatedOrder($biz['biz_no'], $payNo, $accessToken, $reused);
        });
    }

    /**
     * 由收银台凭证授权选择方式；HTTP 入口还须执行同源/CSRF 校验。
     * 重复选择原方式复用已有尝试，选择另一个方式返回冲突，不延长付款窗口。
     *
     * @throws BusinessException 凭证无效、应用停用、状态冲突或没有可分配的通道。
     */
    public function selectPayType(string $bizNo, string $accessToken, string $payType, \DateTimeImmutable $now): PayOrder
    {
        $biz = $this->bizOrders->find($bizNo);
        $this->assertAccess($biz, $accessToken);
        return $this->runCreation(function () use ($bizNo, $accessToken, $payType, $now, $biz): PayOrder {
            // 与直接指定方式的 create 采用同一锁前缀，避免两种入口互相反向等待。
            $app = $this->apps->lock($biz['app_id']);
            $locked = $this->bizOrders->lock($bizNo);
            $this->assertAccess($locked, $accessToken);
            if ((int) $app['status'] !== 1) {
                throw new BusinessException('应用已停用', ApiResponseConstant::FORBIDDEN);
            }
            return $this->attempts->prepare($locked, $payType, $now);
        });
    }

    /**
     * 收银台查询和动作共用随机凭证校验；不存在与凭证错误使用同一公开错误。
     * 这不是后台或易支付身份认证，也不允许仅凭可猜订单号访问订单。
     */
    public function assertAccess(?BizOrder $biz, string $accessToken): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $accessToken) || $biz === null
            || !hash_equals($biz['access_token_hash'], hash('sha256', $accessToken))) {
            throw new BusinessException('订单访问凭证无效', ApiResponseConstant::FORBIDDEN);
        }
    }

    /** 已验签重投才允许恢复凭证明文；校验原值和摘要一致，拒绝损坏后另发新 token。 */
    private function recoverAccessToken(BizOrder $biz): string
    {
        $token = $biz['ext_json']['cashier_token'] ?? null;
        if (!is_string($token)) {
            throw new \RuntimeException('订单访问凭证快照缺失');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals($biz['access_token_hash'], hash('sha256', $token))) {
            throw new \RuntimeException('订单访问凭证快照损坏');
        }
        return $token;
    }

    /**
     * 仅重试不含网络/文件写入的数据库闭包。数据库竞争最多三次；不在结果未知时换通道。
     * 只识别驱动错误码，不解析可能含订单或 SQL 的异常文案；其他数据库故障原样抛出。
     *
     * @template T
     * @param callable():T $operation 不包含外部副作用的完整建单用例。
     * @return T
     */
    private function runCreation(callable $operation): mixed
    {
        $databaseFailures = 0;
        while (true) {
            try {
                return $this->transaction(fn () => $operation());
            } catch (PDOException $exception) {
                $driverCode = (int) ($exception->getData()['PDO Error Info']['Driver Error Code'] ?? 0);
                // 重复键可能来自另一个建单进程；整个事务回滚后重新读原身份才能判断是否同参。
                if (!in_array($driverCode, [1062, 1205, 1213], true)) {
                    throw $exception;
                }
                if (++$databaseFailures === 3) {
                    throw new BusinessException('订单创建发生并发竞争，请稍后重试', ApiResponseConstant::SERVICE_UNAVAILABLE, [], $exception);
                }
            }
        }
    }
}
