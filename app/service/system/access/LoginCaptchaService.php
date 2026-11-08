<?php
declare(strict_types=1);

namespace app\service\system\access;

use app\common\constant\ApiResponseConstant;
use app\common\util\SafeLogger;
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
use Throwable;

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
        private readonly SafeLogger $logger,
    ) {}

    /** 返回官方组件生成的 PNG 字节；HTTP 响应和缓存头留在 Controller。 */
    public function image(): string
    {
        if ($this->config->get('captcha.api') !== false) {
            throw new LogicException('登录验证码只能使用图片模式');
        }
        $key = $this->key();
        try {
            try {
                $response = $this->createImage();
                $hash = $this->session->get('captcha.key');
                if (!($response instanceof Response) || !is_string($hash)) {
                    throw new LogicException('验证码组件未生成有效图片');
                }
            } catch (Throwable $error) {
                // BusinessException 不由全局重复记录；图片错误在本边界保留原始异常位置。
                $this->logger->exception($error);
                throw new BusinessException(
                    '验证码图片生成失败，请联系站点管理员',
                    ApiResponseConstant::INTERNAL_ERROR,
                    [],
                    $error,
                );
            }
            $ttl = (int) $this->config->get('captcha.expire', 120);
            if ($ttl < 1) {
                throw new LogicException('验证码有效期必须为正整数');
            }
            try {
                if (
                    !$this->cache
                        ->store('access')
                        ->set($key, ['hash' => $hash, 'expires' => time() + $ttl], $ttl)
                ) {
                    throw new RateLimitStorageException();
                }
            } catch (ErrorException | FrameworkErrorException $error) {
                throw new RateLimitStorageException($error);
            }
            return $response->getContent();
        } finally {
            // Session 的并发旧快照不能写回已消费验证码；只在本次调用内供官方组件使用。
            $this->session->delete('captcha');
        }
    }

    /**
     * 继续使用官方绘图和校验算法；仅接管 PHP 8.5 对 imagedestroy() 的弃用提示。
     * 该函数在项目最低 PHP 8.2 中已无实际作用；其他错误交还原处理器，调用后必定恢复。
     * 上游移除此调用后，可删除此局部包装并恢复直接调用 create()，不修改 vendor。
     */
    private function createImage(): Response|array
    {
        $previous = null;
        $previous = set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line,
        ) use (&$previous): bool {
            if (
                $severity === E_DEPRECATED &&
                str_starts_with($message, 'Function imagedestroy() is deprecated')
            ) {
                return true;
            }
            return $previous === null
                ? false
                : $previous($severity, $message, $file, $line) !== false;
        });
        try {
            return $this->captcha->create();
        } finally {
            restore_error_handler();
        }
    }

    /** 原生缓存读取后删除挑战，再执行官方校验；只重试读取，不重复消费或校验。 */
    public function verify(string $code): void
    {
        try {
            $key = $this->key();
            try {
                $store = $this->cache->store('access');
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
            } catch (ErrorException | FrameworkErrorException $error) {
                throw new RateLimitStorageException($error);
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
        } finally {
            $this->session->delete('captcha');
        }
    }

    private function key(): string
    {
        return 'login-captcha.' . hash('sha256', $this->session->getId());
    }
}
