<?php
declare(strict_types=1);

namespace app\common\sdk\wechat;

use app\exception\ReceiptMessageException;

/**
 * 微信 PC 消息解析的受限 XML 入口，只构建文档，不识别付款、来源公众号或消息版本。
 * 平台解析器必须在此之后验证确定的节点合同；XML 结构可读不代表消息真实或金额可信。
 */
final class WechatMessageXml
{
    /**
     * 只从有界 UTF-8 字符串构建文档；禁止 DTD/实体声明、外部实体、处理指令及超深结构。
     * 不接受文件路径，不调用 XInclude、DTD/Schema 校验或实体替换；兼容 PHP 8.2/libxml。
     *
     * @return \DOMElement 文档根节点，后续解析应按已验证平台版本精确选择子节点，不能搜索任意金额文本。
     * @throws ReceiptMessageException 非法编码、XML 或禁止的结构；不把 libxml 错误/原文带入异常。
     */
    public function parse(string $xml): \DOMElement
    {
        // 先限制编码再检查声明，避免 UTF-16/NUL 使 ASCII 检查失效。保守拒绝任何位置的 DTD 声明字样。
        if (
            $xml === '' ||
            strlen($xml) > 65536 ||
            !mb_check_encoding($xml, 'UTF-8') ||
            str_contains($xml, "\0") ||
            preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)
        ) {
            throw new ReceiptMessageException('invalid_message');
        }
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;
        // NONET 只限制网络，不能单独作为文件实体防护；前置禁 DTD 且不启用 DTDLOAD/NOENT 才是本合同。
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            // libxml 错误可能包含原文或 URI，丢弃本次错误并恢复调用者的错误处理开关。
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        if (
            !$loaded ||
            $document->doctype !== null ||
            $document->documentElement === null ||
            ($document->encoding !== null &&
                !in_array(strtolower($document->encoding), ['utf-8', 'utf8'], true))
        ) {
            throw new ReceiptMessageException('invalid_message');
        }
        // DOM 已受输入字节和 libxml 默认深度限制；进一步收紧节点/深度，禁止向后续 XPath 传递异常结构。
        $pending = [[$document, 0]];
        $nodeCount = 0;
        while ($pending !== []) {
            [$node, $depth] = array_pop($pending);
            $nodeCount += 1 + ($node->attributes?->length ?? 0);
            if (
                $depth > 32 ||
                $nodeCount > 4096 ||
                in_array(
                    $node->nodeType,
                    [XML_PI_NODE, XML_ENTITY_REF_NODE, XML_DOCUMENT_TYPE_NODE],
                    true,
                )
            ) {
                throw new ReceiptMessageException('invalid_message');
            }
            foreach ($node->childNodes as $child) {
                $pending[] = [$child, $depth + 1];
            }
        }
        return $document->documentElement;
    }
}
