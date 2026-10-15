<?php

namespace Tests\Support;

use HMS\Entities\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;

/** Actual pinned MySQL routines only; never run outside the guarded disposable backend. */
abstract class SqlTestCase extends TestCase
{
    protected $app;
    protected PDO $db;
    protected int $user;
    protected int $tool;
    protected const NOW = '2025-02-15 12:00:00';

    protected function setUp(): void
    {
        $this->app = \application();
        TransportGuard::install();
        (new DatabaseSandbox($this->app))->recreate();
        $this->db = \database();
        // SET timestamp is session-local, including UTC_TIMESTAMP() used by the routines.
        // PHP's Carbon clock cannot control MySQL. Verify rather than assume this contract.
        $this->db->exec("SET time_zone = '+00:00'");
        $this->db->exec('SET timestamp = 1739620800');
        self::assertSame(self::NOW, $this->scalar('SELECT UTC_TIMESTAMP()'));
        self::assertSame('utf8mb4_unicode_ci', $this->scalar(
            "SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()"));
        self::assertSame(['utf8mb4_unicode_ci'], array_column($this->rows(
            "SELECT DISTINCT COLLATION_NAME FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=DATABASE() AND SPECIFIC_NAME='sp_tool_sign_on' AND COLLATION_NAME IS NOT NULL"), 'COLLATION_NAME'));
        $this->user = $this->member('member');
        $tool = Fixtures::tool()->setStatus('FREE')->setUnRestricted();
        $this->app['em']->persist($tool);
        $this->app['em']->flush();
        $this->tool = $tool->getId();
        $this->grant($this->user, 'tools.use');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db)) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->db->exec('SET timestamp = 0');
            }
            $this->app['em']->clear();
            $this->app['em']->getConnection()->close();
            DB::disconnect();
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            $this->app->flush();
        }
    }

    protected function execute(string $sql, array $args = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->rowCount();
    }

    protected function rows(string $sql, array $args = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // CALL adds an empty trailing result set; consume it before the next query.
        while ($stmt->nextRowset()) {}
        $stmt->closeCursor();
        return $rows;
    }

    protected function scalar(string $sql, array $args = []): mixed
    {
        return array_values($this->rows($sql, $args)[0])[0];
    }

    protected function member(string $key, array $permissions = []): int
    {
        $em = $this->app['em'];
        $user = Fixtures::user($key);
        $profile = new Profile($user);
        $user->setProfile($profile);
        foreach ([$user, $profile, Fixtures::card($user, 'TEST-'.strtoupper($key))] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        foreach ($permissions as $permission) {
            $this->grant($user->getId(), $permission);
        }
        return $user->getId();
    }

    protected function grant(int $user, string $permission): void
    {
        $role = 'test.permission.'.str_replace('.', '_', $permission);
        $this->execute('INSERT IGNORE INTO permissions (name) VALUES (?)', [$permission]);
        $this->execute('INSERT IGNORE INTO roles (name, display_name, description) VALUES (?, ?, ?)', [$role, 'Synthetic permission', 'Test only']);
        $this->execute('INSERT IGNORE INTO permission_role (permission_id, role_id) SELECT p.id, r.id FROM permissions p, roles r WHERE p.name=? AND r.name=?', [$permission, $role]);
        $this->execute('INSERT IGNORE INTO role_user (user_id, role_id) SELECT ?, id FROM roles WHERE name=?', [$user, $role]);
    }

    protected function signOn(string $tool = 'test_fixture', string $card = 'TEST-MEMBER'): array
    {
        $this->db->exec('SET @access=NULL, @message=NULL, @member=NULL');
        $this->rows('CALL sp_tool_sign_on(?, ?, @access, @message, @member)', [$tool, $card]);
        return $this->rows('SELECT @access AS access, @message AS message, @member AS member')[0];
    }

    protected function signOff(string $tool = 'test_fixture', int $active = 0): string
    {
        $this->db->exec('SET @message=NULL');
        $this->rows('CALL sp_tool_sign_off(?, ?, @message)', [$tool, $active]);
        return $this->scalar('SELECT @message');
    }

    protected function usage(int $duration = 0, string $status = 'IN_PROGRESS', ?int $user = null, ?int $tool = null): int
    {
        $this->execute('INSERT INTO tool_usages (user_id, tool_id, start, duration, active_time, status) VALUES (?, ?, ?, ?, 0, ?)',
            [$user ?? $this->user, $tool ?? $this->tool, self::NOW, $duration, $status]);
        return (int) $this->db->lastInsertId();
    }

    protected function charge(int $usage, int $seconds, int $active = 0): string
    {
        $this->db->exec('SET @message=NULL');
        $this->rows('CALL sp_tool_charge(?, TIMESTAMPADD(SECOND, ?, ?), ?, @message)', [$usage, $seconds, self::NOW, $active]);
        return $this->scalar('SELECT @message');
    }

    protected function booking(int $start = -60, int $end = 60, string $type = 'MAINTENANCE', ?int $tool = null, ?int $user = null): int
    {
        $this->execute('INSERT INTO bookings (user_id, tool_id, start, end, type, created_at, updated_at) VALUES (?, ?, TIMESTAMPADD(SECOND, ?, ?), TIMESTAMPADD(SECOND, ?, ?), ?, ?, ?)',
            [$user ?? $this->user, $tool ?? $this->tool, $start, self::NOW, $end, self::NOW, $type, self::NOW, self::NOW]);
        return (int) $this->db->lastInsertId();
    }

    protected function snapshot(): array
    {
        return [
            'usage' => $this->rows('SELECT * FROM tool_usages ORDER BY id'),
            'tools' => $this->rows('SELECT * FROM tools ORDER BY id'),
            'balances' => $this->rows('SELECT user_id, balance, credit_limit FROM profile ORDER BY user_id'),
            'transactions' => $this->rows('SELECT * FROM transactions ORDER BY id'),
            'allocations' => $this->rows('SELECT * FROM purchase_payment ORDER BY transaction_id_purchase, transaction_id_payment'),
        ];
    }
}
