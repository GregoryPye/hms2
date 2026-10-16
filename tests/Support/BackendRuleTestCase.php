<?php

namespace Tests\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use HMS\Entities\User;

/** Manager-rule boundary: no Laravel kernel, database or listeners; strict recording ports. */
abstract class BackendRuleTestCase extends TestCase
{
    protected Container $container;
    protected array $events = [];
    private $oldNow;
    private $oldImmutable;
    private $oldContainer;
    private string $oldTimezone;

    protected function setUp(): void
    {
        $this->oldNow = Carbon::getTestNow();
        $this->oldImmutable = CarbonImmutable::getTestNow();
        $this->oldTimezone = date_default_timezone_get();
        $this->oldContainer = Container::getInstance();
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2025-02-15 12:00:00 UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-15 12:00:00 UTC'));
        $this->container = new Container;
        Container::setInstance($this->container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        $events = Mockery::mock(\Illuminate\Contracts\Events\Dispatcher::class);
        $events->shouldReceive('dispatch')->andReturnUsing(function ($event) {
            // Record contracts; there is no application dispatcher/listener execution in unit work.
            $this->events[] = $event;
            return [];
        });
        $this->container->instance('events', $events);
    }

    protected function tearDown(): void
    {
        try { Mockery::close(); }
        finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Container::setInstance($this->oldContainer);
            Carbon::setTestNow($this->oldNow);
            CarbonImmutable::setTestNow($this->oldImmutable);
            date_default_timezone_set($this->oldTimezone);
        }
    }

    protected function identity(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
        return $entity;
    }

    protected function user(array $permissions = [], int $id = 1): User
    {
        $user = Mockery::mock(User::class, ['Synthetic', 'Member', 'test_member', 'member@example.invalid'])->makePartial();
        $this->identity($user, $id);
        $user->shouldReceive('can')->andReturnUsing(static fn ($name) => in_array($name, $permissions, true));
        $user->shouldReceive('cannot')->andReturnUsing(static fn ($name) => ! in_array($name, $permissions, true));
        return $user;
    }

    protected function actingAs(User $user): void
    {
        $auth = Mockery::mock();
        $auth->shouldReceive('user')->andReturn($user);
        $this->container->instance('auth', $auth);
        Facade::clearResolvedInstance('auth');
    }
}
