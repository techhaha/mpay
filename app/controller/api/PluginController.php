<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\Platform;
use think\facade\View;

class PluginController extends BaseController
{
    // 插件列表
    public function getPluginList()
    {
        $query = $this->request->get();
        $data = Platform::order('id', 'desc')->paginate(['list_rows' => $query['limit'], 'page' => $query['page']]);
        if ($data) {
            return json(['code' => 0, 'msg' => 'OK', 'count' => $data->total(), 'data' => $data->items()]);
        } else {
            return json(['code' => 1, 'msg' => '无数据记录', 'count' => 0, 'data' => []]);
        }
    }
    // 插件启用
    public function pluginEnable()
    {
        $info = $this->request->post();
        $up_res = Platform::update($info);
        if ($up_res) {
            return json(\backMsg(0, '成功'));
        } else {
            return json(\backMsg(1, '失败'));
        }
    }
    // 插件选项
    public function pluginOption()
    {
        // 加载平台配置
        $platform = \think\facade\Config::load("extendconfig/platform", 'extendconfig');
        $option = [];
        foreach ($platform as $key => $value) {
            $option[] = ['platform' => $key, 'name' => $value];
        }
        return json($option);
    }
    // 生成插件配置
    public function crtPlfConfig()
    {
        $info = Platform::where('state', 1)->field('platform, name')->select()->toArray();
        $data = [];
        foreach ($info as $value) {
            $data[$value['platform']] = $value['name'];
        }
        $config = View::fetch('tpl/platform_config', $data);
        $path = "../config/extendconfig/platform.php";
        $res = \file_put_contents($path, $config);
        if ($res) {
            return \json(\backMsg(msg: '创建成功'));
        } else {
            return \json(\backMsg(1, '创建成功'));
        }
    }
}
