<?php

declare(strict_types=1);

namespace app\controller\api;

use think\Request;
use app\model\Order;

class ConsoleController
{
    public function orderinfo(Request $request)
    {
        $date = (int)$request->get('time') ?: 0;
        $time = match ($date) {
            0 => [date('Y') . '-01-01 00:00:00', date('Y-m-d 23:59:59')],
            1 => [date('Y-m-d H:i:s', strtotime('-30 days')), date('Y-m-d 23:59:59')],
            2 => [date('Y-m-d H:i:s', strtotime('-6 months')), date('Y-m-d 23:59:59')],
            3 => [date('Y-m-d H:i:s', strtotime('-1 year')), date('Y-m-d 23:59:59')],
            default => []
        };
        if (!$time) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        $orders = Order::whereBetweenTime('create_time', $time[0], $time[1])->where('state', 1)->field('id,type,really_price')->select();
        $data = [
            'ordernum' => count($orders),
            'totalmoney' => \number_format(array_sum(array_column($orders->toArray(), 'really_price')), 2),
            'wxpay' => [
                'num' => count($orders->where('type', 'wxpay')),
                'money' => \number_format(array_sum(array_column($orders->where('type', 'wxpay')->toArray(), 'really_price')), 2)
            ],
            'alipay' => [
                'num' => count($orders->where('type', 'alipay')),
                'money' => \number_format(array_sum(array_column($orders->where('type', 'alipay')->toArray(), 'really_price')), 2)
            ],
            'unionpay' => [
                'num' => count($orders->where('type', 'unionpay')),
                'money' => \number_format(array_sum(array_column($orders->where('type', 'unionpay')->toArray(), 'really_price')), 2)
            ]
        ];
        return json($data);
    }
}
