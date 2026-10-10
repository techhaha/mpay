<?php
declare(strict_types=1);

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 后台管理员身份与密码摘要；singleton_slot 唯一约束限制首版只有一个管理员。
 * session_version 供认证流程判断旧会话失效，模型本身不执行密码校验或会话撤销。
 *
 * @property string $username
 * @property string $password_hash 标准密码摘要，序列化时隐藏。
 * @property int $session_version 当前认证版本。
 * @property-read int $singleton_slot 数据库生成的固定值 1。
 */
final class AdminUser extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_admin_user',
            'schema' => [
                'id' => 'string',
                'username' => 'string',
                'password_hash' => 'string',
                'session_version' => 'int',
                'status' => 'int',
                'singleton_slot' => 'int',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
            'hidden' => ['password_hash'],
        ];
    }

}
