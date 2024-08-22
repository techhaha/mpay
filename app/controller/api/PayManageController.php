<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\PayAccount;
use app\model\PayChannel;
use app\model\Platform;
use think\facade\View;

class PayManageController extends BaseController
{
    // 获取账号列表
    public function getPayAccount()
    {
        $query = $this->request->get();
        $accounts = PayAccount::serchAccount($query)->order('id', 'desc')->paginate(['list_rows' => $query['limit'], 'page' => $query['page']]);
        if ($accounts) {
            return json(['code' => 0, 'msg' => 'OK', 'count' => $accounts->total(), 'data' => $accounts->items()]);
        } else {
            return json(['code' => 1, 'msg' => '无数据记录', 'count' => 0, 'data' => []]);
        }
    }
    // 编辑账号
    public function editAccount()
    {
        $info = $this->request->post();
        $up_res = PayAccount::update($info);
        if ($up_res) {
            return json(\backMsg(0, '修改成功'));
        } else {
            return json(\backMsg(1, '修改失败'));
        }
    }
    // 账号状态
    public function accountEnable()
    {
        $info = $this->request->post();
        $up_res = PayAccount::update($info);
        if ($up_res) {
            return json(\backMsg(0, '成功'));
        } else {
            return json(\backMsg(1, '失败'));
        }
    }
    // 删除账号
    public function delAccount()
    {
        $ids = $this->request->post('ids');
        $res = PayAccount::destroy($ids);
        if ($res) {
            return \json(\backMsg(0, '已删除'));
        } else {
            return \json(\backMsg(1, '失败'));
        }
    }
    // 添加账号
    public function addAccount()
    {
        $info = $this->request->post();
        $pid = $this->request->session('pid');
        $info['pid'] = $pid;
        $res = PayAccount::create($info);
        if ($res) {
            return \json(\backMsg(0, '添加成功'));
        } else {
            return \json(\backMsg(1, '添加失败'));
        }
    }
    // 添加收款终端
    public function addChannel()
    {
        $info = $this->request->post();
        $res = PayChannel::create($info);
        if ($res) {
            return \json(\backMsg(0, '添加成功'));
        } else {
            return \json(\backMsg(1, '添加失败'));
        }
    }
    // 编辑收款终端
    public function editChannel()
    {
        $info = $this->request->post();
        $up_res = PayChannel::update($info);
        if ($up_res) {
            return json(\backMsg(0, '修改成功'));
        } else {
            return json(\backMsg(1, '修改失败'));
        }
    }
    // 收款终端列表
    public function getChannelList()
    {
        $aid = $this->request->post('aid');
        $res = PayChannel::where(['account_id' => $aid])->order('last_time', 'desc')->select();
        if ($res) {
            return \json(\backMsg(0, '获取成功', $res));
        } else {
            return \json(\backMsg(1, '失败'));
        }
    }
    // 生成账号配置
    public function createAccountConfig()
    {
        $query = [
            "date_end" => null,
            "date_start" => null,
            "page" => 1,
            "page_size" => 10,
            "upayQueryType" => 0,
            "status" => "2000",
            "store_sn" => "",
            "type" => "30"
        ];

        $data = [
            'pid'       =>  1001,
            'key'       =>  '154556b80a3f02ab6b57d4199cd6ebdf',
            'aid'       =>  1,
            'platform'  =>  'sqbpay',
            'account'   =>  '18872410423',
            'password'  =>  '7698177hcnSQB',
            'query'     =>  \var_export($query, \true)
        ];
        $config = View::fetch('tpl/account_config', $data);
        $name = "{$data['pid']}_{$data['aid']}";
        $path = "../config/payconfig/{$name}.php";
        $res = \file_put_contents($path, $config);
        if ($res) {
            return \json(\backMsg(msg: '创建成功'));
        } else {
            return \json(\backMsg(1, '创建成功'));
        }
    }
    // 生成平台列表配置
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
