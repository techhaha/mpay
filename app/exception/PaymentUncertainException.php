<?php
declare(strict_types=1);

namespace app\exception;

/**
 * 支付请求可能已被上游处理，但当前无法确认结果，例如请求发出后的连接中断。
 * 生命周期需保留原执行并核对后续事实；全局处理器不能直接改为 FAILED 或无恢复责任的 202。
 */
final class PaymentUncertainException extends \RuntimeException {}
