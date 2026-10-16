<?php

namespace Tests\Feature\Tools;

use App\Events\Tools\BookingCancelled;
use App\Mail\Tools\BookingCancelledForMaintenance;
use HMS\Entities\User;
use Illuminate\Mail\{MailManager,SendQueuedMailable};
use Illuminate\Support\Facades\{DB,Event,Mail,Queue};
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{Fixtures,HttpTestCase};

final class MaintainerCancellationHttpTest extends HttpTestCase
{
    private function recordingMail()
    {
        $this->bootHttp();
        return Mail::fake(); // scoped to this disposable application; no real transport.
    }

    private function otherBooking(string $type='NORMAL')
    {
        $id=$this->member('cancel_owner');
        return $this->makeBooking('2025-02-15 13:00','2025-02-15 14:00',$type,user:$this->em->find(User::class,$id));
    }

    public static function denied(): array
    {
        return ['ordinary'=>[['tools.book']],'inductor'=>[['tools.book','tools.testFixture.book.induction']],
            'other maintainer'=>[['tools.book','tools.spike.book.maintenance']],
            'missing global'=>[['tools.testFixture.book.maintenance']],'guest'=>[['tools.book','tools.testFixture.book.maintenance'],'guest']];
    }

    #[DataProvider('denied')]
    public function testMC04ActualDeletePermissionDenialHasNoWritesEventsOrMail(array $permissions,string $auth='api'): void
    {
        $this->allow($permissions);$booking=$this->otherBooking();$id=$booking->getId();$this->recordingMail();
        $this->request('DELETE',$this->apiPath(booking:$id),auth:$auth)->assertStatus($auth==='guest'?401:403);
        self::assertTrue(DB::table('bookings')->where('id',$id)->exists());
        Event::assertNotDispatched(BookingCancelled::class);Mail::assertNothingOutgoing();
    }

    public static function types(): array{return [['NORMAL'],['INDUCTION'],['MAINTENANCE']];}
    #[DataProvider('types')]
    public function testMC04RealDeleteQueuesExactlyOneOwnerNotificationAndIsNotRepeatable(string $type): void
    {
        $this->allow(['tools.book','tools.testFixture.book.maintenance']);$booking=$this->otherBooking($type);
        $id=$booking->getId();$email=$booking->getUser()->getEmail();$this->recordingMail();
        $this->request('DELETE',$this->apiPath(booking:$id))->assertNoContent();
        self::assertFalse(DB::table('bookings')->where('id',$id)->exists());
        Event::assertDispatchedTimes(BookingCancelled::class,1);
        Event::assertDispatched(BookingCancelled::class,fn($e)=>$e->broadcastWith()===['bookingId'=>$id,'toolId'=>$this->tool]);
        Mail::assertQueued(BookingCancelledForMaintenance::class,fn($m)=>$m->hasTo($email)&&count($m->to)===1&&$m->cc===[]&&$m->bcc===[]);
        Mail::assertQueuedCount(1);Mail::assertNothingSent();
        $this->request('DELETE',$this->apiPath(booking:$id))->assertNotFound();
        Mail::assertQueuedCount(1);Event::assertDispatchedTimes(BookingCancelled::class,1);
        self::assertSame(0,DB::table('transactions')->count());self::assertSame(0,DB::table('tool_usages')->count());
    }

    public static function owners(): array{return [[false],[true]];}
    #[DataProvider('owners')]
    public function testMC04OwnDeleteDoesNotQueueMail(bool $maintainer): void
    {
        $this->allow($maintainer?['tools.book','tools.testFixture.book.maintenance']:['tools.book']);
        $booking=$this->makeBooking('2025-02-15 13:00','2025-02-15 14:00');$id=$booking->getId();$this->recordingMail();
        $this->request('DELETE',$this->apiPath(booking:$id))->assertNoContent();Mail::assertNothingOutgoing();
        self::assertFalse(DB::table('bookings')->where('id',$id)->exists());
    }

    public function testMC04WrongToolBindingRejectsEvenMaintainerOfBothTools(): void
    {
        $this->allow(['tools.book','tools.testFixture.book.maintenance','tools.spike.book.maintenance']);
        $booking=$this->otherBooking();$id=$booking->getId();$this->recordingMail();
        $this->request('DELETE',$this->apiPath($this->routedTool,$id))->assertForbidden();
        self::assertTrue(DB::table('bookings')->where('id',$id)->exists());Mail::assertNothingOutgoing();Event::assertNotDispatched(BookingCancelled::class);
    }

