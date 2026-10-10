<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\http\admin\validation\PaymentCatalogValidator;
use app\service\payment\config\PaymentCatalogService;
use app\service\system\audit\ConfigurationActionContext;
use think\Response;

/** 支付方式和插件管理的 HTTP 边界；事务、引用约束和审计都由目录服务负责。 */
final class PaymentCatalogController extends BaseAdminController
{
    public function index(PaymentCatalogService $service): Response { return $this->success($service->overview()); }

    public function save(PaymentCatalogValidator $validator, PaymentCatalogService $service): Response
    {
        $data = $validator->validate('save', $this->jsonBody());
        return $this->success($service->saveType($data, $this->actor()), '支付方式已保存');
    }

    public function delete(PaymentCatalogValidator $validator, PaymentCatalogService $service): Response
    {
        $data = $validator->validate('delete', $this->jsonBody());
        $service->deleteType($data['id'], $data['revision'], $this->actor());
        return $this->success([], '支付方式已删除');
    }

    public function refresh(PaymentCatalogValidator $validator, PaymentCatalogService $service): Response
    {
        $data = $validator->validate('refresh', $this->jsonBody());
        $service->refresh($this->actor());
        return $this->success($service->overview(), '插件列表已刷新');
    }

    public function enabled(PaymentCatalogValidator $validator, PaymentCatalogService $service): Response
    {
        $data = $validator->validate('enabled', $this->jsonBody());
        $service->pluginEnabled($data, $this->actor());
        return $this->success([], $data['enabled'] ? '插件已启用' : '插件已停止接收新订单');
    }

    private function actor(?string $reason = null): ConfigurationActionContext
    {
        $identity = $this->identity();
        return ConfigurationActionContext::administrator($identity['id'], $identity['session_version'], $this->context->id(),
            $reason, (string) $this->request->server('REMOTE_ADDR', ''));
    }
}
