<?php

namespace Tests\Unit\Tools;

use App\Events\Tools\BookingCancelled;
use App\Mail\Tools\BookingCancelledForMaintenance;
use Carbon\Carbon;
use HMS\Entities\Tools\Booking;
use HMS\Factories\Tools\BookingFactory;
use HMS\Repositories\Tools\BookingRepository;
use HMS\Tools\BookingManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{BackendRuleTestCase,Fixtures};

/** PR #645 manager boundary: no kernel, database, queue worker or mail transport. */
final class MaintainerCancellationTest extends BackendRuleTestCase
{
    private function booking(string $type='NORMAL', string $toolName='fixture'): Booking
    {
        return $this->identity((new Booking)->setTool($this->identity(Fixtures::tool($toolName),10))
            ->setUser($this->user(id:1))->setType($type)
            ->setStart(Carbon::parse('2025-02-15 13:00 UTC'))->setEnd(Carbon::parse('2025-02-15 14:00 UTC')),20);
    }

    private function manager(&$repo): BookingManager
    {
        $repo=Mockery::mock(BookingRepository::class);
        return new BookingManager($repo,new BookingFactory($repo));
    }

    private function mailRecorder(Booking $booking, &$messages, bool $fail=false): void
    {
        $messages=[];
        $mail=Mockery::mock(\Illuminate\Mail\MailManager::class);
        $mail->shouldReceive('to')->with($booking->getUser())->andReturnUsing(function() use ($booking,&$messages,$fail) {
            $pending=Mockery::mock(\Illuminate\Mail\PendingMail::class);
            $pending->shouldReceive('send')->once()->withArgs(function($message) use ($booking) {
                self::assertInstanceOf(BookingCancelledForMaintenance::class,$message);
                self::assertInstanceOf(ShouldQueue::class,$message);
                self::assertSame($booking->getUser()->getFullname(),$message->fullName);
                self::assertSame($booking->getTool()->getName(),$message->toolName);
                return true;
            })->andReturnUsing(function($message) use (&$messages,$fail) {
                if($fail)throw new \RuntimeException('synthetic queue failure');
                $messages[]=$message;
            });
            return $pending;
        });
        Mail::swap($mail);
    }

    public static function denied(): array
    {
        return ['ordinary'=>[[]],'global booking only'=>[['tools.book']],
            'inductor'=>[['tools.testFixture.book.induction']],
            'other tool maintainer'=>[['tools.other.book.maintenance']]];
    }

    #[DataProvider('denied')]
    public function testMC01OtherMemberNeedsThisToolsMaintenancePermission(array $permissions): void
    {
        $booking=$this->booking();$manager=$this->manager($repo);
        $this->actingAs($this->user($permissions,2));
        // No mail binding: an unexpected mail call is an immediate failure here.
        self::assertSame('This is not your booking and you are not a maintainer.',$manager->cancel($booking));
        self::assertSame([],$this->events);self::assertSame(20,$booking->getId());
    }

    public static function owners(): array {return [[false],[true]];}
    #[DataProvider('owners')]
    public function testMC01OwnCancellationNeverNotifiesEvenForMaintainer(bool $maintainer): void
    {
        $booking=$this->booking();$manager=$this->manager($repo);
        $this->actingAs($this->user($maintainer?['tools.testFixture.book.maintenance']:[],1));
        $repo->shouldReceive('remove')->with($booking)->once()->andReturnUsing(fn($b)=>$this->identity($b,0));
        self::assertSame(['bookingId'=>20,'toolId'=>10],$manager->cancel($booking));
        self::assertCount(1,$this->events);self::assertInstanceOf(BookingCancelled::class,$this->events[0]);
        self::assertSame(['bookingId'=>20,'toolId'=>10],$this->events[0]->broadcastWith());
    }

    public static function maintained(): array
    {
        return [['NORMAL','fixture','testFixture'],['INDUCTION','fixture','testFixture'],
            ['MAINTENANCE','fixture','testFixture'],['NORMAL','bench_press','testBenchPress']];
    }

    #[DataProvider('maintained')]
    public function testMC02MaintainerCancelsAndNotifiesOwnerOnce(string $type,string $name,string $permission): void
    {
        $booking=$this->booking($type,$name);$manager=$this->manager($repo);
        $this->actingAs($this->user(['tools.'.$permission.'.book.maintenance'],2));
        $this->mailRecorder($booking,$messages);
        $repo->shouldReceive('remove')->with($booking)->once()->andReturnUsing(fn($b)=>$this->identity($b,0));
        self::assertSame(['bookingId'=>20,'toolId'=>10],$manager->cancel($booking));
        self::assertCount(1,$messages);self::assertCount(1,$this->events);
        self::assertSame(['bookingId'=>20,'toolId'=>10],$this->events[0]->broadcastWith());
    }

    public function testMC03QueueFailurePreservesBookingAndDoesNotPublishCancellation(): void
    {
        $booking=$this->booking();$manager=$this->manager($repo);
        $this->actingAs($this->user(['tools.testFixture.book.maintenance'],2));
        $this->mailRecorder($booking,$messages,true);
        try {$manager->cancel($booking);self::fail('Expected queue rejection');}
        catch(\RuntimeException $error){self::assertSame('synthetic queue failure',$error->getMessage());}
        self::assertSame([],$messages);self::assertSame([],$this->events);self::assertSame(20,$booking->getId());
    }

    public function testMC03DEF18FailedRemovalMustNotAnnounceSuccessfulCancellation(): void
    {
        $booking=$this->booking();$manager=$this->manager($repo);
        $this->actingAs($this->user(['tools.testFixture.book.maintenance'],2));
        $this->mailRecorder($booking,$messages);
        $repo->shouldReceive('remove')->with($booking)->once()->andThrow(new \RuntimeException('synthetic deletion failure'));
        try {$manager->cancel($booking);self::fail('Expected removal rejection');}
        catch(\RuntimeException $error){self::assertSame('synthetic deletion failure',$error->getMessage());}
        self::assertSame(20,$booking->getId());self::assertSame([],$this->events);
        self::assertSame([],$messages,'DEF-18: cancellation was announced before failed removal');
    }
}
