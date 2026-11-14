<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\UpdateFeed;
use App\Support\Saan\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModuleManagerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Module-Admin-Pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        DB::table('users')->where('role', 'admin')->update(['password' => Hash::make(self::PASSWORD), 'is_demo' => false]);
        $this->actingAs(User::where('role', 'admin')->first());
    }

    private function state(string $id, bool $enabled, array $extra = [])
    {
        return $this->postJson('/api/site/modules/'.$id.'/state', array_merge(['enabled' => $enabled, 'current_password' => self::PASSWORD], $extra));
    }

    private function row(array $modules, string $id): array
    {
        return collect($modules)->firstWhere('id', $id);
    }

    public function test_listing_is_admin_only_and_describes_modules(): void
    {
        $r = $this->getJson('/api/site/modules')->assertOk()->json();
        $ita = $this->row($r['modules'], 'web-ita');
        $this->assertTrue($ita['active']);
        $this->assertSame('web-cms', $ita['depends_on'][0]['id']);
        $this->assertContains('web-ita', $this->row($r['modules'], 'web-cms')['required_by']);
        $this->assertFalse($this->row($r['modules'], 'web-cms')['can_disable']);
        $this->get('/admin/modules')->assertOk()->assertSee('รายการโมดูล');
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->getJson('/api/site/modules')->assertForbidden();
        $this->get('/admin/modules')->assertForbidden();
        $this->postJson('/api/site/modules/web-ita/state', ['enabled' => false, 'current_password' => self::PASSWORD])->assertForbidden();
    }

    public function test_disable_enable_keeps_data_and_is_audited_without_touching_env(): void
    {
        $this->postJson('/api/ita/years', ['fiscal_year' => 2570])->assertCreated();
        $before = DB::table('ita_years')->count();
        $this->state('web-ita', false, ['current_password' => 'wrong'])->assertUnprocessable();
        $this->assertNull(Settings::get('modules.disabled'));
        $m = $this->state('web-ita', false)->assertOk()->json('modules');
        $this->assertFalse($this->row($m, 'web-ita')['active']);
        $this->assertSame('admin', $this->row($m, 'web-ita')['disabled_by']);
        $this->assertSame(['web-ita'], Settings::get('modules.disabled'));
        $this->assertSame($before, DB::table('ita_years')->count());
        $this->assertFalse(app(ModuleRegistry::class)->active('web-ita'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'module.disabled')->count());
        // a fresh registry (new request) reads the stored choice
        $fresh = new ModuleRegistry;
        $this->assertFalse($fresh->active('web-ita'));
        $this->state('web-ita', true)->assertOk();
        $this->assertTrue((new ModuleRegistry)->active('web-ita'));
        $this->assertSame([], Settings::get('modules.disabled'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'module.enabled')->count());
    }

        public function test_cannot_disable_a_module_another_active_module_requires(): void
    {
        $r = $this->state('registry', false)->assertUnprocessable();
        $this->assertStringContainsString('guardian-portal', $r->json('message'));
        $this->assertStringContainsString('api-gateway', $r->json('message'));
        $this->assertNull(Settings::get('modules.disabled'));
        $this->assertContains('guardian-portal', $this->row($this->getJson('/api/site/modules')->json('modules'), 'registry')['required_by']);
        $this->state('web-cms', false)->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'ปิดไม่ได้'));
        $this->assertContains('student-portal', $this->row($this->getJson('/api/site/modules')->json('modules'), 'guardian-portal')['required_by']);
        $this->state('guardian-portal', false)->assertUnprocessable(); // the student portal (F8) builds on it
        $this->state('student-portal', false)->assertOk();
        $this->state('guardian-portal', false)->assertOk();
        $this->state('api-gateway', false)->assertOk();
        $this->state('registry', false)->assertOk();
        $this->assertFalse(app(ModuleRegistry::class)->active('registry'));
    }

    public function test_enabling_needs_active_dependencies(): void
    {
        $this->state('student-portal', false)->assertOk();
        $this->state('guardian-portal', false)->assertOk();
        config(['saan.modules.disable' => 'registry']);
        app(ModuleRegistry::class)->flush();
        $r = $this->state('guardian-portal', true)->assertUnprocessable();
        $this->assertStringContainsString('registry', $r->json('message'));
    }

