<?php

declare(strict_types=1);

class Plugin
{
    private static $siteUrl = 'https://api.zhaidashi.cn';
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
        foreach ($local_plugin as $e_val) {
            $install[] = $e_val['platform'];
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
        $app_plugin = self::getHttpResponse(self::$siteUrl . '/Mpay/getPlugins');
        return json_decode($app_plugin, true);
    }
    // 获取通知消息
    public static function getNotifyMessage(): array
    {
        $message = self::getHttpResponse(self::$siteUrl . '/Mpay/message');
        return json_decode($message, true);
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
