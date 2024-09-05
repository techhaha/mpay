<?php

namespace payclient;

class ShouQianBa
{
    private $username;
    private $password;
    private $token_path;
    private $host = 'https://web-platforms-msp.shouqianba.com';
    private $login = '/api/login/ucUser/login';
    private $find_transactions = '/api/transaction/findTransactions';
    private $refresh_token = '/api/login/ucUser/refreshToken';

    function __construct($config)
    {
        $this->username = $config['username'];
        $this->password = $config['password'];
        // 检查token目录
        $dir_path = runtime_path() . 'token/shouqianba';
        if (!is_dir($dir_path)) {
            if (!mkdir($dir_path, 755, true)) {
                echo '目录创建失败';
            }
        }
        $this->token_path = $dir_path . '/' . md5($this->username . $this->password . 'ShouQianBa') . '.json';
        if (!file_exists($this->token_path)) {
            // 失效Token刷新
            $token_info = ['token' => 'Y2FjZmRiMi04MjRjLTQ2NDgtYTU0Ny1lNzg2MDllMTQ1ZTI6MTcxOTQ1Mzg0MzU3OTozNjAwMDAw.txcnX60Za8', 'expire_time' => 1719453843579];
            file_put_contents($this->token_path, json_encode($token_info));
        }
    }
    // 登陆账号
    private function login(): array
    {
        $user_info = [
            "username" => $this->username,
            "password" => md5($this->password),
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
        $header = [
            'Content-Type:application/json;charset=UTF-8',
            'Host:web-platforms-msp.shouqianba.com',
            'Origin:https://s.shouqianba.com',
            'Referer:https://s.shouqianba.com/login',
        ];
        $url = $this->host . $this->login;
        $res = $this->getHttpResponse($url, $header, json_encode($user_info));
        $arr_res = json_decode($res, true);
        $mchUserTokenInfo = $arr_res['data']['mchUserTokenInfo'];
        $token_info = [];
        $token_info['token'] = $mchUserTokenInfo['token'];
        $token_info['expire_time'] = $mchUserTokenInfo['expire_time'];
        // 保存登陆token
        file_put_contents($this->token_path, json_encode($token_info));
        // 返回新token
        return $token_info;
    }
    // 获取token
    private function getToken(): string
    {
        $token_info_find = json_decode(file_get_contents($this->token_path), true);
        // 有效期判断
        $expire_time = intval($token_info_find['expire_time'] / 1000);
        $token = '';
        if ($expire_time - time() < 600) {
            // 刷新token
            $url = $this->host . $this->refresh_token . '?token=' . $token_info_find['token'];
            $header = ["Authorization:Bearer {$token_info_find['token']}"];
            $res = $this->getHttpResponse($url, $header, true);
            $arr_res = json_decode($res, true);
            if ($arr_res['data']['status'] === 0) {
                // 登陆更新token
                $token = ($this->login())['token'];
            } else {
                $token = $arr_res['data']['token'];
                $token_info = [];
                $token_info['token'] = $token;
                $token_info['expire_time'] = $arr_res['data']['expire_time'];
                // 保存Token
                file_put_contents($this->token_path, json_encode($token_info));
            }
        } else {
            $token = $token_info_find['token'];
        }
        return $token;
    }
    // 接口客户端
    public function payQuery(array $query): array
    {
        $token = $this->getToken();
        $url = $this->host . $this->find_transactions . '?client_version=7.0.0&token=' . $token;
        $header = ['Content-Type: application/json;charset=UTF-8'];
        // 构建订单查询
        $now = time();
        $begin_time = ($now - 175) * 1000;
        $end_time = $now * 1000;
        $query['date_start'] = $begin_time;
        $query['date_end'] = $end_time;
        // 查询订单列表
        $res_order = $this->getHttpResponse($url, $header, json_encode($query));
        $arr_res = json_decode($res_order, true);
        $list = [];
        if ($arr_res['code'] === 50000) {
            $list = $arr_res['data']['records'];
        }
        // 重构订单流水，返回数组
        $moneyList = [];
        if ($list) {
            $order_no = [];
            $payway = [];
            $price = [];
            $channel = [];
            $payways = [2 => 'alipay', 3 => 'wxpay'];
            foreach ($list as $value) {
                // 平台订单流水号
                $order_no[] = $value['order_sn'];
                // 支付类型
                $payway[] = $payways[$value['payway']];
                // 收款金额
                $price[] = $value['original_amount'] / 100;
                // 收款渠道(二维码编号)
                $channel[] = $value['terminal_device_fingerprint'];
            }
            $moneyList['order_no'] = $order_no;
            $moneyList['payway'] = $payway;
            $moneyList['price'] = $price;
            $moneyList['channel'] = $channel;
        }
        return $moneyList;
    }
    // 请求外部资源
    private function getHttpResponse($url, $header = [], $post = null, $timeout = 10)
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
