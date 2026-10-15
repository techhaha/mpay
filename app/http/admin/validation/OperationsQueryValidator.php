<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use InvalidArgumentException;
use think\Validate;

/** 工作台及运维记录的只读字段/场景校验；来源代码只用于筛选，不能据请求指定来源可用性。 */
final class OperationsQueryValidator extends Validate
{
    protected $rule = [
        'app_id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'scope_key' => ['string', 'max' => 64], 'plugin_code' => ['string', 'regex' => '/^[a-z][a-z0-9_.-]{0,63}$/D'],
        'pay_no' => ['string', 'regex' => '/^P[A-Za-z0-9]{1,63}$/D'], 'biz_no' => ['string', 'regex' => '/^B[A-Za-z0-9]{1,63}$/D'],
        'notify_no' => ['string', 'regex' => '/^N[A-Za-z0-9]{1,63}$/D'],
        'channel_id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'admin_id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'action' => ['string', 'regex' => '/^[a-z][a-z0-9_.]{0,63}$/D'],
        'result_code' => ['string', 'regex' => '/^[a-z][a-z0-9_]{0,63}$/D'],
        'request_id' => ['string', 'regex' => '/^[a-f0-9]{32}$/D'],
        'status' => ['string'],
    ];
    protected $scene = [
        'receipts' => ['id', 'scope_key', 'plugin_code', 'pay_no'],
        'notifications' => ['notify_no', 'biz_no', 'pay_no', 'status', 'app_id'],
        'ingress' => ['channel_id', 'plugin_code', 'result_code', 'request_id'],
        'audit' => ['admin_id', 'action', 'result_code', 'request_id'],
    ];

    public function __construct(private readonly DateRangeValidator $dates, private readonly PaginationValidator $pagination)
    {
        parent::__construct();
    }

    /**
     * @param array<string,mixed> $input
     * @return array{filters:array<string,string|int>,page:int,limit:int} 仅返回当前场景的筛选及服务端规范时间。
     */
    public function listing(string $kind, array $input): array
    {
        $fields = $this->scene[$kind] ?? throw new InvalidArgumentException('未知运行记录场景');
        $supportsAllDates = in_array($kind, ['receipts', 'notifications'], true);
        $this->known($input, [...$fields, 'start_date', 'end_date', 'page', 'limit', ...($supportsAllDates ? ['all_dates'] : [])]);
        if (isset($input['all_dates']) && !in_array($input['all_dates'], ['0', '1'], true)) {
            throw new BusinessException('日期范围选项无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        $filters = array_intersect_key($input, array_flip($fields));
        foreach ($filters as $key => $value) { if ($value === '') { unset($filters[$key]); } }
        $validator = (clone $this)->scene($kind)->failException(false)->batch();
        if ($kind === 'notifications') { $validator->rule('status', ['string', 'in' => ['0', '1', '2', '3', '4', 'acknowledged']]); }
        foreach (array_keys($filters) as $field) { $validator->append($field, 'require'); }
        if (!$validator->check($filters)) { throw new BusinessException('查询条件格式无效', ApiResponseConstant::VALIDATION_FAILED); }
        if ($kind === 'notifications' && isset($filters['status']) && $filters['status'] !== 'acknowledged') { $filters['status'] = (int) $filters['status']; }
        // 待处理入口查询全部日期，防止历史未处理记录被近七天的默认筛选隐藏；分页上限仍生效。
        if ($supportsAllDates && ($input['all_dates'] ?? '0') === '1') {
            return ['filters' => $filters] + $this->pagination->validate($input);
        }
        $range = $this->dates->range($input);
        return ['filters' => ['from' => $range['from'], 'until' => $range['until']] + $filters] + $this->pagination->validate($input);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{key:string,page:int,limit:int} 详情/历史身份独立校验，不受列表日期范围限制。
     */
    public function identity(string $field, array $input, bool $paged = false): array
    {
        if (!in_array($field, ['id', 'notify_no'], true)) { throw new InvalidArgumentException('未知记录身份'); }
        $this->known($input, $paged ? [$field, 'page', 'limit'] : [$field]);
        $validator = (clone $this)->only([$field])->append($field, 'require')->failException(false);
        if (!$validator->check($input)) { throw new BusinessException('记录编号无效', ApiResponseConstant::VALIDATION_FAILED); }
        return ['key' => $input[$field]] + $this->pagination->validate($input);
    }

    /**
     * @param array<string,mixed> $input 工作台只接收一个自然日，不从客户端获取当前时区。
     * @return array{from:string,until:string,start_date:string,end_date:string}
     */
    public function day(array $input): array
    {
        $this->known($input, ['date', 'app_id']);
        $appId = ($input['app_id'] ?? '') === '' ? null : $input['app_id'];
        if ($appId !== null && !(clone $this)->only(['app_id'])->append('app_id', 'require')->failException(false)->check(['app_id' => $appId])) {
            throw new BusinessException('应用编号无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        return $this->dates->range(array_key_exists('date', $input) ? ['start_date' => $input['date'], 'end_date' => $input['date']] : [], 1, 1) + ['app_id' => $appId];
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $fields
     */
    private function known(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields) !== []) { throw new BusinessException('查询包含未允许的字段', ApiResponseConstant::VALIDATION_FAILED); }
    }
}
