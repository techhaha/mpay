<?php

declare(strict_types=1);

namespace app\common\sdk\crypto;

use app\exception\ReceiptCollectionException;
use Lpilp\Splsm2\smecc\SPLSM2\SimpleSm2;
use Lpilp\Splsm2\smecc\SPLSM2\SimpleSm4;

/** 国密协议适配；使用 V1 基础版附带的固定社区源码，OpenSSL 优先，仅 SM2 需要 GMP。 */
final class GuomiCrypto
{
    /** C1C3C2 十六进制，不带 04 前缀，与 V2 平台报文一致。 */
    public static function sm2(string $text, string $publicKey): string
    {
        if (!extension_loaded('gmp')) {
            throw new ReceiptCollectionException('configuration_conflict', 600, [
                'stage' => '登录加密', 'message' => '当前平台登录需要启用 PHP GMP 扩展',
            ]);
        }
        self::load();
        // 上游默认 nonce 来源包含时钟；仅替换随机源，曲线、KDF 和编码继续复用原实现。
        $engine = new class () extends SimpleSm2 {
            protected function _get_forign_prikey($document = '')
            {
                do {
                    $number = gmp_init(bin2hex(random_bytes(32)), 16);
                } while (gmp_cmp($number, 0) <= 0 || gmp_cmp($number, $this->n) >= 0);
                return $number;
            }
        };
        return $engine->encrypt($publicKey, $text);
    }

    public static function sm4Encrypt(string $text, string $key): string
    {
        if (in_array('sm4-ecb', openssl_get_cipher_methods(), true)) {
            $result = openssl_encrypt($text, 'sm4-ecb', $key, OPENSSL_RAW_DATA);
            if ($result !== false) {
                return base64_encode($result);
            }
        } else {
            self::load();
            return (new SimpleSm4($key))->encrypt($text, 'sm4-ecb', '', 'base64');
        }
        throw new ReceiptCollectionException('protocol_error', 60, [
            'stage' => '登录加密', 'message' => 'SM4 加密失败',
        ]);
    }

    public static function sm4Decrypt(string $hex, string $key): string
    {
        if (!ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
            throw new ReceiptCollectionException('protocol_error', 60, [
                'stage' => '响应解密', 'message' => 'SM4 响应不是有效十六进制',
            ]);
        }
        if (in_array('sm4-ecb', openssl_get_cipher_methods(), true)) {
            $result = openssl_decrypt(hex2bin($hex), 'sm4-ecb', $key, OPENSSL_RAW_DATA);
            if ($result !== false) {
                return $result;
            }
        } else {
            self::load();
            return (new SimpleSm4($key))->decrypt($hex, 'sm4-ecb', '', 'hex');
        }
        throw new ReceiptCollectionException('protocol_error', 60, [
            'stage' => '响应解密', 'message' => 'SM4 解密失败',
        ]);
    }

    /** 基础版附带固定 SDK 文件，不注册全局加载器或要求客户执行国密 Composer 安装。 */
    private static function load(): void
    {
        foreach (['Sm3', 'Sm2Ecc', 'Sm2Point', 'Sm2Asn1', 'SimpleSm2', 'Sm4', 'SimpleSm4'] as $class) {
            require_once __DIR__ . '/third_party/splsm2/' . $class . '.php';
        }
    }
}
