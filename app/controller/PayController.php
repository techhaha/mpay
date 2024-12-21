<?php

namespace app\controller;

use think\Request;
use think\facade\View;
use app\model\User;
use app\model\Order;
use app\model\PayAccount;
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
        if (!$req_data) return '参数错误';
        // 验证签名
        $key = User::where('pid', $req_data['pid'])->where('state', 1)->value('secret_key');
        if (!$key) return '用户禁用或不存在';
        $sign_str = self::getSign($req_data, $key);
        if ($req_data['sign'] !== $sign_str) return '签名错误';
        // 检查商户订单
        $out_trade_no = Order::where('out_trade_no', $req_data['out_trade_no'])->value('out_trade_no');
        if ($out_trade_no) return '订单提交重复';
        // 创建新订单
        $order_id = Order::createOrder($req_data);
        if (!$order_id) return '创建订单失败';
        return redirect("/Pay/console/{$order_id}");
    }
    // api提交订单
    public function mapi(Request $request)
    {
        if (!$request->isPost()) return '请使用POST方式提交';
        $req_data = $request->post();
        if (!$req_data) $req_data = $request->get();
        if (!$req_data) return '参数错误';
        // 验证签名
        $key = User::where('pid', $req_data['pid'])->where('state', 1)->value('secret_key');
        if (!$key) return '用户禁用或不存在';
        $sign_str = self::getSign($req_data, $key);
        if ($req_data['sign'] !== $sign_str) return '签名错误';
        // 检查商户订单
        $out_trade_no = Order::where('out_trade_no', $req_data['out_trade_no'])->value('out_trade_no');
        if ($out_trade_no) return '订单提交重复';
        // 创建新订单
        $order_id = Order::createOrder($req_data);
        if (!$order_id) return '创建订单失败';
        $payurl = $request->domain() . "/Pay/console/{$order_id}";
        $info = ['code' => 1, 'msg' => '订单创建成功', 'trade_no' => $order_id, 'qrcode' => $payurl];
        return json($info);
    }
    // 收银台
    public function console($order_id = '')
    {
        if ($order_id) {
            $act_order = Order::where('order_id', $order_id)->find();
            if ($act_order) {
                $channel = PayChannel::where('id', $act_order->cid)->find();
                View::assign($act_order->toArray());
                $passtime = strtotime($act_order->close_time) - time();
                View::assign('passtime', $passtime > 0 ? $passtime : 0);
                View::assign('payUrl', $channel->qrcode);
                View::assign('code_type', $channel->type);
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
                $passtime = strtotime($act_order->close_time) - time();
                $data = [];
                if ($act_order->state === 0) {
                    $data['order_id'] = $act_order->order_id;
                    $data['passtime'] = $passtime > 0 ? $passtime : 0;
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
                    $data['passtime'] = $passtime > 0 ? $passtime : 0;
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
    // 验证支付结果
    public function validatePayResult(Request $request)
    {
        $data = $request->post();
        $order = Order::find($data['id']);
        if (\strtotime($order->close_time) < \time()) {
            return \json(\backMsg(1, '订单已关闭'));
        }
        $up_data = ['id' => $data['id'], 'patt' => $data['patt']];
        $up_res = Order::update($up_data);
        if ($up_res) {
            return \json(\backMsg(0, '更新成功'));
        } else {
            return \json(\backMsg(1, '更新失败'));
        }
    }
    // 处理收款通知
    private function payHeart(array $records, array $config)
    {
        $pid = $config['pid'];
        $aid = $config['aid'];
        // 检测收款通知
        if (!$records) {
            return json(['code' => 0, 'msg' => '空收款通知']);
        }
        // 当前用户账号
        $query = ['pid' => $pid, 'aid' => $aid];
        // 排除已支付订单
        $doneOrders = Order::scope('dealOrder')->where($query)->column('platform_order');
        $new_orders = [];
        foreach ($records as $order) {
            if (!in_array($order['order_no'], $doneOrders)) $new_orders[] = $order;
        }
        if (!count($new_orders)) return json(['code' => 0, 'msg' => '收款通知无新消息']);
        // 有效订单列表
        $activeOrders = Order::scope('activeOrder')->where($query)->select();
        if (!count($activeOrders)) return json(['code' => 0, 'msg' => '数据库无有效期订单']);
        // 查找所有支付渠道
        $channels = $activeOrders->column('cid');
        $cids = PayChannel::whereIn('id', $channels)->column('channel', 'id');
        // 订单处理
        $notify = [];
        foreach ($new_orders as $new_order) {
            foreach ($activeOrders as $order) {
                // 支付方式核对
                $is_payway = $order->type == $new_order['payway'];
                // 支付渠道核对
                $is_channel = $cids[$order->cid] == $new_order['channel'];
                // 金额核对
                $is_money = $order->money == $new_order['price'];
                // 订单核对
                if ($is_payway && $is_channel && $is_money) {
                    $res = $this->updateOrderState($order, $new_order['order_no']);
                    $notify[] = $res;
                }
            }
        }
        if (!$notify) $notify = ['code' => 0, 'msg' => '收款通知无匹配订单'];
        return json($notify);
    }
    // 修改订单状态并通知
    private function updateOrderState(Order $order, string $order_no = ''): array
    {
        // 支付成功
        $set_order_state = $order->save(['state' => 1, 'pay_time' => date('Y-m-d H:i:s', time()), 'platform_order' => $order_no]);
        if (!$set_order_state) {
            return ['order' => $order->order_id, 'code' => 0, 'msg' => '修改订单状态失败'];
        }
        // 订单成交通知
        $notify = self::crateNotify($order);
        // 字符串签名
        $user_key = User::where('pid', $order->pid)->value('secret_key');
        $sign = self::getSign($notify, $user_key);
        $notify['sign'] = $sign;
        // 异步通知
        $res_notify = self::getHttpResponse($order->notify_url . '?' . http_build_query($notify));
        if ($res_notify === 'success') {
            return ['order' => $order->order_id, 'code' => 1, 'msg' => 'notify success'];
        } else {
            return ['order' => $order->order_id, 'code' => 0, 'msg' => 'notify fail'];
        }
    }
    // [定时任务]获取收款明细，提交收款通知
    public function checkPayResult(Request $request)
    {
        $req_info = $request->get();
        $req_pid = $req_info['pid'];
        $req_aid = $req_info['aid'];
        // 获取订单
        $order_path = runtime_path() . '/order.json';
        if (!file_exists($order_path)) return json(['code' => 3, 'msg' => '订单文件不存在']);
        $new_order = json_decode(file_get_contents($order_path), true);
        // 检测新订单
        if ($new_order['code'] !== 1) return json($new_order);
        // 订单列表
        $order_list = $new_order['orders'];
        // 检测本账号订单
        $orders = [];
        foreach ($order_list as $key => $val) {
            if ($req_pid == $val['pid'] && $req_aid == $val['aid'] && $val['patt'] == 1) {
                $orders[] = $order_list[$key];
            }
        }
        if (!$orders) return json(['code' => 0, 'msg' => '非本账号订单或监听模式不对']);
        // 加载配置文件
        $config = PayAccount::getAccountConfig($req_aid);
        if ($config === false) return json(['code' => 4, 'msg' => '监听收款配置错误']);
        // 登陆账号
        $pay_config = ['username' => $config['account'], 'password' => $config['password']];
        // 收款查询
        $query = $config['query'];
        // 实例监听客户端
        $payclient_name = $config['payclass'];
        $payclient_path = "\\payclient\\{$payclient_name}";
        $Payclient = new $payclient_path($pay_config);
        // 获取支付明细
        $records = $Payclient->getOrderInfo($query);
        if ($records) {
            // 提交收款记录
            $upres = $this->payHeart($records, $config);
            return $upres;
        } else {
            return json(['code' => 0, 'msg' => '查询空订单'], 320);
        }
    }
    // [定时任务]监听新订单,生成JSON文件信息
    public function checkOrder($pid = '', $sign = '')
    {
        if (!($pid && $sign)) {
            return '参数错误';
        }
        $is_user = User::checkUser($pid, $sign);
        $path = runtime_path() . 'order.json';
        if ($is_user) {
            $orders = Order::scope('activeOrder')->field('id,pid,aid,cid,patt')->select();
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
    // 处理微信/支付宝收款通知
    public function mpayNotify(Request $request)
    {
        $info = $request->post();
        $action = isset($info['action']) ? $info['action'] : '';
        if ($action === 'mpay') {
            $data = json_decode($info['data'], true);
            if (!is_array($data)) return 200;
            if(!isset($data['aid']) || !isset($data['pid'])) return 202;
            $config = PayAccount::getAccountConfig($data['aid'], $data['pid']);
            $payclient_path = "\\payclient\\{$config['payclass']}";
            $Payclient = new $payclient_path($info, $config);
            $res = $Payclient->notify();
            if (is_int($res)) return $res;
            $this->payHeart($res, $config);
            return 200;
        } else {
            return 202;
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
