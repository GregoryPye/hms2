<?php

namespace Tests\Unit\Tools;

use HMS\Entities\Role;
use HMS\Factories\Tools\ToolFactory;
use HMS\Repositories\PermissionRepository;
use HMS\Repositories\RoleRepository;
use HMS\Repositories\Tools\ToolRepository;
use HMS\Tools\ToolManager;
use HMS\User\Permissions\RoleManager;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BackendRuleTestCase;
use Tests\Support\Fixtures;

final class ToolRulesTest extends BackendRuleTestCase
{
    private function manager(&$tools = null, &$roles = null, &$permissions = null, &$roleManager = null): ToolManager
    {
        $tools = Mockery::mock(ToolRepository::class);
        $roles = Mockery::mock(RoleRepository::class);
        $permissions = Mockery::mock(PermissionRepository::class);
        $roleManager = Mockery::mock(RoleManager::class);
        return new ToolManager($tools, new ToolFactory, $permissions, $roles, $roleManager);
    }

    public static function flags(): array
    {
        return ['plain' => [false, false], 'restricted' => [true, false], 'hidden' => [false, true], 'both' => [true, true]];
    }

    #[DataProvider('flags')]
    public function testPHP01FactoryDefaultsAndExplicitValues(bool $restricted, bool $hidden): void
    {
        $tool = (new ToolFactory)->create('Test Tool', 'Synthetic display', $restricted, 125, 30, 120, 3, $hidden);
        self::assertSame(['Test Tool', 'Synthetic display', 'DISABLED', 'New tool not yet in use', $restricted, 125, 30, 120, 3, $hidden],
            [$tool->getName(), $tool->getDisplayName(), $tool->getStatus(), $tool->getStatusText(), $tool->isRestricted(), $tool->getPph(), $tool->getBookingLength(), $tool->getLengthMax(), $tool->getBookingsMax(), $tool->isHidden()]);
        self::assertSame('testTool', $tool->getPermissionName());
        $default = Fixtures::tool();
        self::assertSame(1, $default->getBookingsMax());
        self::assertFalse($default->isHidden());
    }

    public function testPHP02CreateReusesExistingPermissionsAndRoles(): void
    {
        $manager = $this->manager($tools, $roles, $permissions, $rm);
        $tools->shouldReceive('save')->once()->with(Mockery::on(fn ($t) => $t->getName() === 'test_fixture'));
        $existing = Fixtures::permission('tools.search.users');
        $permissions->shouldReceive('findOneByName')->with('tools.search.users')->once()->andReturn($existing);
        $permissions->shouldReceive('findOneByName')->with(Mockery::on(fn ($n) => $n !== 'tools.search.users'))->times(7)->andReturnNull();
        $permissions->shouldReceive('save')->times(7)->with(Mockery::type(\LaravelDoctrine\ACL\Permissions\Permission::class));
        $roles->shouldReceive('findOneByName')->with('tools.testFixture.user')->once()->andReturn(Fixtures::role());
        foreach (['inductor', 'maintainer'] as $type) {
            $roles->shouldReceive('findOneByName')->with('tools.testFixture.'.$type)->once()->andReturnNull();
            $rm->shouldReceive('createRoleFromTemplate')->with('tools.testFixture.'.$type, Mockery::type('array'),
                Mockery::on(fn ($p) => count($p) === 8 && $p['tools.search.users'] === $existing))->once();
        }
        self::assertSame('DISABLED', $manager->create('test_fixture', 'Synthetic', true, 100, 30, 120)->getStatus());
        self::assertSame([], $this->events, 'No invented ToolCreated event');
    }

    #[DataProvider('flags')]
    public function testPHP03UpdateCheckboxPresenceAndAllowedDetails(bool $restricted, bool $hidden): void
    {
        $manager = $this->manager($tools);
        $tool = Fixtures::tool();
        $details = ['displayName' => 'Updated', 'cost' => 75, 'bookingLength' => 20, 'lengthMax' => 90, 'bookingsMax' => 2];
        if ($restricted) $details['restricted'] = 'on';
        if ($hidden) $details['hidden'] = 'on';
        $tools->shouldReceive('save')->with($tool)->once();
        $manager->update($tool, $details);
        self::assertSame(['Updated', 75, 20, 90, 2, $restricted, $hidden], [$tool->getDisplayName(), $tool->getPph(), $tool->getBookingLength(), $tool->getLengthMax(), $tool->getBookingsMax(), $tool->isRestricted(), $tool->isHidden()]);
    }

    public function testPHP04DisableAndEnablePersistStatusAndReasonWithoutNotifications(): void
    {
        $manager = $this->manager($tools);
        $tool = Fixtures::tool();
        $tools->shouldReceive('save')->with($tool)->twice();
        $manager->disableTool($tool, 'Synthetic maintenance', true);
        self::assertSame(['DISABLED', 'Synthetic maintenance'], [$tool->getStatus(), $tool->getStatusText()]);
        $manager->enableTool($tool);
        self::assertSame(['FREE', null], [$tool->getStatus(), $tool->getStatusText()]);
        self::assertSame([], $this->events, 'notify is a product TODO, not delivered notification behavior');
    }

    public function testPHP05RemoveRequestsThreeNamedRoleRemovalsThenTool(): void
    {
        $manager = $this->manager($tools, $roles);
        $tool = Fixtures::tool();
        foreach (['user', 'inductor', 'maintainer'] as $type) $roles->shouldReceive('removeOneByName')->with('tools.testFixture.'.$type)->once()->ordered();
        $tools->shouldReceive('remove')->with($tool)->once();
        $manager->removeTool($tool);
        self::assertSame([], $this->events);
    }

    public static function grantTypes(): array
    {
        $cases=[];
        foreach (['USER', 'INDUCTOR', 'MAINTAINER'] as $type) foreach ([false,true] as $present) $cases[$type.($present?' present':' absent')]=[$type,$present];
        return $cases;
    }

    #[DataProvider('grantTypes')]
    public function testPHP06GrantAndRevokeIdempotencyAtRoleBoundary(string $type, bool $present): void
    {
        $manager = $this->manager($tools, $roles, $permissions, $rm);
        $tool = Fixtures::tool(); $user = Fixtures::user();
        if ($present) $user->getRoles()->add(Fixtures::role('tools.testFixture.'.strtolower($type)));
        if (! $present) $rm->shouldReceive('addUserToRoleByName')->with($user,'tools.testFixture.'.strtolower($type))->once();
        else $rm->shouldReceive('removeUserFromRoleByName')->with($user,'tools.testFixture.'.strtolower($type))->once();
        self::assertStringContainsString($present ? 'already' : 'appointed', $manager->grant($tool,$type,$user));
        self::assertStringContainsString($present ? 'removed' : 'is not', $manager->revoke($tool,$type,$user));
    }
}
