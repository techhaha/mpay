<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\http\admin\validation\PaymentPollGroupValidator;
use app\service\system\audit\ConfigurationActionContext;
use app\service\payment\routing\PaymentPollGroupService;
use think\Response;

/** 轮询组管理与选路预览入口，写保护统一挂载于显式路由组。 */
final class PaymentPollGroupController extends BaseAdminController
{
    public function index(PaymentPollGroupService $service): Response { return $this->success($service->listing()); }

    public function create(PaymentPollGroupValidator $validator, PaymentPollGroupService $service): Response
    {
        $data = $validator->input('create', $this->jsonBody());
        return $this->success($service->create($data['name'], $data['pay_type'], $data['route_mode'], $data['remark'], $data['members'], $this->actor()));
    }

    public function save(PaymentPollGroupValidator $validator, PaymentPollGroupService $service): Response
    {
        $data = $validator->input('save', $this->jsonBody());
        $service->save($data['id'], $data['revision'], $data['name'], $data['route_mode'], $data['enabled'], $data['remark'], $data['members'], $this->actor());
        return $this->success(null, '轮询组已保存');
    }

    public function bind(PaymentPollGroupValidator $validator, PaymentPollGroupService $service): Response
    {
        $data = $validator->input('bind', $this->jsonBody());
        $service->bind($data['app_id'], $data['pay_type'], $data['group_id'], $data['revision'], $data['enabled'], $this->actor());
        return $this->success(null, '应用绑定已保存');
    }

    public function preview(PaymentPollGroupValidator $validator, PaymentPollGroupService $service): Response
    {
        $data = $validator->input('preview', $this->jsonBody());
        return $this->success($service->preview($data['app_id'], $data['pay_type'], $data['amount']));
    }

    private function actor(?string $reason = null): ConfigurationActionContext
    {
        $identity = $this->identity();
        return ConfigurationActionContext::administrator($identity['id'], $identity['session_version'], $this->context->id(),
            $reason, (string) $this->request->server('REMOTE_ADDR', ''));
    }
}
