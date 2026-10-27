<?php
declare(strict_types=1);

namespace app\http\api\controller;

use app\common\base\BaseController;
use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\http\api\validation\CashierValidator;
use app\service\payment\cashier\CashierService;
use think\Response;

/** 收银台同源 JSON 边界，读/写入口显式分开，沿用公共响应并由领域服务控制交易行为。 */
final class CashierController extends BaseController
{
    public function order(
        string $biz_no,
        string $token,
        CashierValidator $validator,
        CashierService $cashier,
        Clock $clock,
    ): Response {
        $identity = $validator->identity(compact('biz_no', 'token'));
        return $this->success(
            $cashier->view($identity['biz_no'], $identity['token'], $clock->now()),
            '查询成功',
        );
    }

    public function status(
        string $biz_no,
        string $token,
        CashierValidator $validator,
        CashierService $cashier,
        Clock $clock,
    ): Response {
        $identity = $validator->identity(compact('biz_no', 'token'));
        return $this->success(
            $cashier->status($identity['biz_no'], $identity['token'], $clock->now()),
            '查询成功',
        );
    }

    public function completion(
        string $biz_no,
        string $token,
        CashierValidator $validator,
        CashierService $cashier,
    ): Response {
        $identity = $validator->identity(compact('biz_no', 'token'));
        return $this->success(
            $cashier->completion($identity['biz_no'], $identity['token']),
            '查询成功',
        );
    }

    public function selectType(CashierValidator $validator, CashierService $cashier): Response
    {
        $input = $validator->action($this->jsonBody(), true);
        return $this->success(
            $cashier->selectType(
                $input['biz_no'],
                $input['token'],
                $input['type'],
                $this->context->id(),
            ),
            '原订单已处理',
        );
    }

    public function prepare(CashierValidator $validator, CashierService $cashier): Response
    {
        $input = $validator->action($this->jsonBody(), false);
        return $this->success(
            $cashier->prepare($input['biz_no'], $input['token'], $this->context->id()),
            '原执行已处理',
        );
    }

    /** @return array<string,mixed> JSON 对象是唯一允许的写载荷，不能把 JSON 解析失败当作空参数。 */
    private function jsonBody(): array
    {
        try {
            $body = json_decode($this->request->getInput(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BusinessException('JSON 正文格式无效', ApiResponseConstant::BAD_REQUEST);
        }
        if (!is_array($body) || array_is_list($body)) {
            throw new BusinessException(
                '收银台正文必须为 JSON 对象',
                ApiResponseConstant::BAD_REQUEST,
            );
        }
        return $body;
    }
}
