<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\Order;
use app\model\User;

class OrderController extends BaseController
{
    // 查询订单
    public function getOrders()
    {
        $query = $this->request->get();
        $orders = Order::serchOrders($query)->order('id', 'desc')->paginate(['list_rows' => $query['limit'], 'page' => $query['page']]);
        if ($orders) {
            return json(['code' => 0, 'msg' => 'OK', 'count' => $orders->total(), 'data' => $orders->items()]);
        } else {
            return json(['code' => 1, 'msg' => '无数据记录', 'count' => 0, 'data' => []]);
        }
    }
    // 修改订单支付状态
    public function changeOrderState()
    {
        $info = $this->request->post();
        $uporder_res = Order::update(['state' => $info['state'], 'id' => $info['id']]);
        if ($uporder_res) {
            return json(\backMsg(0, '修改成功'));
        } else {
            return json(\backMsg(1, '修改失败'));
        }
    }
    // 手动补单
    public function doPayOrder()
    {
        $info = $this->request->post();
        // 修改支付状态
        $order = Order::find($info['id']);
        $order->state = $info['state'];
        $res = $order->save();
        if ($res) {
            // 创建通知
            $notify = self::crateNotify($order);
            // 字符串签名
            $user_key = User::where('pid', $order->pid)->value('secret_key');
            $sign = self::getSign($notify, $user_key);
            $notify['sign'] = $sign;
            // 异步通知
            $notify_url = $order->notify_url . '?' . http_build_query($notify);
            if (strpos($order->notify_url, '?')) $notify_url = $order->notify_url . '&' . http_build_query($notify);
            $res_notify = self::getHttpResponse($notify_url);
            if ($res_notify === 'success') {
                return json(\backMsg(0, '订单通知成功'));
            } else {
                return json(\backMsg(1, '异步通知失败'));
            }
        } else {
            return json(\backMsg(1, '支付状态修改失败'));
        }
    }
    // 重新通知
    public function redoPayOrder()
    {
        $id = $this->request->post('id');
        // 修改支付状态
        $order = Order::find($id);
        if ($order) {
            // 创建通知
            $notify = self::crateNotify($order);
            // 字符串签名
            $user_key = User::where('pid', $order->pid)->value('secret_key');
            $sign = self::getSign($notify, $user_key);
            $notify['sign'] = $sign;
            // 异步通知
            $notify_url = $order->notify_url . '?' . http_build_query($notify);
            if (strpos($order->notify_url, '?')) $notify_url = $order->notify_url . '&' . http_build_query($notify);
            $res_notify = self::getHttpResponse($notify_url);
            if ($res_notify === 'success') {
                return json(\backMsg(0, '订单通知成功'));
            } else {
                return json(\backMsg(1, '异步通知失败'));
            }
        } else {
            return json(\backMsg(1, '订单不存在'));
        }
    }
    // 删除订单
    public function deleteOrder()
    {
        $id = $this->request->post('id');
        $del_res = Order::destroy($id);
        if ($del_res) {
            return json(\backMsg(0, '删除成功'));
        } else {
            return json(\backMsg(1, '删除失败'));
        }
    }
    // 批量删除订单
    public function batchRemove()
    {
        $ids = $this->request->post('ids');
        if (!$ids) {
            return json(\backMsg(1, '参数错误'));
        }
        $del_res = Order::destroy($ids);
        if ($del_res) {
            return json(\backMsg(0, '删除成功'));
        } else {
            return json(\backMsg(1, '删除失败'));
        }
    }
    // 清空超时订单
    public function batchTimeout()
    {
        $ids = Order::scope('timeoutOrder')->column('id');
        if (!$ids) {
            return json(\backMsg(1, '无过期订单'));
        }
        $batch_del_res = Order::destroy($ids);
        if ($batch_del_res) {
            return json(\backMsg(0, '清理成功'));
        } else {
            return json(\backMsg(1, '清理失败'));
        }
    }

    // 签名方法
    private static function getSign(array $param = [], string $key = ''): string
    {
        if (!$param)
            return '参数错误';
        if (!$key)
            return '密钥错误';
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
    // 构建通知参数
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
        // $notify = array_merge($notify, unserialize($param->param));
        $notify['param'] = unserialize($param->param);
        // 删除空值
        foreach ($notify as $key => $val) {
            if ($val === '') unset($notify[$key]);
        }
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
