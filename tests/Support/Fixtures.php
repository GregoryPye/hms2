<?php

namespace Tests\Support;

use Carbon\Carbon;
use HMS\Entities\ContentBlock;
use HMS\Entities\Gatekeeper\RfidTag;
use HMS\Entities\Gatekeeper\RfidTagState;
use HMS\Entities\Meta;
use HMS\Entities\Role;
use HMS\Entities\User;
use HMS\Factories\Tools\ToolFactory;
use LaravelDoctrine\ACL\Permissions\Permission;

/** Synthetic builders; persist explicitly, never run development seeders or external managers. */
final class Fixtures
{
    public static function user(string $key = 'member'): User
    {
        if (! preg_match('/^[a-z0-9_-]{1,24}$/', $key)) {
            throw new \InvalidArgumentException('Use a short synthetic fixture key');
        }
        $user = new User('Synthetic', 'Member', 'test_'.$key, $key.'@example.invalid');
        // Public test-only hash for "password". It is not a credential for any deployed account.
        $user->setPassword('$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
        $user->setEmailVerifiedAt(Carbon::parse('2025-01-15T12:00:00Z'));

        return $user;
    }

    public static function tool(string $key = 'fixture')
    {
        return (new ToolFactory)->create('test_'.$key, 'Synthetic '.$key, false, 0, 30, 120);
    }

    public static function role(string $name = 'test.tools.user', array $permissions = []): Role
    {
        $role = new Role($name, 'Synthetic role', 'Test-only role');
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        return $role;
    }

    public static function permission(string $name = 'tools.test_fixture.use'): Permission
    {
        return new Permission($name);
    }

    public static function card(User $user, string $serial = 'TEST-CARD-0001'): RfidTag
    {
        return (new RfidTag($serial))->setUser($user)->setState(RfidTagState::ACTIVE)
            ->setFriendlyName('Synthetic card')->setLastUsed(Carbon::parse('2025-01-15T12:00:00Z'));
    }

    public static function metadata(string $key = 'test_rules_html', string $value = '/test/rules'): Meta
    {
        return (new Meta)->create($key)->setValue($value);
    }

    public static function content(): ContentBlock
    {
        return (new ContentBlock)->setView('tools.tool.index')->setBlock('test_fixture')
            ->setContent('<p>Synthetic test content</p>');
    }
}
