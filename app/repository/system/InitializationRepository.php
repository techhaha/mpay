<?php
declare(strict_types=1);

namespace app\repository\system;

use app\common\base\BaseRepository;
use app\model\system\SystemConfig;
use app\model\payment\PaymentType;

/**
 * 初始化标记与受控表集合的非空检查；不会删除、重置或接管既有业务数据。
 *
 * @extends BaseRepository<SystemConfig>
 */
final class InitializationRepository extends BaseRepository
{
    public function __construct(SystemConfig $model, private readonly PaymentType $types)
    {
        parent::__construct($model);
    }

    public function marker(): ?SystemConfig
    {
        return $this->model->where('config_key', 'mpay.installation')->find();
    }

    /**
     * 只读一行判断存在性，不扫描全表计数，不读取订单/秘密正文；普通业务 CRUD 仍使用对应模型仓储。
     *
     * @param list<string> $tables MigrationService 已核验的固定表名集合。
     * @return list<string> 非空业务表名，基础支付字典单独核对，迁移账本不属于输入集合。
     */
    public function occupiedTables(array $tables, bool $currentRead = false): array
    {
        if ($currentRead) {
            $this->requireTransaction();
        }
        sort($tables, SORT_STRING);
        $occupied = [];
        foreach ($tables as $table) {
            if (!preg_match('/^ma_[a-z_]+$/D', $table)) {
                throw new \LogicException('初始化表集合超出受控命名');
            }
            if (in_array($table, ['ma_payment_type', 'ma_payment_plugin'], true)) {
                continue;
            }
            $query = $this->model->getConnection()->table($table)->field('id')->limit(1);
            if ($currentRead) {
                $query->lock(true);
            }
            if ($query->value('id') !== null) {
                $occupied[] = $table;
            }
        }
        return $occupied;
    }

    /** 目录允许为空或停用，安装状态不因管理员维护支付方式而失效。 */
    public function dictionaryReady(): bool
    {
        foreach ($this->types->column('code') as $code) {
            if (!\app\common\validation\PaymentTypeCode::valid($code)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 与管理员、首个应用、API 凭证和审计同事务提交；标记不替代非空数据保护。
     *
     * @param array{admin_id:string,app_id:string,credential_id:string,initialized_at:string} $references 只有公开引用，不能包含秘密。
     */
    public function record(array $references): void
    {
        $this->insert(
            [
                'config_key' => 'mpay.installation',
                'value_json' => json_encode(
                    ['schema_version' => 2] + $references,
                    JSON_THROW_ON_ERROR,
                ),
                'version' => 1,
            ],
            ['config_key', 'value_json', 'version'],
        );
    }
}
