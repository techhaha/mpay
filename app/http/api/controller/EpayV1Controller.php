<?php
declare(strict_types=1);

namespace app\http\api\controller;

use app\common\constant\ApiResponseConstant;
use app\common\util\RequestContext;
use app\common\util\UrlEncodedForm;
use app\exception\BusinessException;
use app\service\payment\epay\EpayV1ProtocolService;
use think\Request;
use think\Response;

/** 易支付独立 HTTP 边界，不继承后台 JSON 包装；只接受原始 UTF-8 表单参数。 */
final class EpayV1Controller
{
    public function __construct(
        private readonly Request $request,
        private readonly RequestContext $context,
        private readonly EpayV1ProtocolService $protocol,
    ) {}

    /** GET/POST 页面下单返回本地收银台；HTML 错误交给统一异常处理器安全展示。 */
    public function submit(): Response
    {
        if (!in_array($this->request->method(true), ['GET', 'POST'], true)) {
            throw new \think\exception\HttpException(405, '', null, ['Allow' => 'GET, POST']);
        }
        $result = $this->protocol->create(
            $this->parameters(),
            'submit',
            $this->request->ip(),
            $this->device(),
            $this->context->id(),
        );
        return redirect($result['payurl'], 303);
    }

    /** 业务拒绝保持易支付 code=0；程序故障仍由全局处理器返回脱敏 5xx，不伪造受理成功。 */
    public function mapi(): Response
    {
        try {
            $result = $this->protocol->create(
                $this->parameters(),
                'mapi',
                $this->request->ip(),
                $this->device(),
                $this->context->id(),
            );
            return json([
                'code' => 1,
                'msg' => $result['reused'] ? '返回原订单' : '订单已受理',
                'trade_no' => $result['trade_no'],
                'payurl' => $result['payurl'],
            ]);
        } catch (BusinessException $exception) {
            return json(['code' => 0, 'msg' => $exception->getMessage()]);
        }
    }

    public function api(): Response
    {
        try {
            return json($this->protocol->api($this->parameters()));
        } catch (BusinessException $exception) {
            return json(['code' => 0, 'msg' => $exception->getMessage()]);
        }
    }

    /**
     * 不使用 Request::param 的合并/过滤结果参与验签，尤其不能把 JSON 当成表单。
     *
     * @return array<string|int,string>
     */
    private function parameters(): array
    {
        // 原始访问 URL 保留待签名参数；不把服务器伪静态补入的路由参数混进验签。
        $rawQuery = parse_url($this->request->url(), PHP_URL_QUERY);
        if ($rawQuery === false) {
            throw new BusinessException('请求地址格式无效', ApiResponseConstant::BAD_REQUEST);
        }
        $query = UrlEncodedForm::parse($rawQuery ?? '');
        if ($this->request->method(true) === 'GET' || $this->request->method(true) === 'HEAD') {
            return $query;
        }
        $header = (string) $this->request->header('Content-Type', '');
        $contentType = strtolower(trim(explode(';', $header)[0]));
        if (
            $contentType !== 'application/x-www-form-urlencoded' ||
            (preg_match('/(?:^|;)\s*charset\s*=\s*"?([^;"\s]+)/i', $header, $match) &&
                !in_array(strtolower($match[1]), ['utf-8', 'utf8'], true))
        ) {
            throw new BusinessException(
                '易支付入口只接受 UTF-8 表单',
                ApiResponseConstant::BAD_REQUEST,
            );
        }
        return UrlEncodedForm::merge($query, UrlEncodedForm::parse($this->request->getInput()));
    }

    /** 设备只作显示提示，不参与身份认证或收款确认，也不改变原始签名参数。 */
    private function device(): string
    {
        $agent = (string) $this->request->header('User-Agent', '');
        return match (true) {
            stripos($agent, 'MicroMessenger') !== false => 'wechat',
            stripos($agent, 'AlipayClient') !== false => 'alipay',
            stripos($agent, 'QQ/') !== false => 'qq',
            preg_match('/Mobile|Android|iPhone|iPad/i', $agent) === 1 => 'mobile',
            default => 'pc',
        };
    }
}
