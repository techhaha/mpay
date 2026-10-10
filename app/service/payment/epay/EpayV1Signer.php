<?php
declare(strict_types=1);

namespace app\service\payment\epay;

/** 易支付 V1 的签名边界；只实现已约定的 MD5，不按请求中的类名或算法装配其他 signer。 */
final class EpayV1Signer
{
    /**
     * 参数必须为表单解析后的原始字符串，不 trim、重编码或规范化金额。
     * 未声明的非空参数参与签名；null/空串排除，字符串 "0" 必须保留。
     *
     * @param array<string|int, string|null> $parameters 数字参数名可能被 PHP 转为 int，仍按字符串 ASCII 排序。
     * @param string $key 已按认证/订单版本读取的 KEY 明文，不得记录到日志。
     * @throws \InvalidArgumentException 非字符串参数或空 KEY，调用方不得把 JSON/数组当成等价表单。
     */
    public function sign(array $parameters, #[\SensitiveParameter] string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('易支付签名凭证为空');
        }
        ksort($parameters, SORT_STRING);
        $parts = [];
        foreach ($parameters as $name => $value) {
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException('易支付签名参数必须是原始表单字符串');
            }
            if (in_array((string) $name, ['sign', 'sign_type'], true) || $value === null || $value === '') {
                continue;
            }
            $parts[] = (string) $name . '=' . $value;
        }
        return md5(implode('&', $parts) . $key);
    }

    /**
     * @param array<string|int, string|null> $parameters 含原始 sign/sign_type 的完整参数。
     * @return bool 只证明签名匹配；业务字段、授权范围及通知目标仍须另行校验。
     */
    public function verify(array $parameters, #[\SensitiveParameter] string $key): bool
    {
        $signature = $parameters['sign'] ?? null;
        if (($parameters['sign_type'] ?? null) !== 'MD5' || !is_string($signature)
            || !preg_match('/^[a-f0-9]{32}$/D', $signature)) {
            return false;
        }
        return hash_equals($this->sign($parameters, $key), $signature);
    }
}
