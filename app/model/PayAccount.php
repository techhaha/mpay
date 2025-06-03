<?php

declare(strict_types=1);

namespace app\model;

use app\BaseModel;
use app\model\User;
use app\controller\api\PluginController;

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
        return self::withCount(['payChannel' => 'channel_num'])->withSum(['order' => function ($query, &$alias) {
            $query->whereDay('pay_time')->where('state', 1);
            $alias = 'income';
        }], 'really_price')->where($select);
    }
    public static function findAccount($query)
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
        return self::where($select);
    }
    // 获取账号配置
    public static function getAccountConfig($aid, $pid = null): array|bool
    {
        $aid_info = self::find($aid);
        if (!$aid_info) return false;
        // 插件配置
        $platform = PluginController::getPluginInfo($aid_info->getData('platform'));
        // 查询参数
        $params = json_decode($aid_info->params, true);
        if ($aid_info && $platform) {
            $config = [
                'pid'       =>  $aid_info->pid,
                // 账号id
                'aid'       =>  $aid_info->id,
                // 收款平台
                'platform'  =>  $aid_info->getData('platform'),
                // 插件类名
                'payclass'  =>  $platform['class_name'],
                // 账号
                'account'   =>  $aid_info->account,
                // 密码
                'password'  =>  $aid_info->password,
                // 配置参数
                'params'     =>  $params,
            ];
            if ($pid !== null) {
                $pid_info = User::where('pid', $pid)->find();
                $config['key'] = $pid_info->secret_key;
            }
            return $config;
        } else {
            return false;
        }
    }
    // 获取器
    public function getPlatformAttr($value)
    {
        $payplugin_path = config_path() . '/extend/payplugin.php';
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
    // 一对多关联
    public function order()
    {
        return $this->hasMany(Order::class, 'aid', 'id');
    }
}
