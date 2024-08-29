<?php

declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\facade\View;
use app\model\User;
use app\model\Order;
use app\model\PayChannel;

class PayController
{
    // 提交订单
    public function submit(Request $request)
    {
        $req_method = $request->method();
        $req_data = match ($req_method) {
            'GET' => $request->get(),
            'POST' => $request->post(),
            default => []
        };
        if (!$req_data) {
            return '参数错误';
        }
        $key = User::where('pid', $req_data['pid'])->where('state', 1)->value('secret_key');
        if (!$key) {
            return '用户禁用或不存在';
        }
        $sign_str = self::getSign($req_data, $key);
        if ($req_data['sign'] === $sign_str) {
            // 检查商户订单
            $out_trade_no = Order::where('out_trade_no', $req_data['out_trade_no'])->value('out_trade_no');
            if (!$out_trade_no) {
                // 创建新订单
                $order_id = Order::createOrder($req_data);
                if ($order_id) {
                    return redirect("/Pay/console/{$order_id}");
                } else {
                    return '创建订单失败';
                }
            } else {
                return '订单提交重复';
            }
        } else {
            return '签名错误';
        }
    }
    // api提交订单
    public function mapi(Request $request)
    {
        if ($request->isPost()) {
            $req_data = $request->post();
            if (!$req_data) {
                $req_data = $request->get();
                if (!$req_data) {
                    return '参数错误';
                }
            }
        } else {
            return '请使用POST方式提交';
        }
        $key = User::where('pid', $req_data['pid'])->where('state', 1)->value('key');
        if (!$key) {
            return '用户禁用或不存在';
        }
        $sign_str = self::getSign($req_data, $key);
        if ($req_data['sign'] === $sign_str) {
            // 检查商户订单
            $out_trade_no = Order::where('out_trade_no', $req_data['out_trade_no'])->value('out_trade_no');
            if (!$out_trade_no) {
                // 创建新订单
                $order_id = Order::createOrder($req_data);
                if ($order_id) {
                    $payurl = $request->domain() . "/Pay/console/{$order_id}";
                    $info = ['code' => 1, 'msg' => '订单创建成功', 'trade_no' => $order_id, 'qrcode' => $payurl];
                    return json($info);
                } else {
                    return '创建订单失败';
                }
            } else {
                return '订单提交重复';
            }
        } else {
            return '签名错误';
        }
    }
    // 收银台
    public function console($order_id = '')
    {
        if ($order_id) {
            $act_order = Order::where('order_id', $order_id)->field(['out_trade_no', 'name', 'really_price', 'close_time', 'type', 'order_id', 'cid'])->find();
            if ($act_order) {
                $qrcode = PayChannel::where('id', $act_order->cid)->value('qrcode');
                View::assign($act_order->toArray());
                View::assign('payUrl', $qrcode);
                return View::fetch();
            } else {
                return '订单不存在';
            }
        } else {
            return '订单号参数错误';
        }
    }
    // 查询订单状态
    public function getOrderState($order_id = '')
    {
        if ($order_id) {
            $act_order = Order::where('order_id', $order_id)->find();
            if ($act_order) {
                $data = [];
                if ($act_order->state === 0) {
                    $data['order_id'] = $act_order->order_id;
                    $data['state'] = $act_order->state;
                    return json($data);
                } elseif ($act_order->state === 1) {
                    // 通知参数
                    $notify = self::crateNotify($act_order);
                    // 字符串签名
                    $user_key = User::where('pid', $act_order->pid)->value('secret_key');
                    $sign = self::getSign($notify, $user_key);
                    $notify['sign'] = $sign;
                    // 跳转通知URL
                    $res_return_url = $act_order->return_url . '?' . http_build_query($notify);
                    // 响应消息
                    $data['order_id'] = $act_order->order_id;
                    $data['state'] = $act_order->state;
                    $data['return_url'] = $res_return_url;
                    return json($data);
                }
            } else {
                return '订单不存在';
            }
        } else {
            return '订单号参数错误';
        }
    }
    // 处理收款通知
    public function payHeart($pid = '', $aid = '', $sign = '')
    {
        // 检测请求参数
        if (!($pid && $aid && $sign)) {
            return '参数错误';
        }
        // 检测收款通知
        $payList = request()->post();
        if (!$payList) {
            return json(['code' => 0, 'msg' => '空收款通知']);
        }
        // 签名验证
        $is_user = User::checkUser($pid, $sign);
        if (!$is_user) {
            return json(['code' => 0, 'msg' => '签名错误']);
        }
        // 当前用户账号
        $query = ['pid' => $pid, 'aid' => $aid];
        // 排除有效期内的已支付订单
        $doneOrders = Order::scope('dealOrder')->where($query)->column('platform_order');
        if ($doneOrders) {
            $num = count($payList['order_no']);
            for ($i = 0; $i < $num; $i++) {
                if (in_array($payList['order_no'][$i], $doneOrders)) {
                    $payList['price'][$i] = 0;
                }
            }
        }
        if (array_sum($payList['price']) === 0) {
            return json(['code' => 0, 'msg' => '查询无新订单']);
        }
        // 有效订单列表
        $activeOrders = Order::scope('activeOrder')->where($query)->select();
        if (!\count($activeOrders)) {
            return json(['code' => 0, 'msg' => '无有效期订单']);
        }
        // $msg = []; 订单高并发预留
        foreach ($activeOrders as $order) {
            $index = array_search($order->really_price, $payList['price']);
            // 付款金额检查
            if ($index !== false) {
                // 已支付订单容错查询
                $is_order_no = Order::where('platform_order', $payList['order_no'][$index])->where($query)->find();
                // 支付方式核对
                $is_payway = $order->type === $payList['payway'][$index];
                // 支付渠道核对
                $is_channel = PayChannel::where('id', $order->cid)->value('channel') === $payList['channel'][$index];
                // 全部核对通过，修改订单状态
                if (!$is_order_no && $is_payway && $is_channel) {
                    // 支付成功
                    $set_order_state = $order->save(['state' => 1, 'pay_time' => date('Y-m-d H:i:s', time()), 'platform_order' => $payList['order_no'][$index]]);
                    // 订单成交通知
                    if (!$set_order_state) {
                        return json(['code' => 0, 'msg' => '修改订单状态失败']);
                    }
                    $notify = self::crateNotify($order);
                    // 字符串签名
                    $user_key = User::where('pid', $order->pid)->value('secret_key');
                    $sign = self::getSign($notify, $user_key);
                    $notify['sign'] = $sign;
                    // 异步通知
                    $res_notify = self::getHttpResponse($order->notify_url . '?' . http_build_query($notify));
                    if ($res_notify === 'success') {
                        return json(['code' => 0, 'msg' => 'success']);
                    } else {
                        return json(['code' => 1, 'msg' => '异步通知失败']);
                    }
                }
            }
        }
    }
    // [定时任务]监听新订单,生成JSON文件信息
    public function checkOrder($pid = '', $sign = '')
    {
        if (!($pid && $sign)) {
            return '参数错误';
        }
        $is_user = User::checkUser($pid, $sign);
        $path = '../runtime/order.json';
        if ($is_user) {
            $orders = Order::scope('activeOrder')->field('id,pid,aid,cid')->select();
            if (!file_exists($path)) {
                file_put_contents($path, '[]');
            }
            $old_info = file_get_contents($path);
            $num = count($orders);
            if ($num > 0) {
                $info = ['code' => 1, 'msg' => "有{$num}个新订单"];
                $order_list = ['code' => 1, 'msg' => "有{$num}个新订单", 'orders' => $orders];
                if ($old_info !== json_encode($order_list)) {
                    file_put_contents($path, json_encode($order_list));
                }
                return json($info);
            } else {
                $info = ['code' => 0, 'msg' => '没有新订单'];
                if ($old_info !== json_encode($info, 320)) {
                    file_put_contents($path, json_encode($info, 320));
                }
                return json($info);
            }
        } else {
            $info = ['code' => 2, 'msg' => '签名错误'];
            file_put_contents($path, json_encode($info, 320));
            return json($info);
        }
    }
    // 获取收款通知，提交收款订单明细
    public function checkPayResult(Request $request)
    {
        $req_info = $request->get();
        $req_pid = $req_info['pid'];
        $req_aid = $req_info['aid'];
        // 加载配置文件
        $config = \think\facade\Config::load("payconfig/{$req_pid}_{$req_aid}", 'payconfig');
        // 用户账号配置
        $user_config = isset($config['user']) ? $config['user'] : [];
        // 收款平台账号配置
        $pay_config = isset($config['pay']) ? $config['pay'] : [];
        // 配置检查
        if ($user_config && $pay_config) {
            // 账号配置信息
            $pid = $user_config['pid'];
            $aid = $pay_config['aid'];
            if (!($req_pid == $pid && $req_aid == $aid)) {
                return '监听收款配置不一致';
            }
        } else {
            return '监听收款配置文件名错误';
        }
        // 实例化支付类
        $Mpay = new \MpayClass($user_config);
        // 获取订单
        $res_new_order = $Mpay->orderHeart();
        $new_order = json_decode($res_new_order, true);
        // 检测新订单
        if ($new_order['code'] !== 1) {
            return $res_new_order;
        }
        // 订单列表
        $order_list = $new_order['orders'];
        // 检测本账号订单
        $orders = [];
        foreach ($order_list as $key => $val) {
            if ($pid == $val['pid'] && $aid == $val['aid']) {
                $orders[] = $order_list[$key];
            }
        }
        if (!$orders) {
            return \json(['code' => 0, 'msg' => '非本账号订单']);
        }
        // 收款平台
        $platform = ['sqbpay' => 'ShouQianBa', 'storepay' => 'ZhiHuiJingYing', 'mqpay' => 'MaQian', 'ysepay' => 'Ysepay'];
        // 登陆账号
        $config = ['username' => $pay_config['account'], 'password' => $pay_config['password']];
        // 收款查询
        $query = $pay_config['query'];
        // 实例监听客户端
        $payclient_name = $platform[$pay_config['platform']];
        $payclient_path = "\\payclient\\{$payclient_name}";
        $Payclient = new $payclient_path($config);
        // 获取支付明细
        $records = $Payclient->payQuery($query);
        if ($records) {
            // 提交收款记录
            $upres = $Mpay->upRecords($records, $aid);
            return $upres;
        } else {
            return \json(['code' => 0, 'msg' => '查询空订单'], 320);
        }
    }
    // 签名
    private static function getSign(array $param = [], string $key = ''): string
    {
        ksort($param);
        reset($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "sign" && $k != "sign_type" && $v != '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        $signstr .= $key;
        $sign = md5($signstr);
        return $sign;
    }
    // 构建通知
    private static function crateNotify($param): array
    {
        $notify = [
            'pid' => $param->pid,
            'trade_no' => $param->order_id,
            'out_trade_no' => $param->out_trade_no,
            'type' => $param->type,
            'name' => $param->name,
            'money' => $param->money,
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type' => 'MD5',
        ];
        // 添加扩展参数
        $notify = array_merge($notify, unserialize($param->param));
        return $notify;
    }
    // 请求外部资源
    private static function getHttpResponse($url, $header = [], $post = null, $timeout = 10)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($header) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        } else {
            $httpheader[] = "Accept: */*";
            $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
            $httpheader[] = "Connection: close";
            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        }
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
