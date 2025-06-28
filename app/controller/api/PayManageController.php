<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\PayAccount;
use app\model\PayChannel;
use app\model\Order;
use think\facade\Db;
use \think\facade\Log;

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
        try {
            // 获取上传的文件
            $img = $this->request->file('codeimg');
            if (!$img) {
                return json(backMsg(1, '请选择要上传的文件'));
            }

            // 验证文件大小，防止大文件攻击
            $maxSize = 2 * 1024 * 1024; // 2MB
            if ($img->getSize() > $maxSize) {
                return json(backMsg(1, '文件大小不能超过 2MB'));
            }

            // 验证文件类型，防止恶意文件上传
            $allowedTypes = ['image/png', 'image/jpeg', 'image/gif'];
            $fileMimeType = $img->getMime();
            if (!in_array($fileMimeType, $allowedTypes)) {
                return json(backMsg(1, '只允许上传 PNG、JPEG 或 GIF 格式的图片'));
            }

            // 二次验证文件类型，通过文件内容判断
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $realMimeType = finfo_file($finfo, $img->getRealPath());
            finfo_close($finfo);
            if (!in_array($realMimeType, $allowedTypes)) {
                return json(backMsg(1, '文件类型验证失败，请上传有效的图片文件'));
            }

            // 生成唯一文件名，避免文件名冲突
            $filename = 'img_' . time() . '_' . uniqid() . '.' . $img->getOriginalExtension();

            // 过滤文件名，防止路径遍历攻击
            $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);

            // 设置文件保存路径
            $path = public_path() . '/files/qrcode/';
            if (!is_dir($path)) {
                if (!mkdir($path, 0755, true)) {
                    return json(backMsg(1, '创建目录失败'));
                }
            }

            // 移动文件到指定目录
            $info = $img->move($path, $filename);
            if ($info) {
                $imgpath = '/files/qrcode/' . $filename;
                return json(backMsg(0, '上传成功', ['imgpath' => $imgpath]));
            } else {
                return json(backMsg(1, '上传失败'));
            }
        } catch (\Exception $e) {
            Log::error('上传过程中出现异常: ' . $e->getMessage());
            return json(backMsg(1, '上传过程中出现异常，请稍后重试'));
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
        $pay_config = ['username' => $config['account'], 'password' => $config['password'], 'aid' => $config['aid']];
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

    public function payStatisticsList()
    {
        $query = $this->request->get();
        $limit = $query['limit'] ?? 10;
        $page = $query['page'] ?? 1;
        $start_time = $query['time_start'] ?? date('Y-m-d H:i:s', strtotime('today'));
        $end_time = $query['time_end'] ?? date('Y-m-d H:i:s', strtotime('tomorrow') - 1);
        // 确保日期时间格式正确
        $start_time = date('Y-m-d H:i:s', strtotime($start_time));
        $end_time = date('Y-m-d H:i:s', strtotime($end_time));

        $accounts = Db::table('mpay_pay_account', 'PayAccount')
            ->alias('PayAccount')
            ->join('mpay_order Order', 'PayAccount.id = Order.aid AND Order.delete_time IS NULL AND Order.state = 1', 'LEFT')
            ->field([
                'PayAccount.*',
                'SUM(CASE WHEN DATE(Order.pay_time) = CURDATE() THEN Order.really_price ELSE 0 END) as day',
                'SUM(CASE WHEN DATE(Order.pay_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN Order.really_price ELSE 0 END) as yesterday',
                'SUM(CASE WHEN YEARWEEK(Order.pay_time, 1) = YEARWEEK(CURDATE(), 1) THEN Order.really_price ELSE 0 END) as week',
                'SUM(CASE WHEN DATE_FORMAT(Order.pay_time, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m") THEN Order.really_price ELSE 0 END) as month',
                'SUM(CASE WHEN YEAR(Order.pay_time) = YEAR(CURDATE()) THEN Order.really_price ELSE 0 END) as year',
                'SUM(IFNULL(Order.really_price, 0)) as total',
                "SUM(CASE WHEN Order.pay_time BETWEEN '$start_time' AND '$end_time' THEN Order.really_price ELSE 0 END) as income"
            ])
            ->where('PayAccount.delete_time IS NULL')
            ->group('PayAccount.id')
            ->order('PayAccount.id', 'DESC')
            ->paginate(['list_rows' => $limit, 'page' => $page]);

        return json([
            'code' => 0,
            'msg' => 'OK',
            'count' => $accounts->total(),
            'data' => $accounts->items()
        ]);
    }

    // 收款统计
    // public function payStatisticsList()
    // {
    //     $query = $this->request->get();
    //     // 定义统计字段
    //     $fields = [
    //         "SUM(IF(DATE(pay_time) = CURDATE(), really_price, 0)) as day",
    //         "SUM(IF(DATE(pay_time) = CURDATE() - INTERVAL 1 DAY, really_price, 0)) as yesterday",
    //         "SUM(IF(YEARWEEK(pay_time, 1) = YEARWEEK(CURDATE(), 1), really_price, 0)) as week",
    //         "SUM(IF(DATE_FORMAT(pay_time, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m'), really_price, 0)) as month",
    //         "SUM(IF(YEAR(pay_time) = YEAR(CURDATE()), really_price, 0)) as year",
    //         "SUM(really_price) as total"
    //     ];

    //     $where = ['state', 1;

    //     // 合并 pay_account 表字段和统计字段
    //     $allFields = array_merge([PayAccount::getTable() . '.*'], $fields);

    //     $accounts = PayAccount::hasWhere('order', $where, '*', 'LEFT')
    //         ->field($allFields)
    //         ->group(PayAccount::getTable() . '.id')
    //         ->order('id', 'desc')
    //         ->paginate(['list_rows' => $query['limit'] ?? 10, 'page' => $query['page'] ?? 1]);

    //     if ($accounts) {
    //         return json(['code' => 0, 'msg' => PayAccount::getLastSql(), 'count' => $accounts->total(), 'data' => $accounts->items()]);
    //     } else {
    //         return json(['code' => 1, 'msg' => '无数据记录', 'count' => 0, 'data' => []]);
    //     }
    // }
}
