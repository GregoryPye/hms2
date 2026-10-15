<?php

namespace Tests\Foundation\Integration;

use HMS\Entities\Tools\Tool;
use HMS\Entities\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Support\Clock;
use Tests\Support\DatabaseSandbox;
use Tests\Support\Fixtures;
use Tests\Support\TransportGuard;

final class DatabaseSupportTest extends TestCase
{
    private $app;
    private DatabaseSandbox $sandbox;
    private array $transports;

    protected function setUp(): void
    {
        $this->app = \application();
        $this->sandbox = new DatabaseSandbox($this->app);
        $this->transports = TransportGuard::install();
    }

    protected function tearDown(): void
    {
        try {
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

    public function testFixturesCrossBothOrmsAndRollbackRestoresStateAfterFailure(): void
    {
        $this->sandbox->recreate();
        $em = $this->app['em'];
        [$id, $userId] = Clock::during('2025-01-15T12:00:00Z', static function () use ($em) {
            $user = Fixtures::user();
            $permission = Fixtures::permission();
            $role = Fixtures::role(permissions: [$permission]);
            $user->getRoles()->add($role);
            $tool = Fixtures::tool();
            foreach ([$permission, $role, $user, Fixtures::card($user), $tool,
                Fixtures::metadata(), Fixtures::content()] as $entity) {
                $em->persist($entity);
            }
            $em->flush();

            return [$tool->getId(), $user->getId()];
        });
        $em->clear();
        $user = $em->find(User::class, $userId);
        self::assertSame('test.tools.user', $user->getRoles()->first()->getName());
        self::assertSame('tools.test_fixture.use', $user->getRoles()->first()->getPermissions()->first()->getName());
        self::assertSame('member@example.invalid', DB::table('user')->where('id', $userId)->value('email'));
        self::assertSame('Synthetic fixture', DB::table('tools')->where('id', $id)->value('display_name'));
        $tracked = null;
        try {
            $this->sandbox->transaction(function ($em, $laravel) use ($id, &$tracked) {
                $tracked = $em->find(Tool::class, $id);
                $tracked->setDisplayName('Uncommitted change');
                $em->persist(Fixtures::user('rollback'));
                $em->flush();
                self::assertSame('Uncommitted change', $em->getConnection()->fetchOne(
                    'SELECT display_name FROM tools WHERE id = ?', [$id]));
                $laravel->table('meta')->insert(['key' => 'test_rollback', 'value' => 'temporary',
                    'created_at' => '2025-01-15 12:00:00', 'updated_at' => '2025-01-15 12:00:00']);
                self::assertSame('temporary', $laravel->table('meta')->where('key', 'test_rollback')->value('value'));
                throw new \RuntimeException('deliberate database failure');
            });
            self::fail('Expected transaction callback failure');
        } catch (\RuntimeException $error) {
            self::assertSame('deliberate database failure', $error->getMessage());
        }
        self::assertFalse($em->contains($tracked), 'Identity map must not retain rolled-back entities');
        self::assertSame('Synthetic fixture', $em->find(Tool::class, $id)->getDisplayName());
        self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM user WHERE username='test_rollback'"));
        self::assertFalse(DB::table('meta')->where('key', 'test_rollback')->exists());
        $this->sandbox->transaction(static function ($em) {
            $em->persist(Fixtures::user('success'));
            $em->flush();
        });
        self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM user WHERE username='test_success'"),
            'A successful callback must also roll back');
    }

    public function testRecreationRemovesCommittedRoutineEffectsAndRestoresRealSchema(): void
    {
        $this->sandbox->recreate();
        $em = $this->app['em'];
        $tool = Fixtures::tool();
        $em->persist($tool);
        $em->flush();
        $db = \database();
        // Deliberately commits like sp_tool_charge; no application routine is rewritten.
        $db->exec("CREATE PROCEDURE test_fixture_commit() BEGIN START TRANSACTION;
            UPDATE tools SET display_name='Committed routine change' WHERE name='test_fixture'; COMMIT; END");
        $db->beginTransaction();
        $db->exec('CALL test_fixture_commit()');
        self::assertFalse($db->inTransaction(), 'Routine committed the outer transaction');
        self::assertSame('Committed routine change', DB::table('tools')->where('name', 'test_fixture')->value('display_name'));
        self::assertSame('Synthetic fixture', $tool->getDisplayName(), 'Demonstrate stale ORM state before reset');
        $counts = $this->sandbox->recreate();
        self::assertFalse($em->contains($tool));
        self::assertSame(0, DB::table('tools')->count());
        self::assertSame(0, (int) \database()->query("SELECT COUNT(*) FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA='hms_smoke' AND ROUTINE_NAME='test_fixture_commit'")->fetchColumn());
        self::assertSame(['procedures' => 62, 'views' => 20], $counts);
        self::assertGreaterThan(0, DB::table('migrations_doctrine')->count());
        $result = \database()->query('CALL sp_tool_get_status(-1)');
        self::assertSame([], $result->fetchAll());
        $result->closeCursor();
        $em->persist(Fixtures::tool());
        $em->flush();
        self::assertSame(1, DB::table('tools')->count(), 'Fresh schema accepts the same deterministic fixture');
    }

    public function testUnexpectedTransportsFailAndExplicitExpectationsRecordCalls(): void
    {
        foreach ([static fn () => Mail::raw('Synthetic', static function () {}),
            static fn () => Notification::send([], new \stdClass),
            static fn () => Broadcast::connection(),
            static fn () => Queue::push(new \stdClass),
            static fn () => Bus::dispatch(new \stdClass)] as $unexpected) {
            try {
                $unexpected();
                self::fail('Unexpected transport call was accepted');
            } catch (\Mockery\Exception\BadMethodCallException $expected) {
                self::assertNotEmpty($expected->getMessage());
            }
        }
        try {
            Http::get('https://unexpected-transport.invalid');
            self::fail('Unexpected HTTP call was accepted');
        } catch (\RuntimeException $expected) {
            self::assertStringContainsString('without a matching fake', $expected->getMessage());
        }
        $job = new \stdClass;
        $this->transports[Bus::class]->shouldReceive('dispatch')->once()->with($job)->andReturn('recorded');
        self::assertSame('recorded', Bus::dispatch($job));
    }
}
