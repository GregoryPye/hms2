<?php
namespace Tests\Feature\Tools;

use Illuminate\Support\Facades\{Auth,DB};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HttpTestCase;

final class AuthenticationTest extends HttpTestCase
{
    public static function anonymous(): array{return [['/tools',false,302],['/api/tools/1/bookings',true,401]];}
    #[DataProvider('anonymous')]
    public function testHTTP01AnonymousGuardsDenyWithoutWrites(string $path,bool $json,int $status): void
    {
        $response=$this->assertNoBookingWrites(fn()=>$this->request('GET',$path,[], 'guest',$json));$response->assertStatus($status);
        if(!$json)$response->assertRedirect('http://hms.test/login');else $response->assertJsonPath('errors.0.status',401)->assertJsonPath('errors.0.title','Unauthenticated');
    }
    public function testHTTP01WebRequiresVerifiedEmail(): void
    {
        $this->allow(['tools.view']);$this->memberEntity->setEmailVerifiedAt(null);$this->persist($this->memberEntity);
        $this->request('GET','/tools',[],'web',false)->assertRedirect('http://hms.test/email/verify');
    }
    public function testHTTP01ApiCurrentlyDoesNotRequireVerifiedEmail(): void
    {
        $this->allow(['tools.view']);$this->memberEntity->setEmailVerifiedAt(null);$this->persist($this->memberEntity);
        $this->request('GET',$this->apiPath(),['start'=>'2025-02-15','end'=>'2025-02-16'])->assertOk()->assertExactJson([]);
    }
    public static function disabled(): array{return [['web'],['api']];}
    #[DataProvider('disabled')]
    public function testHTTP01DisabledFeatureRedirectsBeforeBookingWrites(string $guard): void
    {
        $this->allow(['tools.book','tools.view']);$this->bootHttp();config(['hms.features.tools'=>false]);
        $r=$this->assertNoBookingWrites(fn()=>$this->request($guard==='api'?'POST':'GET',$guard==='api'?$this->apiPath():'/tools',$this->bookingData(),$guard,$guard==='api'));
        $r->assertRedirect('http://hms.test');
    }
    public static function passwords(): array{return [['test_member','password',302],['member@example.invalid','password',302],['test_member','wrong-password',422]];}
    #[DataProvider('passwords')]
    public function testHTTP01RealLocalCredentialLoginUsesDoctrineStore(string $login,string $password,int $status): void
    {
        $this->allow(['tools.view']);
        $r=$this->request('POST','/login',compact('login','password'),'guest',$status!==302);$r->assertStatus($status);
        if($status===302){
            self::assertSame($this->user,Auth::guard('web')->id());
            $this->request('GET','/tools',[],'session',false)->assertOk();
            $this->request('POST','/logout',[],'session',false)->assertRedirect('http://hms.test');
            $this->request('GET','/tools',[],'session',false)->assertRedirect('http://hms.test/login');
        }else{$r->assertJsonValidationErrors('login');self::assertNull(Auth::guard('web')->id());}
    }
    public function testHTTP01SignedBearerUsesRealPassportGuardAndRevocation(): void
    {
        $this->allow(['tools.view']);$token=$this->bearerToken();
        $query=['start'=>'2025-02-15','end'=>'2025-02-16'];
        $this->request('GET',$this->apiPath(),$query,'bearer',true,['Authorization'=>'Bearer '.$token])->assertOk()->assertExactJson([]);
        DB::table('oauth_access_tokens')->update(['revoked'=>true]);
        $this->request('GET',$this->apiPath(),$query,'bearer',true,['Authorization'=>'Bearer '.$token])->assertUnauthorized();
        $this->request('GET',$this->apiPath(),$query,'bearer',true,['Authorization'=>'Bearer invalid.synthetic.token'])->assertUnauthorized();
    }
    public function testHTTP01WebSessionAloneDoesNotAuthorizeApiWithoutPassportCredentials(): void
    {
        $this->allow(['tools.view']);$this->request('GET',$this->apiPath(),['start'=>'2025-02-15','end'=>'2025-02-16'],'web')->assertUnauthorized();
    }
}
