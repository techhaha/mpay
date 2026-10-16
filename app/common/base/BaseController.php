<?php
declare(strict_types=1);

namespace app\common\base;

use app\common\constant\ApiResponseConstant;
use app\common\util\RequestContext;
use think\App;
use think\Request;
use think\Response;

/** 后台和收银台 JSON 的公共响应边界；易支付、设备 ACK、HTML 和下载采用各自合同。 */
abstract class BaseController
{
    /** 框架当前请求实例，仅在 HTTP 入口读取，不隐式传入领域服务。 */
    protected Request $request;

    public function __construct(protected App $app, protected RequestContext $context)
    {
        $this->request = $app->request;
    }

    /**
     * 返回操作完成的统一 JSON；code=200 不代表支付交易已经成功。
     *
     * @param array<array-key, mixed>|null $data 具体接口已筛选的公开载荷；无载荷用 null。
     */
    protected function success(array|null $data = null, string $message = '操作成功'): Response
    {
        return json([
            'code' => ApiResponseConstant::OK,
            'message' => $message,
            'data' => $data,
            'request_id' => $this->context->id(),
        ]);
    }

    /**
     * 包装分页查询结果；不在这里查库、截取结果或转换成 Layui 的 code=0 格式。
     *
     * @param list<array<string, mixed>> $items 已按公开字段白名单转换的当前页记录。
     * @param int $total 应用相同筛选条件后的总数，不是当前页条数。
     * @param int $page 从 1 开始，调用方已通过 PaginationValidator 校验。
     * @param int $limit 实际页大小，默认 20、最大 100。
     */
    protected function page(array $items, int $total, int $page, int $limit): Response
    {
        return $this->success(compact('items', 'total', 'page', 'limit'), '查询成功');
    }
}
