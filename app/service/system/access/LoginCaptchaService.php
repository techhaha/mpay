<?php
declare(strict_types=1);

namespace app\service\system\access;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\exception\RateLimitStorageException;
use ErrorException;
use LogicException;
use think\Cache;
use think\captcha\Captcha;
use think\Config;
use think\Response;
use think\Session;

/**
 * 官方图片验证码的登录生命周期；答案摘要按 Session 隔离，由框架缓存保存和消费。
 * 正常请求校验后失效，并发消费遵循原生 File 语义，不额外实现跨请求锁。
 */
final class LoginCaptchaService
{
    public function __construct(
        private readonly Captcha $captcha,
        private readonly Session $session,
        private readonly Config $config,
        private readonly Cache $cache,
    ) {}

    /** 返回官方组件生成的 PNG 字节；HTTP 响应和缓存头留在 Controller。 */
    public function image(): string
    {
        if ($this->config->get('captcha.api') !== false) {
            throw new LogicException('登录验证码只能使用图片模式');
        }
        $key = $this->key();
        try {
            $response = $this->captcha->create();
            $hash = $this->session->get('captcha.key');
            if (!($response instanceof Response) || !is_string($hash)) {
                throw new LogicException('验证码组件未生成有效图片');
            }
            $ttl = (int) $this->config->get('captcha.expire', 120);
            if ($ttl < 1) {
                throw new LogicException('验证码有效期必须为正整数');
            }
            if (
                !$this->cache
                    ->store('access')
                    ->set($key, ['hash' => $hash, 'expires' => time() + $ttl], $ttl)
            ) {
                throw new RateLimitStorageException();
            }
            return $response->getContent();
        } catch (ErrorException $error) {
            throw new RateLimitStorageException($error);
        } finally {
            // Session 的并发旧快照不能写回已消费验证码；只在本次调用内供官方组件使用。
            $this->session->delete('captcha');
        }
    }

    /** 使用官方 pull 取出并删除挑战，再执行官方校验；不承诺并发请求的原子消费。 */
    public function verify(string $code): void
    {
        try {
            $challenge = $this->cache->store('access')->pull($this->key());
            if (
                !is_array($challenge) ||
                !is_string($challenge['hash'] ?? null) ||
                !is_int($challenge['expires'] ?? null) ||
                $challenge['expires'] <= time()
            ) {
                throw new BusinessException(
                    '验证码已失效，请刷新后重试',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
            $this->session->set('captcha', ['key' => $challenge['hash']]);
            if (!$this->captcha->check($code)) {
                throw new BusinessException(
                    '验证码不正确，请重新输入',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
        } catch (ErrorException $error) {
            throw new RateLimitStorageException($error);
        } finally {
            $this->session->delete('captcha');
        }
    }

    private function key(): string
    {
        return 'login-captcha.' . hash('sha256', $this->session->getId());
    }
}
