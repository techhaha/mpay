<?php
declare(strict_types=1);

namespace app\common\interface;

/** V1 由 PHP CLI 直接调用采集能力，复用 V2 的平台适配职责，不引入 Go/Redis 运行依赖。 */
interface ReceiptCollectorInterface extends PaymentInterface, ReceiptMatcherInterface
{
    /**
     * init 后由插件解释账号字段；各键为包含插件编码的 SHA-256，不得把密码纳入身份键。
     * session_key 为 null 表示无登录会话；auth_fingerprint 只检测同账号凭证冲突，不作为身份。
     * query_key 区分商户/门店/终端查询；account_key 归并真实流水及识别金额占用。
     * @return array{account_key:string,query_key:string,session_key:?string,auth_fingerprint:string}
     */
    public function getCollectionIdentity(): array;

    /**
     * @param array{from:int,to:int,deadline:\DateTimeImmutable} $window 左闭右开的秒级窗口与整轮期限。
     * @param array<string,scalar|null>|null $pageToken 本轮上一页提供的续页参数。
     * @param array<string,mixed>|null $session 同插件登录身份的共享会话，持久化和互斥由服务负责。
     * @return array{records:list<array<string,mixed>>,complete:bool,next_page:?array,session:?array}
     * @throws \app\exception\ReceiptCollectionException 认证、网络、范围或分页失败，不能作为空页成功。
     */
    public function collectPage(array $window, ?array $pageToken, #[\SensitiveParameter] ?array $session): array;
}
