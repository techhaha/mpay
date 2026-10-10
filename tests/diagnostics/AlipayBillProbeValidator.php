<?php
declare(strict_types=1);

namespace tests\diagnostics;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 只读账务探测的私有 JSON 输入边界；不接受网关、输出路径或修改平台状态的操作参数。 */
final class AlipayBillProbeValidator extends Validate
{
    protected $rule = [
        'account' => ['require', 'accountObject'],
        'from_timestamp' => ['require', 'unixSeconds'],
        'to_timestamp' => ['require', 'unixSeconds'],
        'max_pages' => ['require', 'pageBudget'],
        'budget_seconds' => ['require', 'timeBudget'],
    ];

    /**
     * Unix 时间必须为 JSON 整数；缺省预算为每遍最多三页、两遍共用二十秒，不静默转换字符串或 null。
     * 账号字段格式、RSA 密钥及官方查询窗口继续由 SDK 客户端在发送前核对，不在 CLI 重写这套合同。
     *
     * @param array<string,mixed> $input ConfigurationInputFile 保留内层 JSON 对象与数组的区别。
     * @return array{account:array<string,mixed>,from_timestamp:int,to_timestamp:int,max_pages:int,budget_seconds:int}
     * @throws BusinessException 固定拒绝说明，不输出密钥、输入值或原生验证器的动态错误文本。
     */
    public function validateInput(#[\SensitiveParameter] array $input): array
    {
        if (array_diff(array_keys($input), array_keys($this->rule)) !== []) { $this->reject(); }
        $input += ['max_pages' => 3, 'budget_seconds' => 20];
        $validator = (clone $this)->failException(false);
        $validator->must = array_keys($this->rule);
        if (!$validator->check($input) || $input['to_timestamp'] <= $input['from_timestamp']
            || $input['to_timestamp'] - $input['from_timestamp'] > 31 * 86400) {
            $this->reject();
        }
        $input['account'] = get_object_vars($input['account']);
        return $input;
    }

    protected function accountObject(mixed $value): bool { return $value instanceof \stdClass; }
    protected function unixSeconds(mixed $value): bool { return is_int($value) && $value > 0 && $value <= 253402300799; }
    protected function pageBudget(mixed $value): bool { return is_int($value) && $value >= 1 && $value <= 10; }
    protected function timeBudget(mixed $value): bool { return is_int($value) && $value >= 5 && $value <= 60; }

    private function reject(): never
    {
        throw new BusinessException('账务探测输入无效：account 须为对象，时间须为整数秒，窗口最多 31 天，页数 1～10、总预算 5～60 秒',
            ApiResponseConstant::VALIDATION_FAILED);
    }
}
