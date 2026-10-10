<?php
declare(strict_types=1);

namespace app\http\admin\response;

use think\Response;

/** CSV 的应用响应扩展点；使用框架发送头/结束生命周期，只把已生成流分块输出，不修改框架 File 驱动。 */
final class CsvResponse extends Response
{
    /** @param resource $stream 查询服务已生成并回卷的 CSV；本响应取得关闭责任。 */
    public function __construct(private mixed $stream)
    {
        if (!is_resource($stream)) { throw new \InvalidArgumentException('导出响应需要有效数据流'); }
        $size = fstat($stream)['size'] ?? null;
        if (!is_int($size) || $size < 1) { throw new \InvalidArgumentException('导出响应需要完整的 CSV 内容'); }
        // 当前 ThinkPHP Response 通过受保护 init 初始化，而非父构造器；沿用原生响应子类约定。
        $this->init('', 200);
        $this->header(['Content-Type' => 'text/csv; charset=utf-8',
            // 服务已完整生成临时流，明确长度以便客户端识别截断，不能将空正文当作下载成功。
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'attachment; filename="mpay-orders.csv"', 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }

    /** 框架在响应头处理后调用；业务查询/审计已经结束，慢速下载不会继续持有数据库事务。 */
    protected function sendData(string $data): void
    {
        try {
            if (\think\facade\Request::method(true) === 'HEAD') { return; }
            // 与原生下载响应一样移除可移除的输出缓冲，防止 CSV 在 PHP 层再次整份累积。
            // 遇到不可移除的宿主缓冲停止，不无限循环或修改服务器配置。
            // 没有缓冲时 ob_get_status() 返回空数组；先查层数，避免索引 flags 后中断正文发送。
            while (ob_get_level() > 0 && (($buffer = ob_get_status())['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) !== 0) {
                ob_end_clean();
            }
            while (!feof($this->stream) && !connection_aborted()) {
                $part = fread($this->stream, 65536);
                if ($part === false) { throw new \RuntimeException('导出流读取失败'); }
                echo $part;
                flush();
            }
        } finally { fclose($this->stream); }
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) { fclose($this->stream); }
    }
}
