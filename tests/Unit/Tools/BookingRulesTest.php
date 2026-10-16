<?php

namespace Tests\Unit\Tools;

use App\Events\Tools\BookingCancelled;
use App\Events\Tools\BookingChanged;
use App\Events\Tools\NewBooking;
use Carbon\Carbon;
use HMS\Entities\Tools\Booking;
use HMS\Factories\Tools\BookingFactory;
use HMS\Repositories\Tools\BookingRepository;
use HMS\Tools\BookingManager;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BackendRuleTestCase;
use Tests\Support\Fixtures;

final class BookingRulesTest extends BackendRuleTestCase
{
    private function manager(&$repo = null): BookingManager
    {
        $repo = Mockery::mock(BookingRepository::class);
        return new BookingManager($repo, new BookingFactory($repo));
    }

    private function tool() { return $this->identity(Fixtures::tool(), 10); }
    private function time(string $time): Carbon { return Carbon::parse('2025-02-15 '.$time.' UTC'); }
    private function booking($user = null, $tool = null): Booking
    {
        return $this->identity((new Booking)->setStart($this->time('13:00'))->setEnd($this->time('14:00'))
            ->setUser($user ?? $this->user())->setTool($tool ?? $this->tool())->setType('NORMAL'), 20);
    }

    public static function permissionMatrix(): array
    {
        $cases=[];
        foreach ([false,true] as $restricted) foreach ([false,true] as $general) foreach ([false,true] as $specific) {
            $cases["restricted=$restricted general=$general specific=$specific"]=[$restricted,$general,$specific];
        }
        return $cases;
    }

    #[DataProvider('permissionMatrix')]
    public function testPHP08CapabilitySummaryRequiresGeneralAndSpecificPermissions(bool $restricted, bool $general, bool $specific): void
    {
        $manager=$this->manager($repo);$tool=$this->tool();
        if ($restricted) $tool->setRestricted();
        $allowed=[];
        if ($general) $allowed[]='tools.book';
        if ($specific) $allowed[]='tools.testFixture.book';
        $user=$this->user($allowed);
        $repo->shouldReceive('countNormalByToolAndUser')->with($tool,$user)->once()->andReturn(2);
        self::assertSame(['userId'=>1,'normal'=>$general && (!$restricted || $specific), 'normalCurrentCount'=>2,'induction'=>false,'maintenance'=>false],$manager->canUserBookTool($user,$tool));
    }

    public static function bookings(): array
    {
        $cases=[];
        foreach (['bookNormal','bookInduction','bookMaintenance'] as $method) foreach ([false,true] as $restricted) foreach ([false,true] as $permitted) {
            $cases[$method." restricted=$restricted permitted=$permitted"]=[$method,$restricted,$permitted];
        }
        return $cases;
    }

    #[DataProvider('bookings')]
    public function testPHP08PHP09CreatePermissionAndEventMatrix(string $method,bool $restricted,bool $permitted): void
    {
        $manager=$this->manager($repo);$tool=$this->tool();if($restricted)$tool->setRestricted();
        $suffix=['bookNormal'=>'book','bookInduction'=>'book.induction','bookMaintenance'=>'book.maintenance'][$method];
        $user=$this->user($permitted?['tools.book','tools.testFixture.'.$suffix]:[]);
        $allowed=$permitted || (!$restricted && $method!=='bookMaintenance');
        if($allowed){
            if($method==='bookNormal')$repo->shouldReceive('countNormalByToolAndUser')->once()->andReturn(0);
            $repo->shouldReceive('checkForClashByTool')->once()->andReturn([]);
            $repo->shouldReceive('save')->once()->with(Mockery::type(Booking::class));
        }
        $result=$manager->$method($tool,$this->time('13:00'),$this->time('14:00'),$user);
        if(!$allowed){self::assertSame('Must be inducted to book this tool.',$result);self::assertSame([],$this->events);}
        else {self::assertInstanceOf(Booking::class,$result);self::assertSame($user,$result->getUser());self::assertSame($tool,$result->getTool());self::assertCount(1,$this->events);self::assertInstanceOf(NewBooking::class,$this->events[0]);self::assertSame($result,$this->events[0]->booking);}
    }

    public function testPHP08DirectNormalCreationWithoutGeneralPermissionShouldMatchCapabilityDenial(): void
    {
        $manager=$this->manager($repo);$tool=$this->tool();$user=$this->user();
        $repo->shouldReceive('countNormalByToolAndUser')->andReturn(0);
        $repo->shouldReceive('checkForClashByTool')->andReturn([]);
        $repo->shouldReceive('save')->andReturnNull();
        $result=$manager->bookNormal($tool,$this->time('13:00'),$this->time('14:00'),$user);
        self::assertTrue(is_string($result), 'Direct manager call should enforce tools.book consistently with capability summary; got '.get_debug_type($result));
    }

