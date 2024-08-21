<?php

declare(strict_types=1);

namespace app\model;

use app\BaseModel;

class PayAccount extends BaseModel
{
    // 查询账号列表
    public static function serchAccount($query)
    {
        $select = [];
        $allow_field = ['state', 'platform', 'account', 'pattern'];
        foreach ($query as $key => $value) {
            if (in_array($key, $allow_field) && isset($value)) {
                if ($key === 'account') {
                    $select[] = [$key, 'like', '%' . $value . '%'];
                    continue;
                }
                $select[] = [$key, '=', $value];
            }
        }
        return self::withCount(['payChannel' => 'channel'])->where($select);
    }
    // 获取器
    public function getPlatformAttr($value)
    {
        // 加载平台配置
        $platform = \think\facade\Config::load("extendconfig/platform", 'extendconfig');
        return $platform[$value];
    }
    public function getPatternAttr($value)
    {
        // 监听模式
        $pattern = ['0' => '单次监听·被动', '1' => '连续监听·主动'];
        return $pattern[$value];
    }
    // 一对多关联
    public function payChannel()
    {
        return $this->hasMany(PayChannel::class, 'account_id', 'id');
    }
}
