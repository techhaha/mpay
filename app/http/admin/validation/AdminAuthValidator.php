<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 后台登录/改密的原生验证器；密码不 trim、不转换类型、不回显，身份字段只允许来自服务端会话。 */
final class AdminAuthValidator extends Validate
{
    /** @var array<string,array> 密码长度按字节限制，以免 bcrypt 只处理前 72 字节。 */
    protected $rule = [
        'username' => ['require', 'string', 'max' => 64],
        'password' => ['require', 'string', 'passwordBytes'],
        'current_password' => ['require', 'string', 'passwordBytes'],
        'new_password' => ['require', 'string', 'passwordBytes', 'min' => 12],
        'password_confirmation' => ['require', 'string', 'passwordBytes', 'confirm' => 'new_password'],
    ];

    protected $scene = [
        'login' => ['username', 'password'],
        'password' => ['current_password', 'new_password', 'password_confirmation'],
    ];

    protected $message = [
        'username' => '用户名需要 1～64 个字符',
        'password' => '请填写有效密码，最多 72 字节',
        'current_password' => '请填写有效的当前密码',
        'new_password' => '新密码至少 12 个字符、最多 72 字节，不能含空字符',
        'password_confirmation' => '两次输入的新密码必须一致',
    ];

    /**
     * @param array<string,mixed> $input 原始 JSON 对象，不接收 query/form 合并结果。
     * @return array<string,string> 只包含选定场景的显式字段。
     * @throws BusinessException 字段格式错误；错误说明不包含秘密值。
     */
    public function validateInput(string $scene, array $input): array
    {
        $fields = $this->scene[$scene] ?? throw new \InvalidArgumentException('未知认证验证场景');
        if (array_diff(array_keys($input), $fields) !== []) {
            throw new BusinessException('认证操作包含未允许的字段', ApiResponseConstant::VALIDATION_FAILED);
        }
        $validator = (clone $this)->scene($scene)->batch(true)->failException(false);
        if (!$validator->check($input)) {
            $errors = [];
            foreach ((array) $validator->getError() as $field => $message) {
                $errors[$field] = [$message];
            }
            throw new BusinessException('请检查填写内容', ApiResponseConstant::VALIDATION_FAILED, $errors);
        }
        return array_intersect_key($input, array_flip($fields));
    }

    /** 原生 string 规则之外补充凭据字节边界，不实现另一套验证引擎或读取账号数据。 */
    protected function passwordBytes(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 72
            && !str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }
}
