<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Order;
use app\model\PayAccount;
use app\model\PayChannel;
use think\facade\View;
use app\model\Platform;

class PayManageController extends BaseController
{
    public function index()
    {
        return View::fetch();
    }
    // 编辑账号
    public function editAccount()
    {
        $id = $this->request->get('id');
        $account = PayAccount::find($id);
        View::assign([
            'id' => $id,
            'platform' => $account->getData('platform'),
            'account' => $account->account,
            'password' => $account->password,
            'state' => $account->state,
            'pattern' => $account->getData('pattern'),
            'params' => $account->params,
        ]);
        return View::fetch();
    }
    // 添加账号
    public function addAccount()
    {
        return View::fetch();
    }
    // 添加收款终端
    public function addChannel()
    {
        $aid = $this->request->get('aid');
        View::assign(['aid' => $aid]);
        return View::fetch();
    }
    // 编辑收款终端
    public function editChannel()
    {
        $cid = $this->request->get('cid');
        $channel = PayChannel::with('payAccount')->where('id', $cid)->find();
        View::assign([
            'cid' => $channel->id,
            'platform' => $channel->payAccount->platform,
            'account' => $channel->payAccount->account,
            'channel' => $channel->channel,
            'qrcode' => $channel->qrcode,
            'last_time' => $channel->last_time,
            'state' => $channel->state,
        ]);
        return View::fetch();
    }
    // 收款终端列表
    public function channelList()
    {
        $id = $this->request->get('id');
        View::assign(['id' => $id]);
        return View::fetch();
    }
}
