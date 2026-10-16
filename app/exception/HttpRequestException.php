<?php
declare(strict_types=1);

namespace app\exception;

/** 对外 HTTP 的安全故障分类；消息不含 URL/正文，私有片段仅供通知业务保存，禁止拼入技术日志。 */
final class HttpRequestException extends \RuntimeException
{
    /**
     * @param string $reasonCode 固定内部分类，可安全写入尝试记录；不能从远端错误文本生成。
     * @param int $durationMs 从校验开始到失败的毫秒数，包含本次 DNS 查询时间。
     * @param int|null $httpStatus 已收到的 HTTP 状态；null 表示没有可用状态，不表示未发请求。
     */
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $durationMs,
        public readonly ?int $httpStatus = null,
        private readonly ?string $responsePrefix = null,
    ) {
        if (
            !in_array(
                $reasonCode,
                [
                    'target_rejected',
                    'dns_error',
                    'deadline_elapsed',
                    'timeout',
                    'connect_error',
                    'tls_error',
                    'response_too_large',
                    'header_too_large',
                    'transport_error',
                    'address_mismatch',
                ],
                true,
            )
        ) {
            throw new \InvalidArgumentException('未知 HTTP 故障分类');
        }
        parent::__construct('外部 HTTP 请求未取得有效结果：' . $reasonCode);
    }

    /** 网络中断或正文超限时已收到的前 4096 字节，不表示完整业务应答。 */
    public function responsePrefix(): ?string
    {
        return $this->responsePrefix;
    }
}
