<?php
declare(strict_types=1);

namespace app\http\api\controller;

use app\common\base\BaseController;
use app\service\payment\receipt\PayOrderCallbackService;
use think\Response;

/** 设备消息只进入插件回调服务；来源密钥由插件验证，不使用后台 Session 或 JSON 成功包装。 */
final class PaymentNotifyController extends BaseController
{
    public function notify(string $channelId, PayOrderCallbackService $callbacks): string|Response
    {
        return $callbacks->receiveChannel($channelId, $this->request, $this->context->id());
    }
}
