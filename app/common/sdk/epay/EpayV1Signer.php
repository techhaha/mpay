<?php
declare(strict_types=1);

namespace app\common\sdk\epay;

use InvalidArgumentException;

/** 第三方易支付 V1 插件的 MD5 签名；独立于本站对外易支付协议，不依赖其服务层。 */
final class EpayV1Signer
{
    /**
     * 使用已解码的原始表单值；空串、null、sign、sign_type 不参与，字符串 "0" 保留。
     *
     * @param array<string|int,string|null> $parameters
     */
    public function sign(array $parameters, string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('第三方商户密钥不能为空');
        }
        ksort($parameters, SORT_STRING);
        $parts = [];
        foreach ($parameters as $name => $value) {
            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException('第三方签名参数必须是原始表单字符串');
            }
            if (in_array((string) $name, ['sign', 'sign_type'], true) || $value === null || $value === '') {
                continue;
            }
            $parts[] = $name . '=' . $value;
        }
        return md5(implode('&', $parts) . $key);
    }

    /** @param array<string|int,string|null> $parameters 须明确提供 sign_type=MD5；业务字段及订单归属另行核对。 */
    public function verify(array $parameters, string $key): bool
    {
        $signature = $parameters['sign'] ?? null;
        return ($parameters['sign_type'] ?? null) === 'MD5'
            && is_string($signature)
            && preg_match('/^[a-f0-9]{32}$/D', $signature) === 1
            && hash_equals($this->sign($parameters, $key), $signature);
    }
}
