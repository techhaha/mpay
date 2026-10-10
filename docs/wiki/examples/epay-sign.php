<?php
declare(strict_types=1);

namespace Mpay\Docs\Example;

/**
 * 易支付 V1 文档示例，只处理签名字节；调用者仍须核对 PID、订单金额与业务幂等。
 * 不放入 public 作为通知处理入口，也不在这里保存任何实际 KEY。
 */
final class EpaySignature
{
    /** @param array<string, string|null> $parameters 原始表单值，不预先编码或格式化金额。 */
    public static function sign(array $parameters, #[\SensitiveParameter] string $key): string
    {
        if ($key === '') { throw new \InvalidArgumentException('签名 KEY 不能为空'); }
        $parts = [];
        ksort($parameters, SORT_STRING);
        foreach ($parameters as $name => $value) {
            if ($value !== null && !is_string($value)) { throw new \InvalidArgumentException('签名参数必须为字符串'); }
            if ($name === 'sign' || $name === 'sign_type' || $value === null || $value === '') { continue; }
            $parts[] = $name . '=' . $value;
        }
        return md5(implode('&', $parts) . $key);
    }

    /** @param array<string, string|null> $parameters 完整通知参数；验签通过不等于业务订单处理完成。 */
    public static function verify(array $parameters, #[\SensitiveParameter] string $key): bool
    {
        $signature = $parameters['sign'] ?? '';
        return is_string($signature) && ($parameters['sign_type'] ?? '') === 'MD5'
            && preg_match('/^[a-f0-9]{32}$/D', $signature) === 1
            && hash_equals(self::sign($parameters, $key), $signature);
    }
}
