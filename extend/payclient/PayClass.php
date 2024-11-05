<?php

declare(strict_types=1);

namespace payclient;

class PayClass
{
    // 收款平台
    private $pay_type = 'ysepay';
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
    private $payhost = 'https://xym.ysepay.com';
    // 用户登陆接口
    private $login_path = '/saas_merchant_management/public/login';
    // 订单查询接口
    private $order_query_path = '/saas_merchant_management/order/info';
    // 验证码接口
    private $captcha_path = '/saas_merchant_management/public/captcha';

    function __construct(array $config)
    {
        $this->username = $config['username'];
        $this->password = $config['password'];
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
        $payways = ['ALI' => 'alipay', 'WECHAT' => 'wxpay'];
        foreach ($order_list as $value) {
            $order = [];
            // 平台订单流水号
            $order['order_no'] = $value['tranAcpBsNo'];
            // 支付类型
            $order['payway'] = $payways[$value['tranChannelType']];
            // 收款金额
            $order['price'] = (float)$value['tranOrdAmt'];
            // 收款渠道(二维码编号)
            $order['channel'] = $value['bsShopCode'];
            // 添加到订单列表
            $orders[] = $order;
        }
        return $orders;
    }
    // 查询订单
    private function queryOrder(array $query, $times = 0): array
    {
        // 查询订单列表
        $url = $this->payhost . $this->order_query_path;
        $token = $this->getToken();
        $header = ['Host:xym.ysepay.com', 'token:' . $token];
        $new_query = $this->getOrderQuery($query);
        $res = $this->getHttpResponse($url . '?' . http_build_query($new_query), $header, json_encode($new_query));
        $result = json_decode($res, true);
        // 检查订单信息
        $order_list = [];
        if ($result['code'] === 0) {
            $order_list = $result['data']['list'];
        } else {
            // 重试3次
            if ($times < 3) {
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
        $startTime = date('Y-m-d H:i:s', $now - 175);
        $endTime = date('Y-m-d H:i:s', $now);
        $new_query['queryStartTime'] = $startTime;
        $new_query['queryEndTime'] = $endTime;
        $new_query['time'] = $now . mt_rand(100, 999);
        return $new_query;
    }
    // 获取验证码图像
    private function getCaptcha(): array
    {
        $url = $this->payhost . $this->captcha_path . '?time=' . $this->now . mt_rand(100, 999);
        $header = ['Host:xym.ysepay.com'];
        $res = $this->getHttpResponse($url, $header);
        $data = json_decode($res, true);
        $captcha_info = [];
        if ($data['code'] === 0) {
            $captcha_info['randomCodeBase64'] = $data['data']['randomCodeBase64'];
            $captcha_info['uuid'] = $data['data']['uuid'];
        }
        return $captcha_info;
    }
    // 登陆账号
    private function login($times = 0): bool
    {
        $captcha_info = $this->getCaptcha();
        $captcha = $this->getCaptchaInfo($captcha_info['randomCodeBase64']);
        $url = $this->payhost . $this->login_path;
        $user_info = [
            'loginName' => $this->username,
            'password' => $this->password,
            'randomCode' => $captcha,
            'uuid' => $captcha_info['uuid'],
            'time' => (int)($this->now . mt_rand(100, 999))
        ];
        $header = ['Host:xym.ysepay.com'];
        $res = $this->getHttpResponse($url, $header, json_encode($user_info));
        $data = json_decode($res, true);
        if ($data['code'] === 0) {
            // 如何需要，就保存token
            $this->saveToken($data['data']);
            return true;
        } else {
            // 重试3次
            $is_login = false;
            if ($times < 3) {
                $is_login = $this->login($times + 1);
                return $is_login;
            }
            return $is_login;
        }
    }
    // 更新token
    private function updateToken(): bool
    {
        $is_login = $this->login();
        return $is_login;
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
    // 解析验证码
    private function getCaptchaInfo(string $image = '', string $typeid = '3'): string
    {
        $api_url = 'http://api.ttshitu.com/predict';
        $info = ['username' => '2679275057', 'password' => '7698177hcnTJ', 'typeid' => $typeid, 'image' => $image];
        $res = $this->getHttpResponse($api_url, [], json_encode($info));
        $data = json_decode($res, true);
        $captcha = '';
        if ($data['success'] === true) {
            $captcha = $data['data']['result'];
        }
        return $captcha;
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
