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
use think\exception\ErrorException as FrameworkErrorException;
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
        } catch (ErrorException | FrameworkErrorException $error) {
            throw new RateLimitStorageException($error);
        } finally {
            // Session 的并发旧快照不能写回已消费验证码；只在本次调用内供官方组件使用。
            $this->session->delete('captcha');
        }
    }

    /** 原生缓存读取后删除挑战，再执行官方校验；只重试读取，不重复消费或校验。 */
    public function verify(string $code): void
    {
        try {
            $store = $this->cache->store('access');
            $key = $this->key();
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $challenge = $store->get($key);
                    break;
                } catch (ErrorException | FrameworkErrorException $error) {
                    if ($attempt === 1) {
                        throw new RateLimitStorageException($error);
                    }
                }
            }
            // 删除失败不能继续登录，也不能重试整个验证过程而再次消费验证码。
            if ($challenge !== null && !$store->delete($key)) {
                throw new RateLimitStorageException();
            }
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
        } catch (ErrorException | FrameworkErrorException $error) {
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
