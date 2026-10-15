<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Only use through the outer isolated runner, against its schema-scoped disposable account. */
final class DatabaseSandbox
{
    public function __construct(private $app) {}

    public function validate(): void
    {
        \preflight();
        foreach ([\database(), $this->app['em']->getConnection(), DB::connection()] as $connection) {
            $actual = $connection instanceof \PDO
                ? $connection->query('SELECT DATABASE()')->fetchColumn()
                : ($connection instanceof \Doctrine\DBAL\Connection
                    ? $connection->fetchOne('SELECT DATABASE()')
                    : $connection->selectOne('SELECT DATABASE() AS name')->name);
            \ensure($actual === 'hms_smoke', 'Reset refused: unexpected effective database');
        }
    }

    /** Ordinary ORM tests only. DDL/committing routines must use recreate() instead. */
    public function transaction(callable $work): mixed
    {
        $this->validate();
        $em = $this->app['em'];
        $doctrine = $em->getConnection();
        $laravel = DB::connection();
        \ensure(! $doctrine->isTransactionActive() && $laravel->transactionLevel() === 0,
            'Nested/reset transactions are not supported');
        $doctrine->beginTransaction();
        $laravel->beginTransaction();
        try {
            return $work($em, $laravel);
        } finally {
            $doctrineActive = $doctrine->getWrappedConnection()->getNativeConnection()->inTransaction();
            $laravelActive = $laravel->getPdo()->inTransaction();
            if ($doctrineActive) {
                $doctrine->rollBack();
            }
            if ($laravelActive) {
                $laravel->rollBack();
            }
            $em->clear();
            \ensure($doctrineActive && $laravelActive,
                'Implicit commit detected: use schema recreation, not transaction rollback');
        }
    }

    /** Rebuild real migrations and pinned routines, removing committed rows and schema mutations. */
    public function recreate(): array
    {
        $this->validate();
        $em = $this->app['em'];
        \ensure(! $em->getConnection()->isTransactionActive() && DB::transactionLevel() === 0,
            'Cannot recreate inside a transaction (Doctrine level '.$em->getConnection()->getTransactionNestingLevel().
            ', Laravel level '.DB::transactionLevel().')');
        $em->clear();
        $em->getConnection()->close();
        DB::purge('mysql');
        $db = \database();
        $db->exec('DROP DATABASE `hms_smoke`');
        // Match the actual tools/roles migration collation: routine string parameters inherit
        // database defaults and MySQL 8's 0900 default otherwise causes error 1267.
        // TODO: Confirm production collation separately; this is a disposable test target only.
        $db->exec('CREATE DATABASE `hms_smoke` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        foreach (['migrate' => ['--force' => true], 'doctrine:migrations:migrate' => []] as $command => $args) {
            $code = Artisan::call($command, $args + ['--no-interaction' => true]);
            \ensure($code === 0, 'Test schema recreation failed: '.$command.' '.Artisan::output());
        }
        $counts = \loadSql(\database());
        $em->clear();
        // MySQL DDL commits independently of DBAL's transaction bookkeeping.
        // Reconnect after migration commands so tests start with fresh native and ORM state.
        $em->getConnection()->close();
        DB::purge('mysql');

        return $counts;
    }
}