public function test_experimental_module_needs_typed_confirmation(): void
    {
        config(['school.demo' => false]);
        app(ModuleRegistry::class)->flush();
        $this->assertFalse(app(ModuleRegistry::class)->active('operations'));
        $this->state('operations', true)->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'operations'));
        $this->state('operations', true, ['confirm' => 'wrong'])->assertUnprocessable();
        $m = $this->state('operations', true, ['confirm' => 'operations'])->assertOk()->json('modules');
        $this->assertTrue($this->row($m, 'operations')['active']);
        $this->assertSame(['operations'], Settings::get('modules.enabled'));
    }

    public function test_env_disable_wins_over_admin_choice(): void
    {
        config(['saan.modules.disable' => 'web-social']);
        app(ModuleRegistry::class)->flush();
        Settings::put('modules.enabled', ['web-social']);
        app(ModuleRegistry::class)->flush();
        $this->assertFalse(app(ModuleRegistry::class)->active('web-social'));
        $this->assertSame('env', app(ModuleRegistry::class)->get('web-social')['disabled_by']);
        $this->state('web-social', true)->assertUnprocessable();
    }

    public function test_export_streams_zip_of_module_tables_and_requires_password(): void
    {
        $this->postJson('/api/ita/years', ['fiscal_year' => 2570])->assertCreated();
        $this->postJson('/api/site/modules/web-ita/export', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->assertSame(0, DB::table('audit_events')->where('action', 'module.exported')->count());
        $res = $this->post('/api/site/modules/web-ita/export', ['current_password' => self::PASSWORD]);
        $res->assertOk();
        $this->assertSame('application/zip', $res->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'exp'); file_put_contents($tmp, $res->streamedContent() ?: $res->baseResponse->getFile()->getContent());
        $zip = new \ZipArchive; $this->assertTrue($zip->open($tmp));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame('web-ita', $manifest['module']);
        $this->assertContains('ita_years', array_column($manifest['tables'], 'name'));
        $lines = array_filter(explode("\n", (string) $zip->getFromName('ita_years.jsonl')));
        $this->assertCount(DB::table('ita_years')->count(), $lines);
        $this->assertSame(2570, json_decode(reset($lines), true)['fiscal_year']);
        $zip->close(); @unlink($tmp);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'module.exported')->count());
        $this->postJson('/api/site/modules/no-such-module/export', ['current_password' => self::PASSWORD])->assertNotFound();
    }

    public function test_whats_new_shows_changelog_section_for_everyone_signed_in(): void
    {
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->get('/whats-new')->assertOk()->assertSee('มีอะไรใหม่')->assertSee('ระบบโมดูล');
        $this->get('/whats-new')->assertDontSee('<script>alert', false);
        $s = \App\Support\Modules\WhatsNew::section(base_path('CHANGELOG.md'), '0.0.0-none');
        $this->assertNotNull($s);
        $this->assertFalse($s['exact']);
        $this->assertSame('<code>a&lt;b</code> &lt;i&gt;', \App\Support\Modules\WhatsNew::inline('`a<b` <i>'));
    }

    private function signedFeed(array $feed, ?string $secretB64 = null): array
    {
        $kp = sodium_crypto_sign_keypair();
        config(['saan.release_public_key' => base64_encode(sodium_crypto_sign_publickey($kp))]);
        $json = json_encode($feed);
        $sig = sodium_crypto_sign_detached($json, $secretB64 ? base64_decode($secretB64) : sodium_crypto_sign_secretkey($kp));
        return [$json, base64_encode($sig), base64_encode(sodium_crypto_sign_secretkey($kp))];
    }

    public function test_feed_is_off_by_default_and_never_calls_out(): void
    {
        Http::fake();
        $this->assertFalse(UpdateFeed::enabled());
        $this->artisan('saan:update-check')->assertSuccessful();
        Http::assertNothingSent();
        $this->postJson('/api/site/updates/feed/check')->assertUnprocessable();
        $this->putJson('/api/site/updates/feed', ['enabled' => true, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->putJson('/api/site/updates/feed', ['enabled' => true, 'current_password' => self::PASSWORD])->assertOk()->assertJsonPath('enabled', true);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'updates.feed_enabled')->count());
    }

    public function test_valid_signature_stores_new_version(): void
    {
        config(['saan.update_feed' => 'https://feed.example.test/releases/feed.json', 'saan.version' => '0.6.0']);
        [$json, $sig] = $this->signedFeed(['core' => ['version' => '0.7.0', 'released' => '2026-12-01', 'summary' => 'เพิ่มโมดูล <b>x</b>', 'notes_url' => 'https://example.test/n'], 'modules' => ['web-ita' => ['version' => '9.0.0'], 'ghost' => ['version' => '1.0.0']]]);
        Http::fake(['https://feed.example.test/releases/feed.json' => Http::response($json), 'https://feed.example.test/releases/feed.sig' => Http::response($sig)]);
        Settings::put('updates.feed_enabled', true);
        $this->artisan('saan:update-check')->assertSuccessful();
        $a = Settings::get('updates.available');
        $this->assertTrue($a['any']);
        $this->assertSame('0.7.0', $a['core']['version']);
        $this->assertStringNotContainsString('<b>', $a['core']['summary']);
        $this->assertSame(['web-ita'], array_column($a['modules'], 'id'));
        $this->assertTrue(Settings::get('updates.last_check')['ok']);
        Http::assertSent(fn ($r) => $r->url() === 'https://feed.example.test/releases/feed.json' && $r->hasHeader('Accept') && !$r->hasHeader('Authorization') && !$r->hasHeader('Cookie'));
        $this->getJson('/api/site/updates')->assertOk()->assertJsonPath('feed.available.core.version', '0.7.0');
        $this->get('/whats-new')->assertSee('มีรุ่นใหม่ให้ติดตั้ง');
    }

    public function test_invalid_signature_is_rejected_and_keeps_previous_result(): void
    {
        config(['saan.update_feed' => 'https://feed.example.test/releases/feed.json', 'saan.version' => '0.6.0']);
        Settings::put('updates.feed_enabled', true);
        Settings::put('updates.available', ['any' => false, 'marker' => 'before']);
        [$json, , $secret] = $this->signedFeed(['core' => ['version' => '0.7.0']]);
        // signature made with a different key
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $bad = base64_encode(sodium_crypto_sign_detached($json, $other));
        Http::fake(['*feed.json' => Http::response($json), '*feed.sig' => Http::response($bad)]);
        $this->artisan('saan:update-check')->assertFailed();
        $this->assertSame('before', Settings::get('updates.available')['marker']);
        $last = Settings::get('updates.last_check');
        $this->assertFalse($last['ok']);
        $this->assertStringContainsString('ลายเซ็น', $last['error']);
        // tampered body with a valid signature of other content
        [$j2, $s2] = $this->signedFeed(['core' => ['version' => '0.7.0']]);
        Http::fake(['*feed.json' => Http::response(str_replace('0.7.0', '9.9.9', $j2)), '*feed.sig' => Http::response($s2)]);
        $this->artisan('saan:update-check')->assertFailed();
        $this->assertSame('before', Settings::get('updates.available')['marker']);
    }

    public function test_feed_rejects_plain_http_and_network_errors(): void
    {
        Settings::put('updates.feed_enabled', true);
        Http::fake();
        config(['saan.update_feed' => 'http://feed.example.test/feed.json']);
        $this->artisan('saan:update-check')->assertFailed();
        Http::assertNothingSent();
        config(['saan.update_feed' => 'https://feed.example.test/feed.json']);
        Http::fake(['*' => Http::response('nope', 500)]);
        $this->artisan('saan:update-check')->assertFailed();
        $this->assertFalse(Settings::get('updates.last_check')['ok']);
    }

    private function exportZip(string $id): \ZipArchive
    {
        $res = $this->post('/api/site/modules/'.$id.'/export', ['current_password' => self::PASSWORD]);
        $res->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'exp'); file_put_contents($tmp, $res->streamedContent() ?: $res->baseResponse->getFile()->getContent());
        $zip = new \ZipArchive; $this->assertTrue($zip->open($tmp));
        return $zip;
    }

    private function manifestTable(\ZipArchive $zip, string $table): array
    {
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        return collect($manifest['tables'])->firstWhere('name', $table) ?? $this->fail('table '.$table.' not exported');
    }

    public function test_default_feed_url_is_configured_and_works_without_overriding_config(): void
    {
        $this->assertSame('https://saan.linuxthai.org/releases/feed.json', config('saan.update_feed'));
        config(['saan.version' => '0.6.0']);
        [$json, $sig] = $this->signedFeed(['core' => ['version' => '0.7.0']]);
        Http::fake(['https://saan.linuxthai.org/releases/feed.json' => Http::response($json), 'https://saan.linuxthai.org/releases/feed.sig' => Http::response($sig)]);
        Settings::put('updates.feed_enabled', true);
        $this->artisan('saan:update-check')->assertSuccessful();
        $this->assertSame('0.7.0', Settings::get('updates.available')['core']['version']);
        Http::assertSent(fn ($r) => $r->url() === 'https://saan.linuxthai.org/releases/feed.sig');
    }

    public function test_notice_clears_after_update_or_when_feed_is_switched_off(): void
    {
        config(['saan.version' => '0.6.0']);
        Settings::put('updates.feed_enabled', true);
        Settings::put('updates.available', ['checked_at' => now()->toIso8601String(), 'current' => '0.6.0', 'core' => ['version' => '0.7.0', 'released' => null, 'summary' => '', 'notes_url' => null], 'modules' => [['id' => 'web-ita', 'name' => 'ITA', 'current' => '0.1.0', 'version' => '0.1.0', 'summary' => '', 'notes_url' => null]], 'any' => true]);
        $this->get('/whats-new')->assertSee('มีรุ่นใหม่ให้ติดตั้ง');
        $this->assertTrue(UpdateFeed::status()['available']['any']);
        $this->assertSame([], UpdateFeed::status()['available']['modules']); // module version in the record is not newer than installed
        config(['saan.version' => '0.7.0']);
        $this->assertFalse(UpdateFeed::status()['available']['any']);
        $this->get('/whats-new')->assertDontSee('มีรุ่นใหม่ให้ติดตั้ง');
        config(['saan.version' => '0.6.0']);
        $this->get('/whats-new')->assertSee('มีรุ่นใหม่ให้ติดตั้ง');
        Settings::put('updates.feed_enabled', false);
        $this->assertNull(UpdateFeed::status()['available']);
        $this->get('/whats-new')->assertDontSee('มีรุ่นใหม่ให้ติดตั้ง');
    }

    public function test_admin_landing_page_shows_banner_after_signed_check(): void
    {
        config(['saan.update_feed' => 'https://feed.example.test/releases/feed.json', 'saan.version' => '0.6.0']);
        Settings::put('updates.feed_enabled', true);
        $this->get('/cms')->assertOk()->assertDontSee('update-banner', false);
        [$json, $sig] = $this->signedFeed(['core' => ['version' => '0.7.0']]);
        Http::fake(['*feed.json' => Http::response($json), '*feed.sig' => Http::response($sig)]);
        $this->artisan('saan:update-check')->assertSuccessful();
        $this->get('/cms')->assertOk()->assertSee('update-banner', false)->assertSee('0.7.0');
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->get('/cms')->assertDontSee('update-banner', false);
    }

    public function test_old_signed_feed_cannot_replace_a_newer_one(): void
    {
        config(['saan.update_feed' => 'https://feed.example.test/releases/feed.json', 'saan.version' => '0.6.0']);
        Settings::put('updates.feed_enabled', true);
        [$json, $sig] = $this->signedFeed(['generated_at' => '2026-12-02T00:00:00+00:00', 'core' => ['version' => '0.7.0']]);
        Http::fake(['*feed.json' => Http::response($json), '*feed.sig' => Http::response($sig)]);
        $this->artisan('saan:update-check')->assertSuccessful();
        [$old, $oldSig] = $this->signedFeed(['generated_at' => '2026-12-01T00:00:00+00:00', 'core' => ['version' => '0.6.0']]);
        Http::fake(['*feed.json' => Http::response($old), '*feed.sig' => Http::response($oldSig)]);
        $this->artisan('saan:update-check')->assertFailed();
        $this->assertSame('0.7.0', Settings::get('updates.available')['core']['version']);
    }

    public function test_oversized_feed_is_rejected(): void
    {
        config(['saan.update_feed' => 'https://feed.example.test/releases/feed.json']);
        Settings::put('updates.feed_enabled', true);
        Http::fake(['*' => Http::response(str_repeat('a', UpdateFeed::MAX_BYTES + 1))]);
        $this->artisan('saan:update-check')->assertFailed();
        $this->assertStringContainsString('ขนาด', Settings::get('updates.last_check')['error']);
    }

    public function test_disabled_module_routes_are_blocked_and_data_kept(): void
    {
        $this->getJson('/api/ita/years')->assertOk();
        $this->state('web-ita', false)->assertOk();
        $this->getJson('/api/ita/years')->assertNotFound();
        $this->state('web-ita', true)->assertOk();
        $this->getJson('/api/ita/years')->assertOk();
    }

    public function test_toggle_keeps_other_choices_and_bumps_setting_versions(): void
    {
        $this->state('web-social', false)->assertOk();
        $v1 = DB::table('saan_settings')->where('key', 'modules.disabled')->value('version');
        $this->state('web-ita', false)->assertOk();
        $this->assertEqualsCanonicalizing(['web-ita', 'web-social'], Settings::get('modules.disabled'));
        $this->assertGreaterThan($v1, DB::table('saan_settings')->where('key', 'modules.disabled')->value('version'));
    }

    public function test_export_omits_pin_and_identity_secrets_and_other_schools_rows(): void
    {
        $zip = $this->exportZip('api-gateway');
        $t = $this->manifestTable($zip, 'api_student_pins');
        $this->assertContains('pin_hash', $t['omitted_columns']);
        foreach (array_filter(explode("\n", (string) $zip->getFromName('api_student_pins.jsonl'))) as $line) $this->assertArrayNotHasKey('pin_hash', json_decode($line, true));
        $zip->close();
        $zip = $this->exportZip('registry');
        $t = $this->manifestTable($zip, 'reg_identifiers');
        $this->assertContains('value_enc', $t['omitted_columns']);
        $this->assertContains('value_hash', $t['omitted_columns']);
        $zip->close();

        $other = (int) config('school.school_id') + 998;
        DB::table('ita_years')->insert(['school_id' => $other, 'fiscal_year' => 2599, 'title' => 'other school', 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $zip = $this->exportZip('web-ita');
        $this->assertStringNotContainsString('2599', (string) $zip->getFromName('ita_years.jsonl'));
        $this->assertIsArray(json_decode($zip->getFromName('manifest.json'), true)['skipped_unscoped']);
        $zip->close();
    }

    public function test_whats_new_escapes_markup_from_a_changelog(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'chg'); file_put_contents($f, "# Changelog\n\n## 1.0.0 - 2026-12-01\n\n- เพิ่ม <script>alert(1)</script> และ `<img src=x onerror=1>`\n");
        $s = \App\Support\Modules\WhatsNew::section($f, '1.0.0'); @unlink($f);
        $html = \App\Support\Modules\WhatsNew::inline($s['groups'][0]['items'][0]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }}
