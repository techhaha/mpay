<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\http\admin\validation\OperationsQueryValidator;
use app\service\ops\stat\PaymentStatisticsService;
use app\service\ops\log\AdminOperationQueryService;
use app\service\payment\receipt\ReceiptFlowQueryService;
use app\service\payment\notify\NotifyQueryService;
use think\Response;

/** 后台工作台和运行记录的只读 HTTP 边界；只接收筛选，不提供改状态、写凭证或触发采集的旁路。 */
final class OperationsController extends BaseAdminController
{
    public function dashboard(
        OperationsQueryValidator $validator,
        PaymentStatisticsService $statistics,
    ): Response {
        return $this->success($statistics->day($validator->day($this->request->get())));
    }

    public function receipts(
        OperationsQueryValidator $validator,
        ReceiptFlowQueryService $records,
    ): Response {
        $input = $validator->listing('receipts', $this->request->get());
        $data = $records->listing($input['filters'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    public function receipt(
        OperationsQueryValidator $validator,
        ReceiptFlowQueryService $records,
    ): Response {
        return $this->success(
            $records->detail($validator->identity('id', $this->request->get())['key']),
        );
    }

    public function receiptHistory(
        OperationsQueryValidator $validator,
        ReceiptFlowQueryService $records,
    ): Response {
        $input = $validator->identity('id', $this->request->get(), true);
        $data = $records->history($input['key'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    public function ingress(
        OperationsQueryValidator $validator,
        ReceiptFlowQueryService $records,
    ): Response {
        $input = $validator->listing('ingress', $this->request->get());
        $data = $records->ingress($input['filters'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    public function ingressFlows(
        OperationsQueryValidator $validator,
        ReceiptFlowQueryService $records,
    ): Response {
        $input = $validator->identity('id', $this->request->get(), true);
        $data = $records->ingressFlows($input['key'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    public function notifications(
        OperationsQueryValidator $validator,
        NotifyQueryService $records,
    ): Response {
        $input = $validator->listing('notifications', $this->request->get());
        $data = $records->listing($input['filters'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    public function notification(
        OperationsQueryValidator $validator,
        NotifyQueryService $records,
    ): Response {
        return $this->success(
            $records->detail($validator->identity('notify_no', $this->request->get())['key']),
        );
    }

    public function notificationAttempts(
        OperationsQueryValidator $validator,
        NotifyQueryService $records,
    ): Response {
        $input = $validator->identity('notify_no', $this->request->get(), true);
        $data = $records->attempts($input['key'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    public function audit(
        OperationsQueryValidator $validator,
        AdminOperationQueryService $records,
    ): Response {
        $input = $validator->listing('audit', $this->request->get());
        $data = $records->listing($input['filters'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }
}
