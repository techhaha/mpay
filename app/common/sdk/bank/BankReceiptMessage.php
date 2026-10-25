<?php
declare(strict_types=1);

namespace app\common\sdk\bank;

use app\common\constant\ApiResponseConstant;
use app\common\sdk\wechat\WechatMessageXml;
use app\common\util\Money;
use app\exception\BusinessException;
use app\exception\ReceiptMessageException;
use DateTimeImmutable;
use DateTimeZone;
use DOMXPath;
use InvalidArgumentException;
use JsonException;

/** 银行公众号完整消息的结构、XML、金额和时间解析；不读取订单或推进支付状态。 */
final class BankReceiptMessage
{
    public function __construct(private readonly WechatMessageXml $xml) {}

    /**
     * 调用前须完成四字段表单认证；消息身份来自原始消息游标，不包含通道或本次投递时间。
     * 明确的发出消息及非 appmsg 返回 null，身份不符或结构损坏抛错。
     *
     * @return array{title:string,description:string,message_time:int,event_key:string}|null
     */
    public function parse(string $content, string $account, int $receivedAt): ?array
    {
        try {
            $payload = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ReceiptMessageException('invalid_message');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new ReceiptMessageException('invalid_message');
        }
        $message = $payload['message'] ?? null;
        if (
            ($payload['event'] ?? null) !== 'wxtool.officialAccount.message' ||
            ($payload['account']['username'] ?? null) !== $account ||
            !is_array($message) ||
            ($message['username'] ?? null) !== $account ||
            ($message['source'] ?? null) !== 'message' ||
            ($message['isFallback'] ?? false) !== false ||
            !in_array($message['direction'] ?? null, ['incoming', 'outgoing'], true) ||
            !is_int($message['localType'] ?? null) ||
            !is_int($message['createTime'] ?? null) ||
            $message['createTime'] < 1 ||
            $message['createTime'] > $receivedAt + 300
        ) {
            throw new ReceiptMessageException('invalid_message');
        }
        if ($message['direction'] === 'outgoing') {
            return null;
        }
        if (($message['senderUsername'] ?? null) !== $account) {
            throw new ReceiptMessageException('source_mismatch');
        }
        if ($message['localType'] !== 21474836529) {
            return null;
        }
        $key = $payload['delivery']['messageKey'] ?? null;
        $parts = is_string($key) ? explode(':', $key) : [];
        $sequence = $message['sortSeq'] ?? null;
        $localId = $message['localId'] ?? null;
        if (
            count($parts) !== 5 ||
            !ctype_digit($parts[0]) || (int) $parts[0] < 1 ||
            $parts[1] !== $account ||
            // 先拒绝数组等非标量，避免畸形外部消息在转换时触发 PHP 告警。
            (!is_int($sequence) && !is_string($sequence)) ||
            !ctype_digit($parts[2]) || $parts[2] !== (string) $sequence ||
            $parts[3] !== (string) $message['createTime'] ||
            (!is_int($localId) && !is_string($localId)) ||
            !ctype_digit($parts[4]) || $parts[4] !== (string) $localId ||
            !is_string($message['content'] ?? null)
        ) {
            throw new ReceiptMessageException('invalid_message');
        }
        $root = $this->xml->parse($message['content']);
        $xpath = new DOMXPath($root->ownerDocument);
        $titles = $xpath->query('/msg/appmsg/title');
        $descriptions = $xpath->query('/msg/appmsg/des');
        if (
            $titles === false || $titles->length !== 1 ||
            $descriptions === false || $descriptions->length !== 1
        ) {
            throw new ReceiptMessageException('invalid_message');
        }
        return [
            'title' => trim($titles->item(0)->textContent),
            'description' => trim($descriptions->item(0)->textContent),
            'message_time' => $message['createTime'],
            'event_key' => 'BR' . hash('sha256', $key),
        ];
    }

    /** 描述行存在但为空或重复时必须拒绝，不能作为字段缺失回退。 */
    public function optionalField(string $description, string $label): ?string
    {
        $values = [];
        foreach (preg_split('/\R/u', $description) ?: [] as $line) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*[:：]\s*(.*?)\s*$/u', $line, $match)) {
                $values[] = trim($match[1]);
            }
        }
        if ($values === []) {
            return null;
        }
        if (count($values) !== 1 || $values[0] === '') {
            throw new BusinessException('银行通知字段为空或重复：' . $label, ApiResponseConstant::VALIDATION_FAILED);
        }
        return $values[0];
    }

    public function field(string $description, string $label): string
    {
        return $this->optionalField($description, $label) ??
            throw new BusinessException('银行通知缺少字段：' . $label, ApiResponseConstant::VALIDATION_FAILED);
    }

    /** 仅接受合法分组的千位逗号及至多两位小数，交给公共金额方法转为整数分。 */
    public function amount(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?$/D', $value)) {
            throw new BusinessException('银行入账金额格式无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        try {
            $amount = Money::parse(str_replace(',', '', $value));
        } catch (InvalidArgumentException) {
            throw new BusinessException('银行入账金额超出范围', ApiResponseConstant::VALIDATION_FAILED);
        }
        if ($amount < 1) {
            throw new BusinessException('银行入账金额必须大于零', ApiResponseConstant::VALIDATION_FAILED);
        }
        return $amount;
    }

    /** 将已解析的上海时间分量转换为 Unix 秒，不使用宽松日期自动纠正。 */
    public function timestamp(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
    ): int {
        if (
            !checkdate($month, $day, $year) || $year < 1000 || $year > 9999 ||
            $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59
        ) {
            throw new BusinessException('银行交易时间无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            new DateTimeZone('Asia/Shanghai'),
        );
        if ($date === false) {
            throw new ReceiptMessageException('invalid_message');
        }
        return $date->getTimestamp();
    }
}
