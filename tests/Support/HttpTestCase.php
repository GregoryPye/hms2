<?php

namespace Tests\Support;

use App\Events\Roles\RoleCreated;
use App\Events\Tools\{NewBooking,BookingChanged,BookingCancelled};
use HMS\Entities\{User,Tools\Tool};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Mix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,Event,Facade};
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

/** In-process real HTTP kernel; no web server, network client or middleware bypass. */
abstract class HttpTestCase extends BackendLifecycleTestCase
{
    protected Tool $routedTool;
    protected bool $httpReady = false;
    private array $cookies = [];
    private static ?array $oauthKeys = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordEvents([RoleCreated::class]);
        $this->routedTool=$this->createTool();
        // Seed definitions, not grants. The real ACL provider reads these at request bootstrap.
        foreach(config('roles.permissions') as $name)$this->execute('INSERT IGNORE INTO permissions (name) VALUES (?)',[$name]);
        foreach(['induction_request_html','rules_html','members_guide_html'] as $key)DB::table('meta')->updateOrInsert(['key'=>$key],['value'=>'/test/'.$key]);
        foreach([['tools.tool.index','main'],['tools.booking.index','directions'],['logo','svg']] as [$view,$block]){
            $this->persist(Fixtures::content()->setView($view)->setBlock($block));
        }
    }

    protected function tearDown(): void
    {
        try {parent::tearDown();}
        finally {
            // PHPUnit retains case objects. Release the two bootstrapped Laravel graphs and
            // ORM fixtures, including audit closures bound to this test, between cases.
            unset($this->routedTool,$this->memberEntity,$this->toolEntity,$this->em,$this->app);
            gc_collect_cycles();
        }
    }

    protected function allow(array $permissions, ?User $user=null): void
    {
        $id=($user??$this->memberEntity)->getId();
        foreach($permissions as $name)$this->grant($id,$name);
        $this->em->refresh($user??$this->memberEntity);
    }

    protected function bootHttp(): void
    {
        if($this->httpReady)return;
        $toolId=$this->routedTool->getId();
        $this->em->clear();$this->em->getConnection()->close();DB::disconnect();
        Facade::clearResolvedInstances();Facade::setFacadeApplication(null);$this->app->flush();
        $this->app=\application();TransportGuard::install();$this->em=$this->app['em'];
        $this->memberEntity=$this->em->find(User::class,$this->user);
        $this->toolEntity=$this->em->find(Tool::class,$this->tool);
        $this->routedTool=$this->em->find(Tool::class,$toolId);
        config(['hms.features.tools'=>true]);
        if(self::$oauthKeys===null){
            $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key,$private);self::$oauthKeys=[$private,openssl_pkey_get_details($key)['key']];
        }
        config(['passport.private_key'=>self::$oauthKeys[0],'passport.public_key'=>self::$oauthKeys[1]]);
        // Only static URL resolution is substituted: render the actual templates/layouts.
        // No compiled/private assets are built or fetched by an HTTP response test.
        $this->app->instance(Mix::class,static function($path,$directory=''){
            if($directory!=='' || !in_array($path,['/css/app.css','/css/vue.css','/js/manifest.js','/js/vendor.js','/js/app.js'],true))throw new \RuntimeException('Unexpected asset lookup');
            return new \Illuminate\Support\HtmlString($path);
        });
        $this->localAudit();
        $this->recordEvents([NewBooking::class,BookingChanged::class,BookingCancelled::class]);
        $this->httpReady=true;
    }

    protected function request(string $method,string $path,array $data=[],string $auth='api',bool $json=true,array $headers=[]): TestResponse
    {
        $this->bootHttp();
        Auth::forgetGuards();Auth::shouldUse('web');
        $this->app['session']->driver()->flush();
        if($auth==='api')Passport::actingAs($this->em->find(User::class,$this->user),[],'api');
        elseif($auth==='web')Auth::guard('web')->setUser($this->em->find(User::class,$this->user));
        elseif($auth!=='guest' && $auth!=='session' && $auth!=='bearer')throw new \InvalidArgumentException('Unknown test guard');
        // Session continuity is explicit through cookies; other helpers do not inherit login state.
        if($auth!=='session')$cookies=[];else $cookies=$this->cookies;
        $server=['HTTP_HOST'=>'hms.test','SERVER_NAME'=>'hms.test','REMOTE_ADDR'=>'127.0.0.1','HTTP_ACCEPT'=>$json?'application/json':'text/html'];
        foreach($headers as $name=>$value)$server['HTTP_'.strtoupper(str_replace('-','_',$name))]=$value;
        $content=null;
        if($json && $method!=='GET'){$server['CONTENT_TYPE']='application/json';$content=json_encode($data,JSON_THROW_ON_ERROR);$data=[];}
        $request=Request::create('http://hms.test'.$path,$method,$data,$cookies,[],$server,$content);
        $kernel=$this->app->make(Kernel::class);$response=$kernel->handle($request);$kernel->terminate($request,$response);
        foreach($response->headers->getCookies() as $cookie)$this->cookies[$cookie->getName()]=$cookie->getValue();
        return TestResponse::fromBaseResponse($response);
    }

    protected function apiPath(?Tool $tool=null,?int $booking=null): string
    {
        return '/api/tools/'.($tool??$this->toolEntity)->getId().'/bookings'.($booking===null?'':'/'.$booking);
    }

    protected function bookingData(string $type='NORMAL'): array
    {
        return ['start'=>'2025-02-15T13:00:00Z','end'=>'2025-02-15T14:00:00Z','type'=>$type];
    }

    protected function assertNoBookingWrites(callable $request): TestResponse
    {
        $before=$this->rows('SELECT * FROM bookings ORDER BY id');
        $response=$request();
        self::assertSame($before,$this->rows('SELECT * FROM bookings ORDER BY id'));
        Event::assertNotDispatched(NewBooking::class);Event::assertNotDispatched(BookingChanged::class);Event::assertNotDispatched(BookingCancelled::class);
        return $response;
    }

    protected function bearerToken(): string
    {
        $this->bootHttp();
        $client=$this->app->make(\Laravel\Passport\ClientRepository::class)->createPersonalAccessClient(null,'Synthetic HTTP client','http://hms.test');
        DB::table('oauth_personal_access_clients')->insert(['client_id'=>$client->id,'created_at'=>now(),'updated_at'=>now()]);
        return $this->app->make(\Laravel\Passport\PersonalAccessTokenFactory::class)->make($this->user,'Synthetic HTTP token',[])->accessToken;
    }
}
