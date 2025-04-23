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
    // 安装插件
    public function installPlugin()
    {
        $platform = $this->request->post('platform');
        if (!$platform) return json(backMsg(1, '请选择插件'));
        $intall_info = \Plugin::installPlugin($platform);
        if ($intall_info['code'] !== 0) return json(backMsg(1, $intall_info['msg']));
        // 需要授权
        if ($intall_info['data']['status'] === 0) {
            return json(['code' => 0, 'msg' => '请支付', 'state' => 0, 'data' => $intall_info['data']]);
        }
        $saved = $this->saveNewPluginConfig($intall_info['data']);
        if ($saved['code'] !== 0) return json(backMsg(1, $saved['msg']));
        return json(['code' => 0, 'msg' => '授权成功', 'state' => 1]);
    }
    // 更新插件
    public function updatePlugin()
    {
        $platform = $this->request->post('platform');
        if (!$platform) return json(backMsg(1, '请选择插件'));
        $update_info = \Plugin::updatePlugin($platform);
        if ($update_info['code'] !== 0) return json(backMsg(1, $update_info['msg']));
        $saved = $this->saveNewPluginConfig($update_info['data']);
        if ($saved['code'] !== 0) return json(backMsg(1, $saved['msg']));
        return json(['code' => 0, 'msg' => '更新成功']);
    }
    // 保存全部插件信息
    private function saveNewPluginConfig(array $config = [])
    {
        $plugin_config = $config['config'];
        $plugin_auth = $config['authcode'];
        $plugin_file = $config['file'];
        if (!$this->savePluginFile($plugin_file, $plugin_config)) return backMsg(1, '保存插件文件失败');
        if (!$this->saveAuthCode($plugin_auth, $plugin_config)) return backMsg(1, '保存插件授权码失败');
        if (!$this->addPlugin($plugin_config)) return backMsg(1, '保存插件配置失败');
        return backMsg(0, 'ok');
    }

    // 卸载插件
    public function uninstallPlugin()
    {
        $platform = $this->request->post('platform');
        if (!$platform) return json(backMsg(1, '请选择插件'));
        $this->delPluginFile($platform);
        $this->delPlugin($platform);
        return json(backMsg(0, '卸载成功'));
    }
    // 添加或更新插件
    public function addPlugin(array $option = [])
    {
        $keys = ['platform', 'name', 'class_name', 'price', 'describe', 'website', 'helplink', 'version'];
        $config = [];
        foreach ($option as $key => $value) {
            if (in_array($key, $keys)) $config[$key] = $value;
        }
        $config['state'] = 1;
        $plugin_config = self::getPluginConfig();
        $plugin_platform = $config['platform'] ?: '';
        foreach ($plugin_config as $i => $value) {
            if ($plugin_platform == $value['platform']) {
                $plugin_config[$i] = $config;
                $this->savePluginConfig($plugin_config, '支付插件列表');
                return true;
            }
        }
        $plugin_config[] = $config;
        $this->savePluginConfig($plugin_config, '支付插件列表');
        return true;
    }
    // 删除插件配置
    private function delPlugin(string $plugin_name = '')
    {
        $plugin_config = self::getPluginConfig();
        $index = null;
        foreach ($plugin_config as $i => $value) {
            if ($value['platform'] == $plugin_name) {
                $index = $i;
                break;
            }
        }
        if ($index === null) return false;
        unset($plugin_config[$index]);
        $config = array_values($plugin_config);
        $this->savePluginConfig($config, '支付插件列表');
        return true;
    }
    // 删除插件类库文件
    private function delPluginFile(string $platform = '')
    {
        $file_name = self::getPluginInfo($platform)['class_name'];
        if (!$file_name) return false;
        $plugin_path = root_path() . '/extend/payclient/' . $file_name . '.php';
        if (!file_exists($plugin_path)) return false;
        unlink($plugin_path);
        return true;
    }
    // 修改插件
    public function setPlugin($platform = '', $option = [])
    {
        $config = self::getPluginConfig();
        if (!$platform) return 1;
        if (!$option) return 2;
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
        if (!$this->isPluginInstall($info['platform'])) return json(backMsg(1, '插件未安装'));
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
    // 保存授权码
    private function saveAuthCode(string $authcode = '', array $config = [])
    {
        $dir_path = runtime_path() . "auth/";
        if (!is_dir($dir_path)) mkdir($dir_path, 755, true);
        $auth_path = $dir_path . md5("{$config['platform']}payclient\\{$config['class_name']}") . '.json';
        return file_put_contents($auth_path, json_encode(['authcode' => $authcode])) !== false ? true : false;
    }
    // 保存插件类库文件
    private function savePluginFile($file_url = '', array $config = [])
    {
        if (empty($file_url))  return false;
        $file_content = @file_get_contents($file_url);
        if ($file_content === false) return false;
        $save_dir = root_path() . 'extend/payclient/';
        if (!is_dir($save_dir)) mkdir($save_dir, 0755, true);
        $save_path = $save_dir . $config['class_name'] . '.php';
        return file_put_contents($save_path, $file_content) !== false ? true : false;
    }
    // 获取插件配置
    private static function getPluginConfig(): array
    {
        $payplugin_path = config_path() . '/extend/payplugin.php';
        if (!file_exists($payplugin_path)) return [];
        // 加载插件配置
        $payplugin_config = require $payplugin_path;
        return $payplugin_config;
    }
    // 保存插件配置
    private function savePluginConfig(array $config, string $note = '说明')
    {
        $payplugin_path = config_path() . '/extend/payplugin.php';
        $note_tpl = <<<EOF
// +----------------------------------------------------------------------
// | $note
// +----------------------------------------------------------------------
EOF;
        $config_str = "<?php\n" . $note_tpl . "\n\nreturn " . var_export($config, true) . ";\n";
        \file_put_contents($payplugin_path, $config_str);
    }
}
