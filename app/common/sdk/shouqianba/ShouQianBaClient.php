<?php
declare(strict_types=1);

namespace app\common\sdk\shouqianba;

use app\common\util\Clock;
use app\common\util\HttpClient;
use app\common\util\PlatformTargetPolicy;
use app\exception\HttpRequestException;
use app\exception\ReceiptCollectionException;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use JsonException;

/**
 * 收钱吧商户后台只读客户端，接口、登录摘要和字段口径依据 V2 直连 watcher。
 * 负责登录/刷新、商户上下文和交易页，不依赖订单表；会话由插件按账号缓存，客户端不持久化或参与订单确认。
 */
final class ShouQianBaClient
{
    private const ORIGIN = 'https://web-platforms-msp.shouqianba.com';

    public function __construct(private readonly HttpClient $http, private readonly Clock $clock) {}

    /**
     * 恢复或刷新平台认证；调用者取得返回值立即持久保存，再执行流水查询。
     * 未提供 valid_time 时不臆造到期时间；临期刷新暂时失败仍可用未过期 token 验证。
     * @param array<string,mixed> $settings 收钱吧声明的配置，不接受其他平台字段。
     * @param array<string,mixed> $state 插件保存的私有认证状态。
     * @return array<string,mixed>
     */
    public function authenticate(array $settings, array $state, DateTimeImmutable $deadline): array
    {
        if (!is_string($state['token'] ?? null) || $state['token'] === '') { return $this->login($settings, $deadline); }
        $expires = (int) ($state['expires_at'] ?? 0);
        if ($expires === 0 || $expires > $this->clock->now()->getTimestamp() + 60) { return $state; }
        try {
            $data = $this->request('POST', '/api/login/ucUser/refreshToken', ['token' => $state['token']], [],
                $deadline, ['Authorization' => 'Bearer ' . $state['token']]);
            $state['token'] = $this->text($data['token'] ?? null);
            $state['expires_at'] = $this->expires($data['valid_time'] ?? null);
            unset($state['scope'], $state['scope_selector']);
        } catch (ReceiptCollectionException $error) {
            if ($error->reasonCode === 'authentication_expired') { return $this->login($settings, $deadline); }
            if ($error->reasonCode === 'budget_exhausted' || $expires <= $this->clock->now()->getTimestamp()) { throw $error; }
        }
        return $state;
    }

    /**
     * 固定第一页，正式查询 200 条、预登录探测 1 条；不翻页、不在失败后重发流水请求。
     * 范围状态按平台配置缓存，切换门店重新验证，普通查询不重复登录/切换商户。
     * @param array{from:int,to:int,deadline:DateTimeImmutable} $window 秒级闭区间，由插件按原单生成。
     * @param array<string,mixed> $state 已认证状态，范围变更通过引用交还插件保存。
     * @return array{rows:list<array<string,mixed>>,limit_reached:bool}
     */
    public function query(array $settings, array $window, array &$state, int $limit = 200): array
    {
        if (!in_array($limit, [1, 200], true) || $window['from'] < 1 || $window['to'] < $window['from']) {
            throw new InvalidArgumentException('收钱吧查询窗口无效');
        }
        $token = $this->text($state['token'] ?? null);
        $selector = hash('sha256', json_encode([$settings['receipt_account_no'], $settings['receipt_merchant_name'] ?? '',
            $settings['receipt_terminal_no'] ?? ''], JSON_THROW_ON_ERROR));
        if (($state['scope_selector'] ?? null) !== $selector || !is_array($state['scope'] ?? null)) {
            $state['scope'] = $this->scope($settings, $token, $window['deadline']);
            $state['scope_selector'] = $selector;
        }
        $filters = array_filter(array_intersect_key($state['scope'], array_flip(['merchant_sn', 'store_sn', 'store_id', 'merchant_name', 'store_name'])),
            static fn ($value): bool => $value !== '');
        if (!empty($settings['receipt_terminal_no'])) { $filters['terminal_sn'] = $settings['receipt_terminal_no']; }
        $data = $this->request('POST', '/api/transaction/findTransactions', ['token' => $token, 'client_version' => '7.0.0'],
            ['upayQueryType' => 0, 'page' => 1, 'page_size' => $limit, 'date_start' => $window['from'] * 1000,
                'date_end' => $window['to'] * 1000 + 999, 'show_fund_state' => true] + $filters, $window['deadline']);
        if (!is_array($data['records'] ?? null) || !array_is_list($data['records']) || count($data['records']) > $limit) {
            throw new ReceiptCollectionException('protocol_error');
        }
        $total = $this->integer($data['total'] ?? null);
        if ($total < count($data['records'])) { throw new ReceiptCollectionException('protocol_error'); }
        return ['rows' => $data['records'], 'limit_reached' => $total > $limit || count($data['records']) === $limit];
    }

