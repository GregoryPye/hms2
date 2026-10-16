<?php

namespace Tests\Integration\Tools\Backend;

use Carbon\Carbon;
use HMS\Entities\{User,Tools\Booking,Tools\Tool,Tools\Usage};
use HMS\Repositories\Tools\{BookingRepository,UsageRepository};
use HMS\Factories\Tools\UsageFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{BackendDatabaseTestCase,Fixtures};

final class RepositoryTest extends BackendDatabaseTestCase
{
    private function ids(array $rows): array {return array_map(fn($r)=>$r->getId(),$rows);}
    public static function overlaps(): array
    {
        return ['adjacent before'=>['12:00','13:00',false],'adjacent after'=>['14:00','15:00',false],
            'partial before'=>['12:30','13:30',true],'partial after'=>['13:30','14:30',true],
            'contained'=>['13:10','13:50',true],'covers'=>['12:00','15:00',true],'identical'=>['13:00','14:00',true]];
    }
    #[DataProvider('overlaps')]
    public function testPHP11RealClashIntervalsAndToolIsolation(string $start,string $end,bool $clash): void
    {
        $stored=$this->makeBooking('2025-02-15 13:00','2025-02-15 14:00');
        $other=Fixtures::tool('other');$this->persist($other);
        $repo=$this->app->make(BookingRepository::class);
        self::assertSame($clash?[$stored->getId()]:[], $this->ids($repo->checkForClashByTool($this->toolEntity,$this->time('2025-02-15 '.$start),$this->time('2025-02-15 '.$end))));
        self::assertSame([],$repo->checkForClashByTool($other,$this->time('2025-02-15 '.$start),$this->time('2025-02-15 '.$end)));
    }

    public function testPHP14CurrentNextCountFutureAndTypedQueriesUseRealOrmBoundaries(): void
    {
        $past=$this->makeBooking('2025-02-15 10:00','2025-02-15 11:00');
        $ongoing=$this->makeBooking('2025-02-15 11:30','2025-02-15 12:00');
        $now=$this->makeBooking('2025-02-15 12:00','2025-02-15 12:30');
        $future=$this->makeBooking('2025-02-15 12:30','2025-02-15 13:00');
        $induction=$this->makeBooking('2025-02-15 14:00','2025-02-15 15:00','INDUCTION');
        $maintenance=$this->makeBooking('2025-02-15 15:00','2025-02-15 16:00','MAINTENANCE');
        $other=Fixtures::tool('other');$this->persist($other);
        $otherBooking=$this->makeBooking('2025-02-15 12:00','2025-02-15 13:00',tool:$other);
        $different=Fixtures::user('different');$this->persist($different);
        $this->makeBooking('2025-02-15 18:00','2025-02-15 19:00',user:$different);
        $repo=$this->app->make(BookingRepository::class);
        self::assertSame($ongoing->getId(),$repo->currentForTool($this->toolEntity)->getId());
        self::assertSame($now->getId(),$repo->nextForTool($this->toolEntity)->getId());
        self::assertSame(3,$repo->countNormalByToolAndUser($this->toolEntity,$this->memberEntity));
        self::assertEqualsCanonicalizing([$ongoing->getId(),$now->getId(),$future->getId(),$induction->getId(),$maintenance->getId(),$otherBooking->getId()],$this->ids($repo->findFutureByUser($this->memberEntity)));
        self::assertSame([$past->getId(),$ongoing->getId(),$now->getId(),$future->getId(),$induction->getId(),$maintenance->getId()],$this->ids($repo->findByToolAndUser($this->toolEntity,$this->memberEntity)));
        self::assertSame([$induction->getId()],$this->ids($repo->findInductionByTool($this->toolEntity)));
        self::assertSame([$maintenance->getId()],$this->ids($repo->findMaintenanceByTool($this->toolEntity)));
        self::assertCount(5,$repo->findNormalByTool($this->toolEntity));
        self::assertCount(7,$repo->findByTool($this->toolEntity));
        $removedId=$future->getId();$repo->remove($future);
        self::assertSame(2,$repo->countNormalByToolAndUser($this->toolEntity,$this->memberEntity));
        self::assertFalse(DB::table('bookings')->where('id',$removedId)->exists());
    }

