<?php
declare(strict_types=1);

namespace app\service\system\audit;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\repository\system\AdminOperationLogRepository;
use app\repository\system\AdminUserRepository;
use InvalidArgumentException;

/** 收款/系统配置及其图片管理共用的操作者复核和有限审计合同；不拥有事务，不保存配置正文、图片正文、密钥或平台会话。 */
final class ConfigurationAuditService
{
    public function __construct(private readonly AdminUserRepository $admins, private readonly AdminOperationLogRepository $logs)
    {
    }

    /** 在配置写事务最前面调用，管理员锁位于主体/配置/通道/范围之前。 */
    public function authorize(ConfigurationActionContext $actor): void
    {
        if ($actor->origin === 'cli' && $actor->adminId === null && PHP_SAPI === 'cli') {
            // CLI 与后台配置共用管理员锁，串行化目录、默认关联和通道配置变更。
            // 直接当前读，避免等待管理锁之前建立旧的 RR 快照，漏掉刚创建的支付方式或默认绑定。
            $this->admins->lockSingleton() ?? throw new BusinessException('系统尚未初始化', ApiResponseConstant::SERVICE_UNAVAILABLE);
            return;
        }
        if ($actor->origin !== 'admin' || $actor->adminId === null) {
            throw new BusinessException('配置操作身份无效', ApiResponseConstant::UNAUTHENTICATED);
        }
        $admin = $this->admins->lock($actor->adminId);
        if ($admin === null || (int) $admin['status'] !== 1 || (int) $admin['session_version'] !== $actor->sessionVersion) {
            throw new BusinessException('管理员会话已失效', ApiResponseConstant::UNAUTHENTICATED);
        }
    }

    /**
     * 成功/幂等结果与业务变更同事务写入；错误不能靠跳过审计换成假成功。
     *
     * @param array<string,int|string|bool|null> $summary 只含固定标识、版本、开关及数量，拒绝额外键。
     */
    public function record(ConfigurationActionContext $actor, string $action, string $targetType, ?string $targetId,
        string $result, array $summary): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $action)
            || !in_array($targetType, ['payment_type', 'payment_plugin', 'payment_plugin_conf', 'payment_channel', 'receipt_scope', 'file_asset', 'system_config', 'payment_app', 'payment_poll_group'], true)
            || !in_array($result, ['created', 'updated', 'unchanged', 'enabled', 'disabled', 'deleted', 'verified', 'viewed', 'computed'], true)
            || array_diff(array_keys($summary), ['plugin_code', 'plugin_version', 'old_version', 'new_version',
                'enabled', 'use_default_route', 'biz_no', 'amount',
                'mime_type', 'size', 'sha256', 'config_group',
                'credential_version', 'parameter_count', 'signature_matches']) !== []) {
            throw new InvalidArgumentException('配置审计超出固定字段合同');
        }
        $this->logs->record(['admin_user_id' => $actor->adminId, 'request_id' => $actor->requestId, 'action' => $action,
            'target_type' => $targetType, 'target_id' => $targetId, 'result_code' => $result, 'client_ip' => $actor->clientIp,
            'summary_json' => json_encode(['origin' => $actor->origin] + ($actor->reason === null ? [] : ['reason' => $actor->reason]) + $summary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
}
