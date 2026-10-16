<?php

namespace Tests\Integration\Tools\Backend;

use App\Events\Roles\{RoleCreated,UserAddedToRole,UserRemovedFromRole};
use App\Events\Tools\{NewBooking,BookingChanged,BookingCancelled};
use Carbon\Carbon;
use HMS\Actions\Statistics\GenerateToolStatistics;
use HMS\Entities\{Role,Tools\Booking,Tools\Usage};
use HMS\Factories\Tools\UsageFactory;
use HMS\Prometheus\Collectors\Statistics\ToolUsageCollector;
use HMS\Repositories\Tools\{BookingRepository,ToolRepository,UsageRepository};
use HMS\Tools\{ToolManager,BookingManager};
use Illuminate\Support\Facades\{Cache,DB,Event};
use Tests\Support\{BackendLifecycleTestCase,Fixtures};

final class ReportingWorkflowTest extends BackendLifecycleTestCase
{
    public function testPHP12PHP13PHP15RealBookingWorkflowPersistsAndPublishesRecordedContracts(): void
    {
        $this->recordEvents([NewBooking::class,BookingChanged::class,BookingCancelled::class]);
        $this->actingAs($this->memberEntity);
        $manager=$this->app->make(BookingManager::class);
        $booking=$manager->bookNormal($this->toolEntity,$this->time('2025-02-15 13:00'),$this->time('2025-02-15 14:00'),$this->memberEntity);
        self::assertInstanceOf(Booking::class,$booking);$id=$booking->getId();
        self::assertTrue(DB::table('bookings')->where('id',$id)->exists());
        $manager->update($this->toolEntity,$booking,$this->time('2025-02-15 15:00'),$this->time('2025-02-15 16:00'));
        self::assertSame('2025-02-15 15:00:00',DB::table('bookings')->where('id',$id)->value('start'));
        Event::assertDispatched(NewBooking::class,fn($e)=>$e->booking->getId()===$id);
        Event::assertDispatched(BookingChanged::class,fn($e)=>$e->booking->getId()===$id);
        self::assertSame(['bookingId'=>$id,'toolId'=>$this->tool],$manager->cancel($booking));
        self::assertFalse(DB::table('bookings')->where('id',$id)->exists());
        Event::assertDispatched(BookingCancelled::class,fn($e)=>$e->broadcastWith()===['bookingId'=>$id,'toolId'=>$this->tool]);
        self::assertSame([], $this->app->make(BookingRepository::class)->findFutureByUser($this->memberEntity));
    }

    public function testPHP17RealStatisticsCacheExpiryCountsAndCollectorLabels(): void
    {
        $this->recordEvents([RoleCreated::class,UserAddedToRole::class,UserRemovedFromRole::class]);
        // Remove the base fixture: statistics requires complete tool-role sets, not orphan fixtures.
        $this->em->remove($this->toolEntity);$this->em->flush();
        $tool=$this->createTool();$manager=$this->app->make(ToolManager::class);
        $manager->create('empty','Synthetic empty',false,0,30,120);
        $current=Fixtures::role(Role::MEMBER_CURRENT);$this->persist($current);
        $this->memberEntity->getRoles()->add($current);$this->persist($this->memberEntity);
        foreach(['USER','INDUCTOR','MAINTAINER'] as $type)$manager->grant($tool,$type,$this->memberEntity);
        $former=Fixtures::user('former');$this->persist($former);$manager->grant($tool,'USER',$former);
        $this->makeBooking('2025-02-01 12:00','2025-02-01 13:30',tool:$tool);
        $this->makeBooking('2025-01-15 12:00','2025-01-15 13:00',tool:$tool);
        $repo=$this->app->make(UsageRepository::class);$factory=new UsageFactory($repo);
        foreach([['2025-02-01 12:00',1800],['2025-02-01 13:00',-3600],['2025-01-15 12:00',3661]] as [$start,$duration]){
            $usage=$factory->createFreeTime($tool,$this->memberEntity,$duration)->setStart($this->time($start));$repo->save($usage);
        }
        $this->em->clear();Cache::forget('statistics.tools');
        $action=$this->app->make(GenerateToolStatistics::class);$stats=$action->execute();
        self::assertSame(['userCount'=>1,'inductorCount'=>1,'maintainerCount'=>1,'bookedThisMonth'=>'01:30:00','bookedLastMonth'=>'01:00:00','usedThisMonth'=>'00:30:00','usedLastMonth'=>'01:01:01'],$stats['Synthetic lifecycle']);
        self::assertSame(['userCount'=>0,'inductorCount'=>0,'maintainerCount'=>0,'bookedThisMonth'=>'00:00:00','bookedLastMonth'=>'00:00:00','usedThisMonth'=>'00:00:00','usedLastMonth'=>'00:00:00'],$stats['Synthetic empty']);
        $usage=$factory->createFreeTime($this->em->find(\HMS\Entities\Tools\Tool::class,$tool->getId()),$this->em->find(\HMS\Entities\User::class,$this->user),600);$repo->save($usage);
        self::assertSame($stats,$action->execute(),'Six-hour cache must retain the previous computed value');
        Carbon::setTestNow(Carbon::parse('2025-02-15 18:00:01 UTC'));
        self::assertSame('00:40:00',$action->execute()['Synthetic lifecycle']['usedThisMonth']);
        $collector=new ToolUsageCollector;
        self::assertContains([-1,['spike']],$collector->getValueState($this->app->make(ToolRepository::class)));
        self::assertContains([1,['spike','user']],$collector->getValueUsers($this->app->make(ToolRepository::class),$action));
    }

    public function testPHP18ProductionEventRegistrationsRemainPresentBeforeRecording(): void
    {
        foreach([NewBooking::class,BookingChanged::class,BookingCancelled::class,UserAddedToRole::class,UserRemovedFromRole::class] as $event)self::assertTrue($this->app['events']->hasListeners($event),$event);
        self::assertContains(\App\Listeners\Tools\NotifyNhToolsSubscriber::class,
            (new \ReflectionProperty(\App\Providers\EventServiceProvider::class,'subscribe'))->getValue($this->app->getProvider(\App\Providers\EventServiceProvider::class)));
    }
}
