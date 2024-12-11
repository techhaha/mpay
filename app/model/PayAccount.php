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
        $payplugin_path = config_path() . '/extendconfig/payplugin.php';
        if (!file_exists($payplugin_path)) {
            return [];
        }
        // 加载插件配置
        $payplugin_config = require $payplugin_path;
        $option = [];
        foreach ($payplugin_config as $config) {
            $option[$config['platform']] =  $config['name'];
        }
        return isset($option[$value]) ? $option[$value] : '[已卸载,请停用]';
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
