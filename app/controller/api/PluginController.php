<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;

class PluginController extends BaseController
{
    // 获取插件列表
    public function getPluginList()
    {

        $local_plugin_config = self::getPluginConfig();
        $plugin_config = \Plugin::getPluginList($local_plugin_config);
        if ($plugin_config) {
            return json(['code' => 0, 'msg' => 'OK', 'count' => count($plugin_config), 'data' => $plugin_config]);
        } else {
            return json(['code' => 1, 'msg' => '无数据记录', 'count' => 0, 'data' => []]);
        }
    }
    // 添加插件
    public function addPlugin(array $option = [])
    {
        $keys = ['platform', 'name', 'class_name', 'price', 'describe', 'website', 'state', 'query'];
        $config = [];
        foreach ($option as $key => $value) {
            if (in_array($key, $keys)) {
                $config[$key] = $value;
            }
        }
        $plugin_config = self::getPluginConfig();
        $plugin_platform = $config['platform'] ?: '';
        foreach ($plugin_config as $value) {
            if ($plugin_platform == $value['platform']) {
                return 1; //'插件已存在'
            }
        }
        $plugin_config[] = $config;
        $this->savePluginConfig($plugin_config, '支付插件列表');
        return 0;
    }
    // 删除插件
    public function delPlugin($plugin_name = '')
    {
        $plugin_config = self::getPluginConfig();
        $keys = [];
        foreach ($plugin_config as $index => $value) {
            if ($value['platform'] == $plugin_name) {
                $keys[] = $index;
            }
        }
        foreach ($keys as $index) {
            unset($plugin_config[$index]);
        }
        $config = \array_values($plugin_config);
        $this->savePluginConfig($config, '支付插件列表');
        return 0;
    }
    // 修改插件
    public function setPlugin($platform = '', $option = [])
    {
        $config = self::getPluginConfig();
        if (!$platform) {
            return 1; // 请选择插件
        }
        if (!$option) {
            return 2; // 请添加插件配置
        }
        foreach ($config as $index => $options) {
            if ($options['platform'] == $platform) {
                foreach ($options as $key => $value) {
                    if (\array_key_exists($key, $option)) {
                        $config[$index][$key] = $option[$key];
                    }
                }
                $this->savePluginConfig($config, '支付插件列表');
                return 0;
            }
        }
    }
    // 插件启用
    public function pluginEnable()
    {
        $info = $this->request->post();
        $up_res = $this->setPlugin($info['platform'], ['state' => $info['state']]);
        if ($up_res) {
            return json(\backMsg(1, '失败'));
        } else {
            return json(\backMsg(0, '成功'));
        }
    }
    // 插件选项
    public function pluginOption()
    {
        // 加载平台配置
        $config = self::getPluginConfig();
        $option = [];
        foreach ($config as $value) {
            if ($value['state'] == 0) {
                continue;
            }
            $option[] = ['platform' => $value['platform'], 'name' => $value['name']];
        }
        return json($option);
    }
    // 获取指定插件配置
    public static function getPluginInfo($platform = '')
    {
        $config = self::getPluginConfig();
        $info = [];
        foreach ($config as $item) {
            if ($item['platform'] == $platform) {
                $info = $item;
                break;
            }
        }
        return $info;
    }
    // 获取插件配置
    private static function getPluginConfig(): array
    {
        $payplugin_path = config_path() . '/extendconfig/payplugin.php';
        if (!file_exists($payplugin_path)) {
            return [];
        }
        // 加载插件配置
        $payplugin_config = require $payplugin_path;
        return $payplugin_config;
    }
    // 保存插件配置
    private function savePluginConfig(array $config, string $note = '说明')
    {
        $payplugin_path = config_path() . '/extendconfig/payplugin.php';
        $note_tpl = <<<EOF
// +----------------------------------------------------------------------
// | $note
// +----------------------------------------------------------------------
EOF;
        $config_str = "<?php\n" . $note_tpl . "\n\nreturn " . var_export($config, true) . ";\n";
        \file_put_contents($payplugin_path, $config_str);
    }
}
