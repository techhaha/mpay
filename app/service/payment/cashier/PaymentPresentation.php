<?php
declare(strict_types=1);

namespace app\service\payment\cashier;

use app\common\util\Money;

/**
 * 只持久化公开付款材料；完整页面参数按订单快照和当前服务器时间生成，不缓存过时倒计时。
 *
 * @phpstan-type CashierPresentation array{pay_page:string,pay_type:string,pay_product:string,pay_action:string,
 *     pay_params:array{_page:string,receipt_match_mode:string,order_money:string,receipt_money:string,remark:?string,
 *     expire_at_timestamp:int,server_time_timestamp:int,qrcode_content:?string,qrcode_image_url:?string,
 *     qrcode_encoding:array{mode:string,error_correction:string,max_text_bytes:int}}}
 */
final readonly class PaymentPresentation
{
    public const MODE = 'Byte';
    public const ERROR_CORRECTION = 'M';
    public const MAX_TEXT_BYTES = 2331;
    public const MAX_STORED_TEXT_BYTES = 4096;

    /**
     * @param string|null $qrcodeContent 码内容，按文本编码渲染，不作为可执行 HTML。
     * @param string|null $imagePath 本项目受控 storage 图片路径，禁止远端导入或任意 URL。
     * @throws \InvalidArgumentException 材料为空、包含不受控图片路径或码内容超出显示边界。
     */
    public function __construct(public ?string $qrcodeContent, public ?string $imagePath)
    {
        self::assertMaterial($qrcodeContent, $imagePath !== null);
        if ($imagePath !== null && !preg_match('#^/storage/[a-zA-Z0-9/_-]+\.(png|jpg|jpeg|webp)$#D', $imagePath)) {
            throw new \InvalidArgumentException('公开付款材料无效');
        }
    }

    /** @return array{qrcode_content:?string,qrcode_image_url:?string} 白名单快照，不夹带内部配置或文件顺序 ID。 */
    public function toArray(): array
    {
        return ['qrcode_content' => $this->qrcodeContent, 'qrcode_image_url' => $this->imagePath];
    }

    /**
     * 固定 receiptQrcode 页的标准 presentation；参数仅来自服务端订单，不允许平台指定任意模板路径。
     *
     * @param int $orderAmount 业务金额，整数分。
     * @param int $receiptAmount 准确提示金额，整数分，不能由页面重算偏移。
     * @return CashierPresentation 金额为两位小数元文本，时间为 Unix 秒。
     */
    public function forCashier(string $payType, string $mode, int $orderAmount, int $receiptAmount, ?string $remark,
        \DateTimeImmutable $expiresAt, \DateTimeImmutable $serverTime): array
    {
        if (!\app\common\validation\PaymentTypeCode::valid($payType) || !in_array($mode, ['amount', 'remark'], true)
            || $orderAmount < 1 || $receiptAmount < $orderAmount || ($mode === 'remark' && ($remark === null || $remark === ''))) {
            throw new \LogicException('页面参数缺少有效的付款快照');
        }
        return ['pay_page' => 'page', 'pay_type' => $payType, 'pay_product' => 'receipt', 'pay_action' => 'qrcode',
            'pay_params' => ['_page' => 'receiptQrcode', 'receipt_match_mode' => $mode,
                'order_money' => Money::format($orderAmount), 'receipt_money' => Money::format($receiptAmount),
                'remark' => $mode === 'remark' ? $remark : null, 'expire_at_timestamp' => $expiresAt->getTimestamp(),
                'server_time_timestamp' => $serverTime->getTimestamp(), 'qrcode_content' => $this->qrcodeContent,
                'qrcode_image_url' => $this->imagePath, 'qrcode_encoding' => self::rendering()]];
    }

    /**
     * 保存配置、冻结通道和展示共用同一容量规则，避免后台可保存而收银台无法编码。
     * 图片优先时文本不参与编码，但仍限制保存长度；不截断付款内容或降低纠错等级凑容量。
     *
     * @throws \InvalidArgumentException 材料缺失、非 UTF-8 文本或超过当前 Byte/M 二维码库容量。
     */
    public static function assertMaterial(?string $text, bool $hasImage): void
    {
        if (!$hasImage && $text === null) { throw new \InvalidArgumentException('请提供二维码文本或图片'); }
        if ($text !== null && ($text === '' || !mb_check_encoding($text, 'UTF-8')
            || strlen($text) > ($hasImage ? self::MAX_STORED_TEXT_BYTES : self::MAX_TEXT_BYTES))) {
            throw new \InvalidArgumentException('二维码文本超出当前 UTF-8 编码容量或格式无效');
        }
    }

    /** @return array{mode:string,error_correction:string,max_text_bytes:int} 随包二维码库固定配置，容量单位为字节。 */
    public static function rendering(): array
    {
        return ['mode' => self::MODE, 'error_correction' => self::ERROR_CORRECTION, 'max_text_bytes' => self::MAX_TEXT_BYTES];
    }
}
