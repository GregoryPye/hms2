<?php

namespace Tests\Integration\Tools\Backend;

use App\Events\Roles\{RoleCreated,UserAddedToRole,UserRemovedFromRole};
use App\Listeners\RoleUpdateLogger;
use HMS\Entities\{Role,RoleUpdate,Tools\Tool,User};
use HMS\Tools\ToolManager;
use HMS\User\Permissions\RoleManager;
use HMS\Repositories\{UserRepository,RoleRepository,RoleUpdateRepository};
use Illuminate\Support\Facades\{Auth,DB,Event};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{BackendDatabaseTestCase,Fixtures};

final class LifecycleAuditTest extends \Tests\Support\BackendLifecycleTestCase
{
    public function testPHP02RealCreatePersistsRolePermissionsAndReusesSharedPermission(): void
    {
        $this->recordEvents([RoleCreated::class]);
        $manager=$this->app->make(ToolManager::class);$tool=$this->createTool();
        $manager->create('second','Synthetic second',false,0,20,60);
        self::assertSame(1,(int)DB::table('permissions')->where('name','tools.search.users')->count());
        $this->em->clear();$roleRepo=$this->app->make(RoleRepository::class);
        foreach(['user'=>[true,2],'inductor'=>[false,4],'maintainer'=>[false,6]] as $type=>[$retained,$count]){
            $role=$roleRepo->findOneByName('tools.spike.'.$type);
            self::assertSame($retained,$role->getRetained());self::assertCount($count,$role->getPermissions());
        }
        // clear() detaches fixtures: reload before invoking managers that persist the entity.
        $tool=$this->em->find(Tool::class,$tool->getId());
        self::assertSame('DISABLED',$tool->getStatus());
        Event::assertDispatchedTimes(RoleCreated::class,6);
        $manager->disableTool($tool,'Synthetic maintenance',true);
        self::assertSame('Synthetic maintenance',DB::table('tools')->where('id',$tool->getId())->value('status_text'));
        $manager->enableTool($tool);
        self::assertSame(['FREE',null],array_values($this->rows('SELECT status,status_text FROM tools WHERE id=?',[$tool->getId()])[0]));
    }

    public static function grants(): array{return [['USER'],['INDUCTOR'],['MAINTAINER']];}
    #[DataProvider('grants')]
    public function testPHP06PHP07RealGrantRevokeAndAuditActorAreIdempotent(string $type): void
    {
        $this->localAudit();$tool=$this->createTool();$manager=$this->app->make(ToolManager::class);
        $actor=Fixtures::user('actor');$this->persist($actor);$this->actingAs($actor);
        $manager->grant($tool,$type,$this->memberEntity);$manager->grant($tool,$type,$this->memberEntity);
        $audit=$this->rows('SELECT user_id, added_role_id, removed_role_id, update_by_user_id FROM role_updates');
        self::assertCount(1,$audit);self::assertSame($this->user,(int)$audit[0]['user_id']);self::assertSame($actor->getId(),(int)$audit[0]['update_by_user_id']);
        self::assertNull($audit[0]['removed_role_id']);
        // ACL role permissions are persisted here. Gate abilities are registered at request
        // bootstrap, before these fixtures exist; routed/request-boundary checks belong to 2D.
        self::assertTrue($this->memberEntity->hasPermissionTo('tools.spike.use'));
        self::assertSame($type==='MAINTAINER',$this->memberEntity->hasPermissionTo('tools.spike.maintain'));
        self::assertSame($type==='INDUCTOR',$this->memberEntity->hasPermissionTo('tools.spike.book.induction'));
        $roleId=(int)$audit[0]['added_role_id'];
        self::assertSame(1,(int)$this->scalar('SELECT COUNT(*) FROM role_user WHERE user_id=? AND role_id=?',[$this->user,$roleId]));
        $manager->revoke($tool,$type,$this->memberEntity);$manager->revoke($tool,$type,$this->memberEntity);
        $audits=$this->rows('SELECT * FROM role_updates ORDER BY id');self::assertCount(2,$audits);
        self::assertSame($roleId,(int)$audits[1]['removed_role_id']);self::assertNull($audits[1]['added_role_id']);
        self::assertSame($actor->getId(),(int)$audits[1]['update_by_user_id']);
        self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM role_user WHERE user_id=? AND role_id=?',[$this->user,$roleId]));
    }

    public function testPHP07ApprovalAuditIsExcludedAndSystemActorIsNullable(): void
    {
        $this->localAudit();$rm=$this->app->make(RoleManager::class);
        $approval=Fixtures::role(Role::MEMBER_APPROVAL);$system=Fixtures::role('test.system');$this->persist($approval,$system);
        $rm->addUserToRole($this->memberEntity,$approval);
        self::assertSame(0,(int)DB::table('role_updates')->count());
        $rm->addUserToRole($this->memberEntity,$system);
        $row=DB::table('role_updates')->first();self::assertNull($row->update_by_user_id);self::assertSame($system->getId(),$row->added_role_id);
    }

    public function testPHP03RenameShouldKeepRolePermissionIdentityAligned(): void
    {
        $this->recordEvents([RoleCreated::class]);$tool=$this->createTool();
        $this->app->make(ToolManager::class)->update($tool,['toolName'=>'renamed','restricted'=>'on']);
        self::assertNotNull($this->app->make(RoleRepository::class)->findOneByName('tools.renamed.user'),'DEF-04: renamed tool must retain resolvable grant role');
    }

    public function testPHP03DuplicateInternalNameIsRejectedByRealUniqueConstraint(): void
    {
        $this->recordEvents([RoleCreated::class]);$this->createTool();
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->createTool();
    }

    public function testPHP05UnreferencedToolRemovalPersistsAndRolesAreSoftDeleted(): void
    {
        $this->recordEvents([RoleCreated::class]);$tool=$this->createTool();$id=$tool->getId();
        $this->app->make(ToolManager::class)->removeTool($tool);
        self::assertFalse(DB::table('tools')->where('id',$id)->exists());
        self::assertSame(3,(int)DB::table('roles')->where('name','like','tools.spike.%')->whereNotNull('deleted_at')->count());
        self::assertGreaterThan(0,DB::table('permissions')->where('name','like','tools.spike.%')->count(),'Current removal retains permission records');
    }

    public function testPHP05ReferencedToolRemovalIsRejectedByRealForeignKey(): void
    {
        $this->recordEvents([RoleCreated::class]);$tool=$this->createTool();
        $this->makeBooking('2025-02-15 13:00','2025-02-15 14:00',tool:$tool);
        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $this->app->make(ToolManager::class)->removeTool($tool);
    }
    public function testPHP05FailedRemovalMustNotPartiallyDeleteToolRoles(): void
    {
        $this->recordEvents([RoleCreated::class]);$tool=$this->createTool();
        $this->makeBooking('2025-02-15 13:00','2025-02-15 14:00',tool:$tool);
        $before=$this->rows("SELECT name,deleted_at FROM roles WHERE name LIKE 'tools.spike.%' ORDER BY name");
        try {$this->app->make(ToolManager::class)->removeTool($tool);self::fail('Expected referenced tool removal to fail');}
        catch(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $expected){
            self::assertTrue(DB::table('tools')->where('name','spike')->exists());
            self::assertSame($before,$this->rows("SELECT name,deleted_at FROM roles WHERE name LIKE 'tools.spike.%' ORDER BY name"),'Failed removal should preserve role grants, not leave a surviving tool with deleted roles');
        }
    }

}