    public static function invalidTimes(): array
    {
        return ['start at tolerance'=>['11:45','13:00','Start date cannot be in the past'],
            'end at tolerance'=>['13:00','11:45','End date cannot be in the past'],
            'reversed'=>['14:00','13:00','End date must be after the start date'],
            'zero'=>['13:00','13:00','Length of booking must be greater than zero!'],
            'over maximum'=>['13:00','15:01','Maximum booking time is 120 minutes for this tool']];
    }

    #[DataProvider('invalidTimes')]
    public function testPHP10InvalidTimesRejectBeforePersistence(string $start,string $end,string $expected): void
    {
        $manager=$this->manager($repo);
        self::assertSame($expected,$manager->bookNormal($this->tool(),$this->time($start),$this->time($end),$this->user(['tools.book'])));
        self::assertSame([],$this->events);
    }

    public static function validTimes(): array
    {
        return ['inside tolerance'=>['11:45:01','12:00'], 'maximum'=>['13:00','15:00'], 'minimum'=>['13:00','13:01']];
    }

    #[DataProvider('validTimes')]
    public function testPHP10ExactAllowedBoundaries(string $start,string $end): void
    {
        $manager=$this->manager($repo);
        $repo->shouldReceive('countNormalByToolAndUser')->once()->andReturn(0);
        $repo->shouldReceive('checkForClashByTool')->once()->andReturn([]);
        $repo->shouldReceive('save')->once();
        self::assertInstanceOf(Booking::class,$manager->bookNormal($this->tool(),$this->time($start),$this->time($end),$this->user(['tools.book'])));
    }

    public static function limits(): array {return ['singular'=>[1,1],'plural'=>[2,2],'above'=>[1,3]];}
    #[DataProvider('limits')]
    public function testPHP08OutstandingLimitRejectsBeforeClashAndSave(int $max,int $count): void
    {
        $manager=$this->manager($repo);$tool=$this->tool()->setBookingsMax($max);
        $repo->shouldReceive('countNormalByToolAndUser')->once()->andReturn($count);
        self::assertSame('You can only have '.$max.' '.($max>1?'bookings':'booking').' for this tool.',$manager->bookNormal($tool,$this->time('13:00'),$this->time('14:00'),$this->user(['tools.book'])));
        self::assertSame([],$this->events);
    }

    public static function methods(): array{return [['bookNormal'],['bookInduction'],['bookMaintenance']];}
    #[DataProvider('methods')]
    public function testPHP11ClashesRejectWithNoWritesOrEvents(string $method): void
    {
        $manager=$this->manager($repo);
        if($method==='bookNormal')$repo->shouldReceive('countNormalByToolAndUser')->once()->andReturn(0);
        $repo->shouldReceive('checkForClashByTool')->once()->andReturn([$this->booking()]);
        self::assertSame('Your booking request clashes with another booking. No booking has been made.',$manager->$method($this->tool(),$this->time('13:00'),$this->time('14:00'),$this->user(['tools.book','tools.testFixture.book.maintenance'])));
        self::assertSame([],$this->events);
    }

    public function testPHP09MaintenanceCanExceedToolMaximumButStillChecksClash(): void
    {
        $manager=$this->manager($repo);$tool=$this->tool()->setLengthMax(30);
        $repo->shouldReceive('checkForClashByTool')->once()->andReturn([]);$repo->shouldReceive('save')->once();
        self::assertSame('MAINTENANCE',$manager->bookMaintenance($tool,$this->time('13:00'),$this->time('23:59'),$this->user(['tools.testFixture.book.maintenance']))->getType());
    }

    public function testPHP12WrongOwnerAndToolLeaveBookingUnchanged(): void
    {
        $manager=$this->manager($repo);$booking=$this->booking();$before=$booking->toArray();
        $this->actingAs($this->user(id:2));
        self::assertSame('This is not your booking.',$manager->update($this->tool(),$booking));
        $this->actingAs($booking->getUser());
        $other=$this->identity(Fixtures::tool('other'),11);
        self::assertSame('This booking is not for this tool.',$manager->update($other,$booking));
        self::assertSame($before,$booking->toArray());self::assertSame([],$this->events);
    }

    public function testPHP12UnchangedOngoingStartCanBeExtended(): void
    {
        $manager=$this->manager($repo);$booking=$this->booking()->setStart($this->time('11:30'))->setEnd($this->time('12:30'));
        $this->actingAs($booking->getUser());$repo->shouldReceive('save')->with($booking)->once();
        self::assertSame($booking,$manager->update($booking->getTool(),$booking,end:$this->time('13:00')));
        self::assertCount(1,$this->events);self::assertInstanceOf(BookingChanged::class,$this->events[0]);
    }

