<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use GuzzleHttp\Psr7\Query;
use InvalidArgumentException;

/** Guzzle 表单解析的协议约束：保留待签名字段名，拒绝重复键、数组及不符合边界的输入。 */
final class UrlEncodedForm
{
    /**
     * Guzzle 按 RFC1738 解码加号和百分号；不 trim 值或改写字段名。
     * 明确拒绝数组、重复键和畸形转义；数字参数名可由 PHP 转为 int，签名仍按字符串名称排序。
     *
     * @return array<string|int,string>
     * @throws BusinessException 输入非 UTF-8、结构歧义或超过单次请求边界。
     */
    public static function parse(string $raw, int $maxFields = 128): array
    {
        if ($maxFields < 1 || $maxFields > 256) {
            throw new InvalidArgumentException('表单字段上限配置无效');
        }
        if (strlen($raw) > 65536) {
            throw new BusinessException('表单数据超过允许大小', ApiResponseConstant::BAD_REQUEST);
        }
        if ($raw === '') {
            return [];
        }
        $pairs = explode('&', $raw);
        if (count($pairs) > $maxFields) {
            throw new BusinessException('表单字段数量超过限制', ApiResponseConstant::BAD_REQUEST);
        }
        if (preg_match('/%(?![a-fA-F0-9]{2})/', $raw)) {
            throw new BusinessException('表单百分号编码无效', ApiResponseConstant::BAD_REQUEST);
        }
        // 空分隔项沿用忽略规则，但仍计入上面的数量限制；"0" 不当作空项删除。
        $parameters = Query::parse(
            implode('&', array_filter($pairs, 'strlen')),
            PHP_QUERY_RFC1738,
        );
        $result = [];
        foreach ($parameters as $name => $value) {
            $key = (string) $name;
            // Guzzle 将重复字段保留为数组，统一拒绝；无等号的字段继续按空字符串参与协议处理。
            $value ??= '';
            if (
                !is_string($value) ||
                $key === '' ||
                strlen($key) > 128 ||
                preg_match('/[\[\]\x00-\x1f\x7f]/', $key) ||
                !mb_check_encoding([$key, $value], 'UTF-8')
            ) {
                throw new BusinessException(
                    '表单必须使用不重复的 UTF-8 标量字段',
                    ApiResponseConstant::BAD_REQUEST,
                );
            }
            $result[$name] = $value;
        }
        return $result;
    }

    /**
     * 商户通知/回跳的 query 按签名规则单次解析，并拒绝可能被 PHP 改名的点号/空白字段。
     * 返回的原始标量直接参与签名；平台 SDK 的 query 不使用此规则。
     *
     * @return array<string|int,string>
     */
    public static function parseQuery(string $raw, int $maxFields = 128): array
    {
        $parameters = self::parse($raw, $maxFields);
        foreach (array_keys($parameters) as $name) {
            if (preg_match('/[.\s]/', (string) $name)) {
                throw new BusinessException(
                    '目标 query 字段名存在签名歧义',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
        }
        return $parameters;
    }

    /**
     * GET query 和 POST 正文合并时不允许同名字段，避免签名验证与业务取值使用不同优先级。
     *
     * @param array<string|int,string> $query
     * @param array<string|int,string> $body
     * @return array<string|int,string>
     */
    public static function merge(array $query, array $body): array
    {
        if (array_intersect_key($query, $body) !== [] || count($query) + count($body) > 128) {
            throw new BusinessException(
                '查询与正文包含重复字段或字段过多',
                ApiResponseConstant::BAD_REQUEST,
            );
        }
        return $query + $body;
    }
}
