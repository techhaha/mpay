<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\Platform;

class PluginController extends BaseController
{
    public function index()
    {
        //
    }
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
}
