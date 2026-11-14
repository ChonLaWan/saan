<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->first());
    }

    private function item(string $key): array
    {
        foreach ($this->getJson('/api/module-settings')->assertOk()->json('groups') as $g) foreach ($g['items'] as $it) if ($it['key'] === $key) return $it;
        $this->fail('missing '.$key);
    }

    private function save(string $key, mixed $value, ?int $version = null)
    {
        return $this->putJson('/api/module-settings', ['key' => $key, 'value' => $value, 'version' => $version ?? $this->item($key)['version']]);
    }

    public function test_registry_collects_schema_from_modules(): void
    {
        $keys = array_column(app(ModuleRegistry::class)->settingsSchema(), 'key');
        foreach (['attendance.edit_window_days', 'finance.staff_user_ids', 'documents.clerk_user_ids', 'maintenance.technician_user_ids', 'guardian.restricted_can_view', 'reports.signers', 'reports.show_rank', 'connectors.teacher_lookup', 'constructionism.rubric'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_admin_only_and_work_menu(): void
    {
        $this->get('/work')->assertOk()->assertSee('ตั้งค่าโมดูล')->assertSee('assets/saan-module-settings.js', false);
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::where('role', 'teacher')->first());
        $this->get('/work')->assertOk()->assertDontSee('saan-module-settings.js', false);
        $this->getJson('/api/module-settings')->assertForbidden();
        $this->putJson('/api/module-settings', ['key' => 'attendance.edit_window_days', 'value' => 5, 'version' => 0])->assertForbidden();
        $this->assertNull(DB::table('saan_settings')->where('key', 'attendance.edit_window_days')->first());
    }

    public function test_int_roundtrip_version_and_audit(): void
    {
        $it = $this->item('attendance.edit_window_days');
        $this->assertSame(3, $it['value']);
        $this->assertTrue($it['is_default']);
        $this->assertSame(0, $it['version']);
        $this->save('attendance.edit_window_days', 400)->assertUnprocessable();
        $this->save('attendance.edit_window_days', 'abc')->assertUnprocessable();
        $this->save('attendance.edit_window_days', true)->assertUnprocessable();
        $saved = $this->save('attendance.edit_window_days', 7)->assertOk()->json();
        $this->assertSame(7, $saved['value']);
        $this->assertSame(1, $saved['version']);
        $this->assertSame(7, Settings::get('attendance.edit_window_days'));
        // stale version (first write already happened) -> 409 with the current item
        $this->putJson('/api/module-settings', ['key' => 'attendance.edit_window_days', 'value' => 9, 'version' => 0])->assertConflict()->assertJsonPath('current.value', 7);
        $this->save('attendance.edit_window_days', 9, 1)->assertOk()->assertJsonPath('version', 2);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'settings.module.updated')->count());
    }

    public function test_bool_text_and_unknown_key(): void
    {
        $this->save('guardian.restricted_can_view', 'yes', 0)->assertUnprocessable();
        $this->save('guardian.restricted_can_view', true)->assertOk();
        $this->assertTrue(Settings::get('guardian.restricted_can_view'));
        $this->save('finance.invoice_prefix', 'BAD PREFIX!', 0)->assertUnprocessable();
        $this->save('finance.invoice_prefix', 'FIN1', 0)->assertOk()->assertJsonPath('value', 'FIN1');
        $this->save('reports.gpa_rounding', 'ceil', 0)->assertUnprocessable();
        $this->putJson('/api/module-settings', ['key' => 'no.such_key', 'value' => 1, 'version' => 0])->assertNotFound();
    }

    public function test_users_picker_validates_school_and_active(): void
    {
        $teacher = User::where('role', 'teacher')->first();
        $this->save('documents.clerk_user_ids', [$teacher->id, $teacher->id], 0)->assertOk()->assertJsonPath('value', [$teacher->id]);
        $this->save('documents.clerk_user_ids', [999999])->assertUnprocessable();
        $this->save('documents.clerk_user_ids', 'x')->assertUnprocessable();
        $this->save('documents.clerk_user_ids', ['1; drop'])->assertUnprocessable();
        $other = User::forceCreate(['name' => 'Other school', 'email' => 'other-ms@example.test', 'password' => bcrypt('x'), 'school_id' => (int) config('school.school_id') + 50, 'role' => 'teacher', 'is_active' => true]);
        $this->save('documents.clerk_user_ids', [$other->id])->assertUnprocessable();
        DB::table('users')->where('id', $teacher->id)->update(['is_active' => false]);
        $this->save('documents.clerk_user_ids', [$teacher->id])->assertUnprocessable();
        $users = $this->getJson('/api/module-settings')->json('users');
        $this->assertNotContains($teacher->id, array_column($users, 'id'));
        $this->assertNotContains($other->id, array_column($users, 'id'));
    }

    public function test_reports_signers_normalised_and_version_counter_bumped(): void
    {
        $this->save('reports.signers', [['label' => '', 'name' => 'x']], 0)->assertUnprocessable();
        $this->save('reports.signers', array_fill(0, 7, ['label' => 'a', 'name' => 'b']))->assertUnprocessable();
        $this->save('reports.signers', 'text')->assertUnprocessable();
        $this->save('reports.signers', [['label' => ' ผอ. ', 'name' => ' สมชาย ใจดี ', 'extra' => '<script>']])->assertOk()->assertJsonPath('value', [['label' => 'ผอ.', 'name' => 'สมชาย ใจดี']]);
        $this->assertSame(1, (int) Settings::get('reports.settings_version', 0));
        $this->save('reports.show_rank', true, 0)->assertOk();
        $this->assertSame(2, (int) Settings::get('reports.settings_version', 0));
    }

    public function test_rubric_validation(): void
    {
        $this->save('constructionism.rubric', ['criteria' => []], 0)->assertUnprocessable();
        $this->save('constructionism.rubric', ['criteria' => [['key' => 'Bad Key', 'label' => 'x']]])->assertUnprocessable();
        $this->save('constructionism.rubric', ['criteria' => [['key' => 'a', 'label' => 'ข้อหนึ่ง'], ['key' => 'b', 'label' => 'ข้อหนึ่ง']]])->assertUnprocessable();
        $ok = $this->save('constructionism.rubric', ['criteria' => [['key' => 'a', 'label' => 'ข้อหนึ่ง', 'levels' => ['ก', 'ข', 'ค', 'ง']]]])->assertOk()->json();
        $this->assertSame('a', $ok['value']['criteria'][0]['key']);
    }

    public function test_text_can_be_cleared(): void
    {
        $this->save('activities.cert_prefix', 'ABC', 0)->assertOk()->assertJsonPath('value', 'ABC');
        $this->save('activities.cert_prefix', '')->assertOk()->assertJsonPath('value', '');
        $this->save('school.name', 'โรงเรียนทดสอบ', 0)->assertOk();
        $this->save('school.name', '   ')->assertOk()->assertJsonPath('value', '');
        $this->save('school.name', ['x'])->assertUnprocessable();
    }

    public function test_finance_prefixes_must_differ(): void
    {
        $this->save('finance.receipt_prefix', 'inv', 0)->assertUnprocessable();
        $this->save('finance.invoice_prefix', 'rc', 0)->assertUnprocessable();
        $this->save('finance.receipt_prefix', 'R2', 0)->assertOk();
        $this->save('finance.invoice_prefix', 'r2')->assertUnprocessable();
        $this->save('finance.invoice_prefix', 'I2')->assertOk();
        // an emptied prefix prints as the default (INV / RC), so it must not equal the other prefix either
        $this->save('finance.receipt_prefix', 'INV')->assertOk();
        $this->save('finance.invoice_prefix', '')->assertUnprocessable();
    }

    public function test_maintenance_retention_moves_the_technician_form_token(): void
    {
        $tech = User::where('role', 'teacher')->first();
        $form = $this->getJson('/api/maintenance/technicians')->assertOk()->json();
        $this->save('maintenance.photo_retention_months', 36, 0)->assertOk();
        $this->putJson('/api/maintenance/technicians', ['version' => $form['version'], 'technician_user_ids' => [$tech->id], 'photo_retention_months' => 6])->assertConflict();
        $this->assertSame(36, (int) Settings::get('maintenance.photo_retention_months'));
        // reverse: the maintenance form saves, then a stale page edit of retention gets 409
        $v = $this->item('maintenance.photo_retention_months')['version'];
        $fresh = $this->getJson('/api/maintenance/technicians')->json();
        $this->putJson('/api/maintenance/technicians', ['version' => $fresh['version'], 'technician_user_ids' => [$tech->id], 'photo_retention_months' => 24])->assertOk();
        $this->save('maintenance.photo_retention_months', 12, $v)->assertConflict();
        $this->assertSame(24, (int) Settings::get('maintenance.photo_retention_months'));
    }

    public function test_reports_form_gets_409_after_page_edit_and_reverse(): void
    {
        $form = $this->getJson('/api/reports-pp/overview')->assertOk();
        $v = (int) Settings::get('reports.settings_version', 0);
        $this->save('reports.show_rank', true, 0)->assertOk();
        $this->putJson('/api/reports-pp/settings', ['version' => $v, 'show_rank' => false])->assertConflict();
        $this->putJson('/api/reports-pp/settings', ['version' => (int) Settings::get('reports.settings_version', 0), 'show_rank' => false])->assertOk();
        $this->save('reports.show_rank', true, 1)->assertConflict();
    }

    public function test_first_write_race_and_replay(): void
    {
        $stale = $this->item('attendance.edit_window_days')['version'];
        DB::table('saan_settings')->insert(['school_id' => (int) config('school.school_id'), 'key' => 'attendance.edit_window_days', 'value' => '5', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson('/api/module-settings', ['key' => 'attendance.edit_window_days', 'value' => 9, 'version' => $stale])->assertConflict();
        // an idempotent retry of the same PUT replays the first answer instead of conflicting with itself
        $h = ['Idempotency-Key' => 'ms-test-1'];
        $body = ['key' => 'attendance.edit_window_days', 'value' => 8, 'version' => 1];
        $this->putJson('/api/module-settings', $body, $h)->assertOk()->assertJsonPath('value', 8);
        $this->putJson('/api/module-settings', $body, $h)->assertOk()->assertJsonPath('value', 8);
        $this->assertSame(8, Settings::get('attendance.edit_window_days'));
    }

    public function test_rubric_scored_criterion_guard_and_default_view(): void
    {
        $it = $this->item('constructionism.rubric');
        $this->assertTrue($it['is_default']);
        $this->assertCount(5, $it['value']['criteria']);
        $this->save('constructionism.rubric', ['criteria' => [['key' => 'a', 'label' => 'ข้อหนึ่ง'], ['key' => 'b', 'label' => 'ข้อสอง']]])->assertOk();
        DB::table('con_reflections')->insert(['school_id' => (int) config('school.school_id'), 'project_id' => 1, 'student_id' => 1, 'rubric' => json_encode(['a' => 3]), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->save('constructionism.rubric', ['criteria' => [['key' => 'b', 'label' => 'ข้อสอง']]])->assertUnprocessable();
        $this->save('constructionism.rubric', ['criteria' => [['key' => 'a', 'label' => 'ข้อใหม่']]])->assertOk();
        $this->save('constructionism.rubric', ['criteria' => [['key' => 'a', 'label' => ['x']]]])->assertUnprocessable();
    }

    public function test_audit_keeps_full_json_values_and_users_roles(): void
    {
        $this->save('reports.signers', [['label' => 'ผอ.', 'name' => 'ก']], 0)->assertOk();
        $this->save('reports.signers', [['label' => 'รอง', 'name' => 'ข']])->assertOk();
        $ev = DB::table('audit_events')->where('action', 'settings.module.updated')->orderByDesc('id')->first();
        $this->assertStringContainsString('ผอ.', json_encode(json_decode(json_encode($ev), true), JSON_UNESCAPED_UNICODE));
        $editor = User::forceCreate(['name' => 'Editor', 'email' => 'ed-ms@example.test', 'password' => bcrypt('x'), 'school_id' => (int) config('school.school_id'), 'role' => 'editor', 'is_active' => true]);
        $this->save('finance.staff_user_ids', [$editor->id], 0)->assertUnprocessable();
        $teacher = User::where('role', 'teacher')->first();
        $this->save('finance.staff_user_ids', [$teacher->id])->assertOk();
    }

    public function test_core_keys_survive_and_disabled_module_key_is_404(): void
    {
        $reg = app(ModuleRegistry::class);
        $this->assertSame('core', $reg->settingDef('school.short_code')['module']);
        DB::table('saan_settings')->insert(['school_id' => (int) config('school.school_id'), 'key' => 'modules.disabled', 'value' => json_encode(['constructionism']), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->forgetInstance(ModuleRegistry::class);
        if (app(ModuleRegistry::class)->active('constructionism')) $this->markTestSkipped('constructionism cannot be disabled here');
        $this->putJson('/api/module-settings', ['key' => 'constructionism.rubric', 'value' => ['criteria' => []], 'version' => 0])->assertNotFound();
        $this->save('school.short_code', 'ABC', 0)->assertOk();
    }
}
