<?php
declare(strict_types=1);

namespace app\common\base;

use think\Model;

/**
 * ThinkORM 4 模型共同约定：主键、时间字段和 JSON 数组映射。
 *
 * 子类通过框架 getOptions() 补充 table/schema/hidden，不使用 ORM 3 的属性配置写法。
 * 显式 schema 避免构造模型时查询表结构；大整数 ID 映射为 string，金额映射为 int。
 * 可空字段的 null 由 ORM 保留；日期列使用 Asia/Shanghai 的 Y-m-d H:i:s 字符串。
 * hidden 只影响模型序列化，不代替公开 API 字段白名单；模型事件不得推进交易或调用平台。
 *
 * @property string $id 数据库顺序主键，不用作公开支付凭证。
 * @property string $created_at
 * @property string $updated_at
 */
abstract class BaseModel extends Model
{
    /**
     * 框架模型构造时与子类 getOptions() 合并的基础选项。
     *
     * @return array{pk:string, autoWriteTimestamp:string, createTime:string,
     *     updateTime:string, dateFormat:string, jsonAssoc:bool}
     */
    protected function getBaseOptions(): array
    {
        return [
            'pk' => 'id',
            // 用于 Model 保存路径；Query::insertGetId 不执行模型保存流程，新增时间由表默认值补齐。
            'autoWriteTimestamp' => 'datetime',
            'createTime' => 'created_at',
            'updateTime' => 'updated_at',
            'dateFormat' => 'Y-m-d H:i:s',
            'jsonAssoc' => true,
        ];
    }
}
