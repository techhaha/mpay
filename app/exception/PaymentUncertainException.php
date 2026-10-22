<?php
declare(strict_types=1);

namespace app\exception;

use RuntimeException;

/**
 * 当前无法确认支付结果，例如请求发出后的连接中断或平台查询未成功；不代表支付被拒绝。
 * 插件/SDK 须提供已脱敏说明供受限技术日志记录，不包含凭证、完整请求或响应正文。
 * 生命周期需保留原执行并核对后续事实；全局处理器不能直接改为 FAILED 或无恢复责任的 202。
 */
final class PaymentUncertainException extends RuntimeException {}
