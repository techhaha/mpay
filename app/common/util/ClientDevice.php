<?php
declare(strict_types=1);

namespace app\common\util;

/** 页面入口共用的 UA 展示环境识别；不读取全局请求，不代替 API 明示的 device。 */
final class ClientDevice
{
    public static function detect(string $agent): string
    {
        return match (true) {
            stripos($agent, 'AlipayClient') !== false => 'alipay',
            stripos($agent, 'MicroMessenger') !== false => 'wechat',
            stripos($agent, 'QQ/') !== false => 'qq',
            preg_match('/Mobile|Android|iPhone|iPad/i', $agent) === 1 => 'mobile',
            default => 'pc',
        };
    }
}
