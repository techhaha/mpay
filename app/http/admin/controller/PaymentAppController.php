<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\common\util\SiteUrl;
use app\http\admin\validation\PaymentAppValidator;
use app\service\system\audit\ConfigurationActionContext;
use app\service\payment\config\PaymentAppService;
use think\Response;

/** 应用管理入口；路由组统一登录/CSRF，秘密响应禁止缓存，业务事务由服务负责。 */
final class PaymentAppController extends BaseAdminController
{
    public function index(PaymentAppService $service, SiteUrl $site): Response
    {
        return $this->success(['items' => $service->listing(), 'origin' => $site->origin()])->header(['Cache-Control' => 'no-store']);
    }

    public function create(PaymentAppValidator $validator, PaymentAppService $service): Response
    {
        $data = $validator->input('create', $this->jsonBody());
        return $this->success($service->create($data['name'], $data['pid'], $data['allowed_hosts'], $this->actor()), '应用已创建');
    }

    public function save(PaymentAppValidator $validator, PaymentAppService $service): Response
    {
        $data = $validator->input('save', $this->jsonBody());
        $service->save($data['id'], $data['revision'], $data['name'], $data['pid'], $data['enabled'], $data['allowed_hosts'], $this->actor());
        return $this->success(null, '应用已保存');
    }

    public function reveal(PaymentAppValidator $validator, PaymentAppService $service): Response
    {
        $data = $validator->input('reveal', $this->jsonBody());
        return $this->secret($service->reveal($data['id'], $data['expected_id'], $this->actor(), $data['password']));
    }

    public function rotate(PaymentAppValidator $validator, PaymentAppService $service): Response
    {
        $data = $validator->input('rotate', $this->jsonBody());
        return $this->secret($service->rotate($data['id'], $data['expected_id'], $data['key'] === '' ? null : $data['key'],
            $this->actor($data['reason']), $data['password']));
    }

    public function signature(PaymentAppValidator $validator, PaymentAppService $service): Response
    {
        $data = $validator->input('signature', $this->jsonBody());
        return $this->success($service->signatureTest($data['parameters'], $data['key']))->header(['Cache-Control' => 'no-store']);
    }

    /** @param array{id:string,version:int,key:string} $data 仅密码复核后的专用动作可以返回此结构。 */
    private function secret(array $data): Response
    {
        return $this->success($data)->header(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'Expires' => '0']);
    }

    private function actor(?string $reason = null): ConfigurationActionContext
    {
        $identity = $this->identity();
        return ConfigurationActionContext::administrator($identity['id'], $identity['session_version'], $this->context->id(),
            $reason, (string) $this->request->server('REMOTE_ADDR', ''));
    }

    public function routing(PaymentAppValidator $validator, PaymentAppService $service): Response
    {
        $data = $validator->input('routing', $this->jsonBody());
        $service->routing($data['id'], $data['revision'], $data['use_default_route'], $this->actor());
        return $this->success(null, '收款路由已更新');
    }
}
