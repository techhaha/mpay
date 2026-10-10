<?php
declare(strict_types=1);

namespace app\repository\system;

use app\common\base\BaseRepository;
use app\model\admin\AdminOperationLog;

/**
 * 管理操作的专用审计写入，不通过可轮转技术日志替代；与成功配置变更共用外层事务。
 *
 * @extends BaseRepository<AdminOperationLog>
 */
final class AdminOperationLogRepository extends BaseRepository
{
    public function __construct(AdminOperationLog $model)
    {
        parent::__construct($model);
    }

    /**
     * @param array{admin_user_id:?string,request_id:string,action:string,target_type:string,target_id:?string,
     *     result_code:string,summary_json:string,client_ip:?string} $data 服务已白名单映射的脱敏摘要。
     */
    public function record(array $data): void
    {
        $this->insert($data, ['admin_user_id', 'request_id', 'action', 'target_type', 'target_id',
            'result_code', 'summary_json', 'client_ip']);
    }

    /**
     * 调用认证服务须先锁同一管理员行；使用已有身份/时间索引读取有限失败样本，不由仓储决定封禁策略。
     * 登录失败身份未被认证，adminId 为 null；改密失败使用已认证身份。未知用户名不作为审计查询条件。
     */
    public function rejectedAuthenticationCount(?string $adminId, string $action, ?string $clientIp, \DateTimeImmutable $since, int $limit): int
    {
        $this->requireTransaction();
        $query = $this->model->where('action', $action)
            ->where('result_code', 'rejected')
            ->where('created_at', '>=', $since->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'));
        $query = $adminId === null ? $query->whereNull('admin_user_id') : $query->where('admin_user_id', $adminId);
        $query = $clientIp === null ? $query->whereNull('client_ip') : $query->where('client_ip', $clientIp);
        return count($query->limit($limit)->column('id'));
    }

    /** @return array{items:list<AdminOperationLog>,total:int} 已认证服务按明确目标读取业务审计。 */
    public function pageForTarget(string $type, string $id, int $page, int $limit): array
    {
        return ['items' => $this->model->where('target_type', $type)->where('target_id', $id)
            ->order('id', 'desc')->page($page, $limit)->select()->all(),
            'total' => (int) $this->model->where('target_type', $type)->where('target_id', $id)->count()];
    }

    /**
     * @param array{from:string,until:string,admin_id?:string,action?:string,result_code?:string,request_id?:string} $filters
     * @return array{items:list<AdminOperationLog>,total:int} 包含身份尚未确认的登录拒绝记录，不以账号存在性补全身份。
     */
    public function adminPage(array $filters, int $page, int $limit): array
    {
        $query = $this->model->where('created_at', '>=', $filters['from'])->where('created_at', '<', $filters['until']);
        foreach (['admin_id' => 'admin_user_id', 'action' => 'action', 'result_code' => 'result_code', 'request_id' => 'request_id'] as $key => $column) {
            if (isset($filters[$key])) { $query->where($column, $filters[$key]); }
        }
        return ['total' => (int) (clone $query)->count(), 'items' => $query->order('id', 'desc')->page($page, $limit)->select()->all()];
    }
}
