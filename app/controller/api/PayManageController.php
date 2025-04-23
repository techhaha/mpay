<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\PayAccount;
use app\model\PayChannel;

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
            return json(backMsg(0, '获取成功', $res));
        } else {
            return json(backMsg(1, '失败'));
        }
    }
    // 账号状态
    public function accountEnable()
    {
        $info = $this->request->post();
        $up_res = PayAccount::update($info);
        if ($up_res) {
            return json(backMsg(0, '成功'));
        } else {
            return json(backMsg(1, '失败'));
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
            return json(backMsg(1, '账号已存在'));
        }
        $acc = PayAccount::create($info);
        if ($acc) {
            return json(backMsg(0, '添加成功'));
        } else {
            return json(backMsg(1, '添加失败'));
        }
    }
    // 编辑账号
    public function editAccount()
    {
        $info = $this->request->post();
        $up_res = PayAccount::update($info);
        if ($up_res) {
            return json(backMsg(0, '修改成功'));
        } else {
            return json(backMsg(1, '修改失败'));
        }
    }
    // 删除账号
    public function delAccount()
    {
        $ids = $this->request->post('ids');
        $res = PayAccount::destroy($ids);
        $res2 = PayChannel::whereIn('account_id', $ids)->select()->delete();
        if ($res && $res2) {
            return json(backMsg(0, '已删除'));
        } else {
            return json(backMsg(1, '失败'));
        }
    }
    // 添加收款终端
    public function addChannel()
    {
        $info = $this->request->post();
        $check = PayChannel::where(['account_id' => $info['account_id'], 'channel' => $info['channel']])->count();
        if ($check) {
            return json(backMsg(1, '编号已存在'));
        }
        $info['last_time'] = date('Y-m-d H:i:s');
        $res = PayChannel::create($info);
        if ($res) {
            return json(backMsg(0, '添加成功'));
        } else {
            return json(backMsg(1, '添加失败'));
        }
    }
    // 编辑收款终端
    public function editChannel()
    {
        $info = $this->request->post();
        $up_res = PayChannel::update($info);
        if ($up_res) {
            return json(backMsg(0, '修改成功'));
        } else {
            return json(backMsg(1, '修改失败'));
        }
    }
    // 删除收款终端
    public function delChannel()
    {
        $cid = $this->request->post('id');
        $res = PayChannel::destroy($cid);
        if ($res) {
            return json(backMsg(0, '已删除'));
        } else {
            return json(backMsg(1, '失败'));
        }
    }
    // 上传二维码图片
    public function uploadQrcode()
    {
        $img = $this->request->file('codeimg');
        if (!$img) {
            return json(backMsg(1, '请选择要上传的文件'));
        }
        // 验证文件类型
        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif'];
        $fileMimeType = $img->getMime();
        if (!in_array($fileMimeType, $allowedTypes)) {
            return json(backMsg(1, '只允许上传PNG、JPEG或GIF格式的图片'));
        }
        // 生成唯一文件名
        $filename = 'img_' . time() . '_' . uniqid() . '.' . $img->getOriginalExtension();
        // 设置文件保存路径
        $path = public_path() . '/files/qrcode/';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        // 移动文件到指定目录
        $info = $img->move($path, $filename);
        if ($info) {
            $imgpath = '/files/qrcode/' . $filename;
            return json(backMsg(0, '上传成功', ['imgpath' => $imgpath]));
        } else {
            return json(backMsg(1, '上传失败'));
        }
    }
    // 获取账号交易流水
    public function getAccountTrade()
    {
        $req_info = $this->request->get();
        $req_pid = $req_info['pid'];
        $req_aid = $req_info['aid'];
        // 加载配置文件
        $config = PayAccount::getAccountConfig($req_aid);
        if ($config === false) return json(backMsg(1, '账号配置文件错误'));
        if ($req_aid != $config['aid'] || $req_pid != session('pid')) return json(backMsg(1, '监听收款配置不一致'));
        // 登陆账号
        $pay_config = ['username' => $config['account'], 'password' => $config['password']];
        // 收款查询
        $params = $config['params'];
        // 实例监听客户端
        $payclient_name = $config['payclass'];
        $payclient_path = "\\payclient\\{$payclient_name}";
        $Payclient = new $payclient_path($pay_config);
        // 获取支付明细
        $records = $Payclient->getOrderInfo($params);
        if ($records['code'] === 0) {
            // 收款流水
            return json(backMsg(0, '查询成功', $records['data']));
        } else {
            return json(['code' => 1, 'msg' => $records['msg']]);
        }
    }
}
