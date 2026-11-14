<?php

namespace Tests\Feature;

use App\Support\Saan\Edition;
use App\Support\Saan\HashKey;
use App\Support\Saan\Inbox;
use App\Support\Saan\Inbox\Puller;
use App\Support\Saan\Inbox\PullServer;
use App\Support\Saan\Projection;
use App\Support\Saan\Publish\Builder;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\Receiver;
use App\Support\Saan\Publish\Wire;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Registry\Identity as RegIdentity;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../../installer/Setup.php';
require_once __DIR__.'/../../installer/Split.php';

use SchoolAtelier\Installer\{DbAdmin, Identity, MysqlAdmin, Setup, Split};

/**
 * S8 breach simulation. The attacker owns the ENTIRE public side: its database, its storage directory, its .env and its key directory.
 *
 * The world is built the way a real standard install is built (the real installer: two app roots, two databases with one account each, local pairing,
 * first full publish), then a realistic internal data set is written, every sensitive column of every internal table carries a unique marker, everything
 * is published with the real Builder / Publisher / Receiver, and public forms push sealed envelopes. What the public side holds afterwards is copied
 * ("loot": the files as a directory, the database as a copy of every table and row) and attacked.
 *
 * Internal side = connection saan_internal (default), public side = connection saan_public: two databases on the suite's MariaDB/MySQL server that live
 * as long as the test process. No HTTP except the child process that lists the public edition's routes.
 */
class BreachSimulationTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tmp = [];
    private string $nonce = '';
    private int $serial = 0;
    private string $intRoot = '';
    private string $pubRoot = '';
    private string $keysInt = '';
    private string $pubKeys = '';
    private string $hostKey = '';
    private array $plan = [];
    private array $pubEnv = [];
    /** @var array<string,string> needle => where it came from */
    private array $needles = [];
    private array $colCache = [];
    private array $declCache = [];
    private array $enumCache = [];
    private array $sqlCache = [];
    /** names of the two databases of the world */
    private string $intDb = '';
    private string $pubDb = '';
    private string $lootDir = '';
    /** this test's copy of the public database */
    private string $lootDb = '';
    private ?string $pushPeer = null;
    private ?string $pullPeer = null;
    /** the sealed envelopes the public forms received (kind, marker text) */
    private array $submitted = [];

    /** The world (installs, databases, data set, published content, sealed envelopes) is built once per run; every test works on its own copy of the loot. */
    private static ?array $world = null;
    /** @var list<string> */
    private static array $worldTmp = [];
    /** @var list<string> database accounts of the world; dropped when the class is done */
    private static array $worldUsers = [];
    private static array $worldServer = [];
    /** the one Builder of the test process, see publisher() */
    private static ?Builder $builder = null;
    private bool $building = false;
    private string $lootSrc = '';

    private const WORLD_PROPS = ['nonce', 'intRoot', 'pubRoot', 'keysInt', 'pubKeys', 'plan', 'pubEnv', 'needles', 'pubDb', 'intDb', 'pushPeer', 'pullPeer', 'submitted', 'lootSrc', 'serial'];

    protected function setUp(): void
    {
        parent::setUp();
        @set_time_limit(0);
        $this->hostKey = (string) config('app.key');
        // F9: Turnstile is configured, as on a real install that offers full-name notices. Without it the publisher masks everything whatever full_name_until says,
        // so the expired windows below would pass even if the publisher ignored full_name_until altogether.
        config(['saan.turnstile.site_key' => 'site-breach', 'saan.turnstile.secret' => 'secret-breach']);
        Storage::fake('local');
        Inbox\Handlers::forget();
        if (self::$world === null) {
            $this->nonce = bin2hex(random_bytes(4));
            $this->building = true;
            try { $this->build(); } finally { $this->building = false; }
            self::$world = [];
            foreach (self::WORLD_PROPS as $p) self::$world[$p] = $this->$p;
        } else {
            foreach (self::$world as $p => $v) $this->$p = $v;
            $this->attach();
        }
        $this->lootDir = $this->dir();
        $this->copyTree($this->lootSrc, $this->lootDir);
        // the database part of the loot: every table and row of the public database, copied after the world was built (no test changes the world afterwards)
        $this->lootDb = $this->tempDatabase($this->pubDb);
        $this->tempConnection('loot', $this->lootDb);
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->baseConnection, 'saan.inbox_connection' => null, 'saan.edition' => 'combined', 'app.key' => $this->hostKey]);
        foreach (['saan_internal', 'saan_public', 'loot', 'chk'] as $c) { try { DB::purge($c); } catch (\Throwable) {} }
        parent::tearDown();
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? $this->rrmdir($p) : @unlink($p);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$worldTmp as $p) { is_dir($p) ? (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($p) : @unlink($p); }
        self::$worldTmp = [];
        self::$world = null;
        self::$builder = null;
        self::dropWorldUsers(); // the two databases themselves are dropped when the process ends (Tests\Concerns\TempDatabases::templateDatabase)
        parent::tearDownAfterClass();
    }

    private static function dropWorldUsers(): void
    {
        foreach (self::$worldUsers as $u) { try { (new MysqlAdmin)->dropUser(self::$worldServer, $u); } catch (\Throwable) {} }
        self::$worldUsers = [];
    }

    // ---------------------------------------------------------------- plumbing

    private function dir(): string
    {
        $d = sys_get_temp_dir().'/saan-breach-'.bin2hex(random_bytes(6));
        mkdir($d, 0777, true);
        $d = (string) realpath($d);
        if ($this->building) return self::$worldTmp[] = $d;
        return $this->tmp[] = $d;
    }

    /** Points this test's application at the world (connections, disks, edition). */
    private function attach(): void
    {
        config(['saan.keys_dir' => $this->keysInt, 'school.school_id' => 1,
            'filesystems.disks.breach_public' => ['driver' => 'local', 'root' => $this->pubRoot.'/storage/app/private']]);
        $this->connect($this->intDb, $this->pubDb);
        $this->asInternal();
    }

    /**
     * Connections saan_internal and saan_public on the given databases, as the suite account. The internal one runs without foreign key checks:
     * the data set seeds every table on its own, whether the rows it points at exist or not. The public one keeps them, as in production.
     */
    private function connect(string $internal, string $public): void
    {
        $init = PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_INIT_COMMAND : \PDO::MYSQL_ATTR_INIT_COMMAND;
        $options = (array) config('database.connections.'.$this->baseConnection.'.options', []);
        $this->tempConnection('saan_internal', $internal, ['options' => [$init => 'SET FOREIGN_KEY_CHECKS = 0'] + $options]);
        $this->tempConnection('saan_public', $public);
    }

    /** Both databases as private copies, for the test that changes them. */
    private function forkDatabases(): void
    {
        $this->connect($this->tempDatabase($this->intDb), $this->tempDatabase($this->pubDb));
        $this->asInternal();
    }

    /** The suite's database server and account, in the shape the installer takes a database admin account. */
    private function server(): array
    {
        $c = $this->tempDbConfig('');
        return ['db_host' => (string) $c['host'], 'db_port' => (string) $c['port'], 'db_user' => (string) $c['username'], 'db_password' => (string) $c['password']];
    }

    private function rrmdir(string $d): void
    {
        foreach (glob($d.'/{,.}*', GLOB_BRACE) ?: [] as $p) {
            if (in_array(basename($p), ['.', '..'], true)) continue;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    private function root(): string
    {
        $r = $this->dir();
        foreach (['storage/app/private', 'bootstrap/cache', 'public', 'vendor'] as $s) mkdir($r.'/'.$s, 0777, true);
        file_put_contents($r.'/artisan', '<?php');
        file_put_contents($r.'/vendor/autoload.php', '<?php');
        file_put_contents($r.'/bootstrap/app.php', '<?php');
        file_put_contents($r.'/VERSION', "1.2.3\n");
        return $r;
    }

    private function fakeAdmin(): DbAdmin
    {
        return new class implements DbAdmin {
            public function exists(array $admin, string $db): bool { return false; }
            public function userExists(array $admin, string $user): ?bool { return false; }
            public function createDatabase(array $admin, string $db): void {}
            public function createUser(array $admin, string $user, string $password): void {}
            public function dropDatabase(array $admin, string $db): void {}
            public function dropUser(array $admin, string $user): void {}
            public function grant(array $admin, string $db, string $user): void {}
            public function canConnect(array $cred): bool { return true; }
            public function canRead(array $cred, string $other): bool { return false; }
            public function grants(array $cred): array { return ['GRANT USAGE ON *.* TO `u`@`localhost`', 'GRANT SELECT, INSERT ON `'.$cred['db_name'].'`.* TO `u`@`localhost`']; }
        };
    }

    /**
     * Runs the real standard installer (blank site), then switches this process to "the internal installation". The two databases are what the
     * provisioning step of a standard install leaves: empty, on the suite server, each with an account of its own that is granted only that
     * database (created by the installer's MysqlAdmin). They stay until the test process ends; every test reads them or works on a copy.
     */
    private function build(): void
    {
        $this->intRoot = $this->root();
        $this->pubRoot = $this->root();
        $this->keysInt = $this->dir();
        config(['saan.keys_dir' => $this->keysInt]);
        $id = Identity::generate();
        $d = Identity::derive($id, 'internal');
        $server = self::$worldServer = $this->server();
        register_shutdown_function(static fn () => self::dropWorldUsers()); // in case the process ends before tearDownAfterClass
        $creds = [];
        foreach (['internal', 'public'] as $side) {
            $db = $this->templateDatabase('breach-'.$side.'-'.$this->nonce, fn () => null); // left empty: the installer refuses anything else
            $password = Split::password();
            self::$worldUsers[] = $d['user_'.$side];
            (new MysqlAdmin)->createUser($server, $d['user_'.$side], $password);
            (new MysqlAdmin)->grant($server, $db, $d['user_'.$side]);
            $creds[$side] = ['db_host' => $server['db_host'], 'db_port' => $server['db_port'], 'db_name' => $db, 'db_user' => $d['user_'.$side], 'db_password' => $password];
        }
        $this->plan = ['id' => $id, 'public_root' => $this->pubRoot, 'public_url' => 'https://www.school.example', 'creds' => $creds];
        $this->intDb = $creds['internal']['db_name'];
        $this->pubDb = $creds['public']['db_name'];
        $setup = new Setup($this->intRoot);
        $setup->prepare();
        $site = Setup::validateSite(['mode' => 'blank', 'school_name' => 'โรงเรียนทดสอบสองฝั่ง', 'admin_name' => 'ผู้ดูแลทดสอบ', 'admin_email' => 'owner@school.example', 'password' => 'Synthetic-Install-2026',
            'password_confirmation' => 'Synthetic-Install-2026', 'app_url' => 'https://app.school.example', 'public_email' => 'contact@school.example', 'phone' => '', 'address' => '']);
        $split = new Split($setup, $this->fakeAdmin(), fn (string $root) => [$this->app, $this->app->make(\Illuminate\Contracts\Console\Kernel::class)]);
        $split->install($this->plan, $site);

        $this->pubKeys = $this->pubRoot.'/storage/app/private/saan-keys';
        $this->pubEnv = \Dotenv\Dotenv::parse((string) file_get_contents($this->pubRoot.'/.env'));
        $this->pushPeer = trim((string) file_get_contents($this->pubKeys.'/peer-push.pub'));
        $this->pullPeer = trim((string) file_get_contents($this->pubKeys.'/peer-pull.pub'));
        config(['filesystems.disks.breach_public' => ['driver' => 'local', 'root' => $this->pubRoot.'/storage/app/private']]);
        $this->connect($this->intDb, $this->pubDb);
        config(['school.school_id' => 1]);
        $this->asInternal();

        $this->dataset();
        $this->harvest();
        $this->publish();
        $this->publicForms();
        DB::purge('saan_public');
        $this->takeLoot();
    }

    private function asInternal(): void
    {
        config(['saan.edition' => 'internal', 'database.default' => 'saan_internal', 'saan.inbox_connection' => null, 'saan.keys_dir' => $this->keysInt, 'app.key' => $this->hostKey]);
    }

    private function asPublic(): void
    {
        config(['saan.edition' => 'public', 'database.default' => 'saan_public', 'saan.inbox_connection' => 'saan_public', 'saan.keys_dir' => $this->pubKeys, 'app.key' => $this->pubEnv['APP_KEY']]);
    }

    private function publicSide(callable $fn): mixed
    {
        $this->asPublic();
        try { return $fn(); } finally { $this->asInternal(); }
    }

    private function publisher(): Publisher
    {
        // The real Builder; only the order of its tables is worked out once per test process instead of on every call. Builder::entries() asks the
        // database for the foreign keys of every declared table each time it is called, and one delivered batch calls it hundreds of times: on a
        // MariaDB server that holds many databases that is minutes per batch. The tables and their foreign keys are the same in the world and its copies.
        self::$builder ??= new class extends Builder {
            private ?array $order = null;
            public function entries(): array { return $this->order ??= parent::entries(); }
        };
        self::$builder->skipped = [];
        $receiver = new Receiver('saan_public', Keys::publicKey());
        return new Publisher(self::$builder, function (string $body, array $h) use ($receiver) {
            config(['saan.push.receive_disk' => 'breach_public']);
            return $receiver->handle($body, $h);
        });
    }

    /** Scheduler ticks until nothing waits in the outbox: the installer's first publish and one tick both stop at their time budget, the scheduler goes on a minute later. */
    private function publish(): void
    {
        config(['saan.push.receive_disk' => 'breach_public']);
        $waiting = fn () => DB::table('pub_outbox')->whereNotNull('payload')->whereNull('sent_at')->count();
        $sent = 0;
        for ($tick = 0; $tick < 10; $tick++) {
            $r = $this->publisher()->run(120);
            $this->assertSame(0, $r['failed'], 'publishing failed: '.json_encode($r));
            $sent += $r['sent'];
            if ($waiting() === 0) break;
        }
        $this->assertSame(0, $waiting(), 'every queued batch reached the public side');
        $this->assertGreaterThan(5, $sent);
    }

    // ---------------------------------------------------------------- generic row writer

    private function cols(string $t): array
    {
        return $this->colCache[$t] ??= Schema::getColumns($t);
    }

    /** @return array<string,array<string,string>> table => column => rule, for every table any module or the core exports (active or not) */
    private function declared(): array
    {
        if (!$this->declCache) {
            foreach (Projection::declared(false, true) as $e) $this->declCache[$e['table']] = $e['columns'];
        }
        return $this->declCache;
    }

    /** The CREATE TABLE statement of a table of the internal database: where the values of an enum column and the JSON check of a column are read. */
    private function createSql(string $t): string
    {
        return $this->sqlCache[$t] ??= (string) (((array) DB::selectOne('SHOW CREATE TABLE `'.$t.'`'))['Create Table'] ?? '');
    }

    private function enumValues(string $t, string $c): array
    {
        $key = "$t.$c";
        if (!isset($this->enumCache[$key])) {
            $vals = [];
            if (preg_match('/^\s*`'.preg_quote($c, '/').'` enum\(([^)]*)\)/mi', $this->createSql($t), $m)) preg_match_all("/'([^']*)'/", $m[1], $mm) && $vals = $mm[1];
            $this->enumCache[$key] = $vals;
        }
        return $this->enumCache[$key];
    }

    /** A JSON column accepts JSON text only: MySQL has a type for it, MariaDB a text column with a json_valid() check. */
    private function jsonColumn(string $t, array $c): bool
    {
        return strtolower((string) $c['type_name']) === 'json' || str_contains($this->createSql($t), 'json_valid(`'.$c['name'].'`)');
    }

    /**
     * A unique marker token. It is ASCII only and never appears in generated public content.
     * $max = length of the column it goes into. A column too short for the full marker gets the short form (prefix, nonce, serial), which harvest()
     * finds the same way. A column of 8 to 14 characters gets a random-looking token that is registered as a needle here. Under 8 characters nothing
     * that could be told from ordinary text fits: the value is a filler and proves nothing about that column.
     */
    private function mk(string $slug, int $max = PHP_INT_MAX): string
    {
        $n = ++$this->serial;
        $full = 'BRX'.$this->nonce.'-'.preg_replace('/[^A-Za-z0-9.]/', '', $slug).'.'.$n;
        if (strlen($full) <= $max) return $full;
        $short = 'BRX'.$this->nonce.'-'.base_convert((string) $n, 10, 36);
        if (strlen($short) <= $max) return $short;
        if ($max >= 8) {
            $token = 'BX'.strtoupper(substr(hash('sha256', $this->nonce.'|'.$n), 0, $max - 2));
            $this->needles[$token] = 'short column '.$slug;
            return $token;
        }
        return substr(str_pad(strtoupper(base_convert((string) $n, 10, 36)), $max, 'X', STR_PAD_LEFT), -$max);
    }

    /** A rare Thai word (name part) plus nothing else: registered so a leak of the Thai text alone is found too. */
    private function th(string $word): string
    {
        $this->needles[$word] = 'thai:'.$word;
        return $word;
    }

    private function plain(string $value, string $label): string
    {
        $this->needles[$value] = $label;
        return $value;
    }

    private function digits(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; $i++) $s .= (string) random_int($i === 0 ? 1 : 0, 9);
        return $s;
    }

    /**
     * Inserts one row. Every text column carries a unique marker (so every sensitive column of the table is seeded), required numbers get 1, and $o overrides.
     * $exposed = the row is meant to be public: the columns the projection keeps get plain text instead of a marker.
     */
    private function row(string $table, array $o = [], bool $exposed = false): int
    {
        $rules = $exposed ? ($this->declared()[$table] ?? []) : [];
        $n = ++$this->serial;
        $row = [];
        foreach ($this->cols($table) as $c) {
            $name = $c['name'];
            $ty = strtolower((string) $c['type_name']);
            if (!empty($c['auto_increment'])) continue;
            if (preg_match('/char|text|json|clob|enum/', $ty)) {
                $enum = $this->enumValues($table, $name);
                $max = preg_match('/char\((\d+)\)/', strtolower((string) $c['type']), $m) ? (int) $m[1] : PHP_INT_MAX; // the server refuses a longer value
                $text = ($rules[$name] ?? null) === 'keep' ? 'pub-'.$table.'-'.$name.'-'.$n : null;
                if ($text !== null && strlen($text) > $max) $text = substr('pub'.$n, 0, $max);
                if ($enum) $row[$name] = $enum[0];
                elseif ($this->jsonColumn($table, $c)) $row[$name] = json_encode($text ?? $this->mk("$table.$name")); // the same text as a JSON string
                else $row[$name] = $text ?? $this->mk("$table.$name", $max);
            } elseif (!$c['nullable'] && $c['default'] === null) {
                $row[$name] = str_contains($ty, 'datetime') || str_contains($ty, 'timestamp') ? date('Y-m-d H:i:s') : (str_contains($ty, 'date') ? date('Y-m-d') : 1);
            }
        }
        foreach (['created_at', 'updated_at'] as $t) if (in_array($t, array_column($this->cols($table), 'name'), true)) $row[$t] ??= date('Y-m-d H:i:s');
        return (int) DB::table($table)->insertGetId(array_merge($row, $o));
    }

    private function stash(string $path, string $content): string
    {
        Storage::disk('local')->put($path, $content);
        return $path;
    }

    // ---------------------------------------------------------------- the internal data set

    private function dataset(): void
    {
        $now = date('Y-m-d H:i:s');
        $uuid = fn (int $i) => str_repeat((string) $i, 8).'-'.str_repeat((string) $i, 4).'-4'.str_repeat((string) $i, 3).'-8'.str_repeat((string) $i, 3).'-'.str_repeat((string) $i, 12);
        $png = base64_decode(self::PNG);

        // ---- staff accounts: password hashes, MFA secrets (encrypted at rest) and recovery codes
        $staff = [];
        foreach (['teacher' => 'ครูภูวนัยพิทักษ์ศักดิ์', 'principal' => 'ผอ.ธีรภัทรสกุลลับ', 'finance' => 'เจ้าหน้าที่การเงินกรวิชญ์ชาญชัยลับ'] as $role => $nm) {
            $pw = $this->plain('Pw-'.$this->mk("pw.$role"), "users.$role.password.plain");
            $totp = $this->plain(strtoupper(substr(strtr(base64_encode(random_bytes(24)), ['+' => 'A', '/' => 'B', '=' => 'C', '0' => 'D', '1' => 'E', '8' => 'F', '9' => 'G']), 0, 32)), "users.$role.mfa.totp");
            $codes = [$this->plain(strtoupper(bin2hex(random_bytes(4))), "users.$role.recovery.1"), $this->plain(strtoupper(bin2hex(random_bytes(4))), "users.$role.recovery.2")];
            $u = \App\Models\User::factory()->create([
                'name' => $this->th($nm).' '.$this->mk("users.$role.name"), 'email' => strtolower($this->mk("staff.$role")).'@staff-int.example.invalid', 'password' => Hash::make($pw),
                'remember_token' => $this->mk("users.$role.remember"),
            ]);
            DB::table('users')->where('id', $u->id)->update(['school_id' => 1, 'role' => $role, 'is_active' => 1, 'mfa_secret' => Crypt::encryptString($totp), 'mfa_recovery' => Crypt::encryptString(json_encode(array_map(fn ($c) => Hash::make($c), $codes))), 'mfa_enabled_at' => $now, 'mfa_last_step' => 12345]);
            $staff[$role] = (int) $u->id;
            $this->needles[(string) DB::table('users')->where('id', $u->id)->value('email')] = "users.$role.email";
        }
        $author = $staff['teacher'];
        DB::table('users')->where('role', 'admin')->update(['mfa_secret' => Crypt::encryptString($this->plain('ADMINTOTP'.strtoupper(bin2hex(random_bytes(8))), 'users.admin.mfa.totp')), 'remember_token' => $this->mk('users.admin.remember')]);

        // ---- term, classrooms, teachers
        $term = (int) DB::table('academic_terms')->value('id');
        if (!$term) $term = $this->row('academic_terms', ['year' => 2569, 'term' => 1, 'starts_on' => date('Y-m-d', strtotime('-60 days')), 'ends_on' => date('Y-m-d', strtotime('+200 days'))], true);
        $this->row('academic_terms', ['year' => 2568, 'term' => 2, 'starts_on' => '2025-11-01', 'ends_on' => '2026-03-31']);
        $roomA = $this->row('classrooms', ['name' => 'ป.6/1', 'grade_level' => $this->mk('classrooms.grade', 20), 'homeroom_teacher_id' => null], true);
        $roomB = $this->row('classrooms', ['name' => $this->th('ห้องลับสุดยอดสองสิบ').' '.$this->mk('classrooms.nameB'), 'homeroom_teacher_id' => null]);
        $tShown = $this->row('teachers', ['name' => 'ครูสุภาพร ใจเย็น (ทดสอบ)', 'user_id' => null, 'is_active' => 1], true);
        $tHidden = $this->row('teachers', ['name' => $this->th('ครูวริศราบุญเหลือลับ').' '.$this->mk('teachers.hidden'), 'user_id' => $staff['teacher'], 'is_active' => 1]);
        $tInactive = $this->row('teachers', ['name' => $this->th('ครูนฤมลสายลับเกษียณ').' '.$this->mk('teachers.inactive'), 'is_active' => 0]);
        $jpeg = (function () { ob_start(); imagejpeg(imagecreatetruecolor(8, 8)); return (string) ob_get_clean(); })();
        $photoShown = $this->stash('personnel/1/'.str_repeat('a', 32).'.jpg', $jpeg);
        $photoHidden = $this->stash('personnel/1/'.str_repeat('b', 32).'.jpg', $jpeg.$this->plain($this->mk('personnel.photo.hidden.bytes'), 'file:personnel photo hidden'));
        $this->row('per_profiles', ['teacher_id' => $tShown, 'show_in_directory' => 1, 'photo_path' => $photoShown, 'position' => 'ครู', 'academic_standing' => 'ชำนาญการ', 'department' => 'คณิตศาสตร์', 'work_group' => 'วิชาการ', 'directory_order' => 1, 'start_date' => '2015-05-16'], true);
        $this->row('per_profiles', ['teacher_id' => $tHidden, 'show_in_directory' => 0, 'photo_path' => $photoHidden, 'start_date' => '2012-05-16']);
        $this->row('per_profiles', ['teacher_id' => $tInactive, 'show_in_directory' => 1, 'start_date' => '2001-05-16']);
        $this->row('per_folders', ['code' => 'F1']);
        $this->row('per_files', ['teacher_id' => $tHidden, 'path' => $this->stash('personnel/files/hr-'.$this->nonce.'.pdf', '%PDF-1.4 '.$this->plain($this->mk('per_files.bytes'), 'file:per_files'))]);

        // ---- students, identifiers, guardians
        $studentIds = [];
        $notice = [];
        foreach ([0 => 'อชิรวิชญ์', 1 => 'ปัณณวัฒน์', 2 => 'ชญาณิศา', 3 => 'ธนกฤต', 4 => 'พิมพ์ลดา', 5 => 'กฤตภาส'] as $i => $first) {
            $last = $this->th(['ศรีลับสุดแสน', 'บุญมีลับมาก', 'แก้วใสลับเฉพาะ', 'เลิศล้ำลับลึก', 'จันทร์งามลับตา', 'สายลับเกินไป'][$i]);
            if ($i >= 2) $this->th($first); // students that appear in no public notice: the first name is a secret too
            $nid = $this->plain('1'.$this->digits(12), "students.$i.national_id");
            $this->needles[substr($nid, 0, 1).'-'.substr($nid, 1, 4).'-'.substr($nid, 5, 5).'-'.substr($nid, 10, 2).'-'.substr($nid, 12, 1)] = "students.$i.national_id.formatted";
            $sid = $this->row('students', ['classroom_id' => $roomA, 'code' => 'S'.$this->digits(7), 'name' => $first.' '.$last.' '.$this->mk("students.$i.name"), 'first_name' => $first, 'last_name' => $last.$this->mk("students.$i.last"),
                'birth_date' => '2014-0'.($i + 1).'-1'.$i, 'status' => 'active']);
            $studentIds[] = $sid;
            $this->row('reg_identifiers', ['person_type' => 'student', 'person_id' => $sid, 'kind' => 'thai_id', 'value_enc' => RegIdentity::encrypt($nid), 'value_hash' => RegIdentity::hash($nid), 'last4' => substr($nid, -4), 'verification_state' => 'verified']);
            $phone = $this->plain('08'.$this->digits(8), "guardians.$i.phone");
            $this->needles[substr($phone, 0, 3).'-'.substr($phone, 3, 3).'-'.substr($phone, 6)] = "guardians.$i.phone.formatted";
            $g = $this->row('reg_guardians', ['display_name' => $this->th(['สมพรผู้ปกครองลับ', 'วันเพ็ญผู้ปกครองลับ'][$i % 2]).' '.$this->mk("guardians.$i.name"), 'phone_enc' => RegIdentity::encrypt($phone),
                'email' => strtolower($this->mk("guardians.$i.email")).'@parent.example.invalid']);
            $this->needles[(string) DB::table('reg_guardians')->where('id', $g)->value('email')] = "guardians.$i.email";
            $this->row('reg_student_guardians', ['student_id' => $sid, 'guardian_id' => $g]);
            $this->row('hlth_profiles', ['student_id' => $sid, 'blood_group' => 'O', 'allergies' => 'แพ้ถั่วลิสงรุนแรง '.$this->mk("hlth.$i.allergy"), 'chronic_conditions' => 'โรคหอบหืด '.$this->mk("hlth.$i.chronic"),
                'medications' => 'ยาพ่นขยายหลอดลม '.$this->mk("hlth.$i.meds"), 'emergency_note' => 'โทรหาผู้ปกครองทันที '.$this->mk("hlth.$i.note")]);
            $this->row('hlth_measurements', ['student_id' => $sid, 'term_id' => $term, 'weight_kg' => 31.0 + $i + 0.137, 'height_cm' => 140.0 + $i + 0.213]);
            $this->row('asm_scores', ['student_id' => $sid, 'course_id' => 1, 'score_centi' => 6000 + $i * 517 + 13, 'component_key' => $this->mk("asm.$i.component")]);
            $this->row('asm_score_revisions', ['student_id' => $sid, 'score_id' => 1, 'course_id' => 1, 'old_centi' => 5500, 'new_centi' => 6013, 'reason' => 'แก้คะแนนสอบ '.$this->mk("asm.$i.reason")]);
            $this->row('grade_entries', ['student_id' => $sid, 'term_id' => $term, 'continuous_centi' => 7011, 'exam_centi' => 6542, 'subject' => $this->mk("grade.$i.subject")]);
            $this->row('bhv_records', ['student_id' => $sid, 'term_id' => $term, 'rule_id' => 1, 'occurred_on' => date('Y-m-d'), 'points' => -5, 'note' => 'ทะเลาะวิวาท '.$this->mk("bhv.$i.note")]);
            $this->row('attendances', ['student_id' => $sid, 'date' => date('Y-m-d'), 'status' => 'absent', 'note' => $this->mk("att.$i.note")]);
            $this->row('reg_documents', ['student_id' => $sid, 'path' => $this->stash("registry/$sid/birth-".$this->nonce.'.pdf', '%PDF-1.4 '.$this->plain($this->mk("reg_documents.$i.bytes"), "file:reg_documents.$i"))]);
            $amount = (int) ('7'.$this->digits(8));
            $this->plain((string) $amount, "fin.$i.amount_satang");
            $inv = $this->row('fin_invoices', ['student_id' => $sid, 'term_id' => $term, 'total_satang' => $amount, 'paid_satang' => $amount, 'status' => 'paid']);
            $this->row('fin_invoice_lines', ['invoice_id' => $inv, 'amount_satang' => $amount, 'discount_satang' => 0]);
            $this->row('fin_payments', ['invoice_id' => $inv, 'amount_satang' => $amount, 'paid_on' => date('Y-m-d'), 'snap_student_name' => $first.' '.$last.' '.$this->mk("fin.$i.snapname")]);
            $this->row('fin_ledger', ['amount_satang' => $amount, 'entry_date' => date('Y-m-d')]);
            $this->row('gp_accounts', ['guardian_id' => $g, 'email' => strtolower($this->mk("gp.$i.email")).'@parent.example.invalid', 'password' => Hash::make($this->plain('GpPw-'.$this->mk("gp.$i.pw"), "gp_accounts.$i.password.plain"))]);
            $notice[] = [$i, $first, $last];
        }
        $this->row('fin_fee_items', ['default_amount_satang' => 123450]);
        // F3 guardian fees (internal only): receiving account with QR, a payment claim with its slip, a portal notice. Every text column is marked, the files carry markers.
        $acctNo = $this->plain($this->digits(10), 'fin_payment_accounts.account_number');
        $acct = $this->row('fin_payment_accounts', ['bank_code' => 'kbank', 'account_number' => $acctNo, 'is_active' => 1,
            'qr_path' => $this->stash('finance/1/qr/'.$this->nonce.'.png', $png.$this->plain($this->mk('fin_payment_accounts.qr.bytes'), 'file:fin_payment_accounts qr')), 'qr_mime' => 'image/png', 'qr_size' => strlen($png)]);
        $claimInvoice = (int) DB::table('fin_invoices')->orderBy('id')->value('id');
        $claim = $this->row('fin_payment_claims', ['invoice_id' => $claimInvoice, 'student_id' => $studentIds[0], 'guardian_id' => 1, 'account_id' => $acct, 'source' => 'guardian', 'status' => 'pending',
            'amount_satang' => (int) ('6'.$this->digits(7)), 'transferred_at' => $now, 'entered_by' => null]);
        $slipSha = hash('sha256', $this->plain($this->mk('fin_claim_slips.sha.seed'), 'fin_claim_slips.sha.seed'));
        $this->row('fin_claim_slips', ['claim_id' => $claim, 'path' => $this->stash('finance/1/slips/'.$this->nonce.'.png', $png.$this->plain($this->mk('fin_claim_slips.bytes'), 'file:fin_claim_slips')),
            'mime' => 'image/png', 'size' => strlen($png), 'sha256' => $this->plain($slipSha, 'fin_claim_slips.sha256'), 'dup_key' => $slipSha]);
        $this->row('gp_notices', ['guardian_id' => 1, 'student_id' => $studentIds[0], 'kind' => 'fee_claim', 'ref_id' => $claim, 'read_at' => null]);
        $this->row('fin_counters', []);
        $this->row('fin_periods', ['period' => '2026-10']);
        // F2 payslips (internal only): payee identifiers, a published period, a data slip and an encrypted PDF slip, a staged import and a 50 tawi certificate.
        // Amounts, bank account and national id are encrypted columns; every plain text column is marked, and the secrets are registered as needles too.
        $pslNid = $this->plain('0'.$this->digits(12), 'psl_payees.national_id');
        $pslBank = $this->plain($this->digits(10), 'psl_payees.bank_account');
        $pslGross = (int) ('8'.$this->digits(7));
        $this->plain((string) $pslGross, 'psl.gross_satang');
        $pslPayee = $this->row('psl_payees', ['user_id' => $staff['teacher'], 'name' => $this->th('ครูผู้รับเงินเดือนลับสุดยอด').' '.$this->mk('psl_payees.name'), 'nid_enc' => \Saan\Modules\Payslip\Vault::seal($pslNid),
            'nid_hash' => \Saan\Modules\Payslip\Payees::hash($pslNid), 'nid_last4' => substr($pslNid, -4), 'bank_enc' => \Saan\Modules\Payslip\Vault::seal($pslBank)]);
        $pslPeriod = $this->row('psl_periods', ['year' => 2026, 'month' => 10, 'pay_date' => date('Y-m-d'), 'status' => 'published',
            'totals_enc' => \Saan\Modules\Payslip\Vault::sealJson(['gross' => $pslGross, 'ded' => 1, 'net' => $pslGross - 1, 'data_slips' => 1, 'pdf_slips' => 1])]);
        $pslData = ['e' => [[$this->mk('psl_slips.item'), $pslGross]], 'd' => [], 'tax' => 0, 'sso' => 0, 'pf' => 0, 'gross' => $pslGross, 'ded' => 0, 'net' => $pslGross, 'bank' => 'xxxxxx'.substr($pslBank, -4), 'bank_name' => $this->mk('psl_slips.bank_name')];
        $this->row('psl_slips', ['period_id' => $pslPeriod, 'payee_id' => $pslPayee, 'kind' => 'data', 'status' => 'active', 'data_enc' => \Saan\Modules\Payslip\Vault::sealJson($pslData), 'file_path' => null]);
        $this->row('psl_slips', ['period_id' => $pslPeriod, 'payee_id' => $pslPayee, 'kind' => 'pdf', 'status' => 'voided', 'data_enc' => null,
            'file_path' => $this->stash('payslip/1/pdf/'.$this->nonce.'.enc', Crypt::encryptString('%PDF-1.4 '.$this->plain($this->mk('psl_slips.pdf.bytes'), 'file:psl_slips pdf')))]);
        $this->row('psl_imports', ['period_id' => $pslPeriod, 'created_by' => $staff['finance'], 'status' => 'staged', 'payload_enc' => \Saan\Modules\Payslip\Vault::sealJson(['accepted' => [['payee_id' => $pslPayee, 'data' => $pslData]]]), 'file_sha256' => hash('sha256', $this->mk('psl_imports.sha'))]);
        $this->row('psl_certs', ['year' => 2026, 'payee_id' => $pslPayee, 'status' => 'issued', 'data_enc' => \Saan\Modules\Payslip\Vault::sealJson(['gross' => $pslGross, 'tax' => 0, 'sso' => 0, 'pf' => 0, 'slips' => 1]), 'src_sig' => hash('sha256', $this->mk('psl_certs.sig'))]);
        $this->row('student_classroom_moves', ['student_id' => $studentIds[0], 'from_classroom_id' => $roomA, 'to_classroom_id' => $roomB, 'effective_on' => date('Y-m-d')]);
        $this->row('leave_requests', ['teacher_id' => $tHidden, 'starts_on' => date('Y-m-d'), 'ends_on' => date('Y-m-d'), 'type' => 'sick', 'status' => 'pending', 'reason' => 'ผ่าตัดไส้ติ่ง '.$this->mk('leave.reason')]);
        $this->row('duties', ['teacher_id' => $tHidden, 'date' => date('Y-m-d')]);
        $this->row('lessons', ['teacher_id' => $tHidden, 'classroom_id' => $roomA, 'term_id' => $term, 'weekday' => 1, 'period_id' => 1]);

        // ---- documents (official letters, uploaded files)
        $book = $this->row('doc_books', ['year_be' => 2569, 'next_no' => 2]);
        $docNo = $this->plain('ศธ '.$this->digits(4).'/'.$this->digits(3).'-'.$this->mk('doc.no'), 'doc_documents.doc_no');
        $doc = $this->row('doc_documents', ['book_id' => $book, 'reg_no' => 1, 'doc_no' => $docNo, 'doc_date' => date('Y-m-d'), 'received_on' => date('Y-m-d'), 'registered_on' => date('Y-m-d'), 'subject' => $this->th('หนังสือลับเรื่องการสอบสวนวินัย').' '.$this->mk('doc.subject'), 'created_by' => $staff['principal']]);
        $this->row('doc_files', ['document_id' => $doc, 'path' => $this->stash("documents/$doc/letter-".$this->nonce.'.pdf', '%PDF-1.4 '.$this->plain($this->mk('doc_files.bytes'), 'file:doc_files'))]);
        $this->row('doc_routes', ['document_id' => $doc, 'to_user_id' => $staff['principal']]);

        // ---- other modules: one row each, every text column marked (API gateway, activities, exams, maintenance, projects, constructionism, reports, ...)
        $clientId = $this->row('api_clients', ['client_id' => 'cli-'.$this->mk('api.client'), 'secret_hash' => Hash::make($this->plain('ClientSecret-'.$this->mk('api.secret'), 'api_clients.secret.plain'))]);
        $this->row('api_tokens', ['client_id' => $clientId, 'token_hash' => hash('sha256', $this->plain('tok-'.$this->mk('api.token'), 'api_tokens.token.plain')), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->row('api_student_pins', ['student_id' => $studentIds[0], 'pin_hash' => Hash::make($this->plain($this->digits(6), 'api_student_pins.pin.plain'))]);
        $this->row('gp_links', ['guardian_id' => 1, 'student_id' => $studentIds[0], 'token_hash' => hash('sha256', $this->plain('link-'.$this->mk('gp.link'), 'gp_links.token.plain')), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
        // F8 student portal: a student's sign-in secret (api_student_pins above), what the student did, and verified contact e-mails of students and guardians; every text column is marked
        $this->row('stu_events', ['student_id' => $studentIds[0]]);
        $this->row('gp_contacts', ['subject_id' => $studentIds[0], 'email' => strtolower($this->mk('gp.contact.email')).'@kid.example.invalid', 'verified_at' => $now]);
        $this->row('audit_events', ['actor_id' => $staff['principal'], 'detail' => json_encode('เปลี่ยนรหัสผ่าน '.$this->mk('audit.detail'), JSON_UNESCAPED_UNICODE)]); // a JSON column
        $this->row('password_reset_tokens', ['email' => $this->needles[(string) DB::table('users')->where('id', $staff['teacher'])->value('email')] ? (string) DB::table('users')->where('id', $staff['teacher'])->value('email') : 'x', 'token' => Hash::make($this->plain('reset-'.$this->mk('reset.token'), 'password_reset_tokens.token.plain'))]);
        $this->row('sessions', ['id' => $this->mk('sessions.id'), 'user_id' => $staff['principal'], 'last_activity' => time()]);
        $this->row('school_mail_settings', ['encrypted_payload' => Crypt::encryptString(json_encode(['host' => 'smtp.internal.example', 'password' => $this->plain('Smtp-'.$this->mk('smtp.password'), 'school_mail_settings.password.plain')]))]);
        $this->row('system_updates', []);
        $this->row('cms_import_items', []);
        $this->row('cms_redirects', ['source_path' => '/'.$this->mk('redir.src'), 'target_path' => '/'.$this->mk('redir.dst')]);
        foreach (['saan_inbox_done', 'saan_inbox_quarantine'] as $t) if (Schema::hasTable($t)) $this->row($t, []);
        $this->row('act_clubs', ['advisor_teacher_id' => $tHidden]);
        $this->row('act_elections', ['key_secret' => $this->plain('elk-'.$this->mk('act.keysecret'), 'act_elections.key_secret')]);
        foreach (['act_attendance', 'act_ballots', 'act_candidates', 'act_certificates', 'act_counters', 'act_members', 'act_sessions', 'act_voters', 'asm_attributes', 'asm_courses', 'asm_subjects', 'att_class_days', 'att_corrections', 'bhv_rules',
            'con_archive_files', 'con_archives', 'con_artifacts', 'con_logs', 'con_mapping_runs', 'con_members', 'con_projects', 'con_reflections', 'con_rows', 'exm_exams', 'exm_sheets', 'hlth_measurements', 'ld_duty_swaps', 'ld_leave_types',
            'mnt_assets', 'mnt_request_logs', 'mnt_requests', 'mnt_stock_items', 'mnt_stock_moves', 'prj_activities', 'prj_plans', 'prj_projects', 'prj_reports', 'reg_import_batches', 'reg_import_rows', 'rpt_remarks', 'rpt_special_results',
            'rpt_templates', 'srv_counters', 'acad_calendar_days', 'api_request_log', 'api_sso_codes', 'gp_acks', 'gp_events', 'bell_periods'] as $t) {
            if (Schema::hasTable($t) && !DB::table($t)->exists()) $this->row($t, ($t === 'act_ballots' ? ['id' => $this->mk('act.ballot.id')] : []));
        }
        $this->row('exm_sheets', ['display_name' => $this->th('ใบตอบข้อสอบลับ').' '.$this->mk('exm.sheet'), 'score' => 17.5]);
        $this->row('con_reflections', ['student_id' => $studentIds[1], 'text' => 'บันทึกความรู้สึกส่วนตัว '.$this->mk('con.reflection')]);

        // ---- website content: what is published and what is not
        $cover = $this->row('cms_media', ['uploaded_by' => $author, 'path' => $this->stash('cms/1/'.$uuid(1).'.png', $png), 'mime' => 'image/png', 'bytes' => strlen($png), 'width' => 1, 'height' => 1, 'alt' => 'ภาพปกข่าว',
            'original_name' => $this->mk('media.cover.original').'.png', 'folder' => $this->th('โฟลเดอร์ส่วนตัวห้องครู')], true);
        $this->row('cms_media', ['uploaded_by' => $author, 'path' => $this->stash('cms/1/'.$uuid(2).'.png', $png.$this->plain($this->mk('media.unused.bytes'), 'file:unused media')), 'mime' => 'image/png', 'bytes' => strlen($png), 'width' => 1, 'height' => 1]);
        $this->row('posts', ['title' => 'ข่าวประชาสัมพันธ์โรงเรียน', 'slug' => 'public-news-1', 'status' => 'published', 'published_at' => $now, 'deleted_at' => null, 'cover_media_id' => $cover, 'author_id' => $author,
            'body' => '<p>เนื้อหาข่าวที่เผยแพร่แล้ว</p>', 'scheduled_at' => null, 'scheduled_by' => null], true);
        $this->row('posts', ['title' => $this->th('ร่างข่าวลับยังไม่เผยแพร่').' '.$this->mk('post.draft.title'), 'slug' => 'draft-'.$this->nonce, 'status' => 'draft', 'deleted_at' => null, 'cover_media_id' => null, 'author_id' => $author, 'scheduled_at' => null]);
        $this->row('posts', ['title' => $this->th('ข่าวที่ถูกลบทิ้งแล้วลับ').' '.$this->mk('post.deleted.title'), 'slug' => 'gone-'.$this->nonce, 'status' => 'published', 'deleted_at' => $now, 'cover_media_id' => null, 'author_id' => $author, 'scheduled_at' => null]);
        $this->row('cms_post_revisions', ['post_id' => 1, 'version' => 1, 'published_by' => $author, 'snapshot' => json_encode(['body' => $this->mk('post.revision.body')])]);
        $this->row('cms_messages', ['name' => $this->th('ผู้ส่งข้อความเดิมลับ').' '.$this->mk('msg.name'), 'email' => strtolower($this->mk('msg.email')).'@visitor.example.invalid', 'message' => 'ข้อความจากผู้ปกครอง '.$this->mk('msg.body')]);
        $this->needles[(string) DB::table('cms_messages')->value('email')] = 'cms_messages.email';
        DB::table('cms_site_settings')->update(['draft' => json_encode(['draft' => $this->mk('site.draft')])]);
        DB::table('cms_themes')->update(['draft' => json_encode(['draft' => $this->mk('theme.draft')])]);
        $this->row('saan_settings', ['key' => 'modules.disabled', 'value' => json_encode(['finance']), 'version' => 1], true);
        $this->row('saan_settings', ['key' => 'mail.smtp_password', 'value' => json_encode($this->plain('SettingsSmtp-'.$this->mk('settings.smtp'), 'saan_settings.mail.smtp_password')), 'version' => 1]);
        $this->row('saan_settings', ['key' => 'line.notify_token', 'value' => json_encode($this->plain('LineTok-'.$this->mk('settings.line'), 'saan_settings.line.token')), 'version' => 1]);
        app(\App\Support\Modules\ModuleRegistry::class)->flush();

        // ---- notices: one live list with full names configured, an expired one and a draft
        $cols = json_encode(['order_no', 'display_name']);
        $list = fn (string $slug, string $status, int $from, int $to, array $o = []) => $this->row('notice_lists', ['slug' => $slug, 'title' => 'ประกาศ '.$slug, 'columns' => $cols, 'name_mode' => 'full', 'publish_from' => date('Y-m-d', strtotime("$from days")),
            'expires_on' => date('Y-m-d', strtotime("$to days")), 'status' => $status, 'access_code_hash' => null] + $o, $status === 'published' && $to > 0);
        // F9: the full-name window of the live list has already passed (and the old list never had one): the public copy must hold masked names only
        $live = $list('live-list', 'published', -1, 10, ['full_name_until' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        $old = $list('old-list', 'published', -20, -1, ['full_name_until' => date('Y-m-d H:i:s', strtotime('-10 days'))]);
        $draft = $list('draft-list', 'draft', 0, 5, ['full_name_until' => date('Y-m-d H:i:s', strtotime('+3 days'))]);
        foreach ([[$live, 0], [$live, 1], [$old, 2], [$draft, 3]] as [$lid, $i]) {
            [, $first, $last] = $notice[$i];
            $this->row('notice_rows', ['list_id' => $lid, 'order_no' => $i + 1, 'display_name' => 'เด็กชาย'.$first.' '.$last.$this->mk("notice.$i.name"), 'school_id' => 1]);
        }

        // ---- surveys and what people answered
        $q = json_encode([['key' => 'q1', 'type' => 'text', 'label' => 'ข้อเสนอแนะ', 'required' => true]], JSON_UNESCAPED_UNICODE);
        $sv = $this->row('srv_surveys', ['slug' => 'survey-open', 'title' => 'แบบสอบถามความพึงพอใจ', 'questions' => $q, 'identity_mode' => 'anonymous', 'status' => 'open', 'opens_at' => null, 'closes_at' => null, 'certificate' => null, 'intro' => 'โปรดตอบ'], true);
        $this->row('srv_surveys', ['slug' => 'survey-draft', 'title' => $this->th('แบบสอบถามร่างลับสุดยอด').' '.$this->mk('survey.draft.title'), 'questions' => $q, 'identity_mode' => 'anonymous', 'status' => 'draft']);
        $holderFirst = 'นางสมหญิง';
        $holderLast = $this->th('รักเรียนลับสุดแสนนาน');
        $this->row('srv_responses', ['survey_id' => $sv, 'display_name' => $holderFirst.' '.$holderLast.' '.$this->mk('resp.holder'), 'answers' => json_encode(['q1' => 'คำตอบลับ '.$this->mk('resp.answer.1')], JSON_UNESCAPED_UNICODE), 'certificate_no' => 'SCH-2569-000001', 'score' => 9]);
        foreach ([2, 3] as $i) $this->row('srv_responses', ['survey_id' => $sv, 'display_name' => $this->th('ผู้ตอบแบบสอบถามลับ'.$i).' '.$this->mk("resp.$i.name"), 'answers' => json_encode(['q1' => 'ความเห็นส่วนตัว '.$this->mk("resp.answer.$i")], JSON_UNESCAPED_UNICODE), 'certificate_no' => null]);
        $this->row('srv_pending', ['survey_id' => $sv, 'display_name' => $this->mk('pending.name'), 'answers' => json_encode(['q1' => $this->mk('pending.answer')]), 'response_id' => null, 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->row('srv_roster', ['survey_id' => $sv, 'display_name' => $this->th('รายชื่อผู้มีสิทธิ์ตอบลับ').' '.$this->mk('roster.name'), 'used_at' => null]);

        // ---- learning links: a live room, an expired room and a course-only link
        $this->row('lrn_access', ['classroom_id' => $roomA, 'code_hash' => str_repeat('e', 64), 'expires_at' => date('Y-m-d H:i:s', time() + 3 * 86400), 'created_by' => $author], true);
        $this->row('lrn_access', ['classroom_id' => $roomB, 'code_hash' => hash('sha256', $this->plain($this->mk('lrn.expired.code'), 'lrn_access.expired.code')), 'expires_at' => date('Y-m-d H:i:s', time() - 86400), 'created_by' => $author]);
        $lk = fn (string $scope, string $url, array $o, bool $exp) => $this->row('lrn_links', ['scope_key' => $scope, 'url' => $url, 'url_hash' => sha1($url), 'term_id' => null, 'api_client_id' => null, 'created_by' => $author] + $o, $exp);
        $lk('all', 'https://example.test/all', ['title' => 'ลิงก์ทั้งโรงเรียน', 'classroom_id' => null, 'course_id' => null], true);
        $lk('c1', 'https://example.test/c1', ['title' => 'ลิงก์ห้อง ป.6/1', 'classroom_id' => $roomA, 'course_id' => null], true);
        $lk('k5', 'https://example.test/'.$this->mk('lrn.course.url'), ['title' => $this->th('ลิงก์รายวิชาลับ').' '.$this->mk('lrn.course.title'), 'classroom_id' => null, 'course_id' => 5], false);

        // ---- ITA: a published year and a draft year with topic and item each
        $y = $this->row('ita_years', ['fiscal_year' => 2569, 'title' => 'ITA 2569', 'status' => 'published', 'published_at' => $now], true);
        $yd = $this->row('ita_years', ['fiscal_year' => 2570, 'title' => $this->th('ITA ปีร่างลับ').' '.$this->mk('ita.draft.year'), 'status' => 'draft']);
        $t = $this->row('ita_topics', ['year_id' => $y, 'code' => 'O1', 'title' => 'ข้อมูลพื้นฐาน'], true);
        $td = $this->row('ita_topics', ['year_id' => $yd, 'title' => $this->mk('ita.draft.topic')]);
        $this->row('ita_items', ['topic_id' => $t, 'title' => 'โครงสร้างองค์กร', 'url' => 'https://example.test/ita', 'media_id' => null], true);
        $this->row('ita_items', ['topic_id' => $td, 'title' => $this->mk('ita.draft.item'), 'media_id' => null]);
        $this->row('social_feeds', ['facebook_url' => 'https://facebook.com/school.example', 'heading' => 'เพจโรงเรียน', 'height' => 500, 'enabled' => 1], true);
        $this->row('cms_categories', ['slug' => 'news-'.$this->nonce, 'label' => 'ข่าวทั่วไป'], true);
        $this->row('rpt_templates', []);

        // ---- group hubs (F4): a published hub with a visible category and a published document is public (its internal note and the document note are not); a draft hub, a hidden
        // category, an unpublished document and a document with a plain-http link are not, and neither are their files. Download counts never leave (cms_hub_downloads is forbidden).
        $pdf = fn (string $label, string $bytes) => $this->stash('cms/1/'.$uuid($label === 'live' ? 7 : ($label === 'draft' ? 8 : ($label === 'hidden' ? 9 : 5))).'.pdf', '%PDF-1.4 '.$bytes);
        $hubLive = $this->row('cms_hubs', ['slug' => 'hub-'.$this->nonce, 'kind' => 'work', 'status' => 'published', 'group_name' => 'วิชาการ', 'news_category' => null, 'news_tag' => null], true);
        $hubCatLive = $this->row('cms_hub_categories', ['hub_id' => $hubLive, 'parent_id' => null, 'label' => 'คำสั่ง/ประกาศ', 'is_hidden' => 0], true);
        $hubCatHidden = $this->row('cms_hub_categories', ['hub_id' => $hubLive, 'parent_id' => null, 'label' => $this->th('หมวดซ่อนลับสุดยอด').' '.$this->mk('hub.category.hidden'), 'is_hidden' => 1]);
        $hubMediaLive = $this->row('cms_media', ['uploaded_by' => $author, 'path' => $pdf('live', 'public order'), 'mime' => 'application/pdf', 'bytes' => 30, 'width' => 0, 'height' => 0, 'alt' => 'คำสั่งสาธิต',
            'original_name' => $this->mk('hub.media.original').'.pdf', 'folder' => 'เอกสารกลุ่มงาน'], true);
        $hubDoc = $this->row('cms_hub_documents', ['hub_id' => $hubLive, 'category_id' => $hubCatLive, 'title' => 'คำสั่งแต่งตั้งคณะกรรมการสาธิต', 'doc_date' => date('Y-m-d'), 'media_id' => $hubMediaLive, 'file_name' => 'order.pdf',
            'external_url' => null, 'is_published' => 1], true);
        $hubMediaDraft = $this->row('cms_media', ['uploaded_by' => $author, 'path' => $pdf('draft', $this->plain($this->mk('hub.draft.doc.bytes'), 'file:hub unpublished document')), 'mime' => 'application/pdf', 'bytes' => 30, 'width' => 0, 'height' => 0, 'folder' => 'เอกสารกลุ่มงาน']);
        $this->row('cms_hub_documents', ['hub_id' => $hubLive, 'category_id' => $hubCatLive, 'title' => $this->th('เอกสารร่างยังไม่เผยแพร่ลับ').' '.$this->mk('hub.doc.draft.title'), 'doc_date' => date('Y-m-d'), 'media_id' => $hubMediaDraft,
            'file_name' => $this->mk('hub.doc.draft.file').'.pdf', 'external_url' => null, 'is_published' => 0]);
        $hubMediaHidden = $this->row('cms_media', ['uploaded_by' => $author, 'path' => $pdf('hidden', $this->plain($this->mk('hub.hidden.doc.bytes'), 'file:hub document in a hidden category')), 'mime' => 'application/pdf', 'bytes' => 30, 'width' => 0, 'height' => 0, 'folder' => 'เอกสารกลุ่มงาน']);
        $this->row('cms_hub_documents', ['hub_id' => $hubLive, 'category_id' => $hubCatHidden, 'title' => $this->th('เอกสารในหมวดซ่อนลับ').' '.$this->mk('hub.doc.hidden.title'), 'doc_date' => date('Y-m-d'), 'media_id' => $hubMediaHidden,
            'file_name' => $this->mk('hub.doc.hidden.file').'.pdf', 'external_url' => null, 'is_published' => 1]);
        $this->row('cms_hub_documents', ['hub_id' => $hubLive, 'category_id' => $hubCatLive, 'title' => $this->th('ลิงก์ http ไม่ปลอดภัยลับ').' '.$this->mk('hub.doc.httplink.title'), 'doc_date' => date('Y-m-d'), 'media_id' => null, 'file_name' => null,
            'external_url' => 'http://hub-link.example.invalid/'.$this->mk('hub.doc.httplink.url'), 'is_published' => 1]);
        $hubDraft = $this->row('cms_hubs', ['slug' => 'hubdraft-'.$this->nonce, 'kind' => 'subject', 'status' => 'draft', 'title' => $this->th('หน้ากลุ่มฉบับร่างลับสุดยอด').' '.$this->mk('hub.draft.title'), 'news_category' => null, 'news_tag' => null]);
        $hubDraftCat = $this->row('cms_hub_categories', ['hub_id' => $hubDraft, 'parent_id' => null, 'label' => $this->th('หมวดของหน้าร่างลับ').' '.$this->mk('hub.draft.category'), 'is_hidden' => 0]);
        $hubMediaDraftHub = $this->row('cms_media', ['uploaded_by' => $author, 'path' => $pdf('other', $this->plain($this->mk('hub.drafthub.doc.bytes'), 'file:hub document of a draft hub')), 'mime' => 'application/pdf', 'bytes' => 30, 'width' => 0, 'height' => 0, 'folder' => 'เอกสารกลุ่มงาน']);
        $this->row('cms_hub_documents', ['hub_id' => $hubDraft, 'category_id' => $hubDraftCat, 'title' => $this->th('เอกสารของหน้าร่างลับ').' '.$this->mk('hub.drafthub.doc.title'), 'doc_date' => date('Y-m-d'), 'media_id' => $hubMediaDraftHub,
            'file_name' => $this->mk('hub.drafthub.doc.file').'.pdf', 'external_url' => null, 'is_published' => 1]);
        $this->row('cms_hub_downloads', ['document_id' => $hubDoc, 'day' => date('Y-m-d'), 'n' => 4141, 'sent_n' => 0]);

        // ---- complaints (F1): text, contact, notes, files and accused stay internal. Only receipt number, the HMAC of the tracking code, status, dates and the public reply may reach
        // pub_complaint_status (the spec allows exactly these), so those columns carry plain values and the HMAC is exempt from the secret column needles in harvest().
        $cmpName = $this->th('ผู้ร้องเรียนลับสุดยอดที่สุด');
        $cmpMail = $this->plain(strtolower($this->mk('cmp.contact.email')).'@complainant.example.invalid', 'cmp_cases.contact.email');
        $cmpPhone = $this->plain('08'.$this->digits(8), 'cmp_cases.contact.phone');
        $cmpCase = $this->row('cmp_cases', ['receipt_no' => 'C69-ABCDEFGH', 'track_hash' => hash('sha256', 'public-demo-hmac'), 'category' => 'corruption', 'anonymous' => 0, 'status' => 'replied',
            'subject' => $this->th('หัวข้อเรื่องทุจริตลับสุดขีด').' '.$this->mk('cmp.subject'), 'detail' => $this->th('รายละเอียดการทุจริตลับสุดขีด').' '.$this->mk('cmp.detail'),
            'contact' => Crypt::encryptString(json_encode(['name' => $cmpName, 'email' => $cmpMail, 'phone' => $cmpPhone], JSON_UNESCAPED_UNICODE)),
            'public_reply' => 'โรงเรียนได้รับเรื่องและกำลังดำเนินการ (ข้อความสาธิต)', 'public_reply_at' => $now, 'assignee_id' => null, 'closed_at' => null, 'due_on' => date('Y-m-d', strtotime('+30 days')), 'received_at' => $now, 'status_changed_at' => $now]);
        $this->row('cmp_notes', ['case_id' => $cmpCase, 'user_id' => $staff['principal'], 'body' => $this->th('บันทึกภายในเรื่องทุจริตลับ').' '.$this->mk('cmp.note.body')]);
        $this->row('cmp_actions', ['case_id' => $cmpCase, 'actor_id' => $staff['principal'], 'action' => 'note', 'detail' => json_encode(['x' => $this->mk('cmp.action.detail')])]);
        $this->row('cmp_accused', ['case_id' => $cmpCase, 'user_id' => $staff['teacher']]);
        $this->row('cmp_files', ['case_id' => $cmpCase, 'name' => $this->th('หลักฐานลับสุดขีด').'.png', 'path' => $this->stash('complaints/1/'.str_repeat('c', 32).'.png', $png.$this->plain($this->mk('cmp.file.bytes'), 'file:cmp_files')), 'mime' => 'image/png']);
    }

    /** Collects every marker and every secret-looking value that exists in the internal database. */
    private function harvest(): void
    {
        $re = '/BRX'.$this->nonce.'-[A-Za-z0-9.]+/';
        foreach ($this->tempDbTables($this->intDb) as $t) { // of this database only: the schema builder would list every database the account can see
            if ($t === 'migrations' || str_starts_with($t, 'pub_')) continue;
            $rules = $this->declared()[$t] ?? [];
            $sens = [];
            foreach ($this->cols($t) as $c) if (preg_match('/(password|secret|token|_hash$|_enc$|encrypted|mfa_secret|mfa_recovery)/', $c['name']) && ($rules[$c['name']] ?? null) !== 'keep' && !($t === 'cmp_cases' && $c['name'] === 'track_hash')) $sens[$c['name']] = true; // the HMAC of a tracking code is what pub_complaint_status is allowed to carry
            foreach (DB::table($t)->get() as $row) {
                foreach ((array) $row as $col => $v) {
                    if (!is_string($v) || $v === '') continue;
                    if (preg_match_all($re, $v, $m)) foreach ($m[0] as $tok) $this->needles[$tok] = "$t.$col";
                    if (isset($sens[$col]) && strlen($v) >= 8) $this->needles[$v] = "$t.$col (secret column)";
                }
            }
        }
        foreach (DB::table('users')->get(['name', 'email', 'password']) as $u) foreach ([$u->name, $u->email, $u->password] as $v) if (is_string($v) && strlen($v) >= 8) $this->needles[$v] ??= 'users.*';
        foreach (array_keys($this->needles) as $n) if (strlen((string) $n) < 6) unset($this->needles[$n]);
        // the internal installation's own secrets
        foreach (['push.secret', 'pull.secret', 'inbox.secret'] as $f) $this->needles[(string) Keys::read($f)] = "internal key file $f";
        $this->needles[$this->hostKey] = 'internal APP_KEY (host)';
        $intEnv = \Dotenv\Dotenv::parse((string) file_get_contents($this->intRoot.'/.env'));
        $this->needles[$intEnv['APP_KEY']] = 'internal APP_KEY (.env)';
        $this->needles[substr($intEnv['APP_KEY'], 7)] = 'internal APP_KEY body';
        $this->needles[$this->plan['creds']['internal']['db_password']] = 'internal DB password';
        $this->needles[$this->plan['creds']['internal']['db_user']] = 'internal DB user';
        $this->assertGreaterThan(300, count($this->needles), 'the data set must carry hundreds of markers');
    }

    /** Public forms hand sealed envelopes to the public database. */
    private function publicForms(): void
    {
        $png = base64_decode(self::PNG);
        $this->publicSide(function () use ($png) {
            for ($i = 1; $i <= 3; $i++) {
                $msg = 'ร้องเรียนเรื่องครูใช้ความรุนแรง '.$this->mk("inbox.contact.$i.message");
                $p = ['name' => $this->th('ผู้ร้องเรียนนิรนามลับ'.$i).' '.$this->mk("inbox.contact.$i.name"), 'email' => strtolower($this->mk("inbox.contact.$i.email")).'@complainer.example.invalid', 'subject' => 'เรื่องร้องเรียน '.$this->mk("inbox.contact.$i.subject"), 'message' => $msg];
                foreach ($p as $k => $v) $this->needles[$v] = "inbox contact $i $k";
                $res = Inbox::submit('contact', $p, $i === 1 ? [['name' => 'evidence-'.$this->nonce.'.png', 'content' => $png.$this->plain($this->mk('inbox.attachment.bytes'), 'file:inbox attachment')]] : []);
                $this->assertSame('sealed', $res['mode']);
                $this->submitted[] = ['contact', $p];
            }
            $ans = 'ข้อเสนอแนะที่เป็นความลับ '.$this->mk('inbox.survey.answer');
            $this->needles[$ans] = 'inbox survey answer';
            Inbox::submit('survey', ['slug' => 'survey-open', 'input' => ['a' => ['q1' => $ans]], 'cookie' => md5($this->nonce), 'ip' => '203.0.113.7']);
            $this->submitted[] = ['survey', ['a' => $ans]];
        });
    }

    // ---------------------------------------------------------------- the loot

    private function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $dest = $to.'/'.substr($f->getPathname(), strlen($from) + 1);
            $f->isDir() ? @mkdir($dest, 0777, true) : copy($f->getPathname(), $dest);
        }
    }

    /**
     * Everything the public installation holds on disk, copied to a place the test can read and modify freely. Its database is copied for each
     * test (setUp), from the world's public database, which nothing changes once the world is built.
     */
    private function takeLoot(): void
    {
        DB::purge('saan_public');
        DB::purge('saan_internal');
        $this->lootSrc = $this->dir();
        $this->copyTree($this->pubRoot, $this->lootSrc.'/root');
        $this->asInternal();
    }

    /** @return array<string,string> label => bytes of every file, of the database dumps and of the table definitions */
    private function lootBlobs(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->lootDir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) $out[substr($f->getPathname(), strlen($this->lootDir) + 1)] = (string) file_get_contents($f->getPathname());
        }
        $dump = [];
        $schema = '';
        foreach ($this->tempDbTables($this->lootDb) as $t) {
            $dump[$t] = DB::connection('loot')->table($t)->get()->all();
            $schema .= (string) (((array) DB::connection('loot')->selectOne('SHOW CREATE TABLE `'.$t.'`'))['Create Table'] ?? '')."\n";
        }
        $out['(dump) every row of every public table, unicode raw'] = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $out['(dump) every row of every public table, unicode escaped'] = json_encode($dump, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        // what the bytes of the database itself would show: every stored value as it is (no JSON escaping), read by a connection of its own, and the table definitions
        $out['(dump) every stored value of every public table, as stored'] = $this->tempDbDump($this->lootDb);
        $out['(dump) definition of every public table'] = $schema;
        return $out;
    }

    /** @return list<string> the encodings an attacker would try for one needle (lower case; the haystack is lower cased too) */
    private function variants(string $n): array
    {
        $v = [strtolower($n)];
        $json = trim((string) json_encode($n, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), '"');
        $v[] = strtolower($json);
        $v[] = strtolower(rawurlencode($n));
        $v[] = strtolower(urlencode($n));
        $v[] = bin2hex($n);
        if (strlen($n) >= 9) foreach ([0, 1, 2] as $k) {
            $enc = substr(base64_encode(str_repeat("\0", $k).$n), [0, 2, 3][$k], -4);
            if (strlen($enc) >= 8) $v[] = strtolower($enc);
        }
        return array_values(array_unique(array_filter($v, fn ($x) => strlen($x) >= 6)));
    }

    /** @return list<string> "needle-origin in file" for every needle found in any blob */
    private function scan(array $blobs, ?array $needles = null): array
    {
        $needles ??= $this->needles;
        $hits = [];
        $low = array_map('strtolower', $blobs);
        foreach ($needles as $n => $origin) {
            $n = (string) $n;
            $base64 = strlen($n) >= 9;
            foreach ($this->variants($n) as $v) {
                foreach ($low as $file => $bytes) {
                    // base64 slices are case sensitive: look them up in the original bytes
                    if (strpos($bytes, $v) !== false) $hits[] = "$origin in $file";
                }
            }
            if ($base64) foreach ($this->variants($n) as $v) foreach ($blobs as $file => $bytes) if (strlen($v) >= 8 && strpos($bytes, $v) !== false && !in_array("$origin in $file", $hits, true)) $hits[] = "$origin in $file";
        }
        return array_values(array_unique($hits));
    }

    // ---------------------------------------------------------------- (1) full text scan

    public function test_a_full_text_scan_of_the_public_database_and_every_public_file_finds_no_internal_marker(): void
    {
        $blobs = $this->lootBlobs();
        $this->assertGreaterThan(8, count($blobs));
        // the loot is not empty: the published content really reached the public side (otherwise "nothing found" would prove nothing)
        $pub = DB::connection('loot');
        $this->assertSame(['public-news-1'], $pub->table('posts')->pluck('slug')->all());
        $this->assertSame(['live-list'], $pub->table('notice_lists')->pluck('slug')->all());
        $this->assertSame(['SCH-2569-000001'], $pub->table('pub_certificates')->pluck('number')->all());
        $this->assertSame(2, $pub->table('pub_notice_rows')->count(), 'the two masked rows of the live list');
        $this->assertSame(['survey-open'], $pub->table('srv_surveys')->pluck('slug')->all());
        $this->assertSame(['ITA 2569'], $pub->table('ita_years')->pluck('title')->all());
        $this->assertSame(['ลิงก์ทั้งโรงเรียน', 'ลิงก์ห้อง ป.6/1'], $pub->table('lrn_links')->orderBy('id')->pluck('title')->all());
        $this->assertSame(1, $pub->table('teachers')->count());
        $this->assertSame(['C69-ABCDEFGH'], $pub->table('pub_complaint_status')->pluck('receipt_no')->all(), 'the complaint reached the public side as its status row only');
        $this->assertFileDoesNotExist($this->pubRoot.'/storage/app/private/complaints/1/'.str_repeat('c', 32).'.png', 'complaint attachments never leave the internal side');
        $this->assertSame(['public-news-1'], $pub->table('posts')->pluck('slug')->all());
        $this->assertSame(4, $pub->table('pub_inbox')->count(), 'four sealed envelopes are waiting');
        $this->assertFileExists($this->pubRoot.'/storage/app/private/cms/1/'.str_repeat('1', 8).'-'.str_repeat('1', 4).'-4'.str_repeat('1', 3).'-8'.str_repeat('1', 3).'-'.str_repeat('1', 12).'.png');
        $this->assertFileExists($this->pubRoot.'/storage/app/private/personnel/1/'.str_repeat('a', 32).'.jpg');
        $this->assertFileDoesNotExist($this->pubRoot.'/storage/app/private/personnel/1/'.str_repeat('b', 32).'.jpg', 'the photo of a teacher who is not in the directory');
        $this->assertFileDoesNotExist($this->pubRoot.'/storage/app/private/cms/1/'.str_repeat('2', 8).'-'.str_repeat('2', 4).'-4'.str_repeat('2', 3).'-8'.str_repeat('2', 3).'-'.str_repeat('2', 12).'.png', 'unreferenced media');

        // F4 group hubs: the published hub, its visible category and its one published document with a file are public; drafts, hidden categories, unpublished documents, plain-http links and every count are not
        $this->assertSame(['hub-'.$this->nonce], $pub->table('cms_hubs')->pluck('slug')->all());
        $this->assertSame(1, $pub->table('cms_hub_categories')->count(), 'only the visible category of the published hub');
        $this->assertSame(['คำสั่งแต่งตั้งคณะกรรมการสาธิต'], $pub->table('cms_hub_documents')->pluck('title')->all());
        $this->assertSame(0, $pub->table('cms_hub_downloads')->count(), 'download counts never leave the internal side');
        $pdfAt = fn (int $i) => $this->pubRoot.'/storage/app/private/cms/1/'.str_repeat((string) $i, 8).'-'.str_repeat((string) $i, 4).'-4'.str_repeat((string) $i, 3).'-8'.str_repeat((string) $i, 3).'-'.str_repeat((string) $i, 12).'.pdf';
        $this->assertFileExists($pdfAt(7));
        foreach ([8 => 'unpublished document', 9 => 'document in a hidden category', 5 => 'document of a draft hub'] as $i => $why) $this->assertFileDoesNotExist($pdfAt($i), $why);

        $hits = $this->scan($blobs);
        $this->assertSame([], $hits, count($hits).' internal values found on the public side: '.implode(' | ', array_slice($hits, 0, 15)));
    }

    public function test_f9_with_turnstile_on_the_public_side_holds_masked_names_for_expired_windows_and_full_names_only_inside_one(): void
    {
        $this->assertTrue(\App\Support\Saan\Turnstile::enabled(), 'Turnstile is on in this world');
        $pub = DB::connection('loot');
        // the shipped world: both windows have passed, so the public copy carries no full-name cell at all
        $this->assertSame(0, $pub->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
        $this->assertSame(0, $pub->table('pub_notice_rows')->where('cells', 'like', '%BRX%')->count(), 'the markers of the full names never reached the public copy');

        // a list whose window is still open DOES reach the public side in full (so the assertions above can fail), and loses it again at expiry
        $this->forkDatabases();
        $live = (int) DB::table('notice_lists')->where('slug', 'live-list')->value('id');
        $names = DB::table('notice_rows')->where('list_id', $live)->pluck('display_name')->all();
        $this->assertCount(2, $names);
        DB::table('notice_lists')->where('id', $live)->update(['full_name_until' => date('Y-m-d H:i:s', strtotime('+2 hours')), 'version' => DB::raw('version + 1')]);
        \App\Support\Saan\Publish\Outbox::touch(['pub_notice_rows'], false);
        config(['saan.push.receive_disk' => 'breach_public']);
        $r = $this->publisher()->run(120);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $p = DB::connection('saan_public');
        $this->assertSame(2, $p->table('pub_notice_rows')->whereNotNull('full_name_until')->count(), 'inside the window the full cells are on the public side');
        $dump = json_encode($p->table('pub_notice_rows')->get()->all(), JSON_UNESCAPED_UNICODE);
        foreach ($names as $n) $this->assertStringContainsString($n, $dump);

        // two hours later the very same scheduler tick sends masked cells (nobody edited anything)
        $this->travel(3)->hours();
        $r = $this->publisher()->run(120);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $this->assertSame(0, $p->table('pub_notice_rows')->whereNotNull('full_name_until')->count());
        $dump = json_encode($p->table('pub_notice_rows')->get()->all(), JSON_UNESCAPED_UNICODE);
        foreach ($names as $n) $this->assertStringNotContainsString($n, $dump, 'the full name is gone after the window');
        $this->assertStringNotContainsString('BRX', $dump);
    }

    public function test_the_scanner_finds_a_planted_leak_in_every_kind_of_place(): void
    {
        $marker = $this->plain($this->mk('planted.control'), 'planted');
        $thai = $this->th('ข้อมูลลับที่ถูกวางไว้ทดสอบเครื่องสแกน');
        $b = $this->lootBlobs();
        $this->assertSame([], $this->scan($b, [$marker => 'planted', $thai => 'thai']), 'control: clean before planting');
        foreach (['row' => fn () => DB::connection('loot')->table('posts')->update(['title' => $marker.$thai]), 'file' => fn () => file_put_contents($this->lootDir.'/root/storage/app/private/stray.txt', base64_encode($marker).bin2hex($thai))] as $how => $plant) {
            $plant();
            $hits = $this->scan($this->lootBlobs(), [$marker => 'planted', $thai => 'thai']);
            $this->assertNotEmpty($hits, "the scan must notice a leak planted as a $how");
        }
    }

    // ---------------------------------------------------------------- (2) allowlist

    public function test_no_table_outside_the_projection_allowlist_has_rows_and_no_undeclared_column_carries_data(): void
    {
        $pub = DB::connection('loot');
        $declared = $this->declared();
        $infra = ['users', 'migrations', 'pub_sync_state', 'pub_inbox', 'pub_inbox_nonces', 'pub_active_modules'];
        $allowed = array_merge(array_keys($declared), $infra);
        $tables = $this->tempDbTables($this->lootDb);
        $this->assertGreaterThan(100, count($tables), 'the copy of the public database has the full schema');
        $withRows = [];
        foreach ($tables as $t) if ($pub->table($t)->exists()) $withRows[] = $t;
        $this->assertSame([], array_values(array_diff($withRows, $allowed)), 'tables with rows that nobody declared');
        // every forbidden table is empty
        foreach ($tables as $t) {
            if (Projection::isForbiddenTable($t) && !in_array($t, ['saan_settings', 'migrations', 'users', 'pub_sync_state', 'pub_inbox', 'pub_inbox_nonces'], true)) $this->assertSame(0, $pub->table($t)->count(), $t.' must be empty');
        }
        $this->assertSame(['placeholder'], $pub->table('users')->pluck('role')->all(), 'the only user is the placeholder that authors and uploaders point at');
        $this->assertSame(0, (int) $pub->table('users')->where('is_active', 1)->count());
        $this->assertSame(['modules.disabled'], $pub->table('saan_settings')->pluck('key')->all());
        // the public edition never keeps the sender's bookkeeping
        $this->assertFalse(in_array('pub_outbox', $withRows, true));
        $this->assertFalse(in_array('pub_sent_rows', $withRows, true));
        // column level: what the declaration does not keep is null / the constant on every row
        foreach ($declared as $table => $rules) {
            if (!Schema::connection('loot')->hasTable($table)) continue;
            $info = Schema::connection('loot')->getColumns($table);
            $cols = array_column($info, 'name');
            $defaults = array_column($info, 'default', 'name');
            foreach ($pub->table($table)->get() as $row) {
                foreach ($cols as $c) {
                    $v = $row->$c ?? null;
                    $rule = $rules[$c] ?? null;
                    $default = isset($defaults[$c]) ? trim((string) $defaults[$c], "'\"") : null;
                    if ($rule === null) $this->assertTrue($v === null || $v === '' || $v === 0 || $v === '0' || ($default !== null && (string) $v === $default), "$table.$c is not declared but holds data");
                    elseif ($rule === 'null') $this->assertNull($v, "$table.$c");
                    elseif (str_starts_with($rule, 'const:')) $this->assertSame(substr($rule, 6), (string) $v, "$table.$c");
                }
            }
        }
        // masked names stay masked
        $cells = $pub->table('pub_notice_rows')->pluck('cells')->implode(' ');
        $this->assertStringNotContainsString('ศรีลับสุดแสน', $cells);
        $this->assertStringNotContainsString('บุญมีลับมาก', $cells);
        $this->assertStringContainsString('เด็กชายอชิรวิชญ์', $cells, 'the first name is what the masked notice shows');
        $this->assertStringNotContainsString('รักเรียนลับสุดแสนนาน', (string) $pub->table('pub_certificates')->value('holder_name'));
        // inbox envelopes are ciphertext only
        foreach ($pub->table('pub_inbox')->get() as $e) {
            $this->assertSame(1, preg_match('/^[A-Za-z0-9+\/=]+$/D', $e->ciphertext), 'ciphertext is a base64 blob');
            $this->assertStringNotContainsString('"payload"', (string) base64_decode($e->ciphertext));
        }
    }

    // ---------------------------------------------------------------- (3) envelopes

    /** @return list<string> every 32 byte value an attacker can derive from anything found on the public side */
    private function candidateSecrets(): array
    {
        $strings = [];
        foreach ($this->lootBlobs() as $label => $bytes) {
            if (str_starts_with($label, '(dump)')) continue;
            if (strlen($bytes) < 4096) {
                $strings[] = trim($bytes);
                foreach (preg_split('/\R/', $bytes) ?: [] as $line) {
                    $strings[] = trim($line);
                    if (str_contains($line, '=')) { $strings[] = trim(trim(explode('=', $line, 2)[1]), '"\''); }
                }
            }
            if (is_file($this->lootDir.'/'.$label) && strlen($bytes) >= 32 && strlen($bytes) <= 64) $strings[] = $bytes;
        }
        foreach (\Dotenv\Dotenv::parse((string) file_get_contents($this->lootDir.'/root/.env')) as $v) $strings[] = (string) $v;
        $pub = DB::connection('loot');
        foreach ($this->tempDbTables($this->lootDb) as $t) {
            foreach ($pub->table($t)->limit(2000)->get() as $row) foreach ((array) $row as $v) if (is_string($v) && strlen($v) >= 16 && strlen($v) <= 200) $strings[] = $v;
        }
        $strings[] = (string) config('app.key');
        $out = [];
        foreach (array_unique(array_filter($strings, fn ($s) => $s !== '')) as $s) {
            $forms = [$s, hash('sha256', $s, true), hash('sha256', $s . $s, true), hash_hmac('sha256', 'saan', $s, true)];
            if (str_starts_with($s, 'base64:')) $forms[] = (string) base64_decode(substr($s, 7), true);
            if (ctype_xdigit($s) && strlen($s) % 2 === 0) $forms[] = (string) hex2bin($s);
            $b = base64_decode($s, true);
            if ($b !== false) $forms[] = $b;
            foreach ($forms as $f) {
                if (strlen($f) === 32) { $out[$f] = true; $out[hash('sha256', $f, true)] = true; }
                if (strlen($f) === 64) {
                    $out[substr($f, 0, 32)] = true; // an Ed25519 secret key is seed + public key
                    try { $out[sodium_crypto_sign_ed25519_sk_to_curve25519($f)] = true; } catch (\Throwable) {}
                }
                if (strlen($f) === 32) { try { $out[sodium_crypto_sign_ed25519_pk_to_curve25519($f)] = true; } catch (\Throwable) {} }
            }
        }
        return array_map('strval', array_keys($out));
    }

    private function opens(string $cipherB64, string $secret): bool
    {
        try {
            $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, sodium_crypto_box_publickey_from_secretkey($secret));
            return @sodium_crypto_box_seal_open((string) base64_decode($cipherB64), $kp) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_no_key_or_secret_found_on_the_public_side_opens_an_inbox_envelope(): void
    {
        $rows = DB::connection('loot')->table('pub_inbox')->get();
        $this->assertCount(4, $rows);
        $cands = $this->candidateSecrets();
        $this->assertGreaterThan(30, count($cands), 'the attacker has dozens of candidate keys');
        // the public inbox key itself is among them: it is a public key, and used as a secret it opens nothing
        $peerInbox = (string) base64_decode(trim((string) file_get_contents($this->pubKeys.'/peer-inbox.pub')), true);
        $this->assertContains($peerInbox, $cands);
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = $r->ciphertext;
            foreach (json_decode((string) $r->attachments, true) ?: [] as $a) $parts[] = $a['c'];
        }
        $this->assertGreaterThanOrEqual(5, count($parts), 'envelopes and the attachment');
        foreach ($cands as $sk) foreach ($parts as $ct) $this->assertFalse($this->opens($ct, $sk), 'a key from the public side opened an envelope');
        // control: the real internal secret opens every one of them (so a failure above would have been seen)
        $real = Keys::inboxSecret();
        foreach ($parts as $ct) $this->assertTrue($this->opens($ct, $real), 'control: the internal inbox key opens it');
        // and the retired/rotated keys of the internal side are not on the public side either
        $this->assertSame([], glob($this->pubKeys.'/inbox*'), 'no inbox secret file on the public side');
    }

    // ---------------------------------------------------------------- (4) nothing that authenticates to internal

    public function test_the_public_side_holds_nothing_that_can_authenticate_to_the_internal_side(): void
    {
        // key directory: public halves and the unlock key only
        $files = array_map('basename', glob($this->lootDir.'/root/storage/app/private/saan-keys/*'));
        sort($files);
        $this->assertSame(['peer-inbox.pub', 'peer-pull.pub', 'peer-push.pub', 'peer-unlock.key'], $files);
        foreach (['peer-inbox.pub' => 32, 'peer-pull.pub' => 32, 'peer-push.pub' => 32] as $f => $len) $this->assertSame($len, strlen((string) base64_decode(trim((string) file_get_contents($this->pubKeys."/$f")), true)), "$f is a public key, not a secret key");
        $this->assertSame([], glob($this->lootDir.'/root/storage/app/private/saan-keys/*secret*'));
        $this->assertSame([], glob($this->lootDir.'/root/storage/app/private/saan-keys/*.old.*'));
        // none of the internal secrets, in any encoding, anywhere on the public side
        $secrets = [];
        foreach (['push.secret', 'pull.secret', 'inbox.secret'] as $f) {
            $raw = (string) Keys::read($f);
            $secrets[$f] = $raw;
            $secrets["$f (raw bytes)"] = (string) base64_decode($raw, true);
            if (strlen($secrets["$f (raw bytes)"]) === 64) $secrets["$f (seed)"] = substr($secrets["$f (raw bytes)"], 0, 32);
        }
        $intEnv = \Dotenv\Dotenv::parse((string) file_get_contents($this->intRoot.'/.env'));
        $secrets += ['internal APP_KEY' => $intEnv['APP_KEY'], 'internal APP_KEY body' => substr($intEnv['APP_KEY'], 7), 'host APP_KEY' => $this->hostKey, 'internal DB password' => $this->plan['creds']['internal']['db_password'],
            'internal DB user' => $this->plan['creds']['internal']['db_user'], 'internal DB name' => $this->plan['creds']['internal']['db_name'], 'internal root' => $this->intRoot];
        $hits = $this->scan($this->lootBlobs(), array_flip(array_filter($secrets, fn ($s) => strlen($s) >= 8)));
        $this->assertSame([], $hits, implode(' | ', $hits));
        // the public .env belongs to the public installation only
        $env = \Dotenv\Dotenv::parse((string) file_get_contents($this->lootDir.'/root/.env'));
        $this->assertSame('public', $env['SAAN_EDITION']);
        $this->assertNotSame($intEnv['APP_KEY'], $env['APP_KEY']);
        $this->assertNotSame($this->plan['creds']['internal']['db_user'], $env['DB_USERNAME']);
        $this->assertNotSame($this->plan['creds']['internal']['db_password'], $env['DB_PASSWORD']);
        $this->assertNotSame($this->plan['creds']['internal']['db_name'], $env['DB_DATABASE']);
        foreach (['SAAN_HASH_KEY', 'SAAN_PUBLIC_PUSH_URL', 'SAAN_PUBLIC_UNLOCK_KEY'] as $k) $this->assertTrue(!isset($env[$k]) || $env[$k] === '', "$k must not be set on the public side");
        foreach (array_keys($env) as $k) $this->assertDoesNotMatchRegularExpression('/PUSH_SECRET|PULL_SECRET|INBOX_SECRET|INTERNAL_DB|INTERNAL_PASSWORD/', $k);
        // the public side's only knowledge of the internal installation is its URL (for the footer link): no host name of a database, no password
        $this->assertSame('https://app.school.example', $env['SAAN_INTERNAL_URL']);
        $this->assertFileDoesNotExist($this->lootDir.'/root/storage/app/private/saan-pair.token', 'the one-time pairing token file is gone');
        $pubInstalled = json_decode((string) file_get_contents($this->lootDir.'/root/storage/installed.json'), true);
        $this->assertArrayNotHasKey('public_root', $pubInstalled);
        // the unlock key it holds is a derived value: not the app key, not the hash key, not a signing or box key
        $unlock = trim((string) file_get_contents($this->pubKeys.'/peer-unlock.key'));
        $this->assertNotSame($this->hostKey, $unlock);
        $this->assertNotSame(HashKey::bytes($this->hostKey), $unlock);
        $this->assertNotSame(bin2hex(HashKey::bytes($this->hostKey)), $unlock);
        $this->assertSame(64, strlen($unlock));
        // forging: every candidate key signs a push body and a pull request; the internal-side public keys the attacker holds reject all of them
        $ts = time();
        $body = '{"ts":'.$ts.',"kind":"verify","payload_hash":"'.hash('sha256', '[]').'","payload":[]}';
        $forged = 0;
        foreach ($this->candidateSecrets() as $seed) {
            $sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
            $sig = base64_encode(sodium_crypto_sign_detached(Keys::message($ts, $body), $sk));
            $this->assertFalse(Keys::verify($ts, $body, $sig, $this->pushPeer), 'a key from the public side forged a push signature');
            $sigPull = base64_encode(sodium_crypto_sign_detached(Keys::pullMessage('GET', '/_saan/inbox', [], $ts, str_repeat('a', 32), ''), $sk));
            $this->assertFalse(Keys::pullVerify('GET', '/_saan/inbox', [], $ts, str_repeat('a', 32), '', $sigPull, $this->pullPeer), 'a key from the public side forged a pull signature');
            $forged++;
        }
        $this->assertGreaterThan(30, $forged);
        // control: the real secrets do verify (the check can fail)
        $this->assertTrue(Keys::verify($ts, $body, Keys::sign($ts, $body), $this->pushPeer));
        $this->assertTrue(Keys::pullVerify('GET', '/_saan/inbox', [], $ts, str_repeat('a', 32), '', Keys::pullSign('GET', '/_saan/inbox', [], $ts, str_repeat('a', 32), ''), $this->pullPeer));
        // the push key and the pull key are different keys: one does not stand in for the other
        $this->assertNotSame($this->pushPeer, $this->pullPeer);
        $this->assertFalse(Keys::verify($ts, $body, Keys::sign($ts, $body), $this->pullPeer));
        // the public database connection has no way to the internal database: a different database, user and password and no internal table
        $account = ['db_host' => $env['DB_HOST'], 'db_port' => $env['DB_PORT'], 'db_name' => $env['DB_DATABASE'], 'db_user' => $env['DB_USERNAME'], 'db_password' => $env['DB_PASSWORD']];
        $this->assertSame($this->pubDb, $account['db_name']);
        $this->assertTrue((new MysqlAdmin)->canConnect($account), 'control: the account of the public .env opens its own database');
        $this->assertFalse((new MysqlAdmin)->canRead($account, $this->intDb), 'the account of the public .env was let into the internal database');
        $this->assertSame([], Split::grantProblems((new MysqlAdmin)->grants($account), $this->pubDb), 'every grant of the public account stays inside the public database');
        $this->assertTrue(Schema::connection('loot')->hasTable('students'), 'the table exists (same migrations) but holds nothing');
        $this->assertSame(0, DB::connection('loot')->table('students')->count());
        $this->assertSame(0, DB::connection('loot')->table('users')->where('role', '!=', 'placeholder')->count());
    }

    // ---------------------------------------------------------------- (5) replay and tampering

    private function signed(array $payload, string $kind = 'batch', ?int $ts = null): array
    {
        $ts ??= time();
        $json = Wire::json($payload);
        $body = '{"ts":'.$ts.',"kind":"'.$kind.'","payload_hash":"'.hash('sha256', $json).'","payload":'.$json.'}';
        return [$body, ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Signature' => Keys::sign($ts, $body)]];
    }

    private function pullReq(string $method, string $path, array $query = [], string $body = '', ?int $ts = null, ?string $nonce = null): array
    {
        $ts ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        return [$method, $path, $query, $body, ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Nonce' => $nonce, 'X-Saan-Signature' => Keys::pullSign($method, $path, $query, $ts, $nonce, $body)]];
    }

    public function test_a_replayed_or_tampered_batch_and_pull_request_is_rejected(): void
    {
        $this->forkDatabases();
        config(['saan.push.receive_disk' => 'breach_public']);
        $pubDb = DB::connection('saan_public');
        $receiver = new Receiver('saan_public', $this->pushPeer);
        $mods = array_values((new Builder())->rows(Wire::MODULES));
        $last = (int) $pubDb->table('pub_sync_state')->where('stream', Wire::MODULES)->value('last_seq');
        $this->assertGreaterThan(0, $last);
        $before = $pubDb->table('pub_active_modules')->pluck('module_id')->sort()->values()->all();
        $batch = fn (int $seq, array $rows) => ['v' => 1, 'stream' => Wire::MODULES, 'seq' => $seq, 'op' => 'upsert', 'key' => 'module_id', 'rows' => $rows, 'deletes' => [], 'files' => []];

        // tampered after signing, missing signature, wrong key, attacker keys
        [$body, $h] = $this->signed($batch($last + 1, $mods));
        $this->assertSame(401, $receiver->handle(str_replace($mods[0]['module_id'], 'web-evil', $body), $h)[0]);
        $this->assertSame(401, $receiver->handle($body, ['X-Saan-Timestamp' => $h['X-Saan-Timestamp']])[0]);
        $this->assertSame(401, $receiver->handle($body, ['X-Saan-Timestamp' => (string) (time() - 3), 'X-Saan-Signature' => $h['X-Saan-Signature']])[0], 'header timestamp differs from the signed one');
        $tried = 0;
        foreach ($this->candidateSecrets() as $seed) {
            if ($tried++ >= 40) break;
            $sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
            $ts = time();
            $forgedBody = '{"ts":'.$ts.',"kind":"batch","payload_hash":"'.hash('sha256', Wire::json($batch($last + 1, $mods))).'","payload":'.Wire::json($batch($last + 1, $mods)).'}';
            $this->assertSame(401, $receiver->handle($forgedBody, ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Signature' => base64_encode(sodium_crypto_sign_detached(Keys::message($ts, $forgedBody), $sk))])[0], 'forged push accepted');
        }
        // right key, wrong payload hash; stale and future timestamps even when signed correctly
        $ts = time();
        $liar = '{"ts":'.$ts.',"kind":"batch","payload_hash":"'.str_repeat('0', 64).'","payload":'.Wire::json($batch($last + 1, $mods)).'}';
        $this->assertSame(422, $receiver->handle($liar, ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Signature' => Keys::sign($ts, $liar)])[0]);
        [$b, $hh] = $this->signed($batch($last + 1, $mods), 'batch', time() - 301);
        $this->assertSame('stale_timestamp', $receiver->handle($b, $hh)[1]['error']);
        [$b, $hh] = $this->signed($batch($last + 1, $mods), 'batch', time() + 301);
        $this->assertSame(401, $receiver->handle($b, $hh)[0]);
        $this->assertSame($before, $pubDb->table('pub_active_modules')->pluck('module_id')->sort()->values()->all(), 'nothing rejected changed the database');
        $this->assertSame($last, (int) $pubDb->table('pub_sync_state')->where('stream', Wire::MODULES)->value('last_seq'));
        // a forbidden stream is refused even when the sender is genuine
        foreach (['users', 'students', 'fin_payments', 'srv_responses', 'cms_messages', 'sessions'] as $t) {
            [$b, $hh] = $this->signed(array_replace($batch(1, [['id' => '9']]), ['stream' => $t]));
            $this->assertSame([403, ['error' => 'forbidden_stream']], $receiver->handle($b, $hh), $t);
        }
        // accepted once; the very same bytes, a re-signed copy, an old sequence and a gap are all refused
        $this->assertSame(200, $receiver->handle($body, $h)[0]);
        $this->assertSame('replay', $receiver->handle($body, $h)[1]['error']);
        [$again, $ah] = $this->signed($batch($last + 1, $mods));
        $this->assertSame(409, $receiver->handle($again, $ah)[0], 'a re-signed copy of an applied batch');
        [$old, $oh] = $this->signed($batch($last, $mods));
        $this->assertSame(409, $receiver->handle($old, $oh)[0], 'an older sequence');
        [$gap, $gh] = $this->signed($batch($last + 5, $mods));
        $this->assertSame([409, ['error' => 'gap', 'last_seq' => $last + 1]], $receiver->handle($gap, $gh));
        // the content a replayed batch carries is not applied twice: a replay with different rows does not change the table
        [$evil, $eh] = $this->signed($batch($last + 1, [['module_id' => 'web-evil']]));
        $this->assertSame(409, $receiver->handle($evil, $eh)[0]);
        $this->assertNotContains('web-evil', $pubDb->table('pub_active_modules')->pluck('module_id')->all());

        // ---- the pull channel
        $this->asInternal();
        $server = new PullServer('saan_public', $this->pullPeer);
        $ok = fn (array $t) => $server->handle(...$t);
        $waiting = $pubDb->table('pub_inbox')->count();
        $this->assertSame(4, $waiting);
        $t = $this->pullReq('GET', '/_saan/inbox', ['after' => 0]);
        $this->assertSame(200, $ok($t)[0]);
        $this->assertSame([401, 'replay'], [$ok($t)[0], $ok($t)[1]['error']]);
        $q = $this->pullReq('GET', '/_saan/inbox', ['after' => 0]); $q[2] = ['after' => 99];
        $this->assertSame('bad_signature', $ok($q)[1]['error']);
        $a = $this->pullReq('POST', '/_saan/inbox/ack', [], '{"ids":[1]}'); $a[3] = '{"ids":[1,2,3,4]}';
        $this->assertSame('bad_signature', $ok($a)[1]['error']);
        $c = $this->pullReq('GET', '/_saan/inbox'); $c[0] = 'POST'; $c[1] = '/_saan/inbox/ack';
        $this->assertSame('bad_signature', $ok($c)[1]['error'], 'a list signature is no ack signature');
        $this->assertSame('stale_timestamp', $ok($this->pullReq('GET', '/_saan/inbox', [], '', time() - 1000))[1]['error']);
        $this->assertSame('stale_timestamp', $ok($this->pullReq('GET', '/_saan/inbox', [], '', time() + 1000))[1]['error']);
        // signed with the push key, and with every candidate the attacker can derive
        $ts = time(); $nonce = bin2hex(random_bytes(16));
        $msg = Keys::pullMessage('POST', '/_saan/inbox/ack', [], $ts, $nonce, '{"ids":[1,2,3,4]}');
        $sigs = [base64_encode(sodium_crypto_sign_detached($msg, Keys::secret()))];
        foreach (array_slice($this->candidateSecrets(), 0, 40) as $seed) $sigs[] = base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed))));
        foreach ($sigs as $sig) $this->assertSame('bad_signature', $server->handle('POST', '/_saan/inbox/ack', [], '{"ids":[1,2,3,4]}', ['X-Saan-Timestamp' => (string) $ts, 'X-Saan-Nonce' => $nonce, 'X-Saan-Signature' => $sig])[1]['error']);
        $this->assertSame($waiting, $pubDb->table('pub_inbox')->count(), 'no rejected request deleted an envelope');

        // ---- the internal puller: a genuine pull works, and what an attacker re-injects afterwards changes nothing
        $puller = new Puller(fn ($m, $p, $qq, $b, $hh) => $server->handle($m, $p, $qq, $b, $hh));
        $captured = $pubDb->table('pub_inbox')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $r = $puller->run();
        $this->assertSame(['pulled' => 4, 'handled' => 4, 'quarantined' => 0, 'failed' => 0], $r, json_encode($r));
        $this->assertSame(3, DB::table('cms_messages')->where('email', 'like', '%@complainer.example.invalid')->count(), 'the internal side opened and stored what the public side could not read');
        $this->assertSame(1, DB::table('srv_responses')->where('answers', 'like', '%'.$this->submitted[3][1]['a'].'%')->count());
        $this->assertSame(0, $pubDb->table('pub_inbox')->count());
        $messages = DB::table('cms_messages')->count();
        $responses = DB::table('srv_responses')->count();
        foreach ($captured as $i => $row) { unset($row['id']); $row['dedupe_hash'] = hash('sha256', 'attacker-reinjected-'.$i); $pubDb->table('pub_inbox')->insert($row); } // replay of captured envelopes
        $row = $captured[0]; unset($row['id']);
        $bytes = (string) base64_decode($row['ciphertext']); $bytes[20] = chr(ord($bytes[20]) ^ 1);
        $row['ciphertext'] = base64_encode($bytes); $row['dedupe_hash'] = hash('sha256', 'attacker-tampered'); $pubDb->table('pub_inbox')->insert($row); // tampered copy
        $row = ['kind' => 'contact', 'key_id' => 'ffffffffffffffff', 'ciphertext' => base64_encode(random_bytes(200)), 'size' => 200, 'received_at' => date('Y-m-d H:i:s'), 'dedupe_hash' => hash('sha256', 'attacker-junk')];
        $pubDb->table('pub_inbox')->insert($row); // an envelope sealed for a key that does not exist
        $r = $puller->run();
        $this->assertSame(0, $r['handled'], 'replayed envelopes are not handled again: '.json_encode($r));
        $this->assertSame($messages, DB::table('cms_messages')->count());
        $this->assertSame($responses, DB::table('srv_responses')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('saan_inbox_quarantine')->where('reason', 'like', '%ถอดรหัส%')->count(), 'the tampered envelope is quarantined');
    }

    // ---------------------------------------------------------------- (6) routes

    public function test_the_public_edition_route_list_has_no_auth_or_staff_surface(): void
    {
        DB::purge('loot');
        $dbCopy = $this->tempDatabase($this->lootDb); // the child process gets a copy of the loot of its own
        $server = $this->tempDbConfig($dbCopy);
        $ini = php_ini_loaded_file();
        $cmd = array_values(array_filter([PHP_BINARY, $ini ? '-c' : null, $ini ?: null, base_path('artisan'), 'route:list', '--json', '-v', '--no-interaction']));
        $env = ['SAAN_EDITION' => 'public', 'APP_KEY' => $this->pubEnv['APP_KEY'], 'DB_CONNECTION' => $this->baseConnection, 'DB_HOST' => (string) $server['host'], 'DB_PORT' => (string) $server['port'], 'DB_DATABASE' => $dbCopy,
            'DB_USERNAME' => (string) $server['username'], 'DB_PASSWORD' => (string) $server['password'], 'DB_SOCKET' => (string) ($server['unix_socket'] ?? ''), 'DB_URL' => '', 'SAAN_KEYS_DIR' => $this->pubKeys, 'APP_URL' => 'https://www.school.example',
            'SAAN_INTERNAL_URL' => $this->pubEnv['SAAN_INTERNAL_URL'] ?? '', 'SAAN_PUBLIC_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'APP_ENV' => 'testing'];
        $p = new Process($cmd, base_path(), $env, null, 120);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
        $out = $p->getOutput();
        $json = json_decode(substr($out, (int) strpos($out, '[')), true);
        $this->assertIsArray($json, $out);
        $this->assertGreaterThan(10, count($json));
        // _saan/update-finish: finish step of an update, answered only to the one-time token of an update folder (UpdaterTest)
        $allowedSaan = ['_saan/push', '_saan/inbox', '_saan/inbox/ack', '_saan/setup', '_saan/update-finish'];
        $seen = [];
        foreach ($json as $r) {
            $uri = '/'.ltrim((string) $r['uri'], '/');
            if (str_contains($uri, 'fallbackPlaceholder')) continue;
            $mw = array_map('strval', (array) ($r['middleware'] ?? []));
            $key = ($r['method'] ?? '').' '.$uri;
            $seen[] = $uri;
            $this->assertFalse(Edition::blockedInPublic($uri), "staff/internal path in the public edition: $key");
            foreach ($mw as $m) {
                $this->assertDoesNotMatchRegularExpression('/^(auth|role|can|verified|password\.confirm|guest)\b|Authenticate|Authorize|RequireRole|Role/i', $m, "auth middleware on a public route: $key ($m)");
            }
            $this->assertDoesNotMatchRegularExpression('/^(login|logout|register|password\.|verification\.)/', (string) ($r['name'] ?? ''), "auth route name: $key");
            $this->assertDoesNotMatchRegularExpression('~^/(login|logout|forgot-password|reset-password|admin|cms|work|operations|account|guardian|oauth|api/v1|api/state|api/site|api/cms|install|demo-login|saan-pair|theme-preview|content-preview|hero-preview)~i', $uri, $key);
            if (str_starts_with($uri, '/_saan')) $this->assertContains(ltrim($uri, '/'), $allowedSaan, "unexpected /_saan route $key");
            $this->assertStringNotContainsString('Admin', (string) ($r['action'] ?? ''), $key);
        }
        foreach (['/', '/portal', '/_saan/push', '/_saan/inbox', '/_saan/inbox/ack'] as $must) $this->assertContains($must, $seen, "public route $must missing");
        // the in-process view agrees: the middleware that answers 404 for every staff path in this edition is on the web group
        foreach (['/login', '/logout', '/admin/users', '/cms', '/work', '/guardian', '/oauth/token', '/api/v1/students', '/api/state', '/saan-pair/redeem', '/install'] as $path) {
            $this->assertTrue(Edition::blockedInPublic($path), $path);
        }
    }
}
