<?php

declare(strict_types=1);

namespace app\model;

use app\BaseModel;

class User extends BaseModel
{
    // 验证用户
    public static function checkUser($pid, $sign)
    {
        $user = self::where('pid', $pid)->find();
        $sign2 = md5($user->pid . $user->secret_key);
        if ($sign === $sign2) {
            return true;
        } else {
            return false;
        }
    }
    // 创建用户
    public static function createUser(array $userinfo)
    {
        $last_pid = self::withTrashed()->max('pid');
        $find_username = self::withTrashed()->where(['username' => $userinfo['username']])->find();
        if ($find_username) {
            return 1; // 账户已注册
        }
        $pid = $last_pid ? $last_pid + 1 : 1000;
        $secret = md5($pid . time() . mt_rand());
        $res = self::create(['pid' => $pid, 'secret_key' => $secret, 'username' => $userinfo['username'], 'password' => $userinfo['password'], 'nickname' => self::getNickname('小可爱', 5)]);
        return $res;
    }
    // 随机用户昵称
    private static function getNickname($pre = '', $length = 8)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $pre . $randomString;
    }
}
