<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;

/** 表单及 URL query 的无歧义解析，不让框架过滤、重复键覆盖或数组展开改变待签名参数。 */
final class UrlEncodedForm
{
    /**
     * 按 application/x-www-form-urlencoded 解释加号和百分号，不 trim 值或转换数字。
     * 明确拒绝数组、重复键和畸形转义；数字参数名可由 PHP 转为 int，签名仍按字符串名称排序。
     *
     * @return array<string|int,string>
     * @throws BusinessException 输入非 UTF-8、结构歧义或超过单次请求边界。
     */
    public static function parse(string $raw, int $maxFields = 128): array
    {
        if ($maxFields < 1 || $maxFields > 256) {
            throw new \InvalidArgumentException('表单字段上限配置无效');
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
        $result = [];
        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            if (preg_match('/%(?![a-fA-F0-9]{2})/', $pair)) {
                throw new BusinessException('表单百分号编码无效', ApiResponseConstant::BAD_REQUEST);
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $value = urldecode($value);
            if ($key === '' || strlen($key) > 128 || preg_match('/[\[\]\x00-\x1f\x7f]/', $key)
                || !mb_check_encoding([$key, $value], 'UTF-8') || array_key_exists($key, $result)) {
                throw new BusinessException('表单必须使用不重复的 UTF-8 标量字段', ApiResponseConstant::BAD_REQUEST);
            }
            $result[$key] = $value;
        }
        return $result;
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
            throw new BusinessException('查询与正文包含重复字段或字段过多', ApiResponseConstant::BAD_REQUEST);
        }
        return $query + $body;
    }
}
