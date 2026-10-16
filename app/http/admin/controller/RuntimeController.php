<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\http\admin\validation\RuntimeQueryValidator;
use app\service\system\runtime\RuntimeQueryService;
use app\service\system\runtime\SystemDoctorService;
use think\Response;

/** 运行维护的只读入口；浏览器不具备执行 shell、迁移、文件清理或平台登录的入口。 */
final class RuntimeController extends BaseAdminController
{
    public function overview(RuntimeQueryValidator $validator, RuntimeQueryService $query): Response
    {
        $validator->emptyQuery($this->request->get());
        return $this->success($query->overview());
    }

    public function collections(
        RuntimeQueryValidator $validator,
        RuntimeQueryService $query,
    ): Response {
        $input = $validator->collections($this->request->get());
        $data = $query->collections($input['filters'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    /** 由管理员明确点选再执行较完整的只读结构检查，普通页面刷新不逐表检查迁移。 */
    public function doctor(RuntimeQueryValidator $validator, SystemDoctorService $doctor): Response
    {
        $validator->emptyQuery($this->request->get());
        return $this->success(['sapi' => PHP_SAPI, 'checks' => $doctor->inspect()]);
    }
}
