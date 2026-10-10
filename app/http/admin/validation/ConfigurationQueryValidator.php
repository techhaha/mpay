<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\http\admin\validation\PaginationValidator;
use think\Validate;

/** 配置管理只读参数：显式场景、字符串 ID 和有界分页，不把请求键用作数据库字段或排序表达式。 */
final class ConfigurationQueryValidator extends Validate
{
    protected $rule = [
        'id' => ['string', 'recordId'], 'configuration_id' => ['string', 'recordId'],
        'plugin_code' => ['string', 'regex' => '/^[a-z][a-z0-9_]{0,63}$/D'],
        'enabled' => ['string', 'in' => ['0', '1']],
    ];
    protected $scene = [
        'channels' => ['configuration_id', 'enabled'],
    ];

    public function __construct(private readonly PaginationValidator $pagination)
    {
        parent::__construct();
    }

    /**
     * @param array<string,mixed> $input
     * @return array{filters:array<string,string|int>,page:int,limit:int}
     */
    public function listing(string $kind, array $input): array
    {
        $fields = $this->scene[$kind] ?? throw new \InvalidArgumentException('未知配置列表场景');
        $this->known($input, [...$fields, 'page', 'limit']);
        $filters = array_filter(array_intersect_key($input, array_flip($fields)), static fn (mixed $value): bool => $value !== '');
        $validator = (clone $this)->scene($kind)->failException(false);
        $validator->must = array_keys($filters);
        if (!$validator->check($filters)) { throw new BusinessException('配置筛选无效', ApiResponseConstant::VALIDATION_FAILED); }
        if (array_key_exists('enabled', $filters)) { $filters['enabled'] = (int) $filters['enabled']; }
        return ['filters' => $filters] + $this->pagination->validate($input);
    }

    /** @param array<string,mixed> $input 详情必须显式提供 ID，不能默认选择第一个账号或通道。 */
    public function identity(array $input): string
    {
        $this->known($input, ['id']);
        if (!(clone $this)->only(['id'])->append('id', 'require')->failException(false)->check($input)) {
            throw new BusinessException('配置记录编号无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        return $input['id'];
    }

    /** @param array<string,mixed> $input 无参数接口也拒绝凭浏览器字段选择商户。 */
    public function emptyQuery(array $input): void { $this->known($input, []); }

    protected function recordId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $fields
     */
    private function known(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields) !== []) {
            throw new BusinessException('配置查询包含未允许的字段', ApiResponseConstant::VALIDATION_FAILED);
        }
    }
}
