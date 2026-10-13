<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\http\admin\validation\PaginationValidator;
use think\Validate;

/** 运行页面只读参数；没有任意命令、路径或修复选项，状态枚举由查询服务核验。 */
final class RuntimeQueryValidator extends Validate
{
    protected $rule = ['configuration_id' => ['string', 'recordId'], 'status' => ['string', 'regex' => '/^(?:[0-9]{1,3}|not_initialized|unknown)$/D']];
    public function __construct(private readonly PaginationValidator $pagination) { parent::__construct(); }

    /** @param array<string,mixed> $input 状态/诊断入口不接受目标服务器或文件参数。 */
    public function emptyQuery(array $input): void
    {
        if ($input !== []) { throw new BusinessException('运行查询不接受额外参数', ApiResponseConstant::VALIDATION_FAILED); }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{filters:array<string,string>,page:int,limit:int}
     */
    public function collections(array $input): array
    {
        if (array_diff(array_keys($input), ['configuration_id', 'status', 'page', 'limit']) !== []) {
            throw new BusinessException('运行筛选包含未知字段', ApiResponseConstant::VALIDATION_FAILED);
        }
        $filters = array_filter(array_intersect_key($input, $this->rule), static fn (mixed $value): bool => $value !== '');
        $validator = clone $this;
        $validator->append(array_fill_keys(array_keys($filters), 'require'));
        if (!$validator->failException(false)->check($filters)) { throw new BusinessException('运行筛选格式无效', ApiResponseConstant::VALIDATION_FAILED); }
        return ['filters' => $filters] + $this->pagination->validate($input);
    }

    protected function recordId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1 && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }
}
