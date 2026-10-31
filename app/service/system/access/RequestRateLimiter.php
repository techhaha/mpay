<?php
declare(strict_types=1);

namespace app\service\system\access;

use app\common\util\SafeLogger;
use app\exception\RateLimitException;
use app\exception\RateLimitStorageException;
use ErrorException;
use LogicException;
use think\Cache;
use think\Config;
use think\exception\ErrorException as FrameworkErrorException;
use think\middleware\throttle\CounterSlider;
use think\middleware\throttle\RateParser;

/** 入口与已认证业务身份共用官方滑动窗口；不访问数据库，不推断客户端提供的 PID/订单归属。 */
final class RequestRateLimiter
{
    public const MAX_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly SafeLogger $logger,
    ) {}

    /**
     * 身份必须为连接 IP、已认证管理员/应用 ID 或已验证订单；不传入密码、KEY、Cookie 或正文。
     * 超限只抛领域异常，HTTP 状态及不同入口的响应体由统一异常边界决定。
     */
    public function check(string $rule, string $identity): void
    {
        $rate = $this->config->get('throttle.rules.' . $rule);
        if (!is_string($rate) || $identity === '') {
            throw new LogicException('限流规则或可信身份缺失');
        }
        [$maximum, $duration] = RateParser::parse($rate);
        if (
            $maximum < 1 ||
            $maximum > 1000 ||
            $duration < 1 ||
            $duration > self::MAX_WINDOW_SECONDS
        ) {
            throw new LogicException('限流频率超出允许范围');
        }
        $key = 'request.' . $rule . '.' . hash('sha256', $identity);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $driver = new CounterSlider();
                // 只重试入口计数一次，处理文件在读取期间过期/删除的短暂冲突，不重执行业务。
                $wait = $driver->allowRequest(
                    $key,
                    microtime(true),
                    $maximum,
                    $duration,
                    $this->cache->store('access'),
                )
                    ? 0
                    : max(1, $driver->getWaitSeconds());
                break;
            } catch (ErrorException | FrameworkErrorException $error) {
                if ($attempt === 1) {
                    throw new RateLimitStorageException($error);
                }
            }
        }
        if ($wait > 0) {
            try {
                $this->recordRejection($rule);
            } catch (ErrorException | FrameworkErrorException | RateLimitStorageException) {
                // 计数已明确拒绝，辅助日志缓存失败不能把 429 改成其他响应或放行请求。
            }
            throw new RateLimitException($wait);
        }
    }

    /** 按规则约每分钟输出阶段汇总；并发时允许少量偏差，不保存请求内容。 */
    private function recordRejection(string $rule): void
    {
        $key = 'summary.' . $rule;
        $store = $this->cache->store('access');
        $now = time();
        $summary = $store->get($key, ['next' => 0, 'count' => 0]);
        $summary['count']++;
        $count = $now >= $summary['next'] ? $summary['count'] : 0;
        if ($count > 0) {
            $summary = ['next' => $now + 60, 'count' => 0];
        }
        if (!$store->set($key, $summary, 120)) {
            throw new RateLimitStorageException();
        }
        if ($count > 0) {
            $this->logger->record(
                'request.rate_limited',
                [
                    'rate_rule' => $rule,
                    'rejected_count' => $count,
                    'http_status' => 429,
                ],
                'warning',
            );
        }
    }
}
