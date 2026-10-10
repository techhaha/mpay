<?php
declare(strict_types=1);

/** 文档流程图生成器：仅写 docs/wiki/assets，不读取交易或配置数据。 */
$font = $argv[1] ?? '';
if (!is_file($font) || !function_exists('imagettftext')) {
    fwrite(STDERR, "Usage: php render-flows.php /path/to/chinese-font.ttf (GD + FreeType required)\n");
    exit(1);
}
$specs = [
    'payment-flow' => [
        '从业务下单到确认收款',
        ['业务网站', '应用 PID / KEY 签名', '下单 / 查询'],
        ['MPAY 接口与选路', '验签、幂等建单', '默认组 / 自定义组'],
        ['支付插件', '读取原通道配置快照', '准备付款材料'],
        ['用户付款', '扫码支付到收款账户', '二维码不代表成功'],
        ['插件识别到账', '设备通知 / 主动采集', '认证、匹配、流水防重'],
        ['统一确认事务', '最终流水 + 订单成功', '建立商户通知责任'],
        'payment:work 发送通知 → 业务网站验签、幂等履约并返回 success',
        '每条真实流水只确认一笔订单；未匹配流水不保存。资金直接进入实际收款账户。',
    ],
    'notification-flow' => [
        '订单成功与商户通知分别记录',
        ['确认收款', '保存证据、订单成功', '生成唯一通知任务'],
        ['是否暂缓通知', '正常：进入待发送', '暂缓：等待首次放行'],
        ['payment:work', '领取任务与租约', '事务外 HTTP GET'],
        ['业务网站处理', '验签 + 金额 / 订单核对', '按商户单幂等履约'],
        ['记录每次响应', 'HTTP 状态、耗时与片段', '正文最多 4096 字节'],
        ['确定发送结果', '2xx + success：确认', '否则：按预算重试'],
        '任务结束后可授权单次补发；首次有效确认时间不会被后续失败覆盖',
        '浏览器回跳不能代替通知；通知可重复送达，商户必须幂等处理。',
    ],
];

foreach ($specs as $filename => $spec) {
    $im = imagecreatetruecolor(1500, 760);
    $white = imagecolorallocate($im, 255, 255, 255);
    $ink = imagecolorallocate($im, 40, 51, 62);
    $muted = imagecolorallocate($im, 91, 103, 113);
    $teal = imagecolorallocate($im, 0, 133, 119);
    $tint = imagecolorallocate($im, 239, 249, 247);
    $line = imagecolorallocate($im, 210, 225, 223);
    imagefill($im, 0, 0, $white);
    imagettftext($im, 28, 0, 48, 64, $ink, $font, $spec[0]);
    imagettftext($im, 15, 0, 49, 99, $muted, $font, 'MPAY V1 · ThinkPHP 重构版');
    // Two rows follow a snake path so every arrow has a clear direction.
    $positions = [[50, 145], [550, 145], [1050, 145], [1050, 370], [550, 370], [50, 370]];
    foreach ($positions as $i => [$x, $y]) {
        imagefilledrectangle($im, $x, $y, $x + 400, $y + 165, $tint);
        imagerectangle($im, $x, $y, $x + 400, $y + 165, $line);
        imagefilledrectangle($im, $x, $y, $x + 5, $y + 165, $teal);
        imagettftext($im, 22, 0, $x + 22, $y + 43, $teal, $font, ($i + 1) . '  ' . $spec[$i + 1][0]);
        imagettftext($im, 17, 0, $x + 22, $y + 88, $ink, $font, $spec[$i + 1][1]);
        imagettftext($im, 17, 0, $x + 22, $y + 124, $muted, $font, $spec[$i + 1][2]);
    }
    imagesetthickness($im, 3);
    foreach ([[465,228,533,228], [965,228,1033,228], [1250,324,1250,354], [1034,453,966,453], [534,453,466,453]] as [$x1,$y1,$x2,$y2]) {
        imageline($im,$x1,$y1,$x2,$y2,$teal);
        $a=atan2($y2-$y1,$x2-$x1);
        foreach ([-0.5,0.5] as $angle) {
            imageline($im,$x2,$y2,(int)($x2-12*cos($a+$angle)),(int)($y2-12*sin($a+$angle)),$teal);
        }
    }
    imagefilledrectangle($im,50,590,1450,654,$ink);
    imagettftext($im,21,0,75,632,$white,$font,$spec[7]);
    imagettftext($im,17,0,50,708,$muted,$font,$spec[8]);
    imagepng($im,dirname(__DIR__).'/assets/'.$filename.'.png');
    imagedestroy($im);
}
