<?php

namespace Tests\Feature;

use App\Console\Commands\SchoolArchive;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Edition;
use App\Support\Saan\NetKey;
use App\Support\Saan\Projection;
use App\Support\Saan\Publish\SplitHealth;
use App\Support\Saan\Publish\Wire;
use SchoolAtelier\Installer\Roots;
use Tests\TestCase;

/**
 * Regression tests for the review of the public/internal split: validators, doctor checks, archive marker, cookie and origin rules.
 * The behaviour that needs a database (publisher, receiver, puller) is tested next to its feature in SaanPublishTest and SaanInboxTest.
 */
class SplitReviewFixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('installer/Roots.php');
    }

    public function test_answer_keys_are_scrubbed_at_any_depth_and_the_scrub_is_idempotent(): void
    {
        $in = json_encode([['key' => 'q1', 'correct' => 'ก', 'options' => ['ก', 'ข']], ['key' => 'q2', 'correct' => ['ก'], 'extra' => ['correct' => 1, 'keep' => 2]]], JSON_UNESCAPED_UNICODE);
        $out = Wire::scrubAnswerKeys($in);
        $this->assertStringNotContainsString('correct', $out);
        $this->assertSame(['ก', 'ข'], json_decode($out, true)[0]['options']);
        $this->assertSame(2, json_decode($out, true)[1]['extra']['keep']);
        $this->assertSame($out, Wire::scrubAnswerKeys($out));
        $this->assertSame('[]', Wire::scrubAnswerKeys('not json'));
        $this->assertNull(Wire::scrubAnswerKeys(null));
    }

    public function test_the_projection_validator_backstops(): void
    {
        $e = fn (array $cols, string $table = 'posts', array $more = []) => [['table' => $table, 'columns' => $cols] + $more];
        $bad = function (array $entries, string $needle) {
            try { Projection::validate($entries, 'core', false); $this->fail('must be refused: '.$needle); }
            catch (\RuntimeException $x) { $this->assertStringContainsString($needle, $x->getMessage()); }
        };
        // mask_name is not a way around the list of columns that may only be null or const
        $bad($e(['id' => 'keep', 'email' => 'mask_name']), 'email');
        $bad($e(['id' => 'keep', 'author_id' => 'mask_name']), 'author_id');
        // the rules of the quiz questions and of the certificate holder name are pinned
        $bad($e(['id' => 'keep', 'questions' => 'keep'], 'srv_surveys'), 'scrub_answer_keys');
        $bad($e(['number' => 'keep', 'holder_name' => 'keep'], 'pub_certificates', ['derived' => 'certificate_verify']), 'mask_name');
        $this->assertCount(1, Projection::validate($e(['id' => 'keep', 'questions' => 'scrub_answer_keys'], 'srv_surveys'), 'core', false));
        $this->assertCount(1, Projection::validate($e(['number' => 'keep', 'holder_name' => 'mask_name'], 'pub_certificates', ['derived' => 'certificate_verify']), 'core', false));
        // tables of the split itself and raw notice names can never be declared
        foreach (['notice_rows', 'saan_inbox_quarantine', 'saan_inbox_done', 'saan_pairing_codes', 'pub_outbox', 'pub_sent_rows', 'pub_sync_state'] as $t) $bad($e(['id' => 'keep'], $t), 'ห้ามส่งออก');
    }

    public function test_an_internal_only_module_cannot_declare_a_public_projection(): void
    {
        $fake = ['evil' => ['active' => true, 'edition' => 'internal', 'public_projection' => [['table' => 'posts', 'columns' => ['id' => 'keep']]]]];
        $this->partialMock(ModuleRegistry::class, fn ($m) => $m->shouldReceive('all')->andReturn($fake));
        try { Projection::declared(false, true); $this->fail('must be refused'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('evil', $e->getMessage()); }
    }

    public function test_the_real_declarations_use_the_pinned_rules(): void
    {
        $by = collect(Projection::declared(false, true))->keyBy('table');
        $this->assertSame('scrub_answer_keys', $by['srv_surveys']['columns']['questions']);
        $this->assertSame('mask_name', $by['pub_certificates']['columns']['holder_name']);
    }

        public function test_open_basedir_is_judged_from_the_web_value_and_must_not_cover_the_folders_beside_the_public_app(): void
    {
        $std = ['layout' => 'standard'];
        $by = fn (array $c) => collect($c)->keyBy(0);
        $sep = PATH_SEPARATOR;
        // from a shell the FPM value cannot be seen: "cannot verify", never a pass (even when the CLI ini has a value)
        $c = $by(SplitHealth::osChecks('public', $std, '/home/s/www-app'.$sep.'/tmp', null, null, null, null, null, '/home/s/www-app', true))['install_open_basedir'];
        $this->assertFalse($c[2]);
        $this->assertStringContainsString('ตรวจจากบรรทัดคำสั่งไม่ได้', $c[3]);
        // the value a web request of the pool recorded is what counts
        $web = ['at' => time(), 'open_basedir' => '/home/s/www-app'.$sep.'/tmp'];
        $this->assertTrue($by(SplitHealth::osChecks('public', $std, '', null, null, null, null, $web, '/home/s/www-app', true))['install_open_basedir'][2]);
        $web['open_basedir'] = '';
        $this->assertFalse($by(SplitHealth::osChecks('public', $std, '/x', null, null, null, null, $web, '/home/s/www-app', true))['install_open_basedir'][2], 'the pool value wins over the CLI value');
        // a parent folder (the DirectAdmin default /home/USER/) also holds the internal app next to this one: not isolation
        $web['open_basedir'] = '/home/s/'.$sep.'/tmp';
        $c = $by(SplitHealth::osChecks('public', $std, '', null, null, null, null, $web, '/home/s/www-app', true))['install_open_basedir'];
        $this->assertFalse($c[2]);
        $this->assertStringContainsString('อยู่เหนือหน้าบ้าน', $c[3]);
        $this->assertTrue(SplitHealth::basedirCovers('/home/s/', '/home/s/www-app', true));
        $this->assertFalse(SplitHealth::basedirCovers('/home/s/www-app'.$sep.'/tmp', '/home/s/www-app', true), 'the app folder itself is what it should be');
        $this->assertTrue(SplitHealth::basedirCovers('/home/s/www-app', '/home/s/www-app'));
        // not a one-machine install: nothing to say; a web run (not CLI) without a recorded value uses the ini of that very process
        $this->assertSame([], SplitHealth::osChecks('public', ['layout' => 'advanced'], '', null, null, null, null, null, '/home/s/www-app', true));
        $this->assertTrue($by(SplitHealth::osChecks('public', $std, '/home/s/www-app', null, null, null, null, null, '/home/s/www-app', false))['install_open_basedir'][2]);
    }
public function test_the_doctor_flags_one_origin_and_a_cookie_that_a_sibling_subdomain_can_shadow(): void
    {
        $by = fn (array $c) => collect($c)->keyBy(0);
        $c = $by(SplitHealth::originChecks(['public_url' => 'https://school.ac.th'], 'https://school.ac.th/app', 'saan_x_internal', '/', ''));
        $this->assertFalse($c['install_origin'][2]);
        $this->assertTrue($c['install_origin'][4], 'an error, not a warning');
        $this->assertTrue($by(SplitHealth::originChecks(['public_url' => 'https://www.school.ac.th'], 'https://app.school.ac.th', '__Host-saan_x_internal', '/', ''))['install_origin'][2]);
        $this->assertTrue($by(SplitHealth::originChecks(['public_url' => 'https://www.school.ac.th'], 'https://app.school.ac.th', '__Host-saan_x_internal', '/', ''))['install_host_cookie'][2]);
        $h = $by(SplitHealth::originChecks([], 'https://app.school.ac.th', 'saan_x_internal', '/app', '.school.ac.th'))['install_host_cookie'];
        $this->assertFalse($h[2]);
        $this->assertStringContainsString('SESSION_DOMAIN', $h[3]);
        $this->assertStringContainsString('SESSION_PATH', $h[3]);
        $this->assertArrayNotHasKey('install_host_cookie', $by(SplitHealth::originChecks([], 'http://localhost', 'saan_x', '/', '')), 'plain http development has no __Host- cookie');
    }

    public function test_same_origin_ignores_case_default_ports_and_paths(): void
    {
        foreach ([['https://school.ac.th', 'https://school.ac.th/app'], ['https://School.AC.th', 'https://school.ac.th:443'], ['http://localhost', 'http://localhost:80/x'], ['https://[::1]', 'https://[::1]:443/']] as [$a, $b]) $this->assertTrue(Roots::sameOrigin($a, $b), "$a $b");
        foreach ([['https://www.school.ac.th', 'https://app.school.ac.th'], ['https://school.ac.th', 'https://school.ac.th:8443'], ['https://school.ac.th', 'http://school.ac.th'], ['not a url', 'not a url']] as [$a, $b]) $this->assertFalse(Roots::sameOrigin($a, $b), "$a $b");
    }

    public function test_the_internal_session_cookie_is_a_host_cookie_only_where_the_browser_accepts_one(): void
    {
        $this->assertTrue(Edition::hostCookie('internal', 'https://app.school.ac.th', '/', ''));
        $this->assertFalse(Edition::hostCookie('internal', 'http://localhost', '/', ''), 'the prefix needs Secure');
        $this->assertFalse(Edition::hostCookie('internal', 'https://app.school.ac.th', '/app', ''), 'Path must be /');
        $this->assertFalse(Edition::hostCookie('internal', 'https://app.school.ac.th', '/', '.school.ac.th'), 'no Domain');
        $this->assertFalse(Edition::hostCookie('public', 'https://www.school.ac.th', '/', ''));
        $this->assertFalse(Edition::hostCookie('combined', 'https://school.ac.th', '/', ''));
        $keys = ['SAAN_EDITION', 'APP_URL', 'SESSION_COOKIE', 'SESSION_PATH', 'SESSION_DOMAIN', 'SESSION_SECURE_COOKIE'];
        $old = [];
        foreach ($keys as $k) $old[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null];
        $set = function (string $k, ?string $v) { if ($v === null) { unset($_ENV[$k], $_SERVER[$k]); } else { $_ENV[$k] = $_SERVER[$k] = $v; } };
        try {
            $set('SAAN_EDITION', 'internal'); $set('APP_URL', 'https://app.school.ac.th'); $set('SESSION_COOKIE', 'saan_k3m7qa'); $set('SESSION_PATH', '/'); $set('SESSION_DOMAIN', null); $set('SESSION_SECURE_COOKIE', null);
            $c = require config_path('session.php');
            $this->assertSame('__Host-saan_k3m7qa_internal', $c['cookie']);
            $this->assertTrue($c['secure']);
            $set('SESSION_DOMAIN', '.school.ac.th');
            $c = require config_path('session.php');
            $this->assertSame('saan_k3m7qa_internal', $c['cookie']);
            $set('SESSION_DOMAIN', null); $set('SAAN_EDITION', 'public');
            $this->assertSame('saan_k3m7qa_public', (require config_path('session.php'))['cookie']);
        } finally {
            foreach ($old as $k => [$e, $s]) { if ($e === null) unset($_ENV[$k]); else $_ENV[$k] = $e; if ($s === null) unset($_SERVER[$k]); else $_SERVER[$k] = $s; }
        }
    }

    public function test_the_ipv6_limiter_key_for_a_whole_site_is_the_48(): void
    {
        $this->assertSame(NetKey::wide('2001:db8:1:1::5'), NetKey::wide('2001:db8:1:ffff::9'));
        $this->assertNotSame(NetKey::wide('2001:db8:1::1'), NetKey::wide('2001:db8:2::1'));
        $this->assertNotSame(NetKey::rate('2001:db8:1:1::5'), NetKey::rate('2001:db8:1:2::5'));
        $this->assertSame('203.0.113.9', NetKey::wide('203.0.113.9'));
        $this->assertSame('203.0.113.9', NetKey::wide('::ffff:203.0.113.9'));
    }

    public function test_a_restore_keeps_the_identity_of_the_target_and_takes_only_the_rest_from_the_archive(): void
    {
        $archive = json_encode(['version' => '0.5.0', 'installed_at' => '2026-01-01T00:00:00+00:00', 'mode' => 'blank', 'install_id' => 'aaaaaa', 'edition' => 'combined', 'layout' => 'combined', 'public_root' => '/srv/source/public-app', 'public_url' => 'https://source.example']);
        $own = json_encode(['install_id' => 'k3m7qa', 'edition' => 'internal', 'layout' => 'standard', 'public_root' => '/srv/target/public-app', 'public_url' => 'https://www.target.example', 'db_isolation_verified_at' => '2026-11-01']);
        $m = json_decode(SchoolArchive::mergeMarker($archive, $own, []), true);
        $this->assertSame(['k3m7qa', 'internal', 'standard', '/srv/target/public-app', 'https://www.target.example'], [$m['install_id'], $m['edition'], $m['layout'], $m['public_root'], $m['public_url']]);
        $this->assertSame('2026-11-01', $m['db_isolation_verified_at']);
        $this->assertSame(['0.5.0', 'blank'], [$m['version'], $m['mode']], 'non-identity fields come from the archive');
        // a target without a marker: identity from its .env, nothing that points to the source install
        $m = json_decode(SchoolArchive::mergeMarker($archive, null, ['SAAN_EDITION' => 'internal', 'SAAN_INSTALL_ID' => 'k3m7qa']), true);
        $this->assertSame(['internal', 'k3m7qa'], [$m['edition'], $m['install_id']]);
        foreach (['layout', 'public_root', 'public_url'] as $k) $this->assertArrayNotHasKey($k, $m);
        $m = json_decode(SchoolArchive::mergeMarker($archive, '{broken', []), true);
        $this->assertSame('combined', $m['edition']);
        $this->assertArrayNotHasKey('public_root', $m);
    }

    public function test_the_archive_warning_names_what_a_public_archive_still_holds(): void
    {
        [$secret, $w] = SchoolArchive::keyWarning(true, true);
        $this->assertTrue($secret);
        $this->assertStringContainsString('APP_KEY', $w);
        [$secret, $w] = SchoolArchive::keyWarning(false, true);
        $this->assertTrue($secret, 'peer-unlock.key makes it a secret-bearing archive');
        foreach (['peer-unlock.key', 'APP_KEY', 'รหัสผ่านฐานข้อมูล'] as $s) $this->assertStringContainsString($s, $w);
        [$secret, $w] = SchoolArchive::keyWarning(false, false);
        $this->assertFalse($secret);
        $this->assertStringContainsString('APP_KEY', $w);
        $this->assertStringNotContainsString('ไม่มีกุญแจลับ"', $w);
    }
}