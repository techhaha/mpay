<?php

declare(strict_types=1);

namespace app\model;

use app\BaseModel;
use app\model\PayAccount;
use app\model\PayChannel;

class Order extends BaseModel
{
    // 订单有效期
    private static $activity_time = 180;
    // 新建订单
    public static function createOrder($data): string|false
    {
        $my_time = time();
        $channel = self::setChannel($data['pid'], $data['type']);
        if (!$channel) return false;
        $new_order = [
            // 订单号
            'order_id'      => self::createOrderID('H'),
            // 商户ID
            'pid'           => $data['pid'],
            // 支付类型
            'type'          => $data['type'],
            // 商户订单号
            'out_trade_no'  => $data['out_trade_no'],
            // 异步通知
            'notify_url'    => $data['notify_url'],
            // 跳转通知
            'return_url'    => isset($data['return_url']) ? $data['return_url'] : '',
            // 商品名称
            'name'          => $data['name'],
            // 商品金额
            'money'         => $data['money'],
            // 实际成交金额
            'really_price'  => self::checkMoney($data['money'], $data['type'], $channel['aid'], $channel['cid']),
            // 用户IP
            'clientip'      => isset($data['clientip']) ? $data['clientip'] : '',
            // 设备类型
            'device'        => isset($data['device']) ? $data['device'] : '',
            // 业务扩展参数
            'param'         => serialize(self::getParams($data)),
            // 等待/过期：0, 支付成功：1
            'state'         => 0,
            // 开启监听：1, 关闭监听：0
            'patt'          => $channel['patt'],
            // 订单创建时间
            'create_time'   => self::getFormatTime($my_time),
            // 订单关闭时间
            'close_time'    => self::getFormatTime($my_time + self::$activity_time),
            // 支付时间
            'pay_time'      => self::getFormatTime($my_time),
            // 收款账号id
            'aid'           => $channel['aid'],
            // 交易终端id
            'cid'           => $channel['cid'],
        ];
        $res = self::create($new_order);
        if ($res->order_id) {
            return $res->order_id;
        } else {
            return false;
        }
    }
    // 查询订单列表
    public static function serchOrders($query)
    {
        $select = [];
        $_select = [];
        $allow_field = ['id', 'order_id', 'pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'really_price', 'money', 'clientip', 'device', 'state', 'create_time_start', 'create_time_end', 'close_time', 'pay_time', 'platform', 'platform_order', 'aid', 'cid',];
        foreach ($query as $key => $value) {
            if (in_array($key, $allow_field) && isset($value)) {
                if ($key === 'name') {
                    $select[] = ['Order.' . $key, 'like', '%' . $value . '%'];
                    continue;
                }
                if ($key === 'create_time_start') {
                    $select[] = ['Order.' . 'create_time', '>', $value];
                    continue;
                }
                if ($key === 'create_time_end') {
                    $select[] = ['Order.' . 'create_time', '<', $value];
                    continue;
                }
                if ($key === 'platform') {
                    $_select['platform'] = $value;
                    continue;
                }
                $select[] = ['Order.' . $key, '=', $value];
            }
        }
        return self::with('payAccount')
            ->hasWhere('payAccount', function ($query) use ($_select) {
                $query->where($_select);
            })
            ->where($select);
    }
    // 查询订单详细
    public static function showOrderDetail($id)
    {
        $order = self::find($id);
        $a_list = PayAccount::find($order->aid);
        $c_list = PayChannel::find($order->cid);
        if (!$order) {
            return [];
        }
        $order->platform = $a_list['platform'] ?? '···';
        $order->account = $a_list['account'] ?? '···';
        $order->channel = $c_list['channel'] ?? '···';
        $order->qrcode = $c_list['qrcode'] ?? '···';
        $order->url_type = $c_list['type'] ?? '···';
        return $order->toArray();
    }
    // 选择收款通道
    private static function setChannel($pid, $type): array
    {
        // 查询有效收款账户及通道
        $aids = PayAccount::where('pid', $pid)->where('state', 1)->column('id');
        $channel_infos = PayChannel::whereIn('account_id', $aids)->where('state', 1)->order('last_time', 'asc')->select();
        if (!$channel_infos || !$aids) return [];
        // 微信/支付宝收款处理
        $channel_info = null;
        foreach ($channel_infos as $key => $value) {
            $check_wx = preg_match('/^wxpay\d+#/i', $value->channel);
            $check_ali = preg_match('/^alipay\d+#/i', $value->channel);
            if ($check_wx && $type === 'wxpay') {
                $channel_info = $channel_infos[$key];
                break;
            } elseif ($check_ali && $type === 'alipay') {
                $channel_info = $channel_infos[$key];
                break;
            } else {
                if ($check_wx || $check_ali) {
                    continue;
                }
                $channel_info = $channel_infos[$key];
                break;
            }
        }
        if (!$channel_info) return [];
        // 选取收款通道
        $patt = PayAccount::find($channel_info->account_id);
        $channel = ['aid' => $channel_info->account_id, 'cid' => $channel_info->id, 'patt' => $patt->getData('pattern')];
        PayChannel::update(['last_time' => self::getFormatTime(), 'id' => $channel['cid']]);
        return $channel;
    }
    // 获取扩展参数数组
    private static function getParams(array $data): array
    {
        $keys = ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'sign', 'sign_type'];
        $params = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $keys)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }
    // 检查金额
    private static function checkMoney($money, $type, $aid, $cid): float
    {
        $money = (float) $money;
        // 查询有效订单
        $query = self::scope('activeOrder')->where(['type' => $type, 'aid' => $aid, 'cid' => $cid]);
        $activeOrders = $query->column('really_price');
        $num = count($activeOrders);
        if ($num > 0) {
            for ($i = 0; $i < $num; $i++) {
                if (in_array($money, $activeOrders)) {
                    $money += 0.01;
                } else {
                    break;
                }
            }
        }
        return $money;
    }
    // 获取格式时间
    private static function getFormatTime($time = 0)
    {
        if ($time) {
            return date('Y-m-d H:i:s', $time);
        }
        return date('Y-m-d H:i:s', time());
    }
    // 生成订单号
    private static function createOrderID(string $prefix = ''): string
    {
        return $prefix . date('Ymd') . substr(implode('', array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8);
    }
    // 查询有效期内的未支付订单
    public function scopeActiveOrder($query)
    {
        $query->where('close_time', '>', self::getFormatTime())->where('state', 0);
    }
    // 查询有效期内的成交订单
    public function scopeDealOrder($query)
    {
        $query->where('close_time', '>', self::getFormatTime(time() - self::$activity_time))->where('state', 1);
    }
    // 查询超时过期订单
    public function scopeTimeoutOrder($query)
    {
        $query->where('close_time', '<', self::getFormatTime())->where('state', 0);
    }
    // 模型多对一关联
    public function payAccount()
    {
        return $this->belongsTo(PayAccount::class, 'aid', 'id');
    }
}
