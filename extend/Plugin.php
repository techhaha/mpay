<?php

declare(strict_types=1);

class Plugin
{
    private static $siteUrl = 'https://api.qcjy.cc';
    // 获取全部插件（含本地）
    public static function getAllPlugins(array $local_plugin = []): array
    {
        $app_plugin = [];
        $app_plugin_all = self::getAllPlugin();
        $local_plugin = self::getInstall($local_plugin);
        $plugin_uninstall = self::getUninstall($app_plugin_all, $local_plugin);
        $app_plugin = array_merge($local_plugin, $plugin_uninstall);
        return $app_plugin;
    }
    // 获取未安装插件
    public static function getUninstallPlugins(array $local_plugin = []): array
    {
        return self::getUninstall(self::getAllPlugin(), $local_plugin);
    }
    // 获取已安装插件
    public static function getInstall(array $local_plugin = []): array
    {
        if (empty($local_plugin))  return [];
        foreach ($local_plugin as $key => $value) {
            $local_plugin[$key]['install'] = true;
        }
        return $local_plugin;
    }
    // 获取未安装插件
    public static function getUninstall(array $app_plugin = [], array $local_plugin = []): array
    {
        $uninstall_plugin = [];
        $install = [];
        if (!empty($local_plugin)) {
            foreach ($local_plugin as $e_val) {
                $install[] = $e_val['platform'];
            }
        }
        foreach ($app_plugin as $i_val) {
            if (in_array($i_val['platform'], $install)) {
                continue;
            }
            $val = $i_val;
            $val['install'] = false;
            $uninstall_plugin[] = $val;
        }
        return $uninstall_plugin;
    }
    // 获取平台所有支持插件
    public static function getAllPlugin(): array
    {
        $app_plugin = cache('app_plugin');
        if ($app_plugin) return $app_plugin;
        $app_plugin = self::getHttpResponse(self::$siteUrl . '/MpayApi', ['action' => 'getPluginList']);
        $info = json_decode($app_plugin, true);
        if ($info['code'] === 0) cache('app_plugin', $info['data'], 36000);
        return $info['data'];
    }
    // 获取通知消息
    public static function getNotifyMessage(): array
    {
        $message = cache('message');
        if ($message) return $message;
        $message = self::getHttpResponse(self::$siteUrl . '/MpayApi', ['action' => 'message'], [], 3);
        $info = json_decode($message, true);
        if ($info === null) return [];
        if ($info['code'] === 0) cache('message', $info['data'], 36000);
        return $info['data'];
    }
    // 安装插件
    public static function installPlugin($platform): array
    {
        $res = self::getHttpResponse(self::$siteUrl . '/MpayApi', ['action' => 'installPlugin', 'platform' => $platform, 'host' => parse_url(request()->domain(), PHP_URL_HOST)]);
        // halt($res);
        return json_decode($res, true);
    }
    // 更新插件
    public static function updatePlugin($platform): array
    {
        $res = self::getHttpResponse(self::$siteUrl . '/MpayApi', ['action' => 'updatePlugin', 'platform' => $platform, 'host' => parse_url(request()->domain(), PHP_URL_HOST)]);
        return json_decode($res, true);
    }
    // 请求外部资源
    private static function getHttpResponse($url,  $post = null, $header = [], $timeout = 10)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader = [
            "Accept: application/json",
            "Accept-Language: zh-CN,zh;q=0.9",
            "Connection: close",
            "mpayAgent: your_mpay_agent_identifier"
        ];
        $httpheader = array_merge($httpheader, $header);
        if ($post) {
            if (!is_string($post)) $post = json_encode($post);
            $httpheader[] = "Content-Type: application/json";
            $httpheader[] = "Content-Length: " . strlen($post);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        // 检查 cURL 请求是否出错
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error: $error");
        }
        curl_close($ch);
        return $response;
    }
}
