<?php
declare(strict_types=1);

/** 仅隔离测试装配；记录平台调用次数并返回合成协议，禁止真正发出 HTTP。 */
final class CountingReceiptHttp extends \app\common\util\HttpClient
{
    public int $logins = 0;
    public int $queries = 0;
    public array $rows = [];
    public array $stores = [];
    public ?Closure $duringQuery = null;
    public function __construct() {}

    public function send(\Psr\Http\Message\RequestInterface $request, \app\common\util\PlatformTargetPolicy $policy,
        DateTimeImmutable $deadline, int $maximumBytes = 65536, int $maximumHeaderBytes = 16384): \Psr\Http\Message\ResponseInterface
    {
        $path=$request->getUri()->getPath();
        $data=match($path){
            '/api/login/ucUser/login'=>$this->login(),
            '/api/login/ucUser/getLoginInfo'=>['loginUserInfos'=>[['merchant_sn'=>'merchant-fixture','merchant_user_id'=>'user-fixture']]],
            '/api/login/ucUser/chooseLoginUser'=>[],
            '/api/login/ucUser/getCurrentUser'=>['trade_user_info'=>['merchant_sn'=>'merchant-fixture','merchant_user_id'=>'user-fixture']],
            '/api/findStores'=>['records'=>[['sn'=>'store-a','merchant_sn'=>'merchant-fixture'],['sn'=>'store-b','merchant_sn'=>'merchant-fixture']]],
            '/api/transaction/findTransactions'=>$this->transactions(json_decode((string)$request->getBody(),true,64,JSON_THROW_ON_ERROR)),
            default=>throw new RuntimeException('合成 SDK 收到未登记接口'),
        };
        return new \GuzzleHttp\Psr7\Response(200,['Content-Type'=>'application/json'],json_encode(['code'=>'10000','data'=>$data],JSON_THROW_ON_ERROR));
    }
    private function login(): array { $this->logins++;return ['mchUserTokenInfo'=>['token'=>'synthetic-shared-token','valid_time'=>7200000]]; }
    private function transactions(array $input): array
    {
        $this->queries++;$this->stores[]=$input['store_sn']??'';
        if($this->duringQuery!==null){$callback=$this->duringQuery;$this->duringQuery=null;$callback();}
        $records=array_values(array_filter($this->rows,fn(array $row):bool=>$row['store_sn']===($input['store_sn']??'')));
        return ['records'=>$records,'total'=>count($records)];
    }
}