    public function testPHP12UpdateMustCheckClashBeforeSaving(): void
    {
        $manager=$this->manager($repo);$booking=$this->booking();$this->actingAs($booking->getUser());
        $repo->shouldReceive('checkForClashByTool')->andReturn([$this->booking()]);$repo->shouldReceive('save')->andReturnNull();
        $result=$manager->update($booking->getTool(),$booking,$this->time('14:00'),$this->time('15:00'));
        self::assertTrue(is_string($result),'DEF-01: overlapping update must be rejected; got '.get_debug_type($result));
    }

    public function testPHP15ChangedEventMustRetainIndependentOriginalValues(): void
    {
        $manager=$this->manager($repo);$booking=$this->booking();$old=$booking->getStart()->toAtomString();
        $this->actingAs($booking->getUser());$repo->shouldReceive('save')->once();
        $manager->update($booking->getTool(),$booking,$this->time('14:00'),$this->time('15:00'));
        self::assertSame($old,$this->events[0]->orignalBooking->getStart()->toAtomString(),'DEF-02: before and after must not alias');
    }

    public function testPHP13CancellationEnforcesOwnerAndPreservesRemovedIdentity(): void
    {
        $manager=$this->manager($repo);$booking=$this->booking();$this->actingAs($this->user(id:2));
        self::assertSame('This is not your booking.',$manager->cancel($booking));self::assertSame([],$this->events);
        $this->actingAs($booking->getUser());
        $repo->shouldReceive('remove')->with($booking)->once()->andReturnUsing(fn($b)=>$this->identity($b,0));
        self::assertSame(['bookingId'=>20,'toolId'=>10],$manager->cancel($booking));
        self::assertSame(['bookingId'=>20,'toolId'=>10],$this->events[0]->broadcastWith());
        self::assertInstanceOf(BookingCancelled::class,$this->events[0]);
    }
    public static function dstWindows(): array {return [['2025-03-30 00:30','2025-03-30 02:30',true],['2025-10-26 00:30','2025-10-26 02:30',false]];}
    #[DataProvider('dstWindows')]
    public function testPHP10LondonDstUsesElapsedMinutes(string $start,string $end,bool $valid): void
    {
        $manager=$this->manager($repo);
        if($valid){$repo->shouldReceive('countNormalByToolAndUser')->once()->andReturn(0);$repo->shouldReceive('checkForClashByTool')->once()->andReturn([]);$repo->shouldReceive('save')->once();}
        $result=$manager->bookNormal($this->tool(),Carbon::parse($start,'Europe/London'),Carbon::parse($end,'Europe/London'),$this->user(['tools.book']));
        if($valid)self::assertInstanceOf(Booking::class,$result);else self::assertSame('Maximum booking time is 120 minutes for this tool',$result);
    }

    public function testPHP12OngoingStartExceptionMustStillRejectEndBeforeStart(): void
    {
        $manager=$this->manager($repo);$booking=$this->booking()->setStart($this->time('11:30'))->setEnd($this->time('12:30'));
        $this->actingAs($booking->getUser());$repo->shouldReceive('save')->andReturnNull();
        $result=$manager->update($booking->getTool(),$booking,end:$this->time('11:00'));
        self::assertTrue(is_string($result),'Ongoing-booking tolerance must not bypass reversed/end-in-past checks; got '.get_debug_type($result));
    }

    public function testPHP09OmittedUserUsesAuthenticatedCallerForEachBookingType(): void
    {
        $manager=$this->manager($repo);$user=$this->user(['tools.book','tools.testFixture.book.induction','tools.testFixture.book.maintenance']);
        $this->actingAs($user);
        $repo->shouldReceive('countNormalByToolAndUser')->once()->andReturn(0);
        $repo->shouldReceive('checkForClashByTool')->times(3)->andReturn([]);$repo->shouldReceive('save')->times(3);
        foreach(['bookNormal','bookInduction','bookMaintenance'] as $method){
            self::assertSame($user,$manager->$method($this->tool(),$this->time('13:00'),$this->time('14:00'))->getUser());
        }
    }

    public function testPHP12OmittedEndUsesOriginalAndInvalidChangesDoNotSave(): void
    {
        $manager=$this->manager($repo);$booking=$this->booking();$this->actingAs($booking->getUser());
        $repo->shouldReceive('save')->once();
        self::assertSame($booking,$manager->update($booking->getTool(),$booking,start:$this->time('13:15')));
        self::assertSame('14:00',$booking->getEnd()->format('H:i'));
        self::assertSame('End date must be after the start date',$manager->update($booking->getTool(),$booking,$this->time('15:00'),$this->time('14:00')));
        self::assertCount(1,$this->events);
    }

}
