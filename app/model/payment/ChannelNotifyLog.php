<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 通道入口每次接收及定位的脱敏记录，与支付回调日志和到账事实分别保留。
 * 记录存在只证明有一次入口处理，不能据此确认对应支付单成功。
 *
 * @property string $channel_id
 * @property string $request_id
 * @property string $pluginCode
 * @property string $result_code
 */
final class ChannelNotifyLog extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_channel_notify_log',
            'schema' => [
                'id' => 'string',
                'channel_id' => 'string',
                'request_id' => 'string',
                'event_key' => 'string',
                'plugin_code' => 'string',
                'result_code' => 'string',
                'payload_digest' => 'string',
                'summary_json' => 'json',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
