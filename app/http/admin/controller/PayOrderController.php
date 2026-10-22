<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\common\util\Clock;
use app\http\admin\response\CsvResponse;
use app\http\admin\validation\PayOrderValidator;
use app\service\payment\order\AdminActionContext;
use app\service\payment\order\PayOrderAdminActionService;
use app\service\payment\order\PayOrderQueryService;
use think\Response;

/** 订单后台的查询、下载及动作边界；仅组装已验证输入，不拼查询、不直接更新状态、不发业务通知 HTTP。 */
final class PayOrderController extends BaseAdminController
{
    public function index(PayOrderValidator $validator, PayOrderQueryService $orders): Response
    {
        $input = $validator->filters($this->request->get());
        $result = $orders->listing($input['filters'], $input['page'], $input['limit']);
        return $this->page($result['items'], $result['total'], $result['page'], $result['limit']);
    }

    public function detail(PayOrderValidator $validator, PayOrderQueryService $orders): Response
    {
        return $this->success($orders->detail($validator->identity($this->request->get())));
    }

    public function history(PayOrderValidator $validator, PayOrderQueryService $orders): Response
    {
        $input = $validator->history($this->request->get());
        $result = $orders->history(
            $input['biz_no'],
            $input['kind'],
            $input['page'],
            $input['limit'],
        );
        return $this->page($result['items'], $result['total'], $result['page'], $result['limit']);
    }

    /** 下载使用独立 CSV 合同；框架响应扩展负责流的释放，不能套后台 JSON。 */
    public function export(PayOrderValidator $validator, PayOrderQueryService $orders): Response
    {
        $input = $validator->filters($this->request->get(), true);
        return new CsvResponse($orders->export($input['filters'], $this->actor('导出订单')));
    }

    /** 证据必须由管理员明确填写，不能从期望金额/当前时间自动生成“已核实到账”。 */
    public function confirm(
        PayOrderValidator $validator,
        PayOrderQueryService $orders,
        PayOrderAdminActionService $actions,
        Clock $clock,
    ): Response {
        $input = $validator->action('confirm', $this->jsonBody());
        $actions->confirm(
            $orders->paymentNumber($input['biz_no']),
            $this->actor($input['reason']),
            $validator->evidence($input),
            $input['current_password'],
            $clock->now(),
            $input['notify_merchant'],
        );
        return $this->success(null, '人工确认已处理，请核对原订单和通知状态');
    }

    public function close(
        PayOrderValidator $validator,
        PayOrderAdminActionService $actions,
    ): Response {
        $input = $validator->action('close', $this->jsonBody());
        $actions->closeBusiness($input['biz_no'], $this->actor($input['reason']));
        return $this->success(null, '已本地关闭；收款二维码未被平台撤销');
    }

    /** 返回已持久化的调度结果；首次放行或提前发送沿用自动策略，已结束任务仅补发一次，不等同远端 ACK。 */
    public function retryNotification(
        PayOrderValidator $validator,
        PayOrderQueryService $orders,
        PayOrderAdminActionService $actions,
        Clock $clock,
    ): Response {
        $input = $validator->action('retry', $this->jsonBody());
        return $this->success(
            $actions->retryNotification(
                $orders->paymentNumber($input['biz_no']),
                $input['expected_attempts'],
                $this->actor($input['reason']),
                $clock->now(),
            ),
            '已安排商户通知，请查看发送记录',
        );
    }

    /** 操作者仅来自服务端中间件；动作服务在事务内再次核对身份版本，原因只作为人工审计材料。 */
    private function actor(string $reason): AdminActionContext
    {
        $identity = $this->identity();
        return new AdminActionContext(
            $identity['id'],
            $identity['session_version'],
            $this->context->id(),
            $reason,
        );
    }
}
