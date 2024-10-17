<?php

declare(strict_types=1);

namespace app;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * @mixin \think\Model
 */
class BaseModel extends Model
{
    use SoftDelete;
    protected $deleteTime = 'delete_time';
    protected $autoWriteTimestamp = 'timestamp';
    
}
