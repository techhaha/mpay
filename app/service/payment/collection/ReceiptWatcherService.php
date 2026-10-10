<?php
declare(strict_types=1);

namespace app\service\payment\collection;

use app\common\interface\ReceiptCollectorInterface;
use app\common\util\Clock;
use app\common\util\SafeLogger;
use app\common\base\BaseService;
use app\exception\ReceiptCollectionException;
use app\model\payment\ReceiptRuntimeState;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\runtime\ReceiptRuntimeStateRepository;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\receipt\PayOrderCallbackService;
use app\service\payment\receipt\ReceiptScopeService;

/**
 * 按插件查询身份合并通道并采集完整窗口，批次交插件匹配；只保存匹配成功的流水，全部完成后推进游标。
 * 配置 → 插件目录 → 运行态 → 范围只用于短事务认领/回写；外部查询及交易匹配不持这些锁。
 */
final class ReceiptWatcherService extends BaseService
{
    public function __construct(
        private readonly PaymentPluginConfRepository $configurations,
        private readonly ReceiptRuntimeStateRepository $runtime,
        private readonly \app\repository\payment\runtime\ReceiptSessionRepository $sessions,
        private readonly ReceiptScopeService $scopeAccess,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly PayOrderCallbackService $callbacks,
        private readonly ReceiptCollectionPolicy $policy,
        private readonly Clock $clock,
        private readonly SafeLogger $logger,
        private readonly \app\repository\payment\config\PaymentPluginRepository $pluginRecords,
    ) {
    }

