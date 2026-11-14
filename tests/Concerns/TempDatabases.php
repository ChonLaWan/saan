<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Temporary MariaDB/MySQL databases for tests that need more than the suite database: the public side of a split install, the target of
 * an installer run, a second school. They live on the same server as the suite database, are named <suite database>_t<random> and are
 * dropped when the test ends (TestCase::tearDown), so the test user needs CREATE and DROP on that prefix.
 *
 * Everything that creates or drops a database goes through a PDO of its own: on the suite connection such a statement would commit the
 * RefreshDatabase transaction of the running test.
 */
trait TempDatabases
{
    /** @var list<string> databases created by the running test */
    private array $tempDbNames = [];
    /** @var array<string,string> template key => database, built once per test process */
    private static array $tempDbTemplates = [];
    /** @var list<string> databases that could not be dropped at teardown (a connection still held them); retried when the process ends */
    private static array $tempDbLeftovers = [];
    /** @var array{0:string,1:string,2:string}|null DSN, user and password of the suite server */
    private static ?array $tempDbServer = null;
    private static bool $tempDbShutdown = false;
    /** @var array<string,array{0:list<string>,1:list<string>,2:list<string>}> template database => [CREATE TABLE statements, tables with rows, table names] */
    private static array $tempDbShape = [];
    /** @var array<string,list<string>> template database => copies released by finished tests, reused by tempDatabase() */
    private static array $tempDbPool = [];
    /** @var array<string,string> database of the running test => template it was copied from */
    private array $tempDbCloneOf = [];

