<?php

namespace Tests\Support;

use App\Events\Roles\{RoleCreated,UserAddedToRole,UserRemovedFromRole};
use App\Listeners\RoleUpdateLogger;
use HMS\Entities\{Role,RoleUpdate,Tools\Tool,User};
use HMS\Tools\ToolManager;
use HMS\User\Permissions\RoleManager;
use HMS\Repositories\{UserRepository,RoleRepository,RoleUpdateRepository};
use Illuminate\Support\Facades\{Auth,DB,Event};
use PHPUnit\Framework\Attributes\DataProvider;


abstract class BackendLifecycleTestCase extends BackendDatabaseTestCase
{
    protected function localAudit(): void
    {
        $events=$this->app['events'];
        self::assertTrue($events->hasListeners(UserAddedToRole::class));
        self::assertTrue($events->hasListeners(UserRemovedFromRole::class));
        $logger=$this->app->make(RoleUpdateLogger::class);
        self::assertSame([UserAddedToRole::class=>'onUserAddedToRole',UserRemovedFromRole::class=>'onUserRemovedFromRole'],$logger->subscribe($events));
        // Replace only these queued/outbound-bearing registrations. Execute the actual audit
        // listener synchronously through local closures; all other event types remain registered.
        foreach([RoleCreated::class,UserAddedToRole::class,UserRemovedFromRole::class] as $type)$events->forget($type);
        $events->listen(RoleCreated::class,static fn()=>null);
        $events->listen(UserAddedToRole::class,fn($e)=>$logger->onUserAddedToRole($e));
        $events->listen(UserRemovedFromRole::class,fn($e)=>$logger->onUserRemovedFromRole($e));
    }

    protected function createTool(): Tool
    {
        return $this->app->make(ToolManager::class)->create('spike','Synthetic lifecycle',true,100,30,120);
    }

}
