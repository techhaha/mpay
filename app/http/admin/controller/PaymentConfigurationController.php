<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\common\constant\ApiResponseConstant;
use app\common\util\ClientDevice;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\http\admin\validation\ConfigurationQueryValidator;
use app\http\admin\validation\ImageUploadValidator;
use app\http\admin\validation\PaymentChannelTestValidator;
use app\service\file\FileAssetService;
use app\service\payment\config\PaymentChannelEditorService;
use app\service\payment\config\PaymentChannelTestService;
use app\service\payment\config\PaymentConfigurationQueryService;
use app\service\payment\config\PaymentConfigurationValidator;
use app\service\system\audit\ConfigurationActionContext;
use think\exception\FileException;
use think\Response;

/**
 * 收款配置、范围和通道的后台边界；显式路由选用例，与 CLI 共用验证器和正式业务服务。
 * 管理员、认证版本和主体均来自服务器；入口不自行写模型，不把配置成功解释成已可收款。
 */
final class PaymentConfigurationController extends BaseAdminController
{
    /** 只创建指定通道的真实测试单，付款准备仍由现有收银台 POST 入口执行；重投复用原地址。 */
    public function testChannel(
        PaymentChannelTestValidator $validator,
        PaymentChannelTestService $service,
        Clock $clock,
    ): Response {
        $input = $validator->input($this->jsonBody());
        $order = $service->create(
            $input['app_id'],
            $input['channel_id'],
            $input['amount'],
            $input['request_key'],
            $this->actor(),
            $clock->now(),
            ClientDevice::detect((string) $this->request->header('User-Agent', '')),
        );
        return $this->success(
            [
                'biz_no' => $order->bizNo,
                'pay_no' => $order->payNo,
                'reused' => $order->reused,
                // 后台内跳转使用相对地址，沿用浏览器当前站点，不依赖代理回源时观察到的协议或域名。
                'cashier_url' => (string) url('cashier.pay', [
                    'biz_no' => $order->bizNo,
                    'token' => $order->accessToken,
                ])
                    ->suffix(false)
                    ->domain(false)
                    ->root(''),
            ],
            '测试订单已创建',
        );
    }

    public function plugins(
        ConfigurationQueryValidator $validator,
        PaymentConfigurationQueryService $query,
    ): Response {
        $validator->emptyQuery($this->request->get());
        return $this->success(['items' => $query->plugins()]);
    }

    public function channels(
        ConfigurationQueryValidator $validator,
        PaymentConfigurationQueryService $query,
    ): Response {
        $input = $validator->channels($this->request->get());
        $data = $query->channels($input['filters'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    public function configuration(
        ConfigurationQueryValidator $validator,
        PaymentConfigurationQueryService $query,
    ): Response {
        return $this->success($query->configuration($validator->identity($this->request->get())));
    }

    public function channel(
        ConfigurationQueryValidator $validator,
        PaymentConfigurationQueryService $query,
    ): Response {
        return $this->success($query->channel($validator->identity($this->request->get())));
    }

    /** 上传仅登记图片及审计；通道表单后续显式保存资产 ID，旧订单继续引用旧图片。 */
    public function uploadImage(ImageUploadValidator $validator, FileAssetService $files): Response
    {
        try {
            $uploads = $this->request->file() ?? [];
        } catch (FileException) {
            throw new BusinessException(
                '图片未完整接收，请检查上传大小和站点限制',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $data = $validator->upload($this->request->post(), $uploads);
        return $this->success(
            $files->uploadImage($data['image'], $this->actor()),
            '图片已上传，请保存通道完成绑定',
        );
    }

    public function image(ConfigurationQueryValidator $validator, FileAssetService $files): Response
    {
        return $this->success($files->image($validator->identity($this->request->get())));
    }

    /** 一个表单对应一个完整服务事务；范围与插件配置不再由浏览器分次提交。 */
    public function editorCreate(
        PaymentConfigurationValidator $validator,
        PaymentChannelEditorService $service,
    ): Response {
        $data = $validator->validate('editor-create', $this->jsonBody());
        $result = $service->create($data, $this->actor());
        return $this->success(
            $result,
            $result['enabled'] ? '通道已保存并启用' : '通道已保存，尚未启用',
        );
    }

    public function editorSave(
        PaymentConfigurationValidator $validator,
        PaymentChannelEditorService $service,
    ): Response {
        $data = $validator->validate('editor-save', $this->jsonBody());
        return $this->success($service->save($data, $this->actor()), '通道已保存，需重新启用');
    }

    /** 插件配置独立保存，不接收通道通用字段；当前通道需核对后启用。 */
    public function pluginSave(
        PaymentConfigurationValidator $validator,
        PaymentChannelEditorService $service,
    ): Response {
        $data = $validator->validate('plugin-save', $this->jsonBody());
        return $this->success(
            $service->configure($data, $this->actor()),
            '插件配置已保存，请核对后启用此通道',
        );
    }

    public function editorEnabled(
        PaymentConfigurationValidator $validator,
        PaymentChannelEditorService $service,
    ): Response {
        $data = $validator->validate('channel-enabled', $this->jsonBody());
        $service->setEnabled($data, $this->actor());
        return $this->success(
            ['id' => $data['id'], 'enabled' => $data['enabled']],
            $data['enabled'] ? '通道已启用' : '通道已停用',
        );
    }

    /** 只删除当前通道配置入口，保留被历史交易引用的实体、快照和流水。 */
    public function deleteChannel(
        PaymentConfigurationValidator $validator,
        PaymentChannelEditorService $service,
    ): Response {
        $data = $validator->validate('channel-delete', $this->jsonBody());
        $service->delete($data, $this->actor());
        return $this->success(['id' => $data['id']], '通道已删除');
    }

    /** 管理审计只采用服务器实际连接 IP；代理部署必须在 Web 服务端设置 REMOTE_ADDR。 */
    private function actor(?string $reason = null): ConfigurationActionContext
    {
        $identity = $this->identity();
        return ConfigurationActionContext::administrator(
            $identity['id'],
            $identity['session_version'],
            $this->context->id(),
            $reason,
            (string) $this->request->server('REMOTE_ADDR', ''),
        );
    }
}
