<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付单的金额或备注占用及历史窗口；释放有效槽位后仍保留历史行。
 * active_value 由数据库生成，只有未释放记录参与范围/mode/value 唯一约束。
 *
 * @property string $pay_no
 * @property string $match_mode
 * @property string $match_value 金额模式保存整数分的十进制字符串，备注模式保存识别码。
 * @property int $receipt_amount 分。
 * @property string|null $released_at
 * @property-read string|null $active_value 未释放时等于 match_value，释放后为 null。
 */
final class ReceiptMatchReservation extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_receipt_match_reservation',
            'schema' => [
                'id' => 'string',
                'receipt_scope_key' => 'string',
                'pay_no' => 'string',
                'match_mode' => 'string',
                'match_value' => 'string',
                'receipt_amount' => 'int',
                'valid_from' => 'string',
                'expire_at' => 'string',
                'released_at' => 'string',
                'active_value' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }

}
