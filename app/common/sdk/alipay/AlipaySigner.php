<?php
declare(strict_types=1);

namespace app\common\sdk\alipay;

use app\exception\ReceiptCollectionException;

/** 支付宝账单网关的 RSA2；只处理内存中的密钥文本与签名字节，不读取证书或密钥路径。 */
final class AlipaySigner
{
    /** @param array<string,string> $parameters 网关原始参数，签名前不做 URL 编码，sign_type 参与请求签名。 */
    public function sign(array $parameters, string $privateKey): string
    {
        unset($parameters['sign']);
        ksort($parameters, SORT_STRING);
        $pairs = [];
        foreach ($parameters as $name => $value) {
            if ($value !== '') {
                $pairs[] = $name . '=' . $value;
            }
        }
        if (
            !openssl_sign(
                implode('&', $pairs),
                $signature,
                $this->key($privateKey, true),
                OPENSSL_ALGO_SHA256,
            )
        ) {
            throw new \RuntimeException('支付宝请求签名失败');
        }
        return base64_encode($signature);
    }

    /**
     * 响应必须对原始对象字节验签，禁止 decode 后重新 JSON 编码再验签。
     * @return string 已验签的业务响应对象 JSON；调用方再解释 code、账务及分页。
     */
    public function response(string $body, string $publicKey): string
    {
        try {
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if (!($decoded instanceof \stdClass) || !is_string($decoded->sign ?? null)) {
                throw new \UnexpectedValueException();
            }
            $nodes = $this->topLevelValues($body);
            $name = 'alipay_data_bill_accountlog_query_response';
            if (isset($nodes[$name]) && isset($nodes['error_response'])) {
                throw new \UnexpectedValueException();
            }
            $content = $nodes[$name] ?? ($nodes['error_response'] ?? null);
            $signature = base64_decode($decoded->sign, true);
            if (
                !is_string($content) ||
                !str_starts_with($content, '{') ||
                $signature === false ||
                openssl_verify(
                    $content,
                    $signature,
                    $this->key($publicKey, false),
                    OPENSSL_ALGO_SHA256,
                ) !== 1
            ) {
                throw new \UnexpectedValueException();
            }
            return $content;
        } catch (\JsonException | \UnexpectedValueException) {
            throw new ReceiptCollectionException('protocol_error');
        }
    }

    /** PEM 或 Base64 的 RSA 私钥/公钥；只接受可加载的 2048～4096 位密钥，错误不回显内容。 */
    private function key(string $value, bool $private): \OpenSSLAsymmetricKey
    {
        if ($value === '' || strlen($value) > 16384 || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('支付宝 RSA 密钥格式无效');
        }
        $candidates = [];
        if (str_starts_with(trim($value), '-----BEGIN ')) {
            $pattern = $private
                ? '/\A-----BEGIN ((?:RSA )?PRIVATE KEY)-----\s+[A-Za-z0-9+\/=\s]+-----END \1-----\z/D'
                : '/\A-----BEGIN PUBLIC KEY-----\s+[A-Za-z0-9+\/=\s]+-----END PUBLIC KEY-----\z/D';
            if (preg_match($pattern, trim($value))) {
                $candidates[] = trim($value);
            }
        } else {
            $body = preg_replace('/\s+/', '', $value);
            if (
                is_string($body) &&
                preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $body) &&
                base64_decode($body, true) !== false
            ) {
                foreach ($private ? ['PRIVATE KEY', 'RSA PRIVATE KEY'] : ['PUBLIC KEY'] as $type) {
                    $candidates[] =
                        '-----BEGIN ' .
                        $type .
                        "-----\n" .
                        chunk_split($body, 64, "\n") .
                        '-----END ' .
                        $type .
                        '-----';
                }
            }
        }
        foreach ($candidates as $candidate) {
            $key = $private
                ? @openssl_pkey_get_private($candidate, '')
                : @openssl_pkey_get_public($candidate);
            $details = $key === false ? false : openssl_pkey_get_details($key);
            if (
                $details !== false &&
                $details['type'] === OPENSSL_KEYTYPE_RSA &&
                $details['bits'] >= 2048 &&
                $details['bits'] <= 4096
            ) {
                return $key;
            }
        }
        throw new \InvalidArgumentException('支付宝 RSA 密钥无法加载或位数无效');
    }

    /**
     * 在 JSON 已验证合法后定位顶层值的原文跨度，保留对象内的空白、转义和字段顺序。
     * 拒绝重复顶层键，避免验签对象和后续解释对象来自不同的同名节点。
     * @return array<string,string>
     */
    private function topLevelValues(string $body): array
    {
        $length = strlen($body);
        $offset = 0;
        $values = [];
        while ($offset < $length && ctype_space($body[$offset])) {
            $offset++;
        }
        if (($body[$offset++] ?? '') !== '{') {
            throw new \UnexpectedValueException();
        }
        while ($offset < $length) {
            while ($offset < $length && ctype_space($body[$offset])) {
                $offset++;
            }
            if (($body[$offset] ?? '') === '}') {
                return $values;
            }
            if (($body[$offset] ?? '') !== '"') {
                throw new \UnexpectedValueException();
            }
            $start = $offset++;
            $escaped = false;
            while ($offset < $length) {
                $character = $body[$offset++];
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($character === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($character === '"') {
                    break;
                }
            }
            $key = json_decode(
                substr($body, $start, $offset - $start),
                true,
                4,
                JSON_THROW_ON_ERROR,
            );
            if (array_key_exists($key, $values)) {
                throw new \UnexpectedValueException();
            }
            while ($offset < $length && ctype_space($body[$offset])) {
                $offset++;
            }
            if (($body[$offset++] ?? '') !== ':') {
                throw new \UnexpectedValueException();
            }
            while ($offset < $length && ctype_space($body[$offset])) {
                $offset++;
            }
            $start = $offset;
            $depth = 0;
            $quoted = false;
            $escaped = false;
            while ($offset < $length) {
                $character = $body[$offset];
                if ($quoted) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $quoted = false;
                    }
                } elseif ($character === '"') {
                    $quoted = true;
                } elseif ($character === '{' || $character === '[') {
                    $depth++;
                } elseif ($character === '}' || $character === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($character === ',' && $depth === 0) {
                    break;
                }
                $offset++;
            }
            $values[$key] = trim(substr($body, $start, $offset - $start));
            if (($body[$offset] ?? '') === ',') {
                $offset++;
                continue;
            }
            if (($body[$offset] ?? '') === '}') {
                return $values;
            }
            throw new \UnexpectedValueException();
        }
        throw new \UnexpectedValueException();
    }
}
