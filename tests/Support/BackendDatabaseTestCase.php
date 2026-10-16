<?php

namespace Tests\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use HMS\Entities\{User,Tools\Tool};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

/** Real ORM/schema boundary with the same commit-aware reset and transport guard as SQL tests. */
abstract class BackendDatabaseTestCase extends SqlTestCase
{
    protected $em;
    protected User $memberEntity;
    protected Tool $toolEntity;
    private $oldNow;
    private $oldImmutable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldNow=Carbon::getTestNow();$this->oldImmutable=CarbonImmutable::getTestNow();
        Carbon::setTestNow(Carbon::parse(self::NOW.' UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW.' UTC'));
        $this->em=$this->app['em'];$this->em->clear();
        $this->memberEntity=$this->em->find(User::class,$this->user);
        $this->toolEntity=$this->em->find(Tool::class,$this->tool);
    }

    protected function tearDown(): void
    {
        try {parent::tearDown();}
        finally {Carbon::setTestNow($this->oldNow);CarbonImmutable::setTestNow($this->oldImmutable);}
    }

    /** Only outward-bearing event types are recorded; registration and real local audit are tested separately. */
    protected function recordEvents(array $classes): void { Event::fake($classes); }

    protected function actingAs(User $user): void { Auth::setUser($user); }

    protected function time(string $value): Carbon { return Carbon::parse($value,'UTC'); }

    protected function persist(object ...$entities): void
    {
        foreach($entities as $entity)$this->em->persist($entity);
        $this->em->flush();
    }

    protected function makeBooking(string $start,string $end,string $type='NORMAL',?Tool $tool=null,?User $user=null)
    {
        $booking=(new \HMS\Entities\Tools\Booking)->setTool($tool??$this->toolEntity)->setUser($user??$this->memberEntity)
            ->setType($type)->setStart($this->time($start))->setEnd($this->time($end));
        $this->persist($booking);return $booking;
    }
}
