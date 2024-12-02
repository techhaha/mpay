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
        $show = $this->request->get('show', 1);
        $plugin_config = match ((int)$show) {
            0 => \Plugin::getAllPlugins($local_plugin_config),
            1 => \Plugin::getInstall($local_plugin_config),
            2 => \Plugin::getUninstallPlugins($local_plugin_config),
            default => []
        };
        if ($plugin_config) {
            return json(['code' => 0, 'msg' => 'OK', 'count' => count($plugin_config), 'data' => $plugin_config]);
        } else {
            return json(['code' => 1, 'msg' => '无数据记录', 'count' => 0, 'data' => []]);
        }
    }
    // 卸载插件
    public function uninstallPlugin()
    {
        $platform = $this->request->post('platform');
        $classname = $this->request->post('classname');
        if (!$platform || !$classname) {
            return json(backMsg(1, '请选择插件'));
        }
        $res1 = $this->delPlugin($platform);
        $res2 = $this->delPluginFile($classname);
        if ($res1 && $res2) {
            return json(backMsg(0, '卸载成功'));
        } else {
            return json(backMsg(1, '插件不存在'));
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
    // 删除插件配置
    private function delPlugin(string $plugin_name = '')
    {
        $plugin_config = self::getPluginConfig();
        $index = null;
        foreach ($plugin_config as $i => $value) {
            if ($value['platform'] == $plugin_name) {
                $index = $i;
            }
        }
        if ($index === null) {
            return false; // 插件不存在
        }
        unset($plugin_config[$index]);
        $config = array_values($plugin_config);
        $this->savePluginConfig($config, '支付插件列表');
        return true;
    }
    // 删除插件类库文件
    private function delPluginFile(string $file_name = '')
    {
        $plugin_path = root_path() . '/extend/payclient/' . $file_name . '.php';
        if (file_exists($plugin_path)) {
            unlink($plugin_path);
            return true;
        } else {
            return false;
        }
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
        if ($this->isPluginInstall($info['platform']) == false) {
            return json(backMsg(1, '插件未安装'));
        }
        $up_res = $this->setPlugin($info['platform'], ['state' => $info['state']]);
        if ($up_res) {
            return json(backMsg(1, '失败'));
        } else {
            return json(backMsg(0, '成功'));
        }
    }
    // 检测插件是否安装
    public function isPluginInstall(string $platform): bool
    {
        $config = self::getPluginConfig();
        $platforms = [];
        foreach ($config as $key => $value) {
            $platforms[] = $value['platform'];
        }
        if (in_array($platform, $platforms)) {
            return true;
        } else {
            return false;
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
