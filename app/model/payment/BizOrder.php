<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;
use think\model\relation\HasOne;

/**
 * 原始收款需求及应用归属；所有业务单按应用和外部订单号幂等。
 * order_amount 和 paid_amount 只表达业务金额，识别偏移不计入业务成功金额。
 *
 * @property string $biz_no
 * @property string $merchant_order_no 原始外部单号，保留大小写。
 * @property int $order_amount 业务应付金额，分。
 * @property int $paid_amount 已确认业务金额，分。
 * @property string|null $active_pay_no
 * @property string $credential_version_id 下单时冻结的应用密钥版本 ID。
 * @property string $access_token_hash 公开访问凭证的摘要，序列化时隐藏。
 */
final class BizOrder extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_biz_order',
            'schema' => [
                'id' => 'string',
                'biz_no' => 'string',
                'trace_no' => 'string',
                'app_id' => 'string',
                'pid' => 'string',
                'merchant_order_no' => 'string',
                'subject' => 'string',
                'order_amount' => 'int',
                'paid_amount' => 'int',
                'status' => 'int',
                'active_pay_no' => 'string',
                'attempt_count' => 'int',
                'credential_version_id' => 'string',
                'access_token_hash' => 'string',
                'client_ip' => 'string',
                'expire_at' => 'string',
                'paid_at' => 'string',
                'closed_at' => 'string',
                'failed_at' => 'string',
                'timeout_at' => 'string',
                'notify_url' => 'string',
                'return_url' => 'string',
                'ext_json' => 'json',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
            // ext_json 含原始接入上下文和访问凭证；公开响应必须另行白名单映射。
            'hidden' => ['access_token_hash', 'ext_json'],
        ];
    }

    /** 首版唯一有效支付尝试，关联依据 biz_no，而非数据库自增 ID。 */
    public function payOrder(): HasOne
    {
        return $this->hasOne(PayOrder::class, 'biz_no', 'biz_no');
    }

}
