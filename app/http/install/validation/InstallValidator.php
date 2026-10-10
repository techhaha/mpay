<?php
declare(strict_types=1);

namespace app\http\install\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\service\system\install\InitializationValidator;
use think\Validate;

/** 网页安装输入使用原生 Validate；数据库目标不可夹带 DSN 指令，管理员规则与 CLI 完全共用。 */
final class InstallValidator extends Validate
{
    protected $rule = [
        'host' => ['require', 'string', 'max' => 253, 'databaseHost'],
        'port' => ['require', 'integerJson', 'between' => [1, 65535]],
        'name' => ['require', 'string', 'regex' => '/^[A-Za-z0-9_-]{1,64}$/D'],
        'user' => ['require', 'string', 'max' => 128, 'singleLine'],
        'pass' => ['string', 'max' => 1024, 'singleLine'],
    ];

    /** @return array{host:string,port:int,name:string,user:string,pass:string} 不做 trim，密码保留输入字节。 */
    public function database(#[\SensitiveParameter] array $input): array
    {
        $fields = array_keys($this->rule);
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) { $this->invalid(); }
        $check = clone $this; $check->must = $fields;
        if (!$check->failException(false)->check($input)) { $this->invalid(); }
        return $input;
    }

    /** @return array{database:array,administrator:array} 嵌套 JSON 必须为对象，不接受列表或额外安装开关。 */
    public function installation(#[\SensitiveParameter] array $input): array
    {
        if (count($input) !== 2 || !(($input['database'] ?? null) instanceof \stdClass) || !(($input['administrator'] ?? null) instanceof \stdClass)) { $this->invalid(); }
        $administrator = get_object_vars($input['administrator']);
        if (array_diff(array_keys($administrator), ['username', 'password', 'password_confirmation'])) { $this->invalid(); }
        $administrator['app_name'] = '默认应用';
        return ['database' => $this->database(get_object_vars($input['database'])),
            'administrator' => (new InitializationValidator())->validateInput($administrator)];
    }

    protected function databaseHost(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false || filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
    protected function integerJson(mixed $value): bool { return is_int($value); }
    protected function singleLine(string $value): bool { return mb_check_encoding($value, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $value); }
    private function invalid(): never { throw new BusinessException('请检查数据库地址、端口、库名、账号和输入格式', ApiResponseConstant::VALIDATION_FAILED); }
}
