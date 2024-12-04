<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Session;
use app\model\User;

class UserController extends BaseController
{
    protected $middleware = ['Auth' => ['except' => ['login']]];

    public function login()
    {
        $login_info = $this->request->post();
        $userinfo = self::checkUser($login_info);
        if ($userinfo['code'] === 0) {
            Session::set('userid', $userinfo['data']->id);
            Session::set('pid', $userinfo['data']->pid);
            Session::set('nickname', $userinfo['data']->nickname);
            Session::set('userrole', $userinfo['data']->role);
            Session::set('islogin', true);
            return json(backMsg(0, 'ok'));
        } else {
            return json($userinfo);
        }
    }
    public function logout()
    {
        Session::clear();
        return json(backMsg(0, '注销成功'));
    }
    public function editUser()
    {
        $userid = session('userid');
        $info = $this->request->post();
        $res = User::update($info, ['id' => $userid]);
        if (!$res) {
            return json(backMsg(1, '修改失败'));
        }
        return json(backMsg(0, '重置成功'));
    }
    public function changePassword()
    {
        $userid = session('userid');
        $user_info = User::find($userid);
        $post_info = $this->request->post();
        if (password_verify($post_info['old_password'], $user_info->password)) {
            $new_password = password_hash($post_info['new_password'], PASSWORD_DEFAULT);
            $res = User::update(['password' => $new_password], ['id' => $userid]);
            if (!$res) {
                return json(backMsg(1, '修改失败'));
            }
            return json(backMsg(0, '修改成功'));
        } else {
            return json(backMsg(1, '原密码错误'));
        }
    }
    public function resetKey()
    {
        $userid = session('userid');
        $res = User::update(['secret_key' => $this->generateKey()], ['id' => $userid]);
        if (!$res) {
            return json(backMsg(1, '重置失败'));
        }
        return json(backMsg(0, '重置成功'));
    }
    private function checkUser(array $login_info): array
    {
        $username = $login_info['username'];
        $password = $login_info['password'];
        $userinfo = User::where('username', $username)->find();
        if ($userinfo) {
            if (password_verify($password, $userinfo->password)) {
                return ['code' => 0, 'data' => $userinfo];
            } else {
                return backMsg(1, '登陆密码错误');
            }
        } else {
            return backMsg(2, '用户不存在');
        }
    }
    private function generateKey(bool $strong = true)
    {
        $bytes = openssl_random_pseudo_bytes(16, $strong);
        if ($strong) {
            $key = bin2hex($bytes);
            return md5($key);
        } else {
            return false;
        }
    }
}
