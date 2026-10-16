<?php
namespace Tests\Feature\Tools;

use App\Events\Tools\{NewBooking,BookingChanged,BookingCancelled};
use HMS\Entities\Tools\Booking;
use Illuminate\Support\Facades\{DB,Event};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HttpTestCase;

final class BookingHttpTest extends HttpTestCase
{
    public static function denied(): array{return [['GET','tools.book'],['POST','tools.view'],['PATCH','tools.view'],['DELETE','tools.view']];}
    #[DataProvider('denied')]
    public function testHTTP02GlobalPermissionDenialPreventsMutation(string $method,string $permission): void
    {
        $this->allow([$permission]);$b=$this->makeBooking('2025-02-15 15:00','2025-02-15 16:00');
        $this->assertNoBookingWrites(fn()=>$this->request($method,$this->apiPath(booking:in_array($method,['PATCH','DELETE'])?$b->getId():null),$this->bookingData()))->assertForbidden();
    }
    public static function invalid(): array{return [['start',null],['end','invalid'],['type','OTHER']];}
    #[DataProvider('invalid')]
    public function testHTTP05CreateValidationRejectsBeforeWrites(string $field,$value): void
    {
        $this->allow(['tools.book']);$data=$this->bookingData();$data[$field]=$value;
        $this->assertNoBookingWrites(fn()=>$this->request('POST',$this->apiPath(),$data))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    public static function types(): array{return [['NORMAL'],['INDUCTION'],['MAINTENANCE']];}
    #[DataProvider('types')]
    public function testHTTP05CreateTypesPersistAndPublishExpectedJson(string $type): void
    {
        $this->allow(['tools.book','tools.view','tools.testFixture.book.induction','tools.testFixture.book.maintenance']);
        $r=$this->request('POST',$this->apiPath(),$this->bookingData($type));$r->assertCreated()->assertJsonStructure(['id','start','end','type','userId','toolId','toolDisplayName','title']);
        $id=$r->json('id');$r->assertJsonPath('type',$type)->assertJsonPath('userId',$this->user)->assertJsonPath('toolId',$this->tool)->assertJsonPath('start','2025-02-15T13:00:00+00:00');
        self::assertSame($type,DB::table('bookings')->where('id',$id)->value('type'));Event::assertDispatchedTimes(NewBooking::class,1);
        $this->request('GET',$this->apiPath(),['start'=>'2025-02-15','end'=>'2025-02-16'])->assertOk()->assertJsonPath('0.id',$id);
    }
    public function testHTTP05RestrictedToolDeniesWithoutSpecificInduction(): void
    {
        $this->allow(['tools.book']);$this->assertNoBookingWrites(fn()=>$this->request('POST',$this->apiPath($this->routedTool),$this->bookingData()))->assertUnprocessable();
    }
    public function testHTTP05CreateRejectsOverlapWithNoNewEvent(): void
    {
        $this->allow(['tools.book']);$this->makeBooking('2025-02-15 13:00','2025-02-15 14:00');
        $this->assertNoBookingWrites(fn()=>$this->request('POST',$this->apiPath(),$this->bookingData()))->assertUnprocessable();
    }
    public function testHTTP05UpdateThenCancelPersistsAndReturnsStableIdentity(): void
    {
        $this->allow(['tools.book']);$b=$this->makeBooking('2025-02-15 13:00','2025-02-15 14:00');$id=$b->getId();
        $data=['start'=>'2025-02-15T15:00:00+01:00','end'=>'2025-02-15T16:00:00+01:00'];
        $this->request('PATCH',$this->apiPath(booking:$id),$data)->assertOk()->assertJsonPath('id',$id);
        self::assertSame('2025-02-15 14:00:00',DB::table('bookings')->where('id',$id)->value('start'));Event::assertDispatchedTimes(BookingChanged::class,1);
        $this->request('DELETE',$this->apiPath(booking:$id))->assertNoContent();
        self::assertFalse(DB::table('bookings')->where('id',$id)->exists());Event::assertDispatched(BookingCancelled::class,fn($e)=>$e->broadcastWith()===['bookingId'=>$id,'toolId'=>$this->tool]);
        $this->request('DELETE',$this->apiPath(booking:$id))->assertNotFound();
    }
    public static function mismatches(): array{return [['PATCH','owner',422],['DELETE','owner',403],['PATCH','tool',422],['DELETE','tool',403]];}
    #[DataProvider('mismatches')]
    public function testHTTP02OwnerAndToolBindingMismatchDoNotWrite(string $method,string $mismatch,int $status): void
    {
        $this->allow(['tools.book']);$other=$mismatch==='owner'?$this->member('other'):$this->user;
        $b=$this->makeBooking('2025-02-15 15:00','2025-02-15 16:00',user:$this->em->find(\HMS\Entities\User::class,$other));
        $tool=$mismatch==='tool'?$this->routedTool:$this->toolEntity;
        $this->assertNoBookingWrites(fn()=>$this->request($method,$this->apiPath($tool,$b->getId()),$this->bookingData()))->assertStatus($status);
    }
    public function testHTTP05MissingToolAndBookingUseRealDoctrine404Binding(): void
    {
        $this->allow(['tools.view','tools.book']);
        $this->assertNoBookingWrites(fn()=>$this->request('POST','/api/tools/999999/bookings',$this->bookingData()))->assertNotFound();
        $this->request('PATCH',$this->apiPath(booking:999999),$this->bookingData())->assertNotFound();
    }
    public function testHTTP05IndexValidationAndToolIsolation(): void
    {
        $this->allow(['tools.view']);$a=$this->makeBooking('2025-02-15 13:00','2025-02-15 14:00');$this->makeBooking('2025-02-15 13:00','2025-02-15 14:00',tool:$this->routedTool);
        $this->request('GET',$this->apiPath())->assertUnprocessable()->assertJsonValidationErrors(['start','end']);
        $this->request('GET',$this->apiPath(),['start'=>'2025-02-15T14:00:00+01:00','end'=>'2025-02-15T15:00:00+01:00'])->assertOk()->assertJsonCount(1)->assertJsonPath('0.id',$a->getId());
    }
    public function testHTTP05OverlappingUpdateShouldRejectAndPreserveState(): void
    {
        $this->allow(['tools.book']);$b=$this->makeBooking('2025-02-15 15:00','2025-02-15 16:00');$this->makeBooking('2025-02-15 13:00','2025-02-15 14:00');
        $r=$this->request('PATCH',$this->apiPath(booking:$b->getId()),$this->bookingData());
        $r->assertUnprocessable(); // DEF-01 is also observable through the actual route.
        self::assertSame('2025-02-15 15:00:00',DB::table('bookings')->where('id',$b->getId())->value('start'));
        Event::assertNotDispatched(BookingChanged::class);
    }
    public function testHTTP05AdvertisedShowRouteShouldNotReturnServerError(): void
    {
        $this->allow(['tools.book']);$b=$this->makeBooking('2025-02-15 13:00','2025-02-15 14:00');
        $this->request('GET',$this->apiPath(booking:$b->getId()))->assertOk()->assertJsonPath('id',$b->getId());
    }
}