    /** 登录凭证只用于认证；取得 token 后仍须核实商户并成功查询交易页，才说明状态已通过真实查询验证；取得 token 后应立即交回插件缓存。 */
    private function login(array $settings, DateTimeImmutable $deadline): array
    {
        $password = trim($this->text($settings['watcher_password'] ?? null));
        $data = $this->request('POST', '/api/login/ucUser/login', [], [
            'username' => $this->text($settings['watcher_username'] ?? null),
            'password' => preg_match('/^[a-fA-F0-9]{32}$/D', $password) ? strtolower($password) : md5($password),
            'uc_device' => ['device_type' => 2, 'default_device' => 0, 'platform' => '商户服务平台',
                'device_fingerprint' => bin2hex(random_bytes(16)), 'device_name' => '收钱吧商户平台', 'device_model' => 'Windows', 'device_brand' => 'Chrome'],
        ], $deadline, ['sub_appid' => 'sqb']);
        return ['token' => $this->text($data['mchUserTokenInfo']['token'] ?? null),
            'expires_at' => $this->expires($data['mchUserTokenInfo']['valid_time'] ?? null)];
    }

    /** 根据 V2 对应接口的商户与门店字段核对查询范围，不能把登录账号直接当成收款主体。 */
    private function scope(array $settings, string $token, DateTimeImmutable $deadline): array
    {
        $query = ['token' => $token, 'client_version' => '7.0.0'];
        $info = $this->request('GET', '/api/login/ucUser/getLoginInfo', $query, null, $deadline);
        $merchants = $info['loginUserInfos'] ?? null;
        if (!is_array($merchants) || !array_is_list($merchants) || $merchants === []) { throw new ReceiptCollectionException('scope_mismatch'); }
        $account = (string) ($settings['receipt_account_no'] ?? '');
        $name = (string) ($settings['receipt_merchant_name'] ?? '');
        $matches = array_values(array_filter($merchants, function (array $merchant) use ($account, $name, $merchants): bool {
            $ids = array_map('strval', array_intersect_key($merchant, array_flip(['merchant_sn', 'group_merchant_sn', 'brand_merchant_sn', 'brand_sn'])));
            $names = array_map('strval', array_intersect_key($merchant, array_flip(['merchant_name', 'group_name', 'brand_name'])));
            return ($name === '' || in_array($name, $names, true)) && (count($merchants) === 1 || $name !== '' || in_array($account, $ids, true));
        }));
        if (count($matches) !== 1) { throw new ReceiptCollectionException('scope_mismatch'); }
        $target = $matches[0];
        // 以下别名来自 V2 已明确列出的同接口版本字段，不跨平台泛化猜测。
        $userId = $this->first($target, ['merchant_user_id', 'merchantUserId', 'id', 'user_id', 'userId', 'mch_user_id', 'mchUserId']);
        $merchantSn = $this->first($target, ['merchant_sn', 'group_merchant_sn', 'brand_merchant_sn', 'brand_sn']);
        $this->request('POST', '/api/login/ucUser/chooseLoginUser', ['token' => $token, 'merchantUserId' => $userId], ['merchantUserId' => $userId], $deadline);
        $current = $this->request('GET', '/api/login/ucUser/getCurrentUser', ['token' => $token], null, $deadline);
        $trade = $current['trade_user_info'] ?? [];
        if (!is_array($trade) || (string) ($trade['merchant_sn'] ?? '') !== $merchantSn
            || (isset($trade['merchant_user_id']) && (string) $trade['merchant_user_id'] !== $userId)) { throw new ReceiptCollectionException('scope_mismatch'); }
        $stores = $this->request('POST', '/api/findStores', $query, ['page' => 1, 'page_size' => 9999], $deadline);
        if (!is_array($stores['records'] ?? null) || !array_is_list($stores['records'])) { throw new ReceiptCollectionException('protocol_error'); }
        $scopes = [];
        foreach ($stores['records'] as $store) {
            $scope = ['merchant_sn' => (string) ($store['merchant_sn'] ?? $merchantSn), 'merchant_id' => (string) ($store['merchant_id'] ?? ''),
                'merchant_name' => (string) ($store['merchant_name'] ?? $store['name'] ?? ''), 'store_sn' => $this->first($store, ['sn', 'store_sn'], false),
                'store_id' => $this->first($store, ['id', 'storeId', 'store_id'], false), 'store_name' => (string) ($store['name'] ?? $store['store_name'] ?? '')];
            if (($account === '' || in_array($account, [$scope['merchant_sn'], $scope['merchant_id'], $scope['store_sn'], $scope['store_id']], true))
                && ($name === '' || in_array($name, [$scope['merchant_name'], $scope['store_name']], true))) { $scopes[] = $scope; }
        }
        if ($scopes === [] && $stores['records'] === [] && ($account === '' || $account === $merchantSn)) {
            return ['merchant_sn' => $merchantSn, 'merchant_name' => $name];
        }
        if (count($scopes) > 1 && !empty($settings['receipt_terminal_no'])
            && count(array_unique(array_column($scopes, 'merchant_sn'))) === 1) {
            return ['merchant_sn' => $merchantSn, 'merchant_name' => $name];
        }
        if (count($scopes) !== 1) { throw new ReceiptCollectionException('scope_mismatch'); }
        return $scopes[0];
    }

