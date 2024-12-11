<?php

declare(strict_types=1);

class ImgCaptcha
{
    // 图鉴 http://www.ttshitu.com/
    public static function ttShiTu(string $image = '', string $typeid = '3'): string
    {
        $api_url = 'http://api.ttshitu.com/predict';
        $info = [
            'username' => '2679275057',
            'password' => '7698177hcnTJ',
            'typeid' => $typeid,
            'image' => $image
        ];
        $res = self::getHttpResponse($api_url, [], json_encode($info));
        $data = json_decode($res, true);
        $captcha = '';
        if ($data['success'] === true) {
            $captcha = $data['data']['result'];
        }
        return $captcha;
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
