<?php
declare(strict_types=1);

namespace app\service\ops\log;

use app\common\base\BaseService;
use app\repository\system\AdminOperationLogRepository;

/** 登录和管理操作的审计查询；只返回固定摘要字段，不把新增内部 JSON 或凭证自动开放到后台。 */
final class AdminOperationQueryService extends BaseService
{
    public function __construct(private readonly AdminOperationLogRepository $logs)
    {
    }

    /**
     * @param array{from:string,until:string,admin_id?:string,action?:string,result_code?:string,request_id?:string} $filters
     * @return array{items:list<array>,total:int,page:int,limit:int}
     */
    public function listing(array $filters, int $page, int $limit): array
    {
        return $this->transaction(function () use ($filters, $page, $limit): array {
            $data = $this->logs->adminPage($filters, $page, $limit);
            $items = [];
            foreach ($data['items'] as $record) {
                $row = [];
                foreach (['id', 'admin_user_id', 'request_id', 'action', 'target_type', 'target_id', 'result_code', 'client_ip', 'created_at'] as $field) {
                    $row[$field] = $record[$field];
                }
                $summary = is_array($record['summary_json']) ? $record['summary_json'] : [];
                $allowed = array_intersect_key($summary, array_flip(['reason', 'origin', 'old_version', 'new_version', 'session_version',
                    'before_status', 'after_status', 'rows', 'enabled', 'use_default_route', 'plugin_code', 'plugin_version', 'close_semantics',
                    'mime_type', 'size', 'sha256', 'config_group',
                    'credential_version', 'parameter_count', 'signature_matches']));
                $row['summary'] = array_filter($allowed, static fn (mixed $value): bool => is_int($value) || is_bool($value)
                    || $value === null || (is_string($value) && strlen($value) <= 2048));
                $items[] = $row;
            }
            return ['items' => $items, 'total' => $data['total'], 'page' => $page, 'limit' => $limit];
        });
    }
}
