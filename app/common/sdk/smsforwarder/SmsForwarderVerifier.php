<?php
declare(strict_types=1);

namespace app\common\sdk\smsforwarder;

use app\common\util\UrlEncodedForm;
use app\exception\BusinessException;
use app\exception\ReceiptMessageException;
use DateTimeImmutable;
use InvalidArgumentException;
use think\Request;

/**
 * 微信/支付宝安卓入口共享的 SmsForwarder 兼容传输校验，不负责平台文本解析或订单匹配。
 * 兼容 HMAC 只绑定 timestamp 和 secret；不生成强事件身份，不报告设备在线或收款成功。
 */
final class SmsForwarderVerifier
{
    /**
     * 使用服务器时间、服务器允许来源和账号专属 secret 校验原始 POST 表单。
     * 调用者还须落实接收限流及平台模板校验；只读取传入 Request，不访问全局请求、Session、数据库或系统时间。
     *
     * @param list<string> $allowedSources 服务器配置的精确来源列表，不能来自请求或泛化关键字匹配。
     * @param DateTimeImmutable $receivedAt 服务器接收时间；用毫秒比较边界，不先截成秒。
     * @param bool $secureTransport 由 HTTP 入口依据真实 TLS/受信反代配置确认，不能读取任意转发头作 true。
     * @param int $toleranceSeconds 由插件配置明确传入的双向时间容差；SDK 保留 1～3600 秒输入边界，不另设业务默认值。
     * @return SmsForwarderMessage 传输校验成功，正文仍未被签名认证。
     * @throws ReceiptMessageException 非合同请求、来源不符、签名或时效失败；异常无原始敏感值。
     * @throws InvalidArgumentException 服务器配置/运行时不符合合同，不应当计作客户端认证失败。
     */
    public function verify(
        Request $message,
        string $secret,
        array $allowedSources,
        DateTimeImmutable $receivedAt,
        bool $secureTransport,
        int $toleranceSeconds
    ): SmsForwarderMessage {
        $this->assertConfiguration($secret, $allowedSources, $toleranceSeconds);
        if (!$secureTransport) {
            throw new ReceiptMessageException('transport_required');
        }
        // 不与 query 合并，防止同字段的两种来源或非合同 JSON 被当作已验证表单。
        if ($message->method(true) !== 'POST' || (string) $message->server('QUERY_STRING', '') !== ''
            || !preg_match('/^application\/x-www-form-urlencoded(?:\s*;\s*charset\s*=\s*(?:utf-?8|"utf-?8"))?\s*$/iD',
                trim($message->header('content-type', '')))) {
            throw new ReceiptMessageException('invalid_message');
        }
        try {
            $form = UrlEncodedForm::parse($message->getInput(), 4);
        } catch (BusinessException) {
            throw new ReceiptMessageException('invalid_message');
        }
        $fields = ['from', 'content', 'timestamp', 'sign'];
        if (count($form) !== 4 || array_diff($fields, array_keys($form)) !== []
            || $form['from'] === '' || strlen($form['from']) > 256 || preg_match('/[\x00-\x1f\x7f]/', $form['from'])
            || $form['content'] === '' || strlen($form['content']) > 32768 || str_contains($form['content'], "\0")
            || !preg_match('/^[1-9][0-9]{12}$/D', $form['timestamp'])) {
            throw new ReceiptMessageException('invalid_message');
        }
        $timestamp = (int) $form['timestamp'];
        $receivedMilliseconds = (int) $receivedAt->format('Uv');
        if (abs($receivedMilliseconds - $timestamp) > $toleranceSeconds * 1000) {
            throw new ReceiptMessageException('transport_expired');
        }
        $signature = $this->canonicalSignature($form['sign']);
        $expected = base64_encode(hash_hmac('sha256', $form['timestamp'] . "\n" . $secret, $secret, true));
        if (!hash_equals($expected, $signature)) {
            throw new ReceiptMessageException('authentication_failed');
        }
        if (!in_array($form['from'], $allowedSources, true)) {
            throw new ReceiptMessageException('source_mismatch');
        }
        // 不使用 transport timestamp/hash(content) 伪造强身份，也不在这里创建 ReceiptEvent。
        return new SmsForwarderMessage($form['from'], $form['content'], $timestamp);
    }

    /**
     * 表单解析已解码一层；只兼容 Base64 或“先 URL 编码签名、再表单编码”这两种表示。
     * 对后一种恰好再解码一次；不循环解码，不把丢失的 '+' 所变成的空格猜回签名。
     *
     * @throws ReceiptMessageException 非规范 Base64、混合/多重编码或错误长度。
     */
    private function canonicalSignature(string $value): string
    {
        if (str_contains($value, '%')) {
            $decoded = rawurldecode($value);
            $normalizedEscapes = preg_replace_callback('/%[a-fA-F0-9]{2}/', static fn (array $match): string => strtoupper($match[0]), $value);
            if (rawurlencode($decoded) !== $normalizedEscapes) {
                throw new ReceiptMessageException('authentication_failed');
            }
            $value = $decoded;
        }
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/D', $value)) {
            throw new ReceiptMessageException('authentication_failed');
        }
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) !== 32 || base64_encode($bytes) !== $value) {
            throw new ReceiptMessageException('authentication_failed');
        }
        return $value;
    }

    /** @param list<string> $allowedSources 配置来自服务器配置 schema，不接受请求新增允许来源。 */
    private function assertConfiguration(string $secret, array $allowedSources, int $toleranceSeconds): void
    {
        if (PHP_INT_SIZE < 8 || $secret === '' || strlen($secret) > 1024 || !mb_check_encoding($secret, 'UTF-8')
            || $toleranceSeconds < 1 || $toleranceSeconds > 3600 || !array_is_list($allowedSources)
            || $allowedSources === [] || count($allowedSources) > 16) {
            throw new InvalidArgumentException('SmsForwarder 传输校验配置无效');
        }
        foreach ($allowedSources as $source) {
            if (!is_string($source) || $source === '' || strlen($source) > 256
                || !mb_check_encoding($source, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $source)) {
                throw new InvalidArgumentException('SmsForwarder 允许来源配置无效');
            }
        }
    }
}
