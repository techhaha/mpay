<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use Psr\Http\Message\UriInterface;

/**
 * 平台 SDK 的精确来源站点集合，由平台代码合同固定，不能从商户回调允许列表或账号表单加载。
 * 正式策略只接受 HTTPS；测试例外只能由显式隔离 CLI 构造，不提供 Web 可控的降级开关。
 */
final readonly class PlatformTargetPolicy
{
    /** @var list<string> 规范 scheme/host/port，不含账号、路径或查询参数。 */
    private array $origins;

    /** @param list<string> $origins 代码声明的完整 origin，不能带路径（根路径除外）、query 或 fragment。 */
    private function __construct(array $origins, private bool $isolated)
    {
        if (!array_is_list($origins) || $origins === [] || count($origins) > 16) {
            throw new \InvalidArgumentException('平台来源站点集合无效');
        }
        $normalized = [];
        foreach ($origins as $origin) {
            if (!is_string($origin)) {
                throw new \InvalidArgumentException('平台来源站点必须为字符串');
            }
            $target = HttpTargetPolicy::uri($origin, 2048, false);
            if (
                (!$isolated && $target->getScheme() !== 'https') ||
                $target->getQuery() !== '' ||
                $target->getFragment() !== '' ||
                !in_array($target->getPath(), ['', '/'], true)
            ) {
                throw new \InvalidArgumentException('平台来源必须为明确的 HTTPS origin');
            }
            $normalized[] = self::origin($target);
        }
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new \InvalidArgumentException('平台来源不能重复');
        }
        $this->origins = $normalized;
    }

    /** @param list<string> $origins 平台 SDK 自身的常量列表；认证材料不参与目标授权。 */
    public static function production(array $origins): self
    {
        return new self($origins, false);
    }

    /** @param list<string> $origins 明确的隔离服务 origin；不能用于真实来源可用性验证。 */
    public static function isolatedTest(array $origins): self
    {
        if (PHP_SAPI !== 'cli' || !defined('MPAY_ISOLATED_TEST') || MPAY_ISOLATED_TEST !== true) {
            throw new \LogicException('平台内网传输策略只允许显式隔离 CLI 测试');
        }
        return new self($origins, true);
    }

    /**
     * 连接前只匹配精确 scheme/host/port；禁止 fragment，避免签名 URL 含实际不会传输的尾部。
     *
     * @return bool 是否允许隔离测试地址；随后仍必须执行 DNS/网段检查，不能直接跳过绑定。
     */
    public function validate(UriInterface $target): bool
    {
        if (
            $target->getFragment() !== '' ||
            !in_array(self::origin($target), $this->origins, true)
        ) {
            throw new BusinessException(
                '请求目标不属于此平台的受控来源',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return $this->isolated;
    }

    private static function origin(UriInterface $target): string
    {
        return $target->getScheme() .
            '://' .
            $target->getHost() .
            ':' .
            ($target->getPort() ?? ($target->getScheme() === 'https' ? 443 : 80));
    }
}
