<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The suite runs on MariaDB/MySQL only, the databases Saan supports (phpunit.xml sets every DB_* value; real environment variables override).
 */
abstract class TestCase extends BaseTestCase
{
    use Concerns\TempDatabases;

    /** Name of the suite database, read once per process before any test points the application at a temporary one. */
    protected static string $suiteDatabase = '';

    /**
     * MariaDB/MySQL do not roll AUTO_INCREMENT back with the RefreshDatabase transaction, so seeded ids (1..N) drift
     * between tests. Registered after the trait's rollback callback, this resets counters once the rollback is done.
     */
    private bool $schemaDirty = false;

    /**
     * Run DDL inside a RefreshDatabase test. MariaDB/MySQL commit implicitly on DDL (ending the test transaction and keeping
     * seeded rows), so reopen a real transaction and rebuild the schema after the test.
     */
    protected function ddl(\Closure $change): void
    {
        $change();
        $this->schemaDirty = true;
        $pdo = $this->app['db']->connection()->getPdo();
        if (! $pdo->inTransaction()) $pdo->exec('START TRANSACTION');
    }

    /**
     * The connection the suite runs on (mariadb, or mysql when DB_CONNECTION says so). Tests that switch database.default to a temporary
     * public-side connection must switch BACK to this one by this name: RefreshDatabase would otherwise roll back the wrong connection and
     * leave the real test transaction open with its locks, so the next test waits on them.
     */
    protected string $baseConnection = 'mariadb';

    /**
     * RefreshDatabase reads this list when it begins the transaction AND again when it rolls back. Without the property it falls back to
     * config('database.default') of whatever application is current at teardown: after a test booted another edition on a temporary
     * database that is the wrong one, so the suite connection was never rolled back. Pinned per test.
     */
    protected array $connectionsToTransact = [];

    /** Runs before RefreshDatabase's migrate:fresh: never on anything that does not look like a test database. */
    protected function setUpTraits()
    {
        $c = (array) config('database.connections.'.config('database.default'));
        $name = (string) ($c['database'] ?? '');
        if (! in_array($c['driver'] ?? '', ['mysql', 'mariadb'], true) || ! preg_match('/(^|_)(test|ci)(_|$)/i', $name)) {
            throw new \RuntimeException('The test suite refuses to run on database "'.$name.'" (driver '.($c['driver'] ?? '?').'): it must be MariaDB/MySQL and its name needs a "test" or "ci" part, for example saan_test. Set DB_DATABASE and the other DB_* variables (see phpunit.xml).');
        }
        if (self::$suiteDatabase === '') self::$suiteDatabase = $name;
        $this->connectionsToTransact = [(string) config('database.default')];
        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseConnection = (string) config('database.default');
        if (self::$suiteDatabase === '') self::$suiteDatabase = (string) config('database.connections.'.$this->baseConnection.'.database');
        // controllers call set_time_limit(300|900) during tests; on a networked database the wall-clock budget then kills the whole run
        @set_time_limit(0);
        $this->beforeApplicationDestroyed(function () {
            $db = $this->app['db']->connection();
            if ($this->schemaDirty) {
                $db->reconnect(); // RefreshDatabase's own rollback callback has already run and disconnected
                \Illuminate\Support\Facades\Artisan::call('migrate:fresh');
                return;
            }
            // best effort: after a test booted another edition, this application's settings may name that edition's database (the suite
            // database was already reset in refreshApplication); a failure here must not skip the test's own teardown (env restore)
            try { self::resetAutoIncrement($db); } catch (\Throwable) {}
        });
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); }
        finally { $this->dropTempDatabases(); }
    }

    private static function resetAutoIncrement(\Illuminate\Database\Connection $db): void
    {
        $rows = $db->select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT > 1');
        foreach ($rows as $row) $db->statement('ALTER TABLE `'.str_replace('`', '', $row->t).'` AUTO_INCREMENT = 1');
    }

    /**
     * Tests that boot another edition replace the application mid-test. The old application's connection still holds the RefreshDatabase
     * transaction (and its metadata locks) until PHP frees it, so the next DDL (migrate:fresh, the AUTO_INCREMENT reset below, dropping a
     * temporary database) waits forever. Roll it back and close it first.
     */
    protected function refreshApplication()
    {
        if (isset($this->app)) {
            foreach ($this->app['db']->getConnections() as $c) {
                try { while ($c->transactionLevel() > 0) $c->rollBack(); } catch (\Throwable) { /* already gone */ }
                // the teardown reset only sees the CURRENT application (by then another edition): reset the seeded ids here, or the next
                // test's seed (fixed ids 1..N) meets drifted AUTO_INCREMENT values
                try { self::resetAutoIncrement($c); } catch (\Throwable) { /* best effort */ }
                $c->disconnect();
            }
        }
        parent::refreshApplication();
    }

    /** RT-06: real logins stamp auth_version into the session; tests must do the same instead of relying on a fallback. */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        if (isset($user->auth_version)) $this->withSession(['auth_version' => $user->auth_version]);
        return $this;
    }
}
