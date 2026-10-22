<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\util\Money;
use app\common\validation\PaymentTypeCode;
use InvalidArgumentException;

/**
 * 验签和字段校验完成后的业务建单输入，不接收框架 Request 或原始签名参数。
 * appId/pid/credentialVersionId 由认证服务提供；URL 的目标安全检查仍由协议边界完成。
 * 此对象保留最初请求的支付方式，不能被收银台后来选定的方式覆盖。
 */
final readonly class CreateOrderInput
{
    /**
     * @param int $amount 业务应收金额，整数分，必须大于零。
     * @param string|null $requestedPayType null 表示先进入方式选择页。
     * @param string|null $returnUrl null 表示成功后留在本地结果页。
     * @param string|null $notifyUrl 未提供通知目标时为 null，所有订单统一不生成异步通知任务。
     * @param string|null $clientIp 可空的原始客户端 IP，只作来源记录，不承担认证。
     * @param string|null $device API 请求设备或其协议缺省值，参与业务指纹；页面识别结果使用 detectedDevice。
     * @param string $param 成功通知须原样回传的扩展字符串，不解释其内部内容。
     * @param string|null $detectedDevice HTTP 边界识别的展示环境，仅在 device 省略时使用，不参与幂等指纹。
     * @param string $submitType 服务入口确定的 page/api；不从外部同名参数读取。
     * @param string|null $channelId 仅受信任的后台入口可指定通道；易支付参数不映射此字段。
     * @throws InvalidArgumentException 调用边界遗漏基本领域校验。
     */
    public function __construct(
        public string $appId,
        public string $pid,
        public string $credentialVersionId,
        public string $merchantOrderNo,
        public string $subject,
        public int $amount,
        public ?string $requestedPayType,
        public ?string $notifyUrl,
        public ?string $returnUrl,
        public ?string $clientIp,
        public ?string $device,
        public string $param,
        public ?string $detectedDevice = null,
        public ?string $channelId = null,
        public string $submitType = 'page',
    ) {
        Money::assertRange($amount);
        if (
            !preg_match('/^[1-9][0-9]{0,31}$/D', $pid) ||
            $amount === 0 ||
            !in_array($submitType, ['page', 'api'], true) ||
            !preg_match('/^[1-9][0-9]*$/D', $appId) ||
            !preg_match('/^[1-9][0-9]*$/D', $credentialVersionId) ||
            !mb_check_encoding(
                [$merchantOrderNo, $subject, $param, $notifyUrl ?? '', $returnUrl ?? ''],
                'UTF-8',
            ) ||
            mb_strlen($merchantOrderNo, 'UTF-8') < 1 ||
            mb_strlen($merchantOrderNo, 'UTF-8') > 64 ||
            $subject === '' ||
            strlen($subject) > 127 ||
            strlen($param) > 2048 ||
            ($notifyUrl !== null && ($notifyUrl === '' || mb_strlen($notifyUrl, 'UTF-8') > 2048)) ||
            ($channelId !== null && !preg_match('/^[1-9][0-9]*$/D', $channelId)) ||
            ($returnUrl !== null && ($returnUrl === '' || mb_strlen($returnUrl, 'UTF-8') > 2048)) ||
            ($clientIp !== null && filter_var($clientIp, FILTER_VALIDATE_IP) === false) ||
            ($requestedPayType !== null && !PaymentTypeCode::valid($requestedPayType)) ||
            !in_array($device, [null, 'pc', 'mobile', 'qq', 'wechat', 'alipay', 'jump'], true) ||
            !in_array(
                $detectedDevice,
                [null, 'pc', 'mobile', 'qq', 'wechat', 'alipay', 'jump'],
                true,
            )
        ) {
            throw new InvalidArgumentException('建单输入尚未完成业务规范化');
        }
    }

    /**
     * 明确列出不可在重投中改变的业务语义，避免直接对任意请求数组排序后充当业务合同。
     * 身份版本和 IP 不参与：轮换 KEY、客户端换网后仍可查询原单，但不能改原单上下文。
     */
    public function fingerprint(): string
    {
        return hash(
            'sha256',
            json_encode(
                [
                    'version' => 1,
                    'merchant_order_no' => $this->merchantOrderNo,
                    'subject' => $this->subject,
                    'amount' => $this->amount,
                    'requested_pay_type' => $this->requestedPayType,
                    'notify_url' => $this->notifyUrl,
                    'return_url' => $this->returnUrl,
                    'device' => $this->device,
                    'param' => $this->param,
                ] + ($this->channelId === null ? [] : ['channel_id' => $this->channelId]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
