<?php
// 应用公共文件
function backMsg($code = 0, $msg = '', $data = []): array
{
    $back_msg = ['code' => $code, 'msg' => $msg];
    if ($data) {
        $back_msg['data'] = $data;
    }
    return $back_msg;
}
