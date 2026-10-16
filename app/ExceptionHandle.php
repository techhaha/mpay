<?php
declare(strict_types=1);

namespace app;

use app\common\constant\ApiResponseConstant as Code;
use app\common\util\RequestContext;
use app\common\util\SafeLogger;
use app\exception\BusinessException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理器：区分公开业务错误和内部故障，并按入口协议选择响应格式。
 *
 * report 负责脱敏诊断，render 负责 HTTP 输出；两者都不改变订单或通知状态。
 */
final class ExceptionHandle extends Handle
{
    /**
     * 框架异常报告钩子：只记录需要定位的故障，不调用会保存异常原文的默认报告逻辑。
     * 业务拒绝、HTTP 错误和显式响应异常由各自入口处理，不在这里重复记故障日志。
     */
    public function report(Throwable $exception): void
    {
        if (
            !($exception instanceof BusinessException) &&
            !($exception instanceof HttpResponseException) &&
            !($exception instanceof HttpException)
        ) {
            $this->app->make(SafeLogger::class)->exception($exception);
        }
    }

    /**
     * 将异常转换为入口约定的响应；即使 APP_DEBUG 开启也不输出堆栈或原始异常页。
     *
     * @param \think\Request $request 框架传入的当前请求，保留父类兼容签名。
     */
    public function render($request, Throwable $e): Response
    {
        $id = $this->app->make(RequestContext::class)->id();
        // 框架的显式响应可能是跳转或下载，不能重新套上 JSON 包装。
        if ($e instanceof HttpResponseException) {
            return $e->getResponse()->header(['X-Request-Id' => $id]);
        }
        // 未知故障及支付结果不确定默认按故障输出；这里没有建立持久恢复责任，不能返回 202。
        $code = Code::INTERNAL_ERROR;
        $message = '服务暂时出现问题，请稍后重试';
        $data = null;
        // 只有 BusinessException 的构造合同允许直接公开 message 和字段错误。
        if ($e instanceof BusinessException) {
            $code = $e->getCode();
            $message = $e->getMessage();
            $data = $e->errors === [] ? null : ['errors' => $e->errors];
        } elseif ($e instanceof ValidateException) {
            $code = Code::VALIDATION_FAILED;
            $message = '请求参数校验失败';
        } elseif ($e instanceof HttpException) {
            [$code, $message] = match ($e->getStatusCode()) {
                400 => [Code::BAD_REQUEST, '请求格式错误'],
                401 => [Code::UNAUTHENTICATED, '请先登录'],
                403 => [Code::FORBIDDEN, '无权访问'],
                404 => [Code::NOT_FOUND, '资源不存在'],
                405 => [Code::METHOD_NOT_ALLOWED, '请求方法不允许'],
                429 => [Code::TOO_MANY_REQUESTS, '请求过于频繁'],
                503 => [Code::SERVICE_UNAVAILABLE, '服务暂不可用'],
                default => [$code, $message],
            };
        }
        $status = Code::httpStatus($code);
        // 根据已解析路径分流，而非 Accept 头；易支付和设备不能使用后台应用码。
        $path = '/' . ltrim($request->pathinfo(), '/');
        if (preg_match('#^/(adminapi(?:/|$)|installapi(?:/|$)|api/cashier(?:/|$))#', $path)) {
            $response = json(
                ['code' => $code, 'message' => $message, 'data' => $data, 'request_id' => $id],
                $status,
            );
        } elseif (in_array($path, ['/mapi.php', '/api.php'], true)) {
            $response = json(['code' => 0, 'msg' => $message], $status);
        } elseif (str_starts_with($path, '/api/pay/')) {
            // 设备入口的拒绝和故障保留协议错误响应，不套后台 JSON，也不冒充成功接收。
            $response = response('error', $status, ['Content-Type' => 'text/plain; charset=utf-8']);
        } else {
            $response = view('error/error', ['message' => $message, 'request_id' => $id], $status);
        }
        $headers = ['X-Request-Id' => $id, 'Cache-Control' => 'no-store'];
        // 保留路由声明的 Allow 头，客户端才能知道该路径允许的方法。
        if ($e instanceof HttpException && $status === 405) {
            $headers += $e->getHeaders();
        }
        return $response->header($headers);
    }
}
