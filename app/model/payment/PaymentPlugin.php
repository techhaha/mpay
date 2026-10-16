<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 本地插件的管理目录；类映射来自固定目录扫描，数据库保存同步定义和新单开关。 */
final class PaymentPlugin extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_payment_plugin',
            'schema' => [
                'id' => 'string',
                'code' => 'string',
                'name' => 'string',
                'version' => 'string',
                'pay_types' => 'json',
                'description' => 'string',
                'class_name' => 'string',
                'config_schema' => 'json',
                'enabled' => 'int',
                'revision' => 'int',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
