<?php

declare(strict_types=1);

namespace payclient;

class ShouQianBa
{
    // 收款平台
    private $pay_type = 'sqbpay';
    // 收款平台账号
    private $username;
    // 平台登陆密码
    private $password;
    // token保存路径
    private $token_path;
    // Cookie保存路径
    private $cookie_path;
    // 当前时间戳
    private $now;
    // 收款平台网站
    private $payhost = 'https://web-platforms-msp.shouqianba.com';
    // 用户登陆接口
    private $login_path = '/api/login/ucUser/login';
    // 订单查询接口
    private $order_query_path = '/api/transaction/findTransactions';
    // 刷新Token接口
    private $refresh_token_path = '/api/login/ucUser/refreshToken';

    function __construct(array $config)
    {
        $this->username = $config['username'];
        $this->password = md5($config['password']);
        $this->now = time();
        // 检查token/cookie目录
        $dir_path = runtime_path() . "token/{$this->pay_type}/";
        if (!is_dir($dir_path)) {
            if (!mkdir($dir_path, 755, true)) echo '创建token/cookie目录失败';
        }
        // token/cookie文件路径
        $this->token_path = $dir_path . md5($this->username . $this->password . __CLASS__) . '.json';
        $this->cookie_path = $dir_path . md5($this->username . $this->password . __CLASS__) . '.txt';
        // 检查token文件
        if (!file_exists($this->token_path)) {
            file_put_contents($this->token_path, json_encode(['token' => 'ok', 'update_time' => date('Y-m-d H:i:s')]));
        }
    }
    // 获取订单信息
    public function getOrderInfo(array $query): array
    {
        $order_list = $this->queryOrder($query);
        $orders = [];
        if (!$order_list) return $orders;
        $payways = [2 => 'alipay', 3 => 'wxpay'];
        foreach ($order_list as $value) {
            $order = [];
            // 平台订单流水号
            $order['order_no'] = $value['order_sn'];
            // 支付类型
            $order['payway'] = $payways[$value['payway']];
            // 收款金额
            $order['price'] = (float)($value['original_amount'] / 100);
            // 收款渠道(二维码编号)
            $order['channel'] = $value['terminal_device_fingerprint'];
            // 添加到订单列表
            $orders[] = $order;
        }
        return $orders;
    }
    // 查询订单
    private function queryOrder(array $query, $times = 0): array
    {
        // 查询订单列表
        $token = $this->getToken();
        $url = $this->payhost . $this->order_query_path . '?client_version=7.0.0&token=' . $token;
        $header = ['Content-Type: application/json;charset=UTF-8'];
        $new_query = $this->getOrderQuery($query);
        $res = $this->getHttpResponse($url, $header, json_encode($new_query));
        $result = json_decode($res, true);
        // 检查订单信息
        $order_list = [];
        if ($result['code'] === 50000) {
            $order_list = $result['data']['records'];
        } else {
            // 重试1次
            if ($times < 1) {
                $this->updateToken();
                $order_list = $this->queryOrder($query, $times + 1);
            }
        }
        return $order_list;
    }
    // 构建订单查询数组信息
    private function getOrderQuery(array $query): array
    {
        $new_query = $query;
        $now = $this->now;
        $begin_time = (int)(($now - 175) . mt_rand(100, 999));
        $end_time = (int)($now . mt_rand(100, 999));
        $query['date_start'] = $begin_time;
        $query['date_end'] = $end_time;
        return $new_query;
    }
    // 登陆账号
    private function login($times = 0): bool
    {
        $url = $this->payhost . $this->login_path;
        $user_info = [
            "username" => $this->username,
            "password" => $this->password,
            "uc_device" => [
                "device_type" => 2,
                "default_device" => 0,
                "platform" => "商户服务平台",
                "device_fingerprint" => "12340d18-e414-49cf-815a-66ab8ec1a480",
                "device_name" => "收钱吧商户平台",
                "device_model" => "Windows",
                "device_brand" => "Chrome"
            ]
        ];
        $header = ['Content-Type:application/json;charset=UTF-8', 'Host:web-platforms-msp.shouqianba.com', 'Origin:https://s.shouqianba.com', 'Referer:https://s.shouqianba.com/login'];
        $res = $this->getHttpResponse($url, $header, json_encode($user_info));
        $data = json_decode($res, true);
        if ($data['code'] === 50000 && $data['data']['code'] === 50000) {
            // 保存token
            $this->saveToken($data['data']['mchUserTokenInfo']);
            return true;
        } else {
            // 重试2次
            $is_login = false;
            if ($times < 2) {
                $is_login = $this->login($times + 1);
                return $is_login;
            }
            return $is_login;
        }
    }
    // 更新token
    private function updateToken(): bool
    {
        $token = $this->getToken();
        $url = $this->payhost . $this->refresh_token_path . '?token=' . $token;
        $header = ["Authorization:Bearer {$token}"];
        $res = $this->getHttpResponse($url, $header, true);
        $data = json_decode($res, true);
        if ($data['data']['status'] === 0) {
            // 登陆刷新Token
            $this->login();
        } else {
            $this->saveToken($data['data']);
        }
        return true;
    }
    // 获取token
    private function getToken(): string
    {
        $token_info = json_decode(file_get_contents($this->token_path), true);
        return $token_info['token'];
    }
    // 保存token
    private function saveToken($data)
    {
        $token = $data['token'];
        file_put_contents($this->token_path, json_encode(['token' => $token, 'update_time' => date('Y-m-d H:i:s')]));
    }
    // 请求外部资源
    private function getHttpResponse($url, $header = [], $post = null, $timeout = 10)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_path);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_path);
        if ($header) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        } else {
            $httpheader[] = "Accept: */*";
            $httpheader[] = "Accept-Language: zh-CN,zh;q=0.9";
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
