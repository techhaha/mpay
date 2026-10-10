<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 插件登录身份的共享会话；与门店查询游标分离，不包含用户可编辑的插件参数。 */
final class ReceiptSession extends BaseModel
{
    protected function getOptions(): array
    {
        return ['table' => 'ma_receipt_session', 'schema' => [
            'id'=>'string','session_key'=>'string','auth_fingerprint'=>'string','session_json'=>'json',
            'claim_token'=>'string','lease_until'=>'string','created_at'=>'string','updated_at'=>'string',
        ]];
    }
}
