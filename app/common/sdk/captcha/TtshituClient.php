<?php

declare(strict_types=1);

namespace app\common\sdk\captcha;

use app\common\util\HttpClient;
use app\exception\ReceiptCollectionException;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Request;
use JsonException;

/** 图鉴单次识别；使用官方支持的 HTTPS，不自动重试，不处理平台登录。 */
final class TtshituClient
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** 仅在需要验证码登录时、获取验证码前调用；空配置不发起网络请求。 */
    public function assertConfigured(array $settings): void
    {
        if (trim($settings['ttshitu_username'] ?? '') === '' || ($settings['ttshitu_password'] ?? '') === '') {
            throw new ReceiptCollectionException('configuration_conflict', 600, [
                'source' => 'plugin', 'stage' => '验证码识别',
                'message' => '请在系统设置的验证码识别中配置图鉴账号和密码',
            ]);
        }
    }

    /**
     * @param array{ttshitu_username:string,ttshitu_password:string} $settings
     * @return string 识别文字或坐标，由平台 SDK 解释；一次调用仅发送一次识别请求。
     */
    public function recognize(
        string $image,
        int $type,
        array $settings,
        DateTimeImmutable $deadline,
        ?string $background = null,
    ): string {
        $this->assertConfigured($settings);
        $payload = ['username' => $settings['ttshitu_username'], 'password' => $settings['ttshitu_password'],
            'typeid' => (string) $type, 'image' => base64_encode($image)];
        if ($background !== null) {
            $payload['imageback'] = base64_encode($background);
        }
        $response = $this->http->send(new Request(
            'POST',
            'https://api.ttshitu.com/predict',
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        ), $deadline);
        try {
            $data = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ReceiptCollectionException('protocol_error', 60, [
                'source' => 'plugin', 'stage' => '图鉴识别', 'message' => '图鉴响应不是有效 JSON',
            ]);
        } finally {
            $response->getBody()->close();
        }
        if ($response->getStatusCode() !== 200 || ($data['success'] ?? null) !== true
            || !is_string($data['data']['result'] ?? null) || trim($data['data']['result']) === '') {
            $raw = $data['message'] ?? $data['msg'] ?? '图鉴未返回有效识别结果';
            $message = is_scalar($raw) ? (string) $raw : '图鉴未返回有效识别结果';
            $message = str_replace([$settings['ttshitu_username'], $settings['ttshitu_password']], '[已隐藏]', $message);
            throw new ReceiptCollectionException('protocol_error', 60, [
                'source' => 'platform', 'stage' => '图鉴识别', 'message' => $message,
                'platform_code' => isset($data['code']) && is_scalar($data['code']) ? (string) $data['code'] : null,
                'http_status' => $response->getStatusCode(),
            ]);
        }
        return trim($data['data']['result']);
    }
}
