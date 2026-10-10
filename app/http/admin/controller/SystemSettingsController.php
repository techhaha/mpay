<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\common\util\SiteUrl;
use app\http\admin\validation\SystemSettingsValidator;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\config\SystemConfigService;
use think\Config;
use think\Response;

/** 系统公开设置的认证 HTTP 边界；只返回允许编辑的组与当前站点来源，不暴露部署文件或任意 Config 内容。 */
final class SystemSettingsController extends BaseAdminController
{
    /** 新建通道只读取默认组，已有通道编辑继续使用自身规则，不能被系统默认覆盖。 */
    public function paymentDefaults(SystemSettingsValidator $validator, SystemConfigService $settings): Response
    {
        $validator->emptyQuery($this->request->get());
        return $this->success($settings->read('payment_defaults'));
    }

    public function index(SystemSettingsValidator $validator, SystemConfigService $settings, SiteUrl $site, Config $config): Response
    {
        $validator->emptyQuery($this->request->get());
        return $this->success(['groups' => $settings->all(), 'current_origin' => $site->origin(),
            'notification_ports' => $config->get('http.allowed_ports', [80, 443])]);
    }

    /** 版本冲突原样返回，不能替客户端读取新版本后再次覆盖；审计与配置由服务同事务提交。 */
    public function save(SystemSettingsValidator $validator, SystemConfigService $settings): Response
    {
        $input = $validator->save($this->jsonBody()); $identity = $this->identity();
        $actor = ConfigurationActionContext::administrator($identity['id'], $identity['session_version'], $this->context->id(),
            null, (string) $this->request->server('REMOTE_ADDR', ''));
        return $this->success($settings->save($input['group'], $input['expected_version'], $input['values'], $actor), '设置已保存');
    }
}