    private function tempDbPdo(): \PDO
    {
        if (self::$tempDbServer === null) {
            $c = (array) config('database.connections.'.$this->baseConnection);
            self::$tempDbServer = ['mysql:host='.$c['host'].';port='.$c['port'].';charset=utf8mb4', (string) $c['username'], (string) $c['password']];
        }
        if (!self::$tempDbShutdown) {
            self::$tempDbShutdown = true;
            register_shutdown_function(static function (): void {
                try {
                    $pdo = new \PDO(...self::$tempDbServer); $pdo->exec('SET SESSION lock_wait_timeout = 5');
                    foreach (array_merge(self::$tempDbLeftovers, array_merge([], ...array_values(self::$tempDbPool)), array_values(self::$tempDbTemplates)) as $n) { try { $pdo->exec('DROP DATABASE IF EXISTS `'.$n.'`'); } catch (\Throwable) {} }
                } catch (\Throwable) {}
            });
        }
        $pdo = new \PDO(self::$tempDbServer[0], self::$tempDbServer[1], self::$tempDbServer[2], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION lock_wait_timeout = 5');
        return $pdo;
    }

    private static function tempDbName(string $kind): string
    {
        return substr(self::$suiteDatabase, 0, 40).'_'.$kind.bin2hex(random_bytes(5));
    }

    /**
     * Give a copy of a template back in the state the template has, or report that it cannot be reused. Creating and dropping ~160 tables costs
     * seconds per test, so copies are kept for the next test of the same process when (and only when) their schema is still exactly the
     * template's: same tables, same CREATE statements, no trigger. Rows are removed and the template's rows put back.
     */
    private function tempDbReset(\PDO $pdo, string $db, string $template): bool
    {
        try {
            [$creates, $withRows, $names] = self::$tempDbShape[$template];
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
            if ($this->tempDbTables($db) !== $names) return false;
            if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '.$pdo->quote($db))->fetchColumn() > 0) return false;
            $strip = static fn (string $sql): string => (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql);
            foreach ($names as $i => $t) if ($strip($pdo->query('SHOW CREATE TABLE `'.$db.'`.`'.$t.'`')->fetch(\PDO::FETCH_NUM)[1]) !== $strip($creates[$i])) return false;
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($names as $t) {
                $q = '`'.$db.'`.`'.$t.'`';
                $dirty = (bool) $pdo->query('SELECT EXISTS(SELECT 1 FROM '.$q.')')->fetchColumn();
                if ($dirty) $pdo->exec('DELETE FROM '.$q);
                if ($dirty || in_array($t, $withRows, true)) { try { $pdo->exec('ALTER TABLE '.$q.' AUTO_INCREMENT = 1'); } catch (\Throwable) {} }
            }
            foreach ($withRows as $t) $pdo->exec('INSERT INTO `'.$db.'`.`'.$t.'` SELECT * FROM `'.$template.'`.`'.$t.'`');
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            return true;
        } catch (\Throwable) { return false; }
    }

    /** Create a database. With $cloneOf (a database made by templateDatabase()) it gets that database's tables and rows. Released at teardown. */
    protected function tempDatabase(?string $cloneOf = null): string
    {
        $pdo = $this->tempDbPdo();
        // a copy of this template left by an earlier test of this process, if it is still schema-identical
        while ($cloneOf !== null && !empty(self::$tempDbPool[$cloneOf])) {
            $free = array_pop(self::$tempDbPool[$cloneOf]);
            if ($this->tempDbReset($pdo, $free, $cloneOf)) { $this->tempDbNames[] = $free; $this->tempDbCloneOf[$free] = $cloneOf; return $free; }
            try { $pdo->exec('DROP DATABASE IF EXISTS `'.$free.'`'); } catch (\Throwable) { self::$tempDbLeftovers[] = $free; }
            $pdo = $this->tempDbPdo(); // fresh session settings after a failed reset
        }
        $name = self::tempDbName('t');
        $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->tempDbNames[] = $name;
        if ($cloneOf === null) return $name;
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); $pdo->exec('USE `'.$name.'`');
        $tables = $pdo->query('SHOW FULL TABLES FROM `'.$cloneOf.'` WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM);
        // the template never changes after it is built: its CREATE statements and the list of tables that hold rows are read once per process
        self::$tempDbShape[$cloneOf] ??= [
            array_map(fn ($t) => $pdo->query('SHOW CREATE TABLE `'.$cloneOf.'`.`'.$t[0].'`')->fetch(\PDO::FETCH_NUM)[1], $tables),
            array_values(array_filter(array_column($tables, 0), fn ($t) => (bool) $pdo->query('SELECT EXISTS(SELECT 1 FROM `'.$cloneOf.'`.`'.$t.'`)')->fetchColumn())),
            array_column($tables, 0),
        ];
        foreach (self::$tempDbShape[$cloneOf][0] as $create) $pdo->exec($create);
        foreach (self::$tempDbShape[$cloneOf][1] as $t) $pdo->exec('INSERT INTO `'.$name.'`.`'.$t.'` SELECT * FROM `'.$cloneOf.'`.`'.$t.'`');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->tempDbCloneOf[$name] = $cloneOf;
        return $name;
    }

    /**
     * A database prepared once per test process (usually: all migrations) and copied by tempDatabase($template) for each test.
     * $build receives the database name; the template is dropped when the process ends.
     */
    protected function templateDatabase(string $key, \Closure $build): string
    {
        if (isset(self::$tempDbTemplates[$key])) return self::$tempDbTemplates[$key];
        $name = self::tempDbName('tpl');
        $this->tempDbPdo()->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$tempDbTemplates[$key] = $name; // registered first: a failing $build still gets dropped at shutdown
        $build($name);
        return $name;
    }

    /** Connection settings of the suite server for another database. */
    protected function tempDbConfig(string $database, array $override = []): array
    {
        return array_replace((array) config('database.connections.'.$this->baseConnection), ['database' => $database, 'url' => null], $override);
    }

    /** Register connection $name (same server and user as the suite) on $database. */
    protected function tempConnection(string $name, string $database, array $override = []): void
    {
        config(['database.connections.'.$name => $this->tempDbConfig($database, $override)]);
        DB::purge($name);
    }

    /** The usual case in one call: a connection on a fresh copy of a fully migrated database. Returns the database name. */
    protected function migratedTempConnection(string $name): string
    {
        $template = $this->templateDatabase('migrated', function (string $db): void {
            $this->tempConnection('saan_tpl', $db);
            try { \Illuminate\Support\Facades\Artisan::call('migrate', ['--database' => 'saan_tpl', '--force' => true]); }
            finally { DB::purge('saan_tpl'); }
        });
        $db = $this->tempDatabase($template);
        $this->tempConnection($name, $db);
        return $db;
    }

    /** Table names of a database, read without touching any application connection. @return list<string> */
    protected function tempDbTables(string $database): array
    {
        return array_column($this->tempDbPdo()->query('SHOW FULL TABLES FROM `'.$database.'` WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM), 0);
    }

    /**
     * Every row of every table of a database as one string (JSON per row), for "this text is stored nowhere on the public side" checks.
     * Reads through a connection of its own, so only committed rows are seen: exactly what another process or a database copy would get.
     */
    protected function tempDbDump(string $database): string
    {
        $pdo = $this->tempDbPdo(); $out = '';
        foreach ($this->tempDbTables($database) as $t) {
            foreach ($pdo->query('SELECT * FROM `'.$database.'`.`'.$t.'`')->fetchAll(\PDO::FETCH_ASSOC) as $row) $out .= $t.' '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n".implode("\n", array_map('strval', array_filter($row, 'is_string')))."\n";
        }
        return $out;
    }

    /** Close the given connections and drop every database this test created. TestCase::tearDown calls it; calling it earlier is fine. */
    protected function dropTempDatabases(array $connections = []): void
    {
        foreach ($connections as $c) { try { DB::purge($c); } catch (\Throwable) {} }
        if (!$this->tempDbNames) return;
        $names = $this->tempDbNames; $this->tempDbNames = [];
        $cloneOf = $this->tempDbCloneOf; $this->tempDbCloneOf = [];
        try {
            $pdo = $this->tempDbPdo();
            foreach ($names as $n) {
                // copies of a template are kept for the next test (tempDbReset checks and cleans them); everything else is dropped
                if (isset($cloneOf[$n])) { self::$tempDbPool[$cloneOf[$n]][] = $n; continue; }
                // a connection of an application instance that was replaced mid-test may still be open on the database: the drop then times out and is retried at shutdown
                try { $pdo->exec('DROP DATABASE IF EXISTS `'.$n.'`'); } catch (\Throwable) { self::$tempDbLeftovers[] = $n; }
            }
        } catch (\Throwable) { self::$tempDbLeftovers = array_merge(self::$tempDbLeftovers, $names); }
    }
}
