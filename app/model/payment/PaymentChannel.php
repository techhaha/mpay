<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 通道通用属性与插件配置引用；插件专属参数保存在 PaymentPluginConf。
 * current_revision_id 供新执行引用；已创建支付单的规则以自身 channel_revision_id 为准。
 *
 * @property string $api_config_id PaymentPluginConf 的数据库 ID。
 * @property int $min_amount 单笔最小业务金额，分。
 * @property int $max_amount 单笔最大业务金额，分。
 * @property string|null $current_revision_id
 */
final class PaymentChannel extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_payment_channel',
            'schema' => [
                'id' => 'string',
                'plugin_code' => 'string',
                'api_config_id' => 'string',
                'name' => 'string',
                'pay_types' => 'json',
                'receipt_scope_key' => 'string',
                'min_amount' => 'int',
                'max_amount' => 'int',
                'daily_limit_amount' => 'int',
                'daily_limit_count' => 'int',
                'available_from' => 'string',
                'available_to' => 'string',
                'enabled' => 'int',
                'current_revision_id' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
                // 删除仅移出当前配置；历史订单、快照和迟到流水仍保留原通道身份。
                'deleted_at' => 'string',
            ],
        ];
    }

    public function configuration(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(PaymentPluginConf::class, 'api_config_id', 'id');
    }

    /** 通道当前版本关系，供配置查询使用；历史支付执行应从 PayOrder 读取其冻结版本。 */
    public function revision(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(PaymentChannelRevision::class, 'current_revision_id', 'id');
    }
}