    /** 固定官方站点和接口路径；Guzzle PSR-7 请求交给现有传输，响应错误只保留分类，不保留秘密报文。 */
    private function request(string $method, string $path, array $query, ?array $body,
        DateTimeImmutable $deadline, array $headers = []): array
    {
        $headers += ['Accept' => 'application/json', 'Content-Type' => 'application/json;charset=UTF-8',
            'Origin' => 'https://s.shouqianba.com', 'Referer' => 'https://s.shouqianba.com/',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'];
        $request = new Request($method, self::ORIGIN . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)),
            $headers, $body === null ? null : json_encode((object) $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try { $response = $this->http->send($request, PlatformTargetPolicy::production([self::ORIGIN]), $deadline, 4194304); }
        catch (HttpRequestException $error) { throw new ReceiptCollectionException($error->reasonCode === 'deadline_elapsed' ? 'budget_exhausted' : 'network_error'); }
        if (in_array($response->getStatusCode(), [401, 403], true)) { throw new ReceiptCollectionException('authentication_expired'); }
        if ($response->getStatusCode() === 429) { throw new ReceiptCollectionException('rate_limited'); }
        if ($response->getStatusCode() !== 200) { throw new ReceiptCollectionException('network_error'); }
        try { $data = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ReceiptCollectionException('protocol_error'); }
        if (!is_array($data) || array_is_list($data)) { throw new ReceiptCollectionException('protocol_error'); }
        $code = (string) ($data['code'] ?? '');
        if (in_array($code, ['50201', '50210', '50211', '50212'], true)) { throw new ReceiptCollectionException('authentication_expired'); }
        if ($code === '50105') { throw new ReceiptCollectionException('scope_mismatch'); }
        if (in_array($code, ['50000', '10000'], true) && $path === '/api/login/ucUser/chooseLoginUser' && !isset($data['data'])) { return []; }
        if (!in_array($code, ['50000', '10000'], true) || !is_array($data['data'] ?? null)) { throw new ReceiptCollectionException('protocol_error'); }
        return $data['data'];
    }

    /** @param list<string> $fields 同一收钱吧接口已登记版本的明确别名。 */
    private function first(array $data, array $fields, bool $required = true): string
    {
        foreach ($fields as $field) { if (isset($data[$field]) && (is_string($data[$field]) || is_int($data[$field])) && (string) $data[$field] !== '') { return (string) $data[$field]; } }
        if ($required) { throw new ReceiptCollectionException('protocol_error'); }
        return '';
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 8192) { throw new ReceiptCollectionException('protocol_error'); }
        return $value;
    }

    private function integer(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,12})$/D', (string) $value)) { throw new ReceiptCollectionException('protocol_error'); }
        return (int) $value;
    }

    private function expires(mixed $milliseconds): int
    {
        if ($milliseconds === null || $milliseconds === '') { return 0; }
        $duration = $this->integer($milliseconds);
        // 平台未提供有效期限时，以真实认证结果判断失效，不能把零解释为立即过期。
        return $duration === 0 ? 0 : $this->clock->now()->getTimestamp() + intdiv($duration, 1000);
    }
}
