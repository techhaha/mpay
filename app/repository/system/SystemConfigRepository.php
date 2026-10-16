<?php
declare(strict_types=1);

namespace app\repository\system;

use app\common\base\BaseRepository;
use app\model\system\SystemConfig;

/**
 * 已定义系统配置组的 ORM 读写；字段规则、版本冲突和管理员授权由服务负责。
 *
 * @extends BaseRepository<SystemConfig>
 */
final class SystemConfigRepository extends BaseRepository
{
    public function __construct(SystemConfig $model)
    {
        parent::__construct($model);
    }

    /** null 表示该组仍采用部署默认值；数据库故障不得转换为 null。 */
    public function find(string $key): ?SystemConfig
    {
        return $this->model->where('config_key', $key)->find();
    }

    /** 调用方先锁管理员，串行化尚无配置行时的首次保存；不能仅靠不存在的行充当互斥锁。 */
    public function lock(string $key): ?SystemConfig
    {
        $this->requireTransaction();
        return $this->model->where('config_key', $key)->lock(true)->find();
    }

    /**
     * @param array<string,int|list<string>> $values 服务已按固定组校验的完整公开值。
     * @return int 保存后的逻辑版本；不触碰其他配置键或任何敏感配置正文。
     */
    public function savePublic(string $key, ?SystemConfig $current, array $values, string $now): int
    {
        $this->requireTransaction();
        $json = json_encode((object) $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($current === null) {
            $this->insert(
                ['config_key' => $key, 'value_json' => $json, 'version' => 1],
                ['config_key', 'value_json', 'version'],
            );
            return 1;
        }
        $version = (int) $current['version'] + 1;
        $changed = $this->model
            ->where('id', $current['id'])
            ->where('config_key', $key)
            ->where('version', $current['version'])
            ->update(['value_json' => $json, 'version' => $version, 'updated_at' => $now]);
        if ($changed !== 1) {
            throw new \RuntimeException('系统配置写入条件改变');
        }
        return $version;
    }
}
