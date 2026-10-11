<?php
declare(strict_types=1);

namespace app\http\page;

use app\common\base\BaseController;
use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\http\api\validation\CashierValidator;
use app\service\payment\cashier\CashierService;
use app\service\payment\epay\EpayV1ProtocolService;
use app\service\system\access\AdminSessionService;
use think\Response;

/** 收银台 HTML 和成功回跳的边界；访问凭证验证后只把公开首屏数据交给模板。 */
final class CashierController extends BaseController
{
    /** 成功页重新验证持有者凭证及数据库事实；不能通过手改 URL 把未付款订单展示成成功。 */
    public function completed(CashierValidator $validator, CashierService $cashier, Clock $clock): Response
    {
        $identity = $validator->identity($this->request->get());
        $data = $cashier->view($identity['biz_no'], $identity['token'], $clock->now());
        if ($data['status'] !== TradeConstant::ORDER_STATUS_SUCCESS) {
            return redirect('/cashier/pay?' . http_build_query($identity), 303);
        }
        // 成功页只有只读信息；公共布局仍需要明确的 CSRF 占位，避免模板读取未定义变量。
        return view('cashier/success', [
            'title' => '支付成功 · MPAY',
            'csrf_token' => '',
            'payment' => $data,
            'return_path' => '/cashier/return?' . http_build_query($identity)
        ]);
    }

    public function index(CashierValidator $validator, CashierService $cashier, AdminSessionService $session, Clock $clock): Response
    {
        $identity = $validator->identity($this->request->get());
        $data = $cashier->view($identity['biz_no'], $identity['token'], $clock->now());
        return view('cashier/index', [
            'title' => 'MPAY 收银台', 'csrf_token' => $session->csrfToken(), 'request_id' => $this->context->id(),
            'biz_no' => $identity['biz_no'], 'access_token' => $identity['token'],
            // script 中的 JSON 必须转义 HTML 敏感字符，不能让订单名称关闭 script 标签。
            'initial_json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
    }

    /** 回跳始终重新读取原单并核验本地成功，只签名跳转，不接受外部 success 参数确认收款。 */
    public function back(CashierValidator $validator, EpayV1ProtocolService $protocol): Response
    {
        $identity = $validator->identity($this->request->get());
        return redirect($protocol->returnUrl($identity['biz_no'], $identity['token']), 303);
    }
}
