<?php

namespace Tests\Feature;

use App\Console\Commands\SchoolArchive;
use App\Support\Saan\Publish\Keys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../../installer/Setup.php';
require_once __DIR__.'/../../installer/Split.php';

use SchoolAtelier\Installer\{DbAdmin, Identity, MysqlAdmin, Setup, Split};

/**
 * S7: install id and derived names, the standard (two app roots on one machine) installer end to end on two real, temporary databases of the
 * suite's MariaDB/MySQL server (one account per side, created by the installer's own MysqlAdmin) with a fake database admin where a test has to
 * observe or refuse provisioning calls, the shared-hosting branch with its isolation check, and the backup names.
 */
class InstallSplitTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmp = [];
    /** @var list<string> database accounts created by the running test */
    private array $dbUsers = [];

    protected function tearDown(): void
    {
        config(['database.default' => $this->baseConnection]);
        foreach (['saan_internal', 'saan_public', 'chk_int', 'chk_pub'] as $c) { try { DB::purge($c); } catch (\Throwable) {} }
        foreach ($this->dbUsers as $u) { try { (new MysqlAdmin)->dropUser($this->server(), $u); } catch (\Throwable) {} }
        parent::tearDown(); // drops the temporary databases
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($p) : @unlink($p);
    }

    private function dir(): string
    {
        $d = sys_get_temp_dir().'/saan-split-'.bin2hex(random_bytes(6));
        mkdir($d, 0777, true);
        return $this->tmp[] = realpath($d);
    }

    /** A copy-of-the-release stand-in: only what the installer looks at. */
    private function root(string $version = '1.2.3'): string
    {
        $r = $this->dir();
        foreach (['storage/app/private', 'bootstrap/cache', 'public', 'vendor'] as $s) mkdir($r.'/'.$s, 0777, true);
        file_put_contents($r.'/artisan', '<?php');
        file_put_contents($r.'/vendor/autoload.php', '<?php');
        file_put_contents($r.'/bootstrap/app.php', '<?php');
        file_put_contents($r.'/VERSION', $version."\n");
        return $r;
    }

    private function input(): array
    {
        return ['mode' => 'blank', 'school_name' => 'โรงเรียนทดสอบสองฝั่ง', 'admin_name' => 'ผู้ดูแลทดสอบ', 'admin_email' => 'owner@school.example', 'password' => 'Synthetic-Install-2026', 'password_confirmation' => 'Synthetic-Install-2026', 'app_url' => 'https://app.school.example', 'public_email' => 'contact@school.example', 'phone' => '', 'address' => ''];
    }

    private function fake(array $over = []): DbAdmin
    {
        return new class($over) implements DbAdmin {
            public array $log = [];
            public function __construct(public array $o) {}
            public function exists(array $admin, string $db): bool { if (!empty($this->o['bad_admin'])) throw new \PDOException('Access denied', 1045); return in_array($db, $this->o['existing'] ?? [], true); }
            public array $checked = [];
            public function userExists(array $admin, string $user): ?bool { $this->checked[] = $user; if (($this->o['busy'] ?? 0) > 0 && str_ends_with($user, '_i')) { $this->o['busy']--; return true; } return !empty($this->o['users_unknown']) ? null : false; }
            public function createDatabase(array $admin, string $db): void { if (!empty($this->o['deny'])) throw new \PDOException('denied'); $this->log[] = ['db', $db]; }
            public function createUser(array $admin, string $user, string $password): void { if (str_ends_with($user, '_p') && !empty($this->o['fail_public_user'])) throw new \PDOException('create user failed'); $this->log[] = ['user', $user, strlen($password)]; }
            public function dropDatabase(array $admin, string $db): void { $this->log[] = ['drop-db', $db]; }
            public function dropUser(array $admin, string $user): void { $this->log[] = ['drop-user', $user]; }
            public function grant(array $admin, string $db, string $user): void { $this->log[] = ['grant', $db, $user]; }
            public function canConnect(array $cred): bool { return !in_array($cred['db_user'], $this->o['no_login'] ?? [], true); }
            public function canRead(array $cred, string $other): bool { if (in_array($cred['db_user'], $this->o['read_error'] ?? [], true)) throw new \PDOException('timeout', 2006); return in_array($cred['db_user'], $this->o['reads_other'] ?? [], true); }
            public function grants(array $cred): array { if (in_array($cred['db_user'], $this->o['grants_error'] ?? [], true)) throw new \PDOException('gone away', 2006); return $this->o['grants'][$cred['db_user']] ?? ["GRANT USAGE ON *.* TO `u`@`localhost`", 'GRANT SELECT, INSERT ON `'.$cred['db_name'].'`.* TO `u`@`localhost`']; }
        };
    }

    /** The suite's database server and account, in the shape the installer takes a database admin account. */
    private function server(): array
    {
        $c = $this->tempDbConfig('');
        return ['db_host' => (string) $c['host'], 'db_port' => (string) $c['port'], 'db_user' => (string) $c['username'], 'db_password' => (string) $c['password']];
    }

    /**
     * Plan of a standard install after its provisioning step: two empty databases on the suite server and one account per side that is granted
     * only its own database, created the way the installer creates them (MysqlAdmin, the same privilege list). Databases and accounts are
     * removed at teardown.
     */
    private function plan(string $publicRoot): array
    {
        $id = Identity::generate();
        $d = Identity::derive($id, 'internal');
        $server = $this->server();
        $creds = [];
        foreach (['internal', 'public'] as $side) {
            $db = $this->tempDatabase();
            $password = Split::password();
            $this->dbUsers[] = $d['user_'.$side];
            (new MysqlAdmin)->createUser($server, $d['user_'.$side], $password);
            (new MysqlAdmin)->grant($server, $db, $d['user_'.$side]);
            $creds[$side] = ['db_host' => $server['db_host'], 'db_port' => $server['db_port'], 'db_name' => $db, 'db_user' => $d['user_'.$side], 'db_password' => $password];
        }
        return ['id' => $id, 'public_root' => $publicRoot, 'public_url' => 'https://www.school.example', 'creds' => $creds];
    }

    private function splitter(Setup $setup, ?DbAdmin $admin = null): Split
    {
        return new Split($setup, $admin ?? $this->fake(), fn (string $root) => [$this->app, $this->app->make(\Illuminate\Contracts\Console\Kernel::class)]);
    }

    /** Connection $name on one of the plan's databases, as the suite account (to look at what the installer left there). */
    private function open(string $name, string $database): \Illuminate\Database\Connection
    {
        $this->tempConnection($name, $database);
        return DB::connection($name);
    }

    // ---------------------------------------------------------------- id and prefixes

    public function test_install_id_is_six_base32_characters_and_never_a_taken_one(): void
    {
        $seen = [];
        for ($i = 0; $i < 300; $i++) { $id = Identity::generate($seen ? [$seen[array_rand($seen)]] : []); $this->assertMatchesRegularExpression('/^[a-z2-7]{6}$/D', $id); $seen[] = $id; }
        $this->assertGreaterThan(290, count(array_unique($seen)), 'ids must be random, not a sequence');
        $this->assertFalse(Identity::valid('ABCDEF')); $this->assertFalse(Identity::valid('abc018')); $this->assertFalse(Identity::valid('abcde'));
        $taken = [];
        for ($i = 0; $i < 40; $i++) { $id = Identity::generate($taken); $this->assertNotContains($id, $taken); $taken[] = $id; }
    }

    public function test_prefixes_derive_from_the_id_within_mysql_limits(): void
    {
        $d = Identity::derive('k3m7qa', 'internal');
        $this->assertSame('saan_k3m7qa_int', $d['db_internal']); $this->assertSame('saan_k3m7qa_pub', $d['db_public']);
        $this->assertSame('saan_k3m7qa_i', $d['user_internal']); $this->assertSame('saan_k3m7qa_p', $d['user_public']);
        $this->assertSame('saan_k3m7qa_internal', $d['session_cookie']); $this->assertSame('saan_k3m7qa', $d['session_cookie_env']);
        $this->assertSame('saan_k3m7qa_internal_', $d['cache_prefix']); $this->assertSame('saan-k3m7qa-internal', $d['backup_prefix']); $this->assertSame('saan_k3m7qa_', $d['table_prefix']);
        $this->assertSame('saan_k3m7qa_public', Identity::derive('k3m7qa', 'public')['session_cookie']);
        $this->assertSame('saan_k3m7qa', Identity::derive('k3m7qa', 'combined')['session_cookie'], 'combined has no edition suffix, same as config/session.php');
        $this->assertLessThanOrEqual(32, strlen($d['user_internal'])); $this->assertLessThanOrEqual(64, strlen($d['db_internal']));
        // the cookie the app really builds from the .env value equals the derived one
        $this->assertSame($d['session_cookie'], $d['session_cookie_env'].\App\Support\Saan\Edition::suffix('internal'));
        $env = Identity::env('k3m7qa', 'public');
        $this->assertSame(['SAAN_EDITION' => 'public', 'SAAN_INSTALL_ID' => 'k3m7qa', 'SESSION_COOKIE' => 'saan_k3m7qa', 'CACHE_PREFIX' => 'saan_k3m7qa_public_', 'REDIS_PREFIX' => 'saan_k3m7qa_public_', 'DB_PREFIX' => ''], $env);
        $this->expectException(\InvalidArgumentException::class); Identity::derive('bad', 'internal');
    }

    public function test_two_installs_never_collide_and_the_environment_carries_every_prefix(): void
    {
        $a = Identity::generate(); $b = Identity::generate([$a]);
        $x = Identity::derive($a, 'internal'); $y = Identity::derive($b, 'internal');
        foreach (['db_internal', 'db_public', 'user_internal', 'user_public', 'session_cookie', 'cache_prefix', 'backup_prefix', 'table_prefix'] as $k) $this->assertNotSame($x[$k], $y[$k], $k);
        $setup = new Setup($this->root());
        $db = ['db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'school', 'db_user' => 'school', 'db_password' => 'pw'];
        $parsed = \Dotenv\Dotenv::parse($setup->environment($db, Setup::validateSite($this->input()), 'base64:k', Identity::env($a, 'combined')));
        $this->assertSame('combined', $parsed['SAAN_EDITION']); $this->assertSame($a, $parsed['SAAN_INSTALL_ID']); $this->assertSame('saan_'.$a, $parsed['SESSION_COOKIE']);
        $this->assertSame('saan_'.$a.'_combined_', $parsed['CACHE_PREFIX']); $this->assertSame('', $parsed['DB_PREFIX']); $this->assertSame('mysql', $parsed['DB_CONNECTION']);
        // without extras the old combined environment is unchanged
        $plain = \Dotenv\Dotenv::parse($setup->environment($db, Setup::validateSite($this->input()), 'base64:k'));
        $this->assertArrayNotHasKey('SAAN_INSTALL_ID', $plain); $this->assertStringStartsWith('school_', $plain['SESSION_COOKIE']);
    }

    public function test_the_installer_refuses_roots_that_would_share_storage_or_differ(): void
    {
        $int = $this->root(); $pub = $this->root();
        [$i, $p] = Split::validateRoots($int, $pub); $this->assertNotSame($i, $p);
        foreach ([[$int, $int, 'ซ้อน'], [$int, $pub.'/../nope-'.bin2hex(random_bytes(3)), 'ไม่พบ'], [$int, 'relative/path', 'path เต็ม']] as [$a, $b, $needle]) {
            try { Split::validateRoots($a, $b); $this->fail($needle); } catch (\RuntimeException $e) { $this->assertStringContainsString($needle, $e->getMessage()); }
        }
        $other = $this->root('9.9.9'); // other version
        try { Split::validateRoots($int, $other); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('VERSION', $e->getMessage()); }
        $nested = $int.'/copy'; mkdir($nested.'/storage', 0777, true); mkdir($nested.'/bootstrap/cache', 0777, true); foreach (['artisan', 'VERSION', 'bootstrap/app.php'] as $f) copy($int.'/'.$f, $nested.'/'.$f); mkdir($nested.'/vendor'); copy($int.'/vendor/autoload.php', $nested.'/vendor/autoload.php');
        try { Split::validateRoots($int, $nested); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('ซ้อน', $e->getMessage()); }
        file_put_contents($pub.'/.env', 'APP_KEY=x');
        try { Split::validateRoots($int, $pub); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('ไม่ติดตั้งทับ', $e->getMessage()); }
        unlink($pub.'/.env');
        // a public root whose storage is a link to the internal storage
        $linked = $this->root(); (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($linked.'/storage');
        if (@symlink($int.'/storage', $linked.'/storage')) {
            try { Split::validateRoots($int, $linked); $this->fail('shared storage through a symlink'); } catch (\RuntimeException $e) { $this->assertStringContainsString('storage', $e->getMessage()); }
        }
    }

    // ---------------------------------------------------------------- database provisioning

    public function test_admin_account_creates_both_databases_and_users_with_a_narrow_grant(): void
    {
        $admin = $this->fake();
        $r = $this->splitter(new Setup($this->root()), $admin)->provision(['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_user' => 'root', 'db_password' => 'x']);
        $this->assertTrue($r['auto']);
        $this->assertSame([['db', $r['names']['db_internal']], ['user', $r['names']['user_internal'], 32], ['grant', $r['names']['db_internal'], $r['names']['user_internal']], ['db', $r['names']['db_public']], ['user', $r['names']['user_public'], 32], ['grant', $r['names']['db_public'], $r['names']['user_public']]], $admin->log);
        $this->assertNotSame($r['creds']['internal']['db_password'], $r['creds']['public']['db_password']);
        $this->assertSame(['saan_'.$r['id'].'_int', 'saan_'.$r['id'].'_pub'], [$r['names']['db_internal'], $r['names']['db_public']]);
        foreach (['FILE', 'SUPER', 'GRANT OPTION', 'ALL'] as $bad) $this->assertStringNotContainsString($bad, MysqlAdmin::PRIVILEGES);
        $this->assertSame('SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES,LOCK TABLES', MysqlAdmin::PRIVILEGES);
        // an id whose database already exists is never reused
        $first = Identity::generate();
        $r2 = $this->splitter(new Setup($this->root()), $this->fake(['existing' => ['saan_'.$first.'_int']]))->provision(['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_user' => 'root', 'db_password' => 'x'], []);
        $this->assertNotSame($first, $r2['id']);
    }

    public function test_shared_hosting_shows_names_and_passwords_and_the_check_rejects_a_user_that_reads_the_other_database(): void
    {
        $s = $this->splitter(new Setup($this->root()), $this->fake(['deny' => true]));
        $none = $s->provision(['db_host' => 'localhost', 'db_port' => '3306', 'db_user' => '', 'db_password' => '']); // no admin account at all
        $denied = $s->provision(['db_host' => 'localhost', 'db_port' => '3306', 'db_user' => 'cpuser', 'db_password' => 'x']); // the account cannot create
        foreach ([$none, $denied] as $r) {
            $this->assertFalse($r['auto']); $this->assertNotSame('', $r['note']);
            $this->assertMatchesRegularExpression('/^saan_[a-z2-7]{6}_int$/D', $r['names']['db_internal']);
            foreach (['internal', 'public'] as $k) $this->assertSame(32, strlen($r['creds'][$k]['db_password']));
        }
        $ok = $this->splitter(new Setup($this->root()), $this->fake());
        $this->assertSame([], $ok->verify($none['creds']));
        $leak = $this->splitter(new Setup($this->root()), $this->fake(['reads_other' => [$none['creds']['public']['db_user']]]));
        $problems = $leak->verify($none['creds']);
        $this->assertCount(1, $problems); $this->assertStringContainsString('อ่านฐาน', $problems[0]);
        $this->assertNotEmpty($this->splitter(new Setup($this->root()), $this->fake(['no_login' => [$none['creds']['internal']['db_user']]]))->verify($none['creds']));
        $global = $this->splitter(new Setup($this->root()), $this->fake(['grants' => [$none['creds']['internal']['db_user'] => ['GRANT ALL PRIVILEGES ON *.* TO `u`@`%` WITH GRANT OPTION']]]));
        $this->assertGreaterThanOrEqual(1, count($global->verify($none['creds'])));
        // names can be corrected (control panel prefixes) but the two sides must stay different
        $names = Split::validateNames(['db_internal' => 'cp_saan_a_int'], $none['names']); $this->assertSame('cp_saan_a_int', $names['db_internal']);
        $this->expectException(\InvalidArgumentException::class); Split::validateNames(['db_public' => $none['names']['db_internal']], $none['names']);
    }

    // ---------------------------------------------------------------- standard install end to end

    public function test_standard_install_builds_both_sides_pairs_publishes_and_locks_both(): void
    {
        $intRoot = $this->root(); $pubRoot = $this->root();
        $keysInt = $this->dir(); config(['saan.keys_dir' => $keysInt]);
        $plan = $this->plan($pubRoot); $setup = new Setup($intRoot); $setup->prepare();
        mkdir($setup->dir.'/sessions', 0700, true); file_put_contents($setup->dir.'/sessions/sess_abandoned', 'split|a:1:{s:5:"creds";s:8:"password";}');
        $before = [config('database.default'), config('app.key'), config('saan.edition')];
        $result = $this->splitter($setup)->install($plan, Setup::validateSite($this->input()));
        $this->assertSame($before, [config('database.default'), config('app.key'), config('saan.edition')], 'the host config is restored');
        $this->assertSame('standard', $result['layout']); $this->assertSame($plan['id'], $result['id']); $this->assertArrayHasKey('published', $result);

        // two databases, each migrated; the account lives only on the internal side, published content only reached the public side
        $int = $this->open('chk_int', $plan['creds']['internal']['db_name']); $pub = $this->open('chk_pub', $plan['creds']['public']['db_name']);
        $this->assertSame(1, $int->table('users')->where('email', 'owner@school.example')->count());
        $this->assertSame(0, $pub->table('users')->where('email', 'owner@school.example')->count());
        $this->assertGreaterThan(0, $pub->table('pub_sync_state')->count(), 'first full publish reached the public database');
        $this->assertSame($int->table('migrations')->count(), $pub->table('migrations')->count());

        // .env of each side
        $e = ['int' => \Dotenv\Dotenv::parse(file_get_contents($intRoot.'/.env')), 'pub' => \Dotenv\Dotenv::parse(file_get_contents($pubRoot.'/.env'))];
        $this->assertSame('internal', $e['int']['SAAN_EDITION']); $this->assertSame('public', $e['pub']['SAAN_EDITION']);
        $this->assertSame($plan['id'], $e['int']['SAAN_INSTALL_ID']); $this->assertSame($plan['id'], $e['pub']['SAAN_INSTALL_ID']);
        $this->assertNotSame($e['int']['APP_KEY'], $e['pub']['APP_KEY']); $this->assertStringStartsWith('base64:', $e['pub']['APP_KEY']);
        $this->assertSame('saan_'.$plan['id'], $e['int']['SESSION_COOKIE']); $this->assertSame('saan_'.$plan['id'].'_internal_', $e['int']['CACHE_PREFIX']); $this->assertSame('saan_'.$plan['id'].'_public_', $e['pub']['CACHE_PREFIX']);
        $this->assertSame('https://app.school.example', $e['int']['APP_URL']); $this->assertSame('https://www.school.example', $e['pub']['APP_URL']);
        $this->assertSame('https://www.school.example', $e['int']['SAAN_PUBLIC_URL']); $this->assertSame('https://www.school.example/_saan/push', $e['int']['SAAN_PUBLIC_PUSH_URL']);
        $this->assertSame('https://app.school.example', $e['pub']['SAAN_INTERNAL_URL']);
        $this->assertSame($plan['creds']['internal']['db_user'], $e['int']['DB_USERNAME']); $this->assertSame($plan['creds']['public']['db_user'], $e['pub']['DB_USERNAME']);
        $this->assertSame($plan['creds']['internal']['db_name'], $e['int']['DB_DATABASE']); $this->assertSame($plan['creds']['public']['db_name'], $e['pub']['DB_DATABASE']);
        // each side holds only its own database password and APP_KEY
        $this->assertStringNotContainsString($plan['creds']['public']['db_password'], file_get_contents($intRoot.'/.env'));
        $this->assertStringNotContainsString($plan['creds']['internal']['db_password'], file_get_contents($pubRoot.'/.env'));
        $this->assertStringNotContainsString($e['int']['APP_KEY'], file_get_contents($pubRoot.'/.env'));
        $this->assertSame('', $e['int']['DB_PREFIX']);

        // pairing happened locally: the public side holds the internal public keys and no secret
        $this->assertSame(Keys::publicKey(), trim(file_get_contents($pubRoot.'/storage/app/private/saan-keys/peer-push.pub')));
        foreach (['peer-pull.pub', 'peer-inbox.pub', 'peer-unlock.key'] as $f) $this->assertFileExists($pubRoot.'/storage/app/private/saan-keys/'.$f);
        $this->assertSame([], glob($pubRoot.'/storage/app/private/saan-keys/*.secret'));
        $this->assertFileExists($keysInt.'/push.secret');

        // both sides carry the same id and are locked; only the internal side knows where the public one lives
        $ij = json_decode(file_get_contents($intRoot.'/storage/installed.json'), true); $pj = json_decode(file_get_contents($pubRoot.'/storage/installed.json'), true);
        $this->assertSame([$plan['id'], 'internal', 'standard'], [$ij['install_id'], $ij['edition'], $ij['layout']]);
        $this->assertSame([$plan['id'], 'public', 'standard'], [$pj['install_id'], $pj['edition'], $pj['layout']]);
        $this->assertSame($pubRoot, $ij['public_root']); $this->assertArrayNotHasKey('public_root', $pj); $this->assertNotEmpty($ij['db_isolation_verified_at']);
        $this->assertTrue((new Setup($intRoot))->installed()); $this->assertTrue((new Setup($pubRoot))->installed());
        $this->assertFileDoesNotExist($intRoot.'/storage/app/private/setup/owner-token.txt');
        $this->assertDirectoryDoesNotExist($setup->dir.'/sessions', 'installer sessions (generated passwords) are purged after a finished install');
        // the two sides share neither storage nor bootstrap cache
        $this->assertNotSame(realpath($intRoot.'/storage'), realpath($pubRoot.'/storage'));
    }

    public function test_standard_install_with_the_full_trial_keeps_the_school_internal_and_publishes_only_public_content(): void
    {
        // the internal app of this test is the suite's own application: its files go to a throw-away storage folder
        $original = $this->app->storagePath(); $originalDisk = config('filesystems.disks.local.root'); $isolated = $this->dir().'/storage';
        $this->app->useStoragePath($isolated); config(['filesystems.disks.local.root' => $isolated.'/app/private']); \Illuminate\Support\Facades\Storage::forgetDisk('local');
        try {
            $intRoot = $this->root(); $pubRoot = $this->root();
            config(['saan.keys_dir' => $this->dir()]);
            $plan = $this->plan($pubRoot); $setup = new Setup($intRoot); $setup->prepare();
            $result = $this->splitter($setup)->install($plan, Setup::validateSite(array_replace($this->input(), ['mode' => 'full'])));
            $this->assertSame('full', $result['mode']); $this->assertSame([], $result['demo']['skipped']); $this->assertNotEmpty($result['demo']['accounts']);
            $int = $this->open('chk_int', $plan['creds']['internal']['db_name']); $pub = $this->open('chk_pub', $plan['creds']['public']['db_name']);

            // the school, its people and every account exist on the internal side only
            $this->assertSame([120, 13, 15], [$int->table('students')->count(), $int->table('teachers')->count(), $int->table('users')->count()]);
            $seen = []; // what the public database holds, quoted in every failure message
            foreach (['students', 'reg_guardians', 'reg_identifiers', 'gp_accounts', 'api_student_pins', 'asm_scores', 'attendances', 'lessons', 'leave_requests', 'fin_invoices', 'psl_payees', 'psl_slips', 'cmp_cases',
                'srv_responses', 'notice_rows', 'cms_messages', 'users', 'teachers', 'per_profiles', 'posts', 'notice_lists', 'pub_notice_rows', 'srv_surveys', 'cms_hubs', 'cms_hub_documents', 'ita_years', 'pub_certificates'] as $table) $seen[$table] = $pub->table($table)->count();
            $facts = json_encode($seen);
            foreach (['students', 'reg_guardians', 'reg_identifiers', 'gp_accounts', 'api_student_pins', 'asm_scores', 'attendances', 'lessons', 'leave_requests', 'fin_invoices', 'psl_payees', 'psl_slips', 'cmp_cases', 'srv_responses', 'notice_rows', 'cms_messages'] as $table) {
                $this->assertSame(0, $seen[$table], $table.' must not reach the public database '.$facts);
            }
            // no account of the school (the trial accounts least of all) and no password hash of one exists on the public side
            $this->assertSame(0, $pub->table('users')->where('email', 'like', '%@tonthan.example.test')->orWhere('email', 'owner@school.example')->count(), $facts);
            $hashes = $int->table('users')->pluck('password')->all();
            $this->assertSame(0, $pub->table('users')->whereIn('password', $hashes)->count(), $facts);
            // the public staff directory is the one projection of people: display name and position only, no account link
            $this->assertSame(13, $seen['teachers'], $facts); $this->assertSame(0, $pub->table('teachers')->whereNotNull('user_id')->count());
            $this->assertSame(0, $pub->table('per_profiles')->whereNotNull('education')->count());
            // what is published went through the normal first delivery: news, the name list with masked names, the open survey, hubs, ITA
            $this->assertGreaterThanOrEqual(50, $seen['posts'], $facts);
            // exactly the PUBLISHED name lists of the internal side and their rows (the trial also holds a draft and an archived list, which stay inside)
            $publishedLists = $int->table('notice_lists')->where('status', 'published')->pluck('id');
            $this->assertGreaterThanOrEqual(1, $publishedLists->count()); $this->assertGreaterThan($publishedLists->count(), $int->table('notice_lists')->count());
            $this->assertSame($publishedLists->count(), $seen['notice_lists'], $facts);
            $this->assertSame($int->table('notice_rows')->whereIn('list_id', $publishedLists)->count(), $seen['pub_notice_rows'], $facts);
            $family = explode(' ', $int->table('notice_rows')->orderBy('id')->value('display_name'))[2];
            $this->assertStringNotContainsString($family, json_encode($pub->table('pub_notice_rows')->pluck('cells')->map(fn ($c) => json_decode($c, true))->all(), JSON_UNESCAPED_UNICODE));
            $this->assertSame(['open'], $pub->table('srv_surveys')->pluck('status')->unique()->values()->all(), $facts);
            $this->assertSame(3, $seen['cms_hubs'], $facts);
            $this->assertGreaterThanOrEqual(1, $seen['ita_years'], $facts); $this->assertSame($int->table('ita_years')->where('status', 'published')->count(), $seen['ita_years'], $facts);
            $this->assertGreaterThan(0, $seen['pub_certificates'], $facts);
            $this->assertNotEmpty(glob($pubRoot.'/storage/app/private/cms/1/*'), 'published files were delivered to the public storage');

            // the account list stays in the internal storage; the lock files say which side is the trial and which release was installed
            $this->assertFileExists(storage_path('app/private/demo-accounts.txt'));
            $this->assertFileDoesNotExist($pubRoot.'/storage/app/private/demo-accounts.txt');
            $list = file_get_contents(storage_path('app/private/demo-accounts.txt'));
            $this->assertStringContainsString('https://app.school.example/login', $list); $this->assertStringContainsString('https://www.school.example/portal/complaints/status', $list);
            $ij = json_decode(file_get_contents($intRoot.'/storage/installed.json'), true); $pj = json_decode(file_get_contents($pubRoot.'/storage/installed.json'), true);
            $this->assertSame(['full', '1.2.3', 'blank', '1.2.3'], [$ij['mode'], $ij['version'], $pj['mode'], $pj['version']]);
            // no .env workaround: the starter data is the mode of installed.json, and the mock library accepts "full" like "demo"
            foreach ([$intRoot, $pubRoot] as $side) $this->assertArrayNotHasKey('SCHOOL_STARTER_DATA', \Dotenv\Dotenv::parse(file_get_contents($side.'/.env')));
            $this->assertSame('false', \Dotenv\Dotenv::parse(file_get_contents($intRoot.'/.env'))['SCHOOL_DEMO']);
        } finally {
            $this->app->useStoragePath($original); config(['filesystems.disks.local.root' => $originalDisk]); \Illuminate\Support\Facades\Storage::forgetDisk('local');
        }
    }

    public function test_standard_install_stops_on_a_leaky_user_or_a_used_database_without_writing_or_dropping_anything(): void
    {
        $intRoot = $this->root(); $pubRoot = $this->root(); config(['saan.keys_dir' => $this->dir()]);
        $plan = $this->plan($pubRoot); $setup = new Setup($intRoot); $setup->prepare();
        try { $this->splitter($setup, $this->fake(['reads_other' => [$plan['creds']['internal']['db_user']]]))->install($plan, Setup::validateSite($this->input())); $this->fail('leaky user'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('ฐานข้อมูลยังไม่พร้อม', $e->getMessage()); }
        $this->assertFileDoesNotExist($intRoot.'/.env'); $this->assertFileDoesNotExist($pubRoot.'/.env'); $this->assertFileDoesNotExist($intRoot.'/storage/installed.json');

        // a database that already holds tables is refused and left exactly as it was
        $pubDb = $this->open('chk_pub', $plan['creds']['public']['db_name']); $pubDb->statement('create table customer_data (id integer)'); $pubDb->table('customer_data')->insert(['id' => 7]);
        DB::purge('chk_pub');
        try { $this->splitter($setup)->install($plan, Setup::validateSite($this->input())); $this->fail('used database'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('ต้องว่าง', $e->getMessage()); }
        $this->assertSame(['customer_data'], $this->tempDbTables($plan['creds']['public']['db_name']), 'the used database keeps its table and gets none of ours');
        $this->assertSame(7, (int) $this->open('chk_pub', $plan['creds']['public']['db_name'])->table('customer_data')->value('id'));
        $this->assertFileDoesNotExist($intRoot.'/.env'); $this->assertFileDoesNotExist($pubRoot.'/.env'); $this->assertFileDoesNotExist($pubRoot.'/storage/installed.json');
        $this->assertSame([], $this->tempDbTables($plan['creds']['internal']['db_name']), 'tables created by this run are removed again');
        $this->assertDirectoryDoesNotExist($pubRoot.'/storage/app/private/saan-keys');
    }

    public function test_public_edition_of_a_two_machine_install_asks_for_no_account(): void
    {
        $d = Setup::validatePublicSite(['school_name' => 'โรงเรียนทดสอบ', 'app_url' => 'https://www.school.example']);
        $this->assertArrayNotHasKey('password', $d); $this->assertArrayNotHasKey('admin_email', $d); $this->assertSame('https://www.school.example', $d['app_url']);
        $this->expectException(\InvalidArgumentException::class); Setup::validatePublicSite(['school_name' => 'x', 'app_url' => 'http://www.school.example']);
    }

    // ---------------------------------------------------------------- doctor

    public function test_doctor_reports_identity_cookie_cache_storage_and_isolation_record(): void
    {
        $by = fn (array $rows) => array_column($rows, null, 0);
        config(['saan.install_id' => '', 'saan.edition' => 'combined']);
        $this->assertSame([], \App\Support\Saan\Publish\SplitHealth::identityChecks(), 'installs made before S7 stay quiet');
        config(['saan.edition' => 'public']);
        $this->assertFalse($by(\App\Support\Saan\Publish\SplitHealth::identityChecks())['install_id'][2], 'a split install without an id is warned about');

        $id = 'k3m7qa';
        config(['saan.install_id' => $id, 'saan.edition' => 'internal', 'session.cookie' => 'saan_k3m7qa_internal', 'cache.prefix' => 'saan_k3m7qa_internal_']);
        $storage = $this->dir(); $pubRoot = $this->root();
        $this->app->useStoragePath($storage);
        file_put_contents($storage.'/installed.json', json_encode(['layout' => 'standard', 'edition' => 'internal', 'public_root' => $pubRoot, 'db_isolation_verified_at' => '2026-11-06T00:00:00+00:00']));
        $c = $by(\App\Support\Saan\Publish\SplitHealth::identityChecks());
        foreach (['install_id', 'install_cookie', 'install_cache_prefix', 'install_storage', 'install_isolation'] as $k) $this->assertTrue($c[$k][2], $k.' '.$c[$k][3]);
        config(['session.cookie' => 'school_session', 'cache.prefix' => 'laravel-cache-']);
        $c = $by(\App\Support\Saan\Publish\SplitHealth::identityChecks());
        $this->assertFalse($c['install_cookie'][2]); $this->assertFalse($c['install_cache_prefix'][2]);
        // the other side shares this storage: an error, not a warning
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($pubRoot.'/storage'); @symlink($storage, $pubRoot.'/storage');
        if (is_link($pubRoot.'/storage')) { $c = $by(\App\Support\Saan\Publish\SplitHealth::identityChecks()); $this->assertFalse($c['install_storage'][2]); $this->assertTrue($c['install_storage'][4]); }
        if (is_link($pubRoot.'/storage')) { DIRECTORY_SEPARATOR === '\\' ? @rmdir($pubRoot.'/storage') : @unlink($pubRoot.'/storage'); }
    }

    // ---------------------------------------------------------------- review fixes (P1/P2/P3)

    public function test_every_grant_line_must_stay_inside_the_own_database(): void
    {
        $own = 'saan_k3m7qa_int';
        $this->assertSame([], Split::grantProblems(['GRANT USAGE ON *.* TO `u`@`localhost`', 'GRANT SELECT, INSERT ON `saan_k3m7qa_int`.* TO `u`@`localhost`', 'GRANT SELECT, INSERT ON `saan\\_k3m7qa\\_int`.* TO `u`@`localhost`', 'GRANT SELECT ON `saan_k3m7qa_int`.`users` TO `u`@`localhost`', 'GRANT SELECT (id) ON `saan_k3m7qa_int`.`users` TO `u`@`localhost`', "GRANT USAGE ON *.* TO `u`@`%` IDENTIFIED BY PASSWORD '*ABC'"], $own));
        foreach ([
            'GRANT SELECT ON `mysql`.* TO `u`@`localhost`' => 'mysql',
            'GRANT SELECT ON `%`.* TO `u`@`localhost`' => '%',
            'GRANT ALL ON `customer_b`.* TO `u`@`localhost`' => 'customer_b',
            'GRANT SELECT ON `saan_k3m7qa_pub`.* TO `u`@`localhost`' => 'saan_k3m7qa_pub',
            'GRANT SELECT ON *.* TO `u`@`localhost`' => 'ทั้งเซิร์ฟเวอร์',
            'GRANT USAGE ON *.* TO `u`@`localhost` WITH GRANT OPTION' => 'GRANT OPTION',
            'GRANT `reader`@`%` TO `u`@`localhost`' => 'role',
            'GRANT reader TO u' => 'role',
        ] as $line => $needle) {
            $p = Split::grantProblems([$line], $own);
            $this->assertNotEmpty($p, $line);
            $this->assertStringContainsString($needle, implode('|', $p), $line);
        }
        // and verify() reports them for a user that otherwise passes
        $none = $this->splitter(new Setup($this->root()), $this->fake(['deny' => true]))->provision(['db_host' => 'localhost', 'db_port' => '3306', 'db_user' => '', 'db_password' => '']);
        $v = $this->splitter(new Setup($this->root()), $this->fake(['grants' => [$none['creds']['public']['db_user'] => ['GRANT USAGE ON *.* TO `u`@`%`', 'GRANT SELECT ON `mysql`.* TO `u`@`%`']]]));
        $problems = $v->verify($none['creds']);
        $this->assertCount(1, $problems); $this->assertStringContainsString('mysql', $problems[0]); $this->assertStringContainsString('หน้าบ้าน', $problems[0]);
    }

    public function test_verify_fails_closed_when_the_isolation_check_cannot_be_completed(): void
    {
        $none = $this->splitter(new Setup($this->root()), $this->fake(['deny' => true]))->provision(['db_host' => 'localhost', 'db_port' => '3306', 'db_user' => '', 'db_password' => '']);
        $timeout = $this->splitter(new Setup($this->root()), $this->fake(['read_error' => [$none['creds']['internal']['db_user']]]))->verify($none['creds']);
        $this->assertCount(1, $timeout); $this->assertStringContainsString('ตรวจการแยกไม่สำเร็จ', $timeout[0]);
        $gone = $this->splitter(new Setup($this->root()), $this->fake(['grants_error' => [$none['creds']['public']['db_user']]]))->verify($none['creds']);
        $this->assertCount(1, $gone); $this->assertStringContainsString('อ่านสิทธิ์', $gone[0]);
        $empty = $this->splitter(new Setup($this->root()), $this->fake(['grants' => [$none['creds']['internal']['db_user'] => []]]))->verify($none['creds']);
        $this->assertCount(1, $empty); $this->assertStringContainsString('ตรวจการแยกไม่สำเร็จ', $empty[0]);
        // the real MySQL implementation: only 1044/1045 mean "denied"
        $this->assertSame(1044, MysqlAdmin::errno(new class('x', 0) extends \PDOException { public function __construct($m, $c) { parent::__construct($m); $this->errorInfo = ['42000', 1044, 'denied']; } }));
        $this->assertSame(2002, MysqlAdmin::errno(new class('x', 0) extends \PDOException { public function __construct($m, $c) { parent::__construct($m); $this->errorInfo = ['HY000', 2002, 'down']; } }));
    }

    public function test_provision_never_reuses_an_id_with_a_leftover_user_and_cleans_up_a_partial_run(): void
    {
        $admin = ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_user' => 'root', 'db_password' => 'x'];
        $busy = $this->fake(['busy' => 1]); // the first id's internal user is already on the server
        $r = $this->splitter(new Setup($this->root()), $busy)->provision($admin, []);
        $this->assertTrue($r['auto']); $this->assertNotSame($busy->checked[0], $r['names']['user_internal'], 'an id whose user exists is never reused');
        $this->assertSame([], array_filter($busy->log, fn ($l) => $l[0] === 'user' && $l[1] === $busy->checked[0]));
        // a wrong admin password is reported as such, not as "not enough privileges"
        try { $this->splitter(new Setup($this->root()), $this->fake(['bad_admin' => true]))->provision($admin); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('รหัสผ่านของบัญชีผู้ดูแล', $e->getMessage()); }
        // the public user cannot be created: what this run already created is dropped again, then the manual list is shown
        $fake = $this->fake(['fail_public_user' => true]);
        $r = $this->splitter(new Setup($this->root()), $fake)->provision($admin);
        $this->assertFalse($r['auto']); $this->assertNotSame('', $r['note']);
        $drops = array_map(fn ($l) => $l[1], array_values(array_filter($fake->log, fn ($l) => str_starts_with($l[0], 'drop'))));
        $this->assertContains($r['names']['db_internal'], $drops); $this->assertContains($r['names']['user_internal'], $drops); $this->assertContains($r['names']['db_public'], $drops);
        $this->assertNotContains($r['names']['user_public'], $drops, 'a user that was never created is not dropped');
    }

    private function link(string $target, string $link): bool
    {
        // a symlink, or on Windows without the privilege a junction (PHP's is_link() is false for a junction, realpath() still resolves it)
        if (!@symlink($target, $link) && DIRECTORY_SEPARATOR === '\\') @exec('cmd /c mklink /J "'.str_replace('/', '\\', $link).'" "'.str_replace('/', '\\', $target).'" 2>&1');
        return file_exists($link) && realpath($link) === realpath($target);
    }

    /** Removes only the link itself (rmdir on a junction or Windows directory link, unlink on a POSIX symlink), never the target. */
    private function unlink(string $link): void
    {
        if (!file_exists($link) && !is_link($link)) return;
        if (!is_link($link) && realpath($link) === realpath(dirname($link)).DIRECTORY_SEPARATOR.basename($link)) return; // a real directory: leave it
        @rmdir($link) || @unlink($link);
    }

    public function test_a_link_inside_the_public_storage_or_a_shared_sensitive_folder_is_refused(): void
    {
        $fs = new \Illuminate\Filesystem\Filesystem;
        $int = $this->root(); $pub = $this->root();
        $this->assertNull(\SchoolAtelier\Installer\Roots::conflict($int, $pub));
        // storage/app/private of the public side is a link to the internal one: the top-level storage paths still differ
        $fs->deleteDirectory($pub.'/storage/app/private');
        if (!$this->link($int.'/storage/app/private', $pub.'/storage/app/private')) $this->markTestSkipped('no symlink or junction available here');
        try {
            // a POSIX symlink is named as such; a junction is caught by the real-path comparison of storage/app/private
            try { Split::validateRoots($int, $pub); $this->fail('link inside storage'); } catch (\RuntimeException $e) { $this->assertMatchesRegularExpression('~symlink|storage/app/private~', $e->getMessage()); }
            $this->assertMatchesRegularExpression('~symlink|storage/app/private~', (string) \SchoolAtelier\Installer\Roots::conflict($int, $pub), 'school:doctor uses the same helper');
        } finally { $this->unlink($pub.'/storage/app/private'); }
        mkdir($pub.'/storage/app/private', 0777, true);
        // the internal side links its logs into the public logs: caught by the real-path comparison of the sensitive folders
        mkdir($pub.'/storage/logs', 0777, true); $fs->deleteDirectory($int.'/storage/logs');
        if ($this->link($pub.'/storage/logs', $int.'/storage/logs')) {
            try { $this->assertStringContainsString('storage/logs', (string) \SchoolAtelier\Installer\Roots::conflict($int, $pub)); } finally { $this->unlink($int.'/storage/logs'); }
        }
    }

    public function test_a_late_write_failure_removes_the_files_already_written_and_the_tables(): void
    {
        $intRoot = $this->root(); $pubRoot = $this->root(); config(['saan.keys_dir' => $this->dir()]);
        $plan = $this->plan($pubRoot); $setup = new Setup($intRoot); $setup->prepare();
        mkdir($intRoot.'/storage/installed.json'); // the last of the four files cannot be written
        try { $this->splitter($setup)->install($plan, Setup::validateSite($this->input())); $this->fail('must fail'); } catch (\Throwable $e) { $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e); }
        foreach (['/.env', '/storage/installed.json'] as $f) $this->assertFileDoesNotExist($pubRoot.$f, 'public '.$f);
        $this->assertFileDoesNotExist($intRoot.'/.env');
        $this->assertSame([], glob($pubRoot.'/*.tmp') ?: []); $this->assertSame([], glob($pubRoot.'/storage/*.tmp') ?: []);
        $this->assertSame([], $this->tempDbTables($plan['creds']['internal']['db_name']));
        $this->assertSame([], $this->tempDbTables($plan['creds']['public']['db_name']));
        rmdir($intRoot.'/storage/installed.json');
        // and the operator can simply run it again
        $this->assertSame('standard', $this->splitter($setup)->install($plan, Setup::validateSite($this->input()))['layout']);
    }

    public function test_setup_purges_installer_sessions(): void
    {
        $setup = new Setup($this->root()); $setup->prepare();
        mkdir($setup->dir.'/sessions', 0700, true); file_put_contents($setup->dir.'/sessions/sess_a', 'x'); file_put_contents($setup->dir.'/sessions/sess_b', 'y');
        $setup->purgeSessions();
        $this->assertDirectoryDoesNotExist($setup->dir.'/sessions');
        $setup->purgeSessions(); // nothing left: no error
        $this->addToAssertionCount(1);
    }

    public function test_cache_and_redis_prefix_equal_the_installer_value_and_the_edition_suffix_only_goes_on_the_default(): void
    {
        $keys = ['CACHE_PREFIX', 'REDIS_PREFIX', 'SAAN_EDITION'];
        $old = [];
        foreach ($keys as $k) $old[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null];
        $set = function (string $k, ?string $v) { if ($v === null) { unset($_ENV[$k], $_SERVER[$k]); } else { $_ENV[$k] = $_SERVER[$k] = $v; } };
        try {
            $d = Identity::derive('k3m7qa', 'internal');
            $set('SAAN_EDITION', 'internal'); $set('CACHE_PREFIX', $d['cache_prefix']); $set('REDIS_PREFIX', $d['cache_prefix']);
            $this->assertSame('saan_k3m7qa_internal_', (require config_path('cache.php'))['prefix']);
            $this->assertSame('saan_k3m7qa_internal_', (require config_path('database.php'))['redis']['options']['prefix']);
            $set('CACHE_PREFIX', null); $set('REDIS_PREFIX', null);
            $this->assertStringEndsWith('-cache-internal-', (require config_path('cache.php'))['prefix']);
            $this->assertStringEndsWith('-database-internal-', (require config_path('database.php'))['redis']['options']['prefix']);
            $set('SAAN_EDITION', 'combined');
            $this->assertStringEndsWith('-cache-', (require config_path('cache.php'))['prefix']);
        } finally {
            foreach ($old as $k => [$e, $s]) { if ($e === null) unset($_ENV[$k]); else $_ENV[$k] = $e; if ($s === null) unset($_SERVER[$k]); else $_SERVER[$k] = $s; }
        }
    }

    public function test_doctor_os_level_checks_warn_about_a_shared_os_user_missing_open_basedir_and_loose_env_permissions(): void
    {
        $by = fn (array $rows) => array_column($rows, null, 0);
        $std = ['layout' => 'standard'];
        $c = $by(\App\Support\Saan\Publish\SplitHealth::osChecks('internal', $std, '', 1000, 1000, 0100600, 0100600));
        $this->assertFalse($c['install_os_user'][2]); $this->assertFalse($c['install_os_user'][4], 'a warning, not an error'); $this->assertTrue($c['install_env_perms'][2]); $this->assertArrayNotHasKey('install_open_basedir', $c);
        $this->assertTrue($by(\App\Support\Saan\Publish\SplitHealth::osChecks('internal', $std, '', 1000, 1001, 0100600, null))['install_os_user'][2]);
        $c = $by(\App\Support\Saan\Publish\SplitHealth::osChecks('public', $std, '', null, null, 0100644, 0100600));
        $this->assertFalse($c['install_open_basedir'][2]); $this->assertFalse($c['install_env_perms'][2]); $this->assertStringContainsString('chmod 600', $c['install_env_perms'][3]);
        $this->assertTrue($by(\App\Support\Saan\Publish\SplitHealth::osChecks('public', $std, '/home/s/www-app:/tmp', null, null, 0100600, 0100600))['install_open_basedir'][2]);
        $this->assertFalse($by(\App\Support\Saan\Publish\SplitHealth::osChecks('public', $std, '', null, null, 0100600, 0100640))['install_env_perms'][2]);
        // not a standard install (two machines or combined): no same-machine warnings
        $this->assertSame([], \App\Support\Saan\Publish\SplitHealth::osChecks('public', ['layout' => 'advanced'], '', null, null, null, null));
        $this->assertSame([], \App\Support\Saan\Publish\SplitHealth::osChecks('internal', ['layout' => 'advanced'], '', 1, 1, null, null));
    }

    public function test_restore_refuses_an_archive_of_another_edition_or_install(): void
    {
        $m = fn (string $ed, string $id = 'k3m7qa') => ['edition' => $ed, 'install_id' => $id];
        $ok = [['internal', 'internal'], ['public', 'public'], ['combined', 'combined'], ['combined', 'internal']];
        foreach ($ok as [$src, $dst]) { SchoolArchive::assertSameInstall($m($src), $dst, 'k3m7qa'); $this->addToAssertionCount(1); }
        SchoolArchive::assertSameInstall(['version' => 1], 'combined', 'k3m7qa'); // archive from before S7: no edition, no id
        SchoolArchive::assertSameInstall($m('combined', ''), 'internal', 'k3m7qa');
        foreach ([['internal', 'public'], ['combined', 'public'], ['public', 'internal'], ['internal', 'combined'], ['public', 'combined']] as [$src, $dst]) {
            try { SchoolArchive::assertSameInstall($m($src), $dst, 'k3m7qa', true); $this->fail($src.' -> '.$dst); } catch (\RuntimeException $e) { $this->assertStringContainsString('edition', $e->getMessage()); }
        }
        try { SchoolArchive::assertSameInstall($m('internal', 'aaaaaa'), 'internal', 'k3m7qa'); $this->fail('other install id'); } catch (\RuntimeException $e) { $this->assertStringContainsString('--use-source-install', $e->getMessage()); }
        SchoolArchive::assertSameInstall($m('internal', 'aaaaaa'), 'internal', 'k3m7qa', true);
        $this->assertStringContainsString('use-source-install', (new SchoolArchive)->getDefinition()->getOption('use-source-install')->getName());
    }

    // ---------------------------------------------------------------- backup names

    public function test_backup_file_names_carry_the_install_id_and_the_edition(): void
    {
        config(['saan.install_id' => 'k3m7qa', 'saan.edition' => 'public']);
        $this->assertSame('saan-k3m7qa-public-20261106T010203Z.zip', SchoolArchive::fileName('20261106T010203Z'));
        config(['saan.edition' => 'internal']);
        $this->assertMatchesRegularExpression('/^saan-k3m7qa-internal-\d{8}T\d{6}Z\.zip$/D', SchoolArchive::fileName());
        config(['saan.install_id' => '']);
        $this->assertStringStartsWith('saan-noid-internal-', SchoolArchive::fileName());
    }

    public function test_a_public_url_on_the_same_origin_as_the_internal_one_is_refused_before_anything_is_written(): void
    {
        $intRoot = $this->root(); $pubRoot = $this->root(); config(['saan.keys_dir' => $this->dir()]);
        $setup = new Setup($intRoot); $setup->prepare();
        // the internal URL of input() is https://app.school.example: the same host with a path, another case or an explicit default port is still one origin
        foreach (['https://app.school.example', 'https://app.school.example/portal', 'https://APP.school.example:443', 'https://app.school.example/'] as $u) {
            $plan = $this->plan($pubRoot); $plan['public_url'] = $u;
            try { $this->splitter($setup)->install($plan, Setup::validateSite($this->input())); $this->fail($u); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('คนละโฮสต์', $e->getMessage(), $u); }
            $this->assertFileDoesNotExist($pubRoot.'/.env', $u);
            $this->assertFileDoesNotExist($intRoot.'/.env', $u);
        }
    }
}