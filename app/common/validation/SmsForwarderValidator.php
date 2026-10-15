<?php
declare(strict_types=1);

namespace app\common\validation;

use app\exception\ReceiptMessageException;
use DateTimeImmutable;
use think\Validate;

/** 手机/PC 转发通知共用的字段与签名校验；平台消息解释和订单匹配由插件完成。 */
final class SmsForwarderValidator extends Validate
{
    protected $rule = [
        'from' => ['require', 'string', 'max' => 256, 'regex' => '/^[^\x00-\x1f\x7f]+$/uD'],
        'content' => ['require', 'string', 'utf8Text', 'byteLength' => 32768],
        'timestamp' => ['require', 'string', 'regex' => '/^[1-9][0-9]{12}$/D'],
        'sign' => ['require', 'string', 'max' => 132],
    ];

    /**
     * 只读取框架解析的 POST 字段，不接收路由/query 合并参数，不依赖服务器重写或传输协议。
     * 兼容签名仅认证 timestamp；不能把校验通过解释为 from/content 正文或到账真实性已认证。
     *
     * @param array<string,mixed> $input 框架 POST 参数，额外字段不进入处理结果。
     * @param string $secret 已通过插件配置规则检查的通知密钥。
     * @param list<string> $allowedSources 插件声明的来源列表。
     * @param DateTimeImmutable $receivedAt 当前接收时间，毫秒精度。
     * @param int $toleranceSeconds 插件配置的双向时间容差，不另设默认值。
     * @return array{from:string,content:string,timestamp:int} 原始通知文本与毫秒时间戳，尚未解析收款事实。
     * @throws ReceiptMessageException 字段、时效、签名或来源不符；不携带秘密值。
     */
    public function validateInput(
        array $input,
        string $secret,
        array $allowedSources,
        DateTimeImmutable $receivedAt,
        int $toleranceSeconds
    ): array {
        if (!(clone $this)->failException(false)->check($input)) {
            throw new ReceiptMessageException('invalid_message');
        }
        $timestamp = (int) $input['timestamp'];
        if (abs((int) $receivedAt->format('Uv') - $timestamp) > $toleranceSeconds * 1000) {
            throw new ReceiptMessageException('transport_expired');
        }
        // POST 已解码一次；兼容发送端预先 URL 编码签名的形式，仅再解码一次。
        $signature = rawurldecode($input['sign']);
        $expected = base64_encode(hash_hmac('sha256', $input['timestamp'] . "\n" . $secret, $secret, true));
        if (!hash_equals($expected, $signature)) {
            throw new ReceiptMessageException('authentication_failed');
        }
        if (!(clone $this)->only(['from'])->append('from', ['in' => $allowedSources])->failException(false)->check($input)) {
            throw new ReceiptMessageException('source_mismatch');
        }
        return ['from' => $input['from'], 'content' => $input['content'], 'timestamp' => $timestamp];
    }

    /** 原生 max 按字符计数；通知正文需要字节上限。 */
    protected function byteLength(string $value, int $maximum): bool { return strlen($value) <= $maximum; }

    protected function utf8Text(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0");
    }
}
