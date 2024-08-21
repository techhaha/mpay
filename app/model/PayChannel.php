<?php

declare(strict_types=1);

namespace app\model;

use app\BaseModel;

class PayChannel extends BaseModel
{
    // 模型多对一关联
    public function payAccount()
    {
        return $this->belongsTo(PayAccount::class, 'account_id', 'id');
    }
}