    public function testMC05ActualMailerQueuesSerializableJobWithoutDelivering(): void
    {
        $this->allow(['tools.book','tools.testFixture.book.maintenance']);$booking=$this->otherBooking();
        $id=$booking->getId();$email=$booking->getUser()->getEmail();$name=$booking->getUser()->getFullname();
        $this->bootHttp();Queue::fake();Mail::swap(new MailManager($this->app));
        $this->request('DELETE',$this->apiPath(booking:$id))->assertNoContent();
        Queue::assertPushed(SendQueuedMailable::class,function($job) use ($email,$name) {
            $copy=unserialize(serialize($job));$mail=$copy->mailable;
            self::assertInstanceOf(BookingCancelledForMaintenance::class,$mail);
            self::assertSame($name,$mail->fullName);self::assertSame('test_fixture',$mail->toolName);
            self::assertTrue($mail->hasTo($email));self::assertCount(1,$mail->to);
            return true;
        });
        Queue::assertPushed(SendQueuedMailable::class,1);Queue::assertCount(1);
    }

    public function testMC05ActualTemplateRendersEscapedSyntheticNamesAndBranding(): void
    {
        $this->bootHttp();config(['branding.space_name'=>'Synthetic space','branding.community_name'=>'Synthetic community']);
        // Rendering resolves Laravel's mailer service. Permit only the local array
        // mailer here; queue/bus guards and independent network denial remain.
        self::assertSame('array',config('mail.default'));Mail::swap(new MailManager($this->app));
        $owner=Fixtures::user('cancel_markup')->setFirstname('<em>Owner</em>');
        $mail=new BookingCancelledForMaintenance($owner,Fixtures::tool('mail')->setName('tool_<strong>name</strong>'));
        $mail=unserialize(serialize($mail));$html=$mail->render();
        self::assertSame('Synthetic space: tool_<strong>name</strong> booking cancelled',$mail->subject);
        self::assertStringContainsString('Synthetic community',$html);
        self::assertStringContainsString('cancelled due to an issue with the tool',$html);
        self::assertStringContainsString('&lt;em&gt;Owner&lt;/em&gt;',$html);
        self::assertStringContainsString('&lt;strong&gt;name&lt;/strong&gt;',$html);
        self::assertStringNotContainsString('<em>Owner</em>',$html);
        self::assertStringNotContainsString('<strong>name</strong>',$html);
    }

    public function testMC03QueueFailureReturnsClientErrorWithoutDeleting(): void
    {
        $this->allow(['tools.book','tools.testFixture.book.maintenance']);$booking=$this->otherBooking();$id=$booking->getId();$this->bootHttp();
        $pending=Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $pending->shouldReceive('send')->once()->with(Mockery::type(BookingCancelledForMaintenance::class))->andThrow(new \RuntimeException('synthetic queue failure'));
        Mail::shouldReceive('to')->once()->andReturn($pending);
        $this->request('DELETE',$this->apiPath(booking:$id))->assertStatus(500);
        self::assertTrue(DB::table('bookings')->where('id',$id)->exists());Event::assertNotDispatched(BookingCancelled::class);
    }

    public function testMC03DEF18RealDeletionFailureMustNotQueueSuccessNotice(): void
    {
        $this->allow(['tools.book','tools.testFixture.book.maintenance']);$booking=$this->otherBooking();$id=$booking->getId();$this->recordingMail();
        $this->execute("CREATE TRIGGER test_cancel_failure BEFORE DELETE ON bookings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic deletion failure'");
        $this->request('DELETE',$this->apiPath(booking:$id))->assertStatus(500);
        self::assertTrue(DB::table('bookings')->where('id',$id)->exists());Event::assertNotDispatched(BookingCancelled::class);
        Mail::assertNothingOutgoing(); // DEF-18: queued success notification precedes the failed ORM flush.
    }

    public function testMC06OtherMembersCancellationDoesNotRelaxServerPersonalBookingLimit(): void
    {
        $this->allow(['tools.book','tools.testFixture.book.maintenance']);$this->toolEntity->setUnRestricted()->setBookingsMax(2);$this->em->flush();
        $this->makeBooking('2025-02-15 15:00','2025-02-15 16:00');$this->makeBooking('2025-02-15 17:00','2025-02-15 18:00');
        $other=$this->otherBooking();$id=$other->getId();$this->recordingMail();
        $this->request('DELETE',$this->apiPath(booking:$id))->assertNoContent();
        $this->request('POST',$this->apiPath(),$this->bookingData())->assertUnprocessable();
        self::assertSame(2,DB::table('bookings')->where('user_id',$this->user)->count());Mail::assertQueuedCount(1);
    }
}
