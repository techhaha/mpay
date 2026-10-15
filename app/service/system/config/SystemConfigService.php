<?php
declare(strict_types=1);

namespace app\service\system\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\model\system\SystemConfig;
use app\repository\system\SystemConfigRepository;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;
use think\Config;

/**
 * 系统公开设置的版本读取与保存；未配置组使用部署默认，损坏或不可读时明确失败。
 * 不把数据库配置复制进全局容器缓存：HTTP 和常驻 CLI 每次使用时读取，防止保存成功后工作进程仍永久使用旧值。
 */
final class SystemConfigService extends BaseService
{
    public function __construct(private readonly SystemConfigRepository $settings, private readonly SystemConfigValidator $validator,
        private readonly Config $config, 
        private readonly ConfigurationAuditService $audit, private readonly Clock $clock)
    {
    }

    /** @return array{group:string,version:int,source:string,values:array<string,int|list<string>>} version=0 表示该组尚未保存。 */
    public function read(string $group): array
    {
        $key = $this->key($group);
        return $this->describe($group, $this->settings->find($key));
    }

    /** @return array<string,int|list<string>> 业务使用方只读取自己消费的组，不批量载入无关配置。 */
    public function values(string $group): array { return $this->read($group)['values']; }

    /** @return list<array{group:string,version:int,source:string,values:array<string,int|list<string>>}> 管理页的一致视图，不含部署密钥、环境文件或平台配置。 */
    public function all(): array
    {
        return $this->transaction(fn (): array => array_map(fn (string $group): array => $this->read($group), $this->validator->groups()));
    }

    /**
     * @param array<string,mixed> $values 完整替换当前组；不修改旧订单有效期、冻结通道或通知签名。
     * @return array{group:string,version:int,source:string,values:array<string,int|list<string>>}
     * @throws BusinessException 身份撤销、版本冲突或输入无效。
     */
    public function save(string $group, int $expectedVersion, array $values, ConfigurationActionContext $actor): array
    {
        $key = $this->key($group);
        $values = $this->validator->values($group, $values);
        if ($expectedVersion < 0 || $expectedVersion >= 4294967295) { throw new BusinessException('配置版本无效或已用尽', ApiResponseConstant::CONFLICT); }

        return $this->transaction(function () use ($group, $key, $expectedVersion, $values, $actor): array {
            $this->audit->authorize($actor);

            $row = $this->settings->lock($key);
            $previous = $this->describe($group, $row);
            if ($previous['version'] !== $expectedVersion) { throw new BusinessException('系统设置已改变，请重新读取后再保存', ApiResponseConstant::STALE_VERSION); }
            $changed = $previous['values'] != $values;
            // 首次保存即使等于默认值也显式固定到数据库；之后才可把同值请求记为 unchanged。
            $version = $row !== null && !$changed ? $expectedVersion
                : $this->settings->savePublic($key, $row, $values, $this->clock->now()->format('Y-m-d H:i:s'));
            $this->audit->record($actor, 'system.configuration', 'system_config', $key, $version === $expectedVersion ? 'unchanged' : 'updated',
                ['config_group' => $group, 'old_version' => $expectedVersion, 'new_version' => $version]);
            return ['group' => $group, 'version' => $version, 'source' => 'database', 'values' => $values];
        });
    }

    private function key(string $group): string
    {
        if (!in_array($group, $this->validator->groups(), true)) { throw new BusinessException('不支持的系统设置组', ApiResponseConstant::VALIDATION_FAILED); }
        return 'mpay.' . $group;
    }

    /** @return array{group:string,version:int,source:string,values:array<string,int|list<string>>} */
    private function describe(string $group, ?SystemConfig $row): array
    {
        if ($row !== null && (!is_array($row['value_json']) || (int) $row['version'] < 1)) {
            throw new \RuntimeException('系统公开配置存储合同不一致');
        }
        $values = $row === null ? $this->defaults($group) : $row['value_json'];
        try { $values = $this->validator->values($group, $values); }
        catch (BusinessException $error) { throw new \RuntimeException('已保存的系统配置或部署默认无效', previous: $error); }
        return ['group' => $group, 'version' => $row === null ? 0 : (int) $row['version'],
            'source' => $row === null ? 'deployment' : 'database', 'values' => $values];
    }

    /** @return array<string,mixed> 只列出实际消费的公开默认值，不遍历或导出整个框架 Config。 */
    private function defaults(string $group): array
    {
        return match ($group) {
            'payment_defaults' => ['order_valid_seconds' => $this->config->get('mpay.order_valid_seconds', 300)],
            'maintenance' => ['technical_log_days' => $this->config->get('mpay.technical_log_days', 30)],
            default => throw new \LogicException('未知系统默认组'),
        };
    }
}
