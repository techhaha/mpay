<?php
declare(strict_types=1);

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 登录、配置及密钥操作的业务审计记录；与可轮转的技术日志分开保存。
 * 身份尚未确定的操作允许 admin_user_id 为 null，summary_json 只保存脱敏摘要。
 *
 * @property string|null $admin_user_id
 * @property string $request_id
 * @property string $action
 * @property string $result_code
 */
final class AdminOperationLog extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_admin_operation_log',
            'schema' => [
                'id' => 'string',
                'admin_user_id' => 'string',
                'request_id' => 'string',
                'action' => 'string',
                'target_type' => 'string',
                'target_id' => 'string',
                'result_code' => 'string',
                'summary_json' => 'json',
                'client_ip' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
