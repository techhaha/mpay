<?php

class MpayClass
{
    private $pid;
    private $key;
    private $host = 'http://localhost:60';
    private $check_neworder_url;
    private $submit_records_url;
    function __construct($config)
    {
        $this->pid = $config['pid'];
        $this->key = $config['key'];
        $this->check_neworder_url = $this->host . '/order.json';
        $this->submit_records_url = $this->host . '/payHeart';
    }
    public function orderHeart()
    {
        $url = $this->check_neworder_url . "?pid={$this->pid}&sign={$this->getSign()}";
        $res = $this->getHttpResponse($url);
        // { "code":1, "msg":"有2个新订单", "orders":[aids] }
        return $res;
    }
    public function upRecords($records, $aid)
    {
        $header = ['Content-Type: application/json;charset=UTF-8'];
        $url = $this->submit_records_url . "/{$this->pid}/{$aid}/{$this->getSign()}";
        $res = $this->getHttpResponse($url, $header, json_encode($records));
        return $res;
    }

    // 签名方法
    private function getSign()
    {
        return md5($this->pid . $this->key);
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
