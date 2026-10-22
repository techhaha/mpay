<?php
declare(strict_types=1);

namespace app\http\api\controller;

use app\common\base\BaseController;
use app\service\payment\receipt\PayOrderCallbackService;
use think\Response;

/** 设备/平台消息共用插件回调入口；插件验签并应答，不使用后台 Session 或 JSON 成功包装。 */
final class PaymentNotifyController extends BaseController
{
    public function notify(string $identifier, PayOrderCallbackService $callbacks): string|Response
    {
        return $callbacks->receiveChannel($identifier, $this->request, $this->context->id());
    }
}
