<?php

namespace Tests\Unit\Tools;

use App\Events\Tools\{BookingCancelled,BookingChanged,NewBooking};
use App\Listeners\Tools\NotifyNhToolsSubscriber;
use Carbon\Carbon;
use HMS\Actions\Statistics\GenerateToolStatistics;
use HMS\Entities\Tools\{Booking,Usage};
use HMS\Entities\Role;
use HMS\Factories\Tools\UsageFactory;
use HMS\Prometheus\Collectors\Statistics\ToolUsageCollector;
use HMS\Repositories\{RoleRepository};
use HMS\Repositories\Tools\{ToolRepository,BookingRepository,UsageRepository};
use Illuminate\Events\CallQueuedListener;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{BackendRuleTestCase,Fixtures};

final class ReportingContractsTest extends BackendRuleTestCase
{
    public static function durations(): array {return ['award'=>[-3600],'positive'=>[3600],'zero'=>[0]];}
    #[DataProvider('durations')]
    public function testPHP16FreeTimeFactoryKeepsSignedSecondsWithoutSaving(int $duration): void
    {
        $repo=Mockery::mock(UsageRepository::class);$tool=Fixtures::tool();$user=Fixtures::user();
        $usage=(new UsageFactory($repo))->createFreeTime($tool,$user,$duration);
        self::assertSame([$duration,0,'COMPLETE',$tool,$user],[$usage->getDuration(),$usage->getActiveTime(),$usage->getStatus(),$usage->getTool(),$usage->getUser()]);
        self::assertSame('2025-02-15T12:00:00+00:00',$usage->getStart()->toAtomString());
    }

    public function testPHP15BookingJsonBroadcastChannelAndQueuedRoundTripUseStableIdentity(): void
    {
        $tool=$this->identity(Fixtures::tool(),10);$user=$this->identity(Fixtures::user(),1);
        $booking=$this->identity((new Booking)->setTool($tool)->setUser($user)->setType('NORMAL')
            ->setStart(Carbon::parse('2025-07-01 13:00 Europe/London'))->setEnd(Carbon::parse('2025-07-01 14:00 Europe/London')),20);
        $expected=['id'=>20,'start'=>'2025-07-01T13:00:00+01:00','end'=>'2025-07-01T14:00:00+01:00',
            'title'=>'Synthetic Member','type'=>'NORMAL','toolId'=>10,'toolDisplayName'=>'Synthetic fixture','userId'=>1];
        self::assertSame($expected,$booking->toArray());
        self::assertSame($expected,json_decode(json_encode($booking,JSON_THROW_ON_ERROR),true,flags:JSON_THROW_ON_ERROR));
        foreach([new NewBooking($booking),new BookingChanged(clone $booking,$booking),new BookingCancelled($tool,20)] as $event){
            self::assertSame('tools.10.bookings',$event->broadcastOn()->name);
            $restored=unserialize(serialize($event),['allowed_classes'=>true]);
            self::assertSame('tools.10.bookings',$restored->broadcastOn()->name);
        }
        $job=new CallQueuedListener(NotifyNhToolsSubscriber::class,'newBooking',[new NewBooking($booking)]);
        $copy=unserialize(serialize($job),['allowed_classes'=>true]);
        self::assertSame(NotifyNhToolsSubscriber::class,$copy->class);
        self::assertSame('newBooking',$copy->method);
        self::assertSame($expected,$copy->data[0]->booking->toArray());
        self::assertSame([], $this->events,'Serialization does not dispatch or execute the queued MQTT job');
    }

    public function testPHP18NotifierRegistrationMatchesEventContractsWithoutExecutingTransport(): void
    {
        self::assertSame([NewBooking::class=>'newBooking',BookingChanged::class=>'bookingChanged',BookingCancelled::class=>'bookingCancelled'],(new NotifyNhToolsSubscriber)->subscribe(null));
    }

    public function testPHP17StatisticsCountCurrentMembersAndSignedUsageAndMonthWindows(): void
    {
        $tools=Mockery::mock(ToolRepository::class);$roles=Mockery::mock(RoleRepository::class);
        $bookings=Mockery::mock(BookingRepository::class);$usages=Mockery::mock(UsageRepository::class);
        $tool=Fixtures::tool();$tools->shouldReceive('findAll')->once()->andReturn([$tool]);
        $current=Fixtures::user();$current->getRoles()->add(Fixtures::role(Role::MEMBER_CURRENT));
        $former=Fixtures::user('former');
        foreach(['user','inductor','maintainer'] as $type){
            $role=Fixtures::role('tools.testFixture.'.$type);
            // Doctrine initializes this inverse collection; the pure unit fixture has no ORM.
            (new \ReflectionProperty($role,'users'))->setValue($role,new \Doctrine\Common\Collections\ArrayCollection);
            $role->getUsers()->add($current);
            if($type==='user')$role->getUsers()->add($former);
            $roles->shouldReceive('findOneByName')->with('tools.testFixture.'.$type)->once()->andReturn($role);
        }
        $booking=(new Booking)->setStart(Carbon::parse('2025-02-01 12:00'))->setEnd(Carbon::parse('2025-02-01 13:30'));
        $positive=(new Usage)->setDuration(3661);$negative=(new Usage)->setDuration(-3600);$zero=(new Usage)->setDuration(0);
        foreach(['2025-02'=>[$booking],'2025-01'=>[]] as $month=>$rows){
            $bookings->shouldReceive('findByToolForMonth')->with($tool,Mockery::on(fn($d)=>$d->format('Y-m')===$month))->once()->andReturn($rows);
            $usages->shouldReceive('findByToolForMonth')->with($tool,Mockery::on(fn($d)=>$d->format('Y-m')===$month))->once()->andReturn($month==='2025-02'?[$positive,$negative,$zero]:[]);
        }
        self::assertSame(['Synthetic fixture'=>['userCount'=>1,'inductorCount'=>1,'maintainerCount'=>1,'bookedThisMonth'=>'01:30:00','bookedLastMonth'=>'00:00:00','usedThisMonth'=>'01:01:01','usedLastMonth'=>'00:00:00']],(new GenerateToolStatistics)->generateToolStats($tools,$roles,$bookings,$usages));
    }

    public function testPHP17MetricStateValuesLabelsRoleCountsAndMissingStatistics(): void
    {
        $repo=Mockery::mock(ToolRepository::class);$tools=[];
        foreach(['DISABLED','FREE','IN_USE','UNKNOWN'] as $n=>$state)$tools[]=Fixtures::tool('state'.$n)->setStatus($state);
        $repo->shouldReceive('findAll')->twice()->andReturn($tools);
        $collector=new ToolUsageCollector;
        self::assertSame([[-1,['test_state0']],[0,['test_state1']],[1,['test_state2']],[-1,['test_state3']]],$collector->getValueState($repo));
        $action=Mockery::mock(GenerateToolStatistics::class);
        $action->shouldReceive('execute')->once()->andReturn(['Synthetic state1'=>['userCount'=>2,'inductorCount'=>1,'maintainerCount'=>3]]);
        self::assertSame([[2,['test_state1','user']],[1,['test_state1','inductor']],[3,['test_state1','maintainer']]],$collector->getValueUsers($repo,$action));
    }
}