    public function testPHP14BetweenAndDayWeekMonthQueriesAreInclusiveAndToolScoped(): void
    {
        $first=$this->makeBooking('2025-02-15 00:00','2025-02-15 01:00');
        $last=$this->makeBooking('2025-02-15 23:00','2025-02-16 00:00');
        $week=$this->makeBooking('2025-02-10 12:00','2025-02-10 13:00');
        $month=$this->makeBooking('2025-02-01 12:00','2025-02-01 13:00');
        $this->makeBooking('2025-03-01 12:00','2025-03-01 13:00');
        $other=Fixtures::tool('other');$this->persist($other);$this->makeBooking('2025-02-15 12:00','2025-02-15 13:00',tool:$other);
        $repo=$this->app->make(BookingRepository::class);$day=$this->time('2025-02-15 12:00');
        self::assertSame([$first->getId(),$last->getId()],$this->ids($repo->findByToolForDay($this->toolEntity,$day)));
        self::assertSame([$week->getId(),$first->getId(),$last->getId()],$this->ids($repo->findByToolForWeek($this->toolEntity,$day)));
        self::assertSame([$week->getId(),$first->getId(),$last->getId()],$this->ids($repo->findByToolForThisWeek($this->toolEntity)));
        self::assertSame([$month->getId(),$week->getId(),$first->getId(),$last->getId()],$this->ids($repo->findByToolForMonth($this->toolEntity,$day)));
        self::assertSame([$first->getId()],$this->ids($repo->findByToolBetween($this->toolEntity,$this->time('2025-02-15 01:00'),$this->time('2025-02-15 02:00'))));
        self::assertSame('2025-02-15 12:00:00',$day->format('Y-m-d H:i:s'),'Query wrappers must copy the caller clock');
    }

    public static function dst(): array {return ['spring'=>['2025-03-30 00:30','2025-03-30 02:30',3600], 'autumn'=>['2025-10-26 00:30','2025-10-26 02:30',10800]];}
    #[DataProvider('dst')]
    public function testPHP10DoctrineCarbonMappingStoresUtcAcrossLondonDst(string $start,string $end,int $seconds): void
    {
        $booking=(new Booking)->setTool($this->toolEntity)->setUser($this->memberEntity)->setType('NORMAL')
            ->setStart(Carbon::parse($start,'Europe/London'))->setEnd(Carbon::parse($end,'Europe/London'));
        $expectedStart=$booking->getStart()->copy()->utc()->format('Y-m-d H:i:s');
        $expectedEnd=$booking->getEnd()->copy()->utc()->format('Y-m-d H:i:s');
        $this->persist($booking);$id=$booking->getId();
        self::assertSame([$expectedStart,$expectedEnd],array_values($this->rows('SELECT start, end FROM bookings WHERE id=?',[$id])[0]));
        $this->em->clear();$rehydrated=$this->em->find(Booking::class,$id);
        self::assertInstanceOf(Carbon::class,$rehydrated->getStart());
        self::assertSame($seconds,(int)$rehydrated->getStart()->diffInSeconds($rehydrated->getEnd()));
    }

    public function testPHP16UsageQueriesFreeTimeAndSqlInteropRespectUserToolAndStatus(): void
    {
        $repo=$this->app->make(UsageRepository::class);$factory=new UsageFactory($repo);
        self::assertNull($repo->freeTimeForToolUser($this->toolEntity,$this->memberEntity));
        $award=$factory->createFreeTime($this->toolEntity,$this->memberEntity,-3600);$repo->save($award);
        self::assertSame('01:00:00',$repo->freeTimeForToolUser($this->toolEntity,$this->memberEntity));
        $used=$factory->createFreeTime($this->toolEntity,$this->memberEntity,600);$repo->save($used);
        $charged=$factory->createFreeTime($this->toolEntity,$this->memberEntity,900);$charged->setStatus('CHARGED');$repo->save($charged);
        self::assertSame('00:50:00',$repo->freeTimeForToolUser($this->toolEntity,$this->memberEntity));
        $other=Fixtures::tool('other');$this->persist($other);self::assertNull($repo->freeTimeForToolUser($other,$this->memberEntity));
        self::assertCount(3,$repo->findByToolBetween($this->toolEntity,$this->time(self::NOW),$this->time(self::NOW)));
        foreach(['findByToolForDay','findByToolForWeek','findByToolForMonth'] as $method)self::assertCount(3,$repo->$method($this->toolEntity,$this->time(self::NOW)));
        self::assertCount(3,$repo->findByToolForThisWeek($this->toolEntity));
        self::assertSame(-3000,(int)DB::table('tool_usages')->where('tool_id',$this->tool)->where('status','COMPLETE')->sum('duration'));
        $this->execute('UPDATE tool_usages SET duration=-1800 WHERE id=?',[$award->getId()]);$this->em->clear();
        self::assertSame(-1800,$this->em->find(Usage::class,$award->getId())->getDuration());
        self::assertSame('00:20:00',$repo->freeTimeForToolUser($this->toolEntity,$this->memberEntity));
    }
}
