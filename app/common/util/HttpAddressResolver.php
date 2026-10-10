<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;

/** 共享的 DNS 与网段边界，调用方先限定目标主机/端口；全部返回地址须由传输层绑定，不再二次解析。 */
class HttpAddressResolver
{
    /**
     * 每次连接重新检查所有 A/AAAA 和有界 CNAME 链，任一禁止地址都会拒绝整个目标。
     * 系统 DNS 可能阻塞，调用者须在解析后再次检查绝对预算；本类不声称能中止系统解析调用。
     *
     * @param bool $development 仅由已核对的开发目标政策传入，不能来自请求参数。
     * @return list<string> 本次允许的字面地址，不含主机名或日志中的敏感 URL。
     * @throws BusinessException 地址拒绝或 DNS 无有效结果，不把故障伪装成空列表。
     */
    public function resolve(string $host, bool $development = false): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertAddress($host, $development);
            return [$host];
        }
        if ($development && $host === 'localhost') {
            return ['127.0.0.1'];
        }
        $queue = [$host];
        $visited = [];
        $addresses = [];
        while ($queue !== []) {
            $host = array_shift($queue);
            if (isset($visited[$host])) {
                continue;
            }
            if (count($visited) >= 8) {
                throw new BusinessException('目标 DNS 别名链过长', ApiResponseConstant::SERVICE_UNAVAILABLE);
            }
            $visited[$host] = true;
            $records = $this->lookup($host);
            foreach ($records as $record) {
                if (isset($record['ip']) || isset($record['ipv6'])) {
                    $ip = $record['ip'] ?? $record['ipv6'];
                    $this->assertAddress($ip, $development);
                    $addresses[$ip] = $ip;
                    if (count($addresses) > 64) {
                        throw new BusinessException('目标 DNS 地址数量超出连接边界', ApiResponseConstant::SERVICE_UNAVAILABLE);
                    }
                } elseif (($record['type'] ?? null) === 'CNAME' && is_string($record['target'] ?? null)) {
                    $alias = rtrim(strtolower($record['target']), '.');
                    if (!preg_match('/^[a-z0-9.-]{1,253}$/D', $alias)) {
                        throw new BusinessException('目标 DNS 别名无效', ApiResponseConstant::SERVICE_UNAVAILABLE);
                    }
                    $queue[] = $alias;
                }
            }
        }
        if ($addresses === []) {
            throw new BusinessException('目标域名当前无法解析', ApiResponseConstant::SERVICE_UNAVAILABLE);
        }
        return array_values($addresses);
    }

    /**
     * 可在隔离测试替换 DNS 返回；真实实现不把查询失败伪装成合法空列表。
     *
     * @return list<array<string,mixed>> 系统 DNS 扩展的动态记录；resolve 只接受地址与 CNAME 字段。
     */
    protected function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        if ($records === false) {
            throw new BusinessException('目标 DNS 查询失败', ApiResponseConstant::SERVICE_UNAVAILABLE);
        }
        return $records;
    }

    /** 默认拒绝内网、保留、组播、映射和隧道范围；开发例外只放行明确主机的私网/回环。 */
    public function assertAddress(string $ip, bool $development): void
    {
        $bytes = @inet_pton($ip);
        if ($bytes === false) {
            throw new BusinessException('目标 IP 地址无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        $private = ['10.0.0.0/8', '127.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'];
        if ($development && $this->inAnyRange($bytes, $private)) {
            return;
        }
        if (strlen($bytes) === 4) {
            $blocked = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
            $public = !$this->inAnyRange($bytes, $blocked);
        } else {
            $public = $this->inAnyRange($bytes, ['2000::/3'])
                && !$this->inAnyRange($bytes, ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']);
        }
        if (!$public) {
            throw new BusinessException('目标地址不属于允许的公网范围', ApiResponseConstant::VALIDATION_FAILED);
        }
    }

    /** @param list<string> $ranges 固定 CIDR 列表，不接收 HTTP 输入。 */
    private function inAnyRange(string $bytes, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $prefix] = explode('/', $range, 2);
            $base = inet_pton($network);
            if (strlen($base) !== strlen($bytes)) {
                continue;
            }
            $whole = intdiv((int) $prefix, 8);
            $remainder = (int) $prefix % 8;
            if (substr($base, 0, $whole) === substr($bytes, 0, $whole)
                && ($remainder === 0 || ((ord($base[$whole]) ^ ord($bytes[$whole])) & (0xff << (8 - $remainder))) === 0)) {
                return true;
            }
        }
        return false;
    }

}