    /**
     * 至多处理一个账号窗口；已分类的平台故障写状态并返回，其他账号可继续。
     * 不捕获后伪装空账单；程序/数据库故障仍抛出，由 CLI 返回非零退出状态。
     *
     * @param string $owner 当前采集进程随机身份，32 位十六进制。
     * @return string idle/deferred/completed/retry/lease_lost；completed 只表示完整窗口处理完成，不代表任何订单成功。
     */
    public function collectOne(string $owner): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $owner)) {
            throw new \InvalidArgumentException('采集进程身份无效');
        }
        $claim = $this->claim($owner);
        if (!$claim instanceof ReceiptCollectionClaim) {
            return $claim;
        }
        $started = hrtime(true);
        $requestId = bin2hex(random_bytes(16));
        $context = $claim->context;
        $plugin = $this->plugins->initialize($context->pluginCode, $context->configuration, []);
        if (!$plugin instanceof ReceiptCollectorInterface) {
            throw new \LogicException('已认领来源没有主动采集能力');
        }
        $session = $claim->session;
        $pageToken = null;
        $seenPages = [];
        $eventCount = 0;
        $records = [];
        try {
            for ($pageNumber = 0; $pageNumber < $this->policy->maxPages; $pageNumber++) {
                if (!$this->checkpoint($claim, null)) {
                    return 'lease_lost';
                }
                if ($this->clock->now() >= $context->deadline) {
                    throw new ReceiptCollectionException('budget_exhausted');
                }
                // 短事务已提交，登录/刷新/查询共享 context 的绝对预算；SDK 不能每页重置总预算。
                $page = new ReceiptCollectionPage(...$this->validateCollection($plugin->collectPage(
                    ['from' => $context->fromTimestamp, 'to' => $context->toTimestamp, 'deadline' => $context->deadline],
                    $pageToken, $session)));
                $eventCount += count($page->records);
                if ($eventCount > $this->policy->maxEvents) {
                    throw new ReceiptCollectionException('pagination_incomplete');
                }
                if (!$this->checkpoint($claim, $page->session)) {
                    return 'lease_lost';
                }
                $session = $page->session ?? $session;
                // 有界窗口先收齐全部分页；不在服务层把流水拆开逐条交给插件。
                array_push($records, ...$page->records);
                if ($page->complete) {
                    if (!$this->checkpoint($claim, null)) { return 'lease_lost'; }
                    $this->callbacks->receiveCollected($context, $records, $requestId);
                    return $this->finish($claim, null, $this->elapsed($started));
                }
                $pageToken = $page->nextPage;
                ksort($pageToken, SORT_STRING);
                $pageIdentity = hash('sha256', json_encode($pageToken, JSON_THROW_ON_ERROR));
                if (isset($seenPages[$pageIdentity])) {
                    throw new ReceiptCollectionException('pagination_incomplete');
                }
                $seenPages[$pageIdentity] = true;
            }
            throw new ReceiptCollectionException('pagination_incomplete');
        } catch (ReceiptCollectionException $e) {
            return $this->finish($claim, $e, $this->elapsed($started));
        }
        // 未预期异常保留当前租约和原游标，故障进程退出后由租约接管；不无限吞错循环。
    }

    /**
     * 初始化和认领都在对应配置锁下；版本变更使旧任务失效；登录会话仅随认证材料变化，旧分页不能覆盖新任务进度。
     *
     * @return ReceiptCollectionClaim|string 已提交的授权快照，或 idle/deferred。
     */
    private function claim(string $owner): ReceiptCollectionClaim|string
    {
        foreach ($this->configurations->dueCollectionIds($this->clock->now()->format('Y-m-d H:i:s')) as $id) {
            $observed = $this->configurations->find($id);
            if ($observed === null || $observed['query_key'] === null) { continue; }
            $claim = $this->transaction(function () use ($observed, $owner): ReceiptCollectionClaim|string|null {
                // 同查询范围一次锁全组配置；两个工作者或不同通道不能重复认领同一窗口。
                $group = $this->configurations->collectionGroup($observed['query_key'], true);
                if ($group === []) { return null; }
                $configuration = $group[0]; $id = $configuration['id'];
                $now = $this->clock->now(); $time = $now->format('Y-m-d H:i:s');
                $state = $this->runtime->lockForQuery($configuration['query_key']) ?? throw new \RuntimeException('查询运行记录缺失');
                if (($state['lease_until'] !== null && $state['lease_until'] > $time)
                    || ($state['next_run_at'] !== null && $state['next_run_at'] > $time)) { return null; }
                $later = $now->modify('+' . $this->policy->disabledSeconds . ' seconds')->format('Y-m-d H:i:s');
                $entry = $this->pluginRecords->find($configuration['plugin_code']);
                $pending = false;
                foreach ($group as $item) { $pending = $pending || $this->pluginRecords->hasPending($item['id']); }
                if (($entry === null || (int) $entry['enabled'] !== 1) && !$pending) {
                    $this->runtime->defer($state, \app\common\constant\ReceiptRuntimeConstant::PAUSED, 'plugin_disabled', $later, $time);
                    return 'deferred';
                }
                $plugin = $this->plugins->initialize($configuration['plugin_code'], $configuration['config_json'], []);
                if (!$plugin instanceof ReceiptCollectorInterface) { throw new \LogicException('非采集插件不能建立查询任务'); }
                $definition = $this->plugins->describe($plugin); $versions = []; $scopeRecords = [];
                $fingerprints = [];
                foreach ($group as $item) {
                    if ($item['account_key'] !== $configuration['account_key'] || $item['session_key'] !== $configuration['session_key']
                        || $item['plugin_code'] !== $definition->code) { throw new \LogicException('插件查询身份与流水范围不一致'); }
                    $revision = $this->configurations->revision($item['id'], (int) $item['config_version'], true);
                    if ($revision === null || $revision['config_json'] !== $item['config_json']) { throw new \RuntimeException('采集配置快照不一致'); }
                    $versions[$item['id']] = (int) $item['config_version']; $fingerprints[] = $item['auth_fingerprint'];
                    $scopeRecords += $this->scopeAccess->current($item, false);
                }
                if ($configuration['session_key'] !== null) { $fingerprints = $this->configurations->sessionFingerprints($configuration['session_key']); }
                if (count(array_unique($fingerprints)) !== 1) {
                    // 允许管理员保存重复账号；采集时明确报冲突，不轮番使用不同密码挤占会话。
                    $this->runtime->defer($state, $this->policy->failureStatus('configuration_conflict'), 'configuration_conflict', $later, $time);
                    return 'deferred';
                }
                $sessionRow = $configuration['session_key'] === null ? null : $this->sessions->lock($configuration['session_key']);
                if ($configuration['session_key'] !== null && $sessionRow === null) { throw new \RuntimeException('登录会话记录缺失'); }
                if ($sessionRow !== null && $sessionRow['lease_until'] !== null && $sessionRow['lease_until'] > $time) { return null; }
                ksort($scopeRecords, SORT_STRING); $allowed = $this->scopeAccess->describe($scopeRecords);
                try { $window = $this->policy->window($configuration['query_key'], $definition->code, $allowed, $state['cursor_json'], $now); }
                catch (ReceiptCollectionException $error) {
                    $this->runtime->defer($state, $this->policy->failureStatus($error->reasonCode), $error->reasonCode, $later, $time);
                    return 'deferred';
                }
                $until = $now->modify('+' . $this->policy->leaseSeconds . ' seconds'); $token = bin2hex(random_bytes(16));
                // 登录与门店切换串行到整窗结束；数据库锁在外呼前释放，仅保留有期限的租约。
                $session = $sessionRow === null ? null : $this->sessions->claim($sessionRow, $configuration['auth_fingerprint'], $token, $until->format('Y-m-d H:i:s'));
                $context = new ReceiptCollectionContext($id, $configuration['account_key'], (int) $configuration['config_version'],
                    $definition->code, $definition->version, $allowed, $configuration['config_json'], $window['from'], $window['to'],
                    $until->modify('-2 seconds'), $configuration['query_key'], $configuration['session_key'], $configuration['auth_fingerprint'], $versions);
                $this->runtime->claim($state, $token, $owner, $time, $until->format('Y-m-d H:i:s'), json_encode($window['cursor'], JSON_THROW_ON_ERROR));
                return new ReceiptCollectionClaim($context, $token, $session, $window['cursor'], (int) $state['retry_count']);
            });
            if ($claim !== null) { return $claim; }
        }
        return 'idle';
    }

    /**
     * 每页前后重新核对授权身份；会话可逐页更新，但流水在完整窗口结束后才匹配，游标/会话只能由仍有效的原持有者回写。
     *
     * @param array<string,mixed>|null $session 真实查询已验证的新会话，不含原始响应日志。
     */
    private function checkpoint(ReceiptCollectionClaim $claim, #[\SensitiveParameter] ?array $session): bool
    {
        return $this->transaction(function () use ($claim, $session): bool {
            $state = $this->lockCurrent($claim);
            if ($state === null) {
                return false;
            }
            $this->runtime->heartbeat($state, $this->clock->now()->format('Y-m-d H:i:s'));
            if ($session !== null && $claim->context->sessionKey !== null) {
                $this->sessions->save($this->sessions->lock($claim->context->sessionKey), $session);
            }
            return true;
        });
    }

    /** 调用方拥有短事务；返回 null 表示资格或租约变更，禁止让旧执行者替新配置报告健康。 */
    private function lockCurrent(ReceiptCollectionClaim $claim): ?ReceiptRuntimeState
    {
        $context = $claim->context; $versions = [];
        foreach ($this->configurations->collectionGroup($context->queryKey, true) as $configuration) {
            $versions[$configuration['id']] = (int) $configuration['config_version'];
            if ($configuration['account_key'] !== $context->accountKey || $configuration['session_key'] !== $context->sessionKey
                || $configuration['auth_fingerprint'] !== $context->authFingerprint) { return null; }
        }
        if ($versions !== $context->configurationVersions) { return null; }
        $state = $this->runtime->lockForQuery($context->queryKey); $time = $this->clock->now()->format('Y-m-d H:i:s');
        if ($state === null || $state['claim_token'] !== $claim->token || $state['lease_until'] === null || $state['lease_until'] <= $time) { return null; }
        if ($context->sessionKey !== null) {
            $session = $this->sessions->lock($context->sessionKey);
            if ($session === null || $session['claim_token'] !== $claim->token || $session['lease_until'] <= $time
                || $this->configurations->sessionFingerprints($context->sessionKey) !== [$context->authFingerprint]) { return null; }
        }
        return $state;
    }

    /**
     * 平台页结构和每条标准记录先统一校验；不接受失败被转换成空页成功。
     *
     * @return array{0:array,1:bool,2:?array,3:?array} 服务内部分页载体的构造参数。
     */
    private function validateCollection(array $page): array
    {
        if (!is_array($page['records'] ?? null) || !array_is_list($page['records']) || !is_bool($page['complete'] ?? null)
            || !array_key_exists('next_page', $page) || !array_key_exists('session', $page)) {
            throw new ReceiptCollectionException('protocol_error');
        }
        $validator = new \app\service\payment\receipt\ReceiptRecordValidator();
        $records = [];
        foreach ($page['records'] as $record) {
            if (!is_array($record)) { throw new ReceiptCollectionException('protocol_error'); }
            try { $records[] = $validator->validateRecord($record); }
            catch (\InvalidArgumentException|\UnexpectedValueException) { throw new ReceiptCollectionException('protocol_error'); }
        }
        return [$records, $page['complete'], $page['next_page'], $page['session']];
    }

    /** 完整窗口成功才前移游标；任一页失败只更新失败状态/退避，不抹去此前保存的事实。 */
    private function finish(ReceiptCollectionClaim $claim, ?ReceiptCollectionException $failure, int $durationMs): string
    {
        $result = $this->transaction(function () use ($claim, $failure, $durationMs): string {
            $state = $this->lockCurrent($claim);
            if ($state === null) {
                return 'lease_lost';
            }
            $now = $this->clock->now();
            $time = $now->format('Y-m-d H:i:s');
            if ($failure === null) {
                $cursor = $claim->cursor;
                $cursor['completed_through'] = $claim->context->toTimestamp;
                $caughtUp = $this->policy->caughtUp($claim->context->toTimestamp, $now);
                $changes = ['status' => $caughtUp ? \app\common\constant\ReceiptRuntimeConstant::HEALTHY : \app\common\constant\ReceiptRuntimeConstant::CATCHING_UP,
                    'reason_code' => $caughtUp ? null : 'catching_up', 'retry_count' => 0,
                    'last_duration_ms' => $durationMs, 'last_success_at' => $time,
                    'cursor_json' => json_encode($cursor, JSON_THROW_ON_ERROR),
                    'next_run_at' => $now->modify('+' . $this->policy->pollSeconds . ' seconds')->format('Y-m-d H:i:s')];
            } else {
                $changes = ['status' => $this->policy->failureStatus($failure->reasonCode), 'reason_code' => $failure->reasonCode,
                    'retry_count' => min(4294967295, $claim->previousFailures + 1), 'last_duration_ms' => $durationMs,
                    'next_run_at' => $now->modify('+' . $this->policy->retryDelay($failure, $claim->previousFailures) . ' seconds')->format('Y-m-d H:i:s')];

            }
            if ($claim->context->sessionKey !== null) {
                $session = $this->sessions->lock($claim->context->sessionKey);
                if ($failure?->reasonCode === 'authentication_expired') { $this->sessions->save($session, null); }
                $this->sessions->release($session);
            }
            return $this->runtime->finish($state, $changes, $time) ? ($failure === null ? 'completed' : 'retry') : 'lease_lost';
        });
        $this->logger->record('receipt.collection', ['reason_code' => $result === 'lease_lost' ? $result : ($failure?->reasonCode ?? 'window_completed'),
            'api_config_id' => $claim->context->configurationId, 'config_version' => $claim->context->configurationVersion,
            'plugin_code' => $claim->context->pluginCode,
            'duration_ms' => $durationMs]);
        return $result;
    }

    private function elapsed(int $started): int
    {
        return (int) min(4294967295, (hrtime(true) - $started) / 1000000);
    }
}
