<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\PayAccount;
use app\model\PayChannel;
use app\model\User;

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
    // 添加账号
    public function addAccount()
    {
        $info = $this->request->post();
        $pid = $this->request->session('pid');
        $info['pid'] = $pid;
        $info['params'] = '{}';
        $check_acc = PayAccount::where(['account' => $info['account'], 'platform' => $info['platform'], 'pid' => $pid])->find();
        if ($check_acc) {
            return \json(\backMsg(1, '账号已存在'));
        }
        $acc = PayAccount::create($info);
        if ($acc) {
            $state =  $this->createAccountConfig($acc);
            if (!$state) {
                return json(\backMsg(1, '自字义参数错误'));
            }
            return \json(\backMsg(0, '添加成功'));
        } else {
            return \json(\backMsg(1, '添加失败'));
        }
    }
    // 编辑账号
    public function editAccount()
    {
        $info = $this->request->post();
        $up_res = PayAccount::update($info);
        if ($up_res) {
            $acc = PayAccount::find($info['id']);
            $state =  $this->createAccountConfig($acc);
            if (!$state) {
                return json(\backMsg(1, '自字义参数错误'));
            }
            return json(\backMsg(0, '修改成功'));
        } else {
            return json(\backMsg(1, '修改失败'));
        }
    }
    // 删除账号
    public function delAccount()
    {
        $ids = $this->request->post('ids');
        $res = PayAccount::destroy($ids);
        $res2 = PayChannel::whereIn('account_id', $ids)->select()->delete();
        if ($res && $res2) {
            $accs = PayAccount::whereIn('id', $ids)->withTrashed()->select();
            foreach ($accs as $acc) {
                $this->delAccountConfig($acc);
            }
            return \json(\backMsg(0, '已删除'));
        } else {
            return \json(\backMsg(1, '失败'));
        }
    }
    // 添加收款终端
    public function addChannel()
    {
        $info = $this->request->post();
        $check = PayChannel::where(['account_id' => $info['account_id'], 'channel' => $info['channel']])->count();
        if ($check) {
            return \json(\backMsg(1, '编号已存在'));
        }
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
    // 删除收款终端
    public function delChannel()
    {
        $cid = $this->request->post('id');
        $res = PayChannel::destroy($cid);
        if ($res) {
            return \json(\backMsg(0, '已删除'));
        } else {
            return \json(\backMsg(1, '失败'));
        }
    }
    // 删除账号配置
    public function delAccountConfig($acc)
    {
        $path = config_path() . "/payconfig/{$acc->pid}_{$acc->id}.php";
        if (file_exists($path)) {
            unlink($path);
        }
    }
    // 上传二维码图片
    public function uploadQrcode()
    {
        $img = $this->request->file('codeimg');
        $path = public_path() . '/files/qrcode/';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $info = $img->move($path, 'img' . time() . '.' . $img->getOriginalExtension());
        if ($info) {
            $imgpath = '/files/qrcode/';
            return json(backMsg(0, '上传成功', ['imgpath' => $imgpath . $info->getFilename()]));
        } else {
            return json(backMsg(1, '上传失败'));
        }
    }
    // 生成账号配置
    private function createAccountConfig($acc)
    {
        $params = \json_decode($acc->params, \true);
        if ($params === null) {
            return false; // 自定义参数错误
        }
        $platform = \app\controller\api\PluginController::getPluginInfo($acc->getData('platform'));
        $user = User::where('pid', $acc->pid)->find();
        $query_tpl = $platform['query'];
        $query = var_export(\array_merge($query_tpl, $params), \true);
        $config = <<<EOF
<?php
// +----------------------------------------------------------------------
// | 支付监听配置，一个文件，一个账号
// +----------------------------------------------------------------------

return [
    // 用户账号配置
    'user' => [
        'pid'       =>  {$user->pid},
        'key'       =>  '{$user->secret_key}'
    ],
    // 收款平台账号配置
    'pay' => [
        // 账号id
        'aid'       =>  $acc->id,
        // 收款平台
        'platform'  =>  '{$acc->getData('platform')}',
        // 插件类名
        'payclass'  =>  '{$platform['class_name']}',
        // 账号
        'account'   =>  '{$acc->account}',
        // 密码
        'password'  =>  '{$acc->password}',
        // 订单查询参数配置
        'query'     =>  {$query},
    ]
];

EOF;
        $name = "{$user->pid}_{$acc->id}";
        $path = config_path() . "/payconfig/{$name}.php";
        \file_put_contents($path, $config);
        return true;
    }
}
