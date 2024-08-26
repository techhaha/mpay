<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\Platform;

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
}
