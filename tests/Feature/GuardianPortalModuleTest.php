<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Settings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\Assessment\GradePolicy;
use Tests\TestCase;

class GuardianPortalModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $now = now();
        // guardian 1: both children of ป.4/1 (students 1 and 2); guardian 2: restricted for student 1; guardian 3: student 9 (ม.1/1... other room)
        foreach ([[1, 'ผู้ปกครองทดสอบ ก'], [2, 'ผู้ปกครองทดสอบ ข'], [3, 'ผู้ปกครองทดสอบ ค']] as [$id, $name]) {
            DB::table('reg_guardians')->insert(['id' => $id, 'school_id' => 1, 'display_name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([[1, 1, true, null], [2, 1, true, null], [1, 2, false, 'PICKUPNOTE-MARKER'], [9, 3, true, null]] as [$student, $guardian, $pick, $note]) {
            DB::table('reg_student_guardians')->insert(['school_id' => 1, 'student_id' => $student, 'guardian_id' => $guardian, 'relationship' => 'mother',
                'can_pick_up' => $pick, 'pickup_note' => $note, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::where('role', $role)->first());
    }

    private function otherTeacher(): User
    {
        DB::table('teachers')->insert(['id' => 7, 'school_id' => 1, 'name' => 'ครูทดสอบ (สมมติ)', 'subject' => 'ศิลปะ']);
        DB::table('users')->insert(['id' => 9, 'school_id' => 1, 'name' => 'ครูอีกคน (สมมติ)', 'email' => 'other@demo.school.test', 'password' => 'x', 'role' => 'teacher',
            'is_demo' => true, 'teacher_id' => 7, 'classroom_id' => 2, 'created_at' => now(), 'updated_at' => now()]);
        return User::find(9);
    }

    /** F8: the school has announced the term, so its closed results may show to families. */
    private function announce(int $term = 1): void
    {
        Settings::put('student.announce.'.$term, ['at' => now()->subMinute()->format('Y-m-d H:i:s')]);
    }

    private function today(): string
    {
        return Carbon::now('Asia/Bangkok')->toDateString();
    }

    /** Staff creates a link; returns the JSON body. */
    private function makeLink(int $student, int $guardian, string $purpose = 'view', string $role = 'teacher', int $days = 14): array
    {
        return $this->as($role)->postJson('/api/guardian-portal/links', ['student_id' => $student, 'guardian_id' => $guardian, 'purpose' => $purpose, 'days' => $days])->assertCreated()->json();
    }

    /** Guardian opens a link in a fresh browser (no staff login, no old guardian session). */
    private function openAsGuardian(string $url): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('เปิดดูข้อมูล');
        $this->post($path)->assertRedirect('/guardian');
    }

    private function bhv(int $student, array $over = []): int
    {
        return DB::table('bhv_records')->insertGetId($over + ['school_id' => 1, 'term_id' => 1, 'student_id' => $student, 'occurred_on' => $this->today(), 'points' => 5,
            'note' => 'ช่วยงานโรงเรียน (ทดสอบ)', 'recorded_by' => 1, 'status' => 'active', 'guardian_visible' => true, 'classroom_id' => 1, 'rule_label' => 'ช่วยงาน',
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_link_is_one_time_expires_and_can_be_revoked(): void
    {
        $made = $this->makeLink(1, 1);
        $this->assertStringContainsString('/guardian/l/', $made['url']);
        $this->assertSame(1, DB::table('gp_links')->count());
        $this->assertNotSame($made['url'], DB::table('gp_links')->value('token_hash')); // only the hash is stored
        $token = basename($made['path']);
        $this->assertSame(hash('sha256', $token), DB::table('gp_links')->value('token_hash'));
        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_portal.link.created']);
        $this->assertEqualsWithDelta(14 * 86400, strtotime($made['expires_at']) - time(), 120);

        $this->openAsGuardian($made['url']);
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1);
        $this->assertDatabaseHas('gp_events', ['action' => 'guardian.login', 'guardian_id' => 1, 'student_id' => 1]);

        // second use of the same link (another browser) is refused with the same page as an unknown link
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($made['path'])->assertNotFound()->assertSee('ลิงก์นี้ใช้ไม่ได้');
        $this->post($made['path'])->assertNotFound();
        $this->getJson('/guardian/api/children')->assertUnauthorized();
        $this->get('/guardian/l/'.str_repeat('a', 32))->assertNotFound();

        // expired link
        $exp = $this->makeLink(1, 1);
        DB::table('gp_links')->where('id', $exp['id'])->update(['expires_at' => now()->subMinute()]);
        $this->app['auth']->forgetGuards();
        $this->get($exp['path'])->assertNotFound();
        $this->post($exp['path'])->assertNotFound();

    }

    public function test_revoke_pending_link_and_new_link_replaces_old(): void
    {
        $a = $this->makeLink(1, 1);
        $b = $this->makeLink(1, 1); // replaces a
        $this->assertNotNull(DB::table('gp_links')->where('id', $a['id'])->value('revoked_at'));
        $this->app['auth']->forgetGuards();
        $this->get($a['path'])->assertNotFound();
        $c = $this->makeLink(1, 1);
        $this->as('teacher')->deleteJson('/api/guardian-portal/links/'.$c['id'], ['version' => 99])->assertStatus(409)->assertJsonStructure(['message', 'current']);
        $this->deleteJson('/api/guardian-portal/links/'.$c['id'], ['version' => $c['version']])->assertOk()->assertJsonPath('revoked', true);
        $this->deleteJson('/api/guardian-portal/links/'.$c['id'], ['version' => $c['version'] + 1])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->get($c['path'])->assertNotFound();

        // revoking a link after the session started ends the session
        $d = $this->makeLink(1, 1);
        $this->openAsGuardian($d['url']);
        $this->getJson('/guardian/api/children/1/behavior')->assertOk();
        $link = DB::table('gp_links')->where('id', $d['id'])->first();
        $this->as('teacher')->deleteJson('/api/guardian-portal/links/'.$d['id'], ['version' => $link->version])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/guardian/api/children/1/behavior')->assertNotFound();
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_link_is_limited_to_its_student_and_guardian(): void
    {
        $made = $this->makeLink(1, 1); // guardian 1 also has student 2 in the registry, but this link covers student 1 only
        $this->openAsGuardian($made['url']);
        $this->getJson('/guardian/api/children')->assertJsonCount(1, 'data');
        foreach (['attendance', 'behavior', 'results', 'health'] as $part) {
            $this->getJson("/guardian/api/children/1/$part")->assertOk();
            $this->getJson("/guardian/api/children/2/$part")->assertNotFound();
            $this->getJson("/guardian/api/children/9/$part")->assertNotFound();
            $this->getJson("/guardian/api/children/999/$part")->assertNotFound();
        }
        $rec = $this->bhv(2);
        $this->postJson('/guardian/api/children/2/ack', ['kind' => 'behavior', 'ref_id' => $rec])->assertNotFound();
        $this->assertSame(0, DB::table('gp_acks')->count());
        // a record of child 2 cannot be acknowledged through child 1
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => $rec])->assertNotFound();
        $this->assertNull(DB::table('bhv_records')->where('id', $rec)->value('guardian_ack_at'));
    }

    public function test_restricted_guardian_needs_school_permission(): void
    {
        $this->as('teacher')->postJson('/api/guardian-portal/links', ['student_id' => 1, 'guardian_id' => 2])->assertUnprocessable();
        $this->assertSame(0, DB::table('gp_links')->count());
        $g = $this->getJson('/api/guardian-portal/classrooms/1/guardians')->assertOk()->json('students.0.guardians');
        $this->assertSame([true, false], array_column($g, 'may_view'));
        $this->putJson('/api/guardian-portal/settings', ['restricted_can_view' => true])->assertForbidden(); // teacher cannot change it

        $this->as('admin')->putJson('/api/guardian-portal/settings', ['restricted_can_view' => true])->assertOk()->assertJsonPath('settings.restricted_can_view', true);
        $made = $this->makeLink(1, 2, 'view', 'admin');
        $this->openAsGuardian($made['url']);
        $this->getJson('/guardian/api/children')->assertJsonCount(1, 'data');

        // the school switches the permission off again: the open session loses access immediately
        Settings::put('guardian.restricted_can_view', false);
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/guardian/api/children/1/behavior')->assertNotFound();
        $this->assertSame(false, Settings::get('guardian.restricted_can_view'));
    }

    public function test_acknowledgements_are_written_once_and_idempotent(): void
    {
        $rec = $this->bhv(1);
        $hidden = $this->bhv(1, ['guardian_visible' => false]);
        $voided = $this->bhv(1, ['status' => 'voided', 'voided_reason' => 'SECRET-REASON']);
        $made = $this->makeLink(1, 1);
        $this->openAsGuardian($made['url']);

        $b = $this->getJson('/guardian/api/children/1/behavior')->assertOk()->json();
        $this->assertSame([$rec], array_column($b['records'], 'id')); // hidden and voided never listed
        $this->assertFalse($b['records'][0]['acknowledged']);
        $this->assertStringNotContainsString('SECRET-REASON', json_encode($b));

        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => $hidden])->assertNotFound();
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => $voided])->assertNotFound();

        $first = $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => $rec, 'note' => 'รับทราบครับ'])->assertOk()->json();
        $this->assertTrue($first['acknowledged']);
        $this->assertFalse($first['already']);
        $at = DB::table('bhv_records')->where('id', $rec)->first();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $at->guardian_ack_at); // never a json-encoded Carbon
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $first['acknowledged_at']);
        $this->assertSame('รับทราบครับ', $at->guardian_ack_note);
        $this->assertSame('guardian:1', $at->guardian_ack_by);
        $this->assertSame(2, (int) $at->version);

        $again = $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => $rec])->assertOk()->json();
        $this->assertTrue($again['already']);
        $this->assertSame($first['acknowledged_at'], $again['acknowledged_at']);
        $this->assertSame(1, DB::table('gp_acks')->count());
        $this->assertSame(2, (int) DB::table('bhv_records')->where('id', $rec)->value('version')); // not written twice
        $this->assertSame(1, DB::table('gp_events')->where('action', 'guardian.acknowledged')->count());
        $rec0 = $this->getJson('/guardian/api/children/1/behavior')->json('records.0');
        $this->assertTrue($rec0['acknowledged']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $rec0['acknowledged_at']);

        // attendance month (note kept, months outside today..12 months back refused)
        $month = substr($this->today(), 0, 7);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'attendance_month', 'ref_month' => '1990-01'])->assertUnprocessable();
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'attendance_month', 'ref_month' => Carbon::now('Asia/Bangkok')->startOfMonth()->subMonthsNoOverflow(13)->format('Y-m')])->assertUnprocessable();
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'attendance_month', 'ref_month' => $month, 'note' => '  ลาป่วยจริง  '])->assertOk()->assertJsonPath('already', false);
        $this->assertSame('ลาป่วยจริง', DB::table('gp_acks')->where('kind', 'attendance_month')->value('note'));
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'attendance_month', 'ref_month' => $month])->assertOk()->assertJsonPath('already', true);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'attendance_month', 'ref_month' => '2999-01'])->assertUnprocessable();
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'attendance_month', 'ref_month' => 'x'])->assertUnprocessable();
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'weird', 'ref_id' => 1])->assertUnprocessable();
        $this->assertTrue($this->getJson('/guardian/api/children/1/attendance')->json('acknowledged'));
        $this->assertSame(2, DB::table('gp_acks')->count());
        $this->assertSame(2, DB::table('gp_events')->where('action', 'guardian.acknowledged')->count());
    }

    public function test_term_results_only_for_closed_courses(): void
    {
        $subject = DB::table('asm_subjects')->insertGetId(['school_id' => 1, 'code' => 'M1', 'code_key' => 'm1', 'name' => 'คณิตศาสตร์ (ทดสอบ)', 'created_at' => now(), 'updated_at' => now()]);
        $subject2 = DB::table('asm_subjects')->insertGetId(['school_id' => 1, 'code' => 'T1', 'code_key' => 't1', 'name' => 'ภาษาไทย (ทดสอบ)', 'created_at' => now(), 'updated_at' => now()]);
        $mk = fn (int $subj, string $status) => DB::table('asm_courses')->insertGetId(['school_id' => 1, 'term_id' => 1, 'subject_id' => $subj, 'classroom_id' => 1,
            'policy' => json_encode(GradePolicy::defaults()), 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $closed = $mk($subject, 'closed');
        $open = $mk($subject2, 'open');
        foreach ([$closed, $open] as $c) foreach ([['continuous', 6000], ['exam', 2500]] as [$k, $v]) {
            DB::table('asm_scores')->insert(['school_id' => 1, 'course_id' => $c, 'student_id' => 1, 'component_key' => $k, 'score_centi' => $v, 'created_at' => now(), 'updated_at' => now()]);
        }
        $made = $this->makeLink(1, 1);
        $this->openAsGuardian($made['url']);
        $this->assertSame([], $this->getJson('/guardian/api/children/1/results')->assertOk()->json('terms'), 'not announced yet: nothing of the term shows');
        $this->announce();
        $r = $this->getJson('/guardian/api/children/1/results')->assertOk()->json();
        $this->assertCount(1, $r['terms']);
        $this->assertSame(['คณิตศาสตร์ (ทดสอบ)'], array_column($r['terms'][0]['courses'], 'subject'));
        $this->assertSame(85, $r['terms'][0]['courses'][0]['total']);
        $this->assertSame('4', $r['terms'][0]['courses'][0]['grade']);
        $this->assertStringNotContainsString('ภาษาไทย (ทดสอบ)', json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'term_result', 'ref_id' => 1])->assertOk()->assertJsonPath('already', false);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'term_result', 'ref_id' => 1])->assertOk()->assertJsonPath('already', true);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'term_result', 'ref_id' => 77])->assertNotFound();
        DB::table('asm_courses')->where('id', $closed)->update(['status' => 'open']);
        $this->assertSame([], $this->getJson('/guardian/api/children/1/results')->json('terms'));
    }

    public function test_staff_scoping(): void
    {
        $this->as('teacher'); // ครูอรุณ: homeroom of classroom 1
        $this->getJson('/api/guardian-portal/context')->assertOk()->assertJsonCount(1, 'classrooms')->assertJsonPath('can_manage_settings', false);
        $this->getJson('/api/guardian-portal/classrooms/1/guardians')->assertOk()->assertJsonPath('students.0.guardians.0.name', 'ผู้ปกครองทดสอบ ก');
        $this->getJson('/api/guardian-portal/classrooms/2/guardians')->assertForbidden();
        $this->getJson('/api/guardian-portal/classrooms/999/guardians')->assertNotFound();
        $this->postJson('/api/guardian-portal/links', ['student_id' => 9, 'guardian_id' => 3])->assertForbidden(); // student of classroom 2
        $this->postJson('/api/guardian-portal/links', ['student_id' => 1, 'guardian_id' => 3])->assertNotFound(); // guardian 3 is not linked to student 1

        $other = $this->otherTeacher(); // homeroom is classroom 2 via users.classroom_id
        $this->actingAs($other);
        $this->postJson('/api/guardian-portal/links', ['student_id' => 1, 'guardian_id' => 1])->assertForbidden();
        $this->getJson('/api/guardian-portal/classrooms/1/guardians')->assertForbidden();
    }

    public function test_staff_scoping_other_teacher_and_admin(): void
    {
        $other = $this->otherTeacher();
        $this->actingAs($other)->getJson('/api/guardian-portal/classrooms/2/guardians')->assertOk()->assertJsonPath('students.0.guardians.0.name', 'ผู้ปกครองทดสอบ ค');
        $r = $this->postJson('/api/guardian-portal/links', ['student_id' => 9, 'guardian_id' => 3])->assertCreated()->json();
        $this->assertSame(9, (int) DB::table('gp_links')->where('id', $r['id'])->value('student_id'));
        // another teacher cannot revoke it either
        $this->as('teacher')->deleteJson('/api/guardian-portal/links/'.$r['id'], ['version' => 1])->assertForbidden();
        $this->as('admin')->getJson('/api/guardian-portal/context')->assertJsonCount(3, 'classrooms')->assertJsonPath('can_manage_settings', true);
        $this->getJson('/api/guardian-portal/classrooms/3/guardians')->assertOk();
        $this->deleteJson('/api/guardian-portal/links/'.$r['id'], ['version' => 1])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_portal.link.revoked']);
        // no session, no role
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/guardian-portal/context')->assertUnauthorized();
        $this->postJson('/api/guardian-portal/links', ['student_id' => 1, 'guardian_id' => 1])->assertUnauthorized();
    }

    public function test_guardian_json_never_carries_identifiers_documents_or_pickup(): void
    {
        $now = now();
        DB::table('students')->where('id', 1)->update(['code' => 'CODE-MARKER-01', 'nickname' => 'NICKMARKER']);
        DB::table('reg_identifiers')->insert(['school_id' => 1, 'person_type' => 'student', 'person_id' => 1, 'kind' => 'thai_id', 'value_enc' => 'ENCMARKER', 'value_hash' => str_repeat('a', 64), 'last4' => '9999', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('reg_documents')->insert(['school_id' => 1, 'student_id' => 1, 'kind' => 'birth', 'label' => 'DOCMARKER', 'path' => 'x/y.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('b', 64), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('hlth_profiles')->insert(['school_id' => 1, 'student_id' => 1, 'blood_group' => 'AB', 'allergies' => 'ALLERGYMARKER', 'emergency_note' => 'EMERGENCYMARKER', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('hlth_measurements')->insert(['school_id' => 1, 'term_id' => 1, 'round' => 1, 'student_id' => 1, 'measured_on' => $this->today(), 'weight_kg' => 30.5, 'height_cm' => 130.2, 'recorded_by' => 2, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('attendances')->insert(['school_id' => 1, 'student_id' => 1, 'date' => $this->today(), 'status' => 'absent', 'note' => 'ATTNOTEMARKER', 'recorded_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->bhv(1);

        $made = $this->makeLink(1, 1);
        $this->openAsGuardian($made['url']);
        $all = [];
        foreach (['/guardian/api/children', '/guardian/api/children/1/attendance', '/guardian/api/children/1/behavior', '/guardian/api/children/1/results', '/guardian/api/children/1/health'] as $url) {
            $all[$url] = $this->getJson($url)->assertOk()->json();
        }
        $this->assertSame('absent', $all['/guardian/api/children/1/attendance']['exceptions'][0]['status']);
        $this->assertSame(30.5, $all['/guardian/api/children/1/health']['measurements'][0]['weight_kg']);
        $this->assertSame(130.2, $all['/guardian/api/children/1/health']['measurements'][0]['height_cm']);
        $this->assertSame(['id' => 1, 'name' => 'นักเรียนตัวอย่าง 01', 'classroom' => 'ป.4/1'], $all['/guardian/api/children'][
            'data'][0]);

        $badKey = '/(code|national|passport|identifier|document|pickup|allerg|blood|phone|email|password|token|recorded_by|line_id|_enc|last4|ack_by|note_)/i';
        $walk = function ($node, string $path) use (&$walk, $badKey) {
            if (!is_array($node)) return;
            foreach ($node as $k => $v) {
                if (is_string($k)) $this->assertDoesNotMatchRegularExpression($badKey, $k, "forbidden key $k at $path");
                $walk($v, $path.'/'.$k);
            }
        };
        $blob = '';
        foreach ($all as $url => $json) { $walk($json, $url); $blob .= json_encode($json, JSON_UNESCAPED_UNICODE); }
        foreach (['CODE-MARKER-01', 'NICKMARKER', 'ENCMARKER', '9999', 'DOCMARKER', 'ALLERGYMARKER', 'EMERGENCYMARKER', 'PICKUPNOTE-MARKER', 'ATTNOTEMARKER'] as $secret) {
            $this->assertStringNotContainsString($secret, $blob);
        }
        // the page itself never prints them either
        $page = $this->get('/guardian')->assertOk()->getContent();
        foreach (['CODE-MARKER-01', 'PICKUPNOTE-MARKER'] as $secret) $this->assertStringNotContainsString($secret, $page);
        // the old way (student code in the URL) opens nothing
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/guardian/api/children')->assertUnauthorized();
        $this->get('/guardian?code=CODE-MARKER-01')->assertOk()->assertDontSee('นักเรียนตัวอย่าง 01')->assertSee('เข้าสู่ระบบ');
    }

    public function test_attendance_shows_exceptions_only_for_the_month(): void
    {
        $now = now();
        $today = $this->today();
        DB::table('attendances')->delete(); // seeded demo rows would collide with the dates used here
        foreach ([[$today, 'late'], [Carbon::parse($today)->subMonthsNoOverflow(2)->toDateString(), 'absent']] as [$d, $s]) {
            DB::table('attendances')->insert(['school_id' => 1, 'student_id' => 1, 'date' => $d, 'status' => $s, 'recorded_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('attendances')->insert(['school_id' => 1, 'student_id' => 1, 'date' => Carbon::parse($today)->subDay()->toDateString(), 'status' => 'present', 'recorded_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('attendances')->insert(['school_id' => 1, 'student_id' => 2, 'date' => $today, 'status' => 'sick', 'recorded_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $made = $this->makeLink(1, 1);
        $this->openAsGuardian($made['url']);
        $r = $this->getJson('/guardian/api/children/1/attendance')->assertOk()->json();
        $this->assertSame(['late'], array_column($r['exceptions'], 'status'));
        $this->assertSame(1, $r['counts']['late']);
        $old = $this->getJson('/guardian/api/children/1/attendance?month='.substr(Carbon::parse($today)->subMonthsNoOverflow(2)->toDateString(), 0, 7))->assertOk()->json();
        $this->assertSame(['absent'], array_column($old['exceptions'], 'status'));
        $this->getJson('/guardian/api/children/1/attendance?month=2999-01')->assertUnprocessable();
        $this->getJson('/guardian/api/children/1/attendance?month=abc')->assertUnprocessable();
    }

    public function test_account_from_invite_login_and_throttle(): void
    {
        $inv = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($inv['path'])->assertOk()->assertSee('ตั้งบัญชีผู้ปกครอง');
        $this->post($inv['path'], ['email' => 'parent@example.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($inv['path'], ['email' => 'Parent@Example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $acc = DB::table('gp_accounts')->first();
        $this->assertSame('parent@example.test', $acc->email_key);
        $this->assertNotSame('correct-horse-9', $acc->password);
        $this->assertSame(1, (int) $acc->guardian_id);
        // an account sees only the children whose teacher issued a used, unrevoked invite (guardian 1 is also linked to student 2)
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1);
        $this->getJson('/guardian/api/children/2/behavior')->assertNotFound();
        $this->get($inv['path'])->assertNotFound(); // invite is one-time too

        // login by e-mail, case-insensitive, wrong password refused
        $this->flushSession();
        $this->getJson('/guardian/api/children')->assertUnauthorized();
        $this->post('/guardian/login', ['email' => 'parent@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->post('/guardian/login', ['email' => 'PARENT@example.test', 'password' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull(DB::table('gp_accounts')->value('last_login_at'));
        $this->assertSame(2, DB::table('gp_events')->where('action', 'guardian.login')->count());
        $this->assertSame(1, DB::table('gp_events')->where('action', 'guardian.login.failed')->count());

        // e-mail already used by another guardian
        $inv3 = $this->makeLink(9, 3, 'invite', 'admin');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv3['path'], ['email' => 'parent@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertSessionHasErrors('email');
        $this->assertSame(1, DB::table('gp_accounts')->count());

        // switching the account off or bumping auth_version ends the session
        $this->post('/guardian/login', ['email' => 'parent@example.test', 'password' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertOk();
        DB::table('gp_accounts')->update(['auth_version' => 2]);
        $this->getJson('/guardian/api/children')->assertUnauthorized();

        // per e-mail limiter, then the route throttle
        $this->flushSession();
        for ($i = 0; $i < 5; $i++) $this->post('/guardian/login', ['email' => 'nobody@example.test', 'password' => 'x'.$i]);
        $this->post('/guardian/login', ['email' => 'nobody@example.test', 'password' => 'x'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('หลายครั้ง', session('errors')->first('email'));
        $status = null;
        for ($i = 0; $i < 25; $i++) $status = $this->post('/guardian/login', ['email' => 'flood'.$i.'@example.test', 'password' => 'x'])->getStatusCode();
        $this->assertSame(429, $status);
    }

    public function test_pages_are_noindex_and_module_is_registered(): void
    {
        $this->get('/guardian')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertSee('เข้าสู่ระบบ');
        $this->getJson('/guardian/api/children')->assertUnauthorized()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertContains('guardian-portal', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->as('teacher')->get('/work')->assertOk()->assertSee('assets/modules/guardian-portal/staff.js', false)->assertSee('ลิงก์ผู้ปกครอง');
        $this->as('admin')->get('/work')->assertOk()->assertSee('ลิงก์ผู้ปกครอง');
    }

    public function test_second_guardian_has_own_ack_and_note_and_staff_sees_it(): void
    {
        DB::table('reg_guardians')->insert(['id' => 4, 'school_id' => 1, 'display_name' => 'ผู้ปกครองทดสอบ ง', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reg_student_guardians')->insert(['school_id' => 1, 'student_id' => 1, 'guardian_id' => 4, 'relationship' => 'father', 'can_pick_up' => true, 'created_at' => now(), 'updated_at' => now()]);
        $rec = $this->bhv(1);
        $l1 = $this->makeLink(1, 1);
        $l4 = $this->makeLink(1, 4);
        $this->openAsGuardian($l1['url']);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => $rec, 'note' => 'คนแรก'])->assertOk()->assertJsonPath('already', false);
        $v = (int) DB::table('bhv_records')->where('id', $rec)->value('version');

        $this->openAsGuardian($l4['url']);
        $b = $this->getJson('/guardian/api/children/1/behavior')->json('records.0');
        $this->assertFalse($b['acknowledged']);          // own acknowledgement, not the other guardian's
        $this->assertTrue($b['family_acknowledged']);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => $rec, 'note' => 'คนที่สอง'])->assertOk()->assertJsonPath('already', false);
        $this->assertSame(2, DB::table('gp_acks')->where('kind', 'behavior')->count());
        $this->assertSame($v, (int) DB::table('bhv_records')->where('id', $rec)->value('version')); // bhv_records keeps the first one
        $this->assertSame('guardian:1', DB::table('bhv_records')->where('id', $rec)->value('guardian_ack_by'));
        $this->assertTrue($this->getJson('/guardian/api/children/1/behavior')->json('records.0.acknowledged'));

        // term acknowledged by both guardians counts once in the staff page; notes are listed
        foreach ([1, 4] as $g) DB::table('gp_acks')->insert(['school_id' => 1, 'guardian_id' => $g, 'student_id' => 1, 'kind' => 'term_result', 'ref_id' => 1, 'ref_key' => '1|', 'note' => 'โน้ต <b>x</b>', 'acknowledged_at' => now()->toDateTimeString()]);
        $s = $this->as('teacher')->getJson('/api/guardian-portal/classrooms/1/guardians')->assertOk()->json('students.0');
        $this->assertSame(1, $s['acks']['term_results_acked']);
        $this->assertContains('คนที่สอง', array_column($s['notes'], 'note'));
        $this->assertContains('โน้ต <b>x</b>', array_column($s['notes'], 'note')); // raw JSON, escaped by staff.js
    }

    public function test_staff_can_disable_account_and_revoke_opened_link(): void
    {
        $inv = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv['path'], ['email' => 'p@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(1, 'data');
        $acc = DB::table('gp_accounts')->first();

        $staff = $this->as('teacher')->getJson('/api/guardian-portal/classrooms/1/guardians')->assertOk()->json('students.0.guardians.0.account');
        $this->assertTrue($staff['active']);
        $this->assertArrayNotHasKey('password', $staff);
        $this->putJson('/api/guardian-portal/accounts/1', ['version' => 99, 'active' => false])->assertStatus(409);
        $this->putJson('/api/guardian-portal/accounts/1', ['version' => $staff['version'], 'active' => true])->assertUnprocessable();
        $this->actingAs($this->otherTeacher())->putJson('/api/guardian-portal/accounts/1', ['version' => $staff['version'], 'active' => false])->assertForbidden();
        $this->assertTrue((bool) DB::table('gp_accounts')->value('is_active'));

        $this->as('teacher')->putJson('/api/guardian-portal/accounts/1', ['version' => $staff['version'], 'active' => false])->assertOk()->assertJsonPath('active', false);
        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_portal.account.disabled']);
        $this->assertSame((int) $acc->auth_version + 1, (int) DB::table('gp_accounts')->value('auth_version'));
        $this->putJson('/api/guardian-portal/accounts/1', ['version' => $staff['version'] + 1, 'active' => false])->assertUnprocessable();
        $this->putJson('/api/guardian-portal/accounts/77', ['version' => 1, 'active' => false])->assertNotFound();

        // the open guardian session is dead and the password no longer logs in
        $this->app['auth']->forgetGuards();
        $this->getJson('/guardian/api/children')->assertUnauthorized();
        $this->flushSession();
        $this->post('/guardian/login', ['email' => 'p@example.test', 'password' => 'correct-horse-9'])->assertSessionHasErrors('email');
        // no staff login, no way to disable
        $this->putJson('/api/guardian-portal/accounts/1', ['version' => 1, 'active' => false])->assertUnauthorized();
    }

    public function test_sibling_needs_own_invite_and_revoking_it_removes_the_child(): void
    {
        $inv = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv['path'], ['email' => 'p2@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertJsonCount(1, 'data');
        $inv2 = $this->makeLink(2, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv2['path'], ['email' => 'p2@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertJsonCount(2, 'data');
        // revoking the used invite of child 2 takes that child away
        $link = DB::table('gp_links')->where('id', $inv2['id'])->first();
        $this->as('teacher')->deleteJson('/api/guardian-portal/links/'.$inv2['id'], ['version' => $link->version])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->post('/guardian/login', ['email' => 'p2@example.test', 'password' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1);
    }

    public function test_link_columns_are_datetime_not_auto_updating_timestamp(): void
    {
        $made = $this->makeLink(1, 1);
        $before = DB::table('gp_links')->where('id', $made['id'])->value('expires_at');
        $row = DB::table('gp_links')->where('id', $made['id'])->first();
        $this->as('teacher')->deleteJson('/api/guardian-portal/links/'.$made['id'], ['version' => $row->version])->assertOk();
        $this->assertSame($before, DB::table('gp_links')->where('id', $made['id'])->value('expires_at'));
        $this->assertStringContainsString('datetime', strtolower((string) collect(\Illuminate\Support\Facades\Schema::getColumns('gp_links'))->firstWhere('name', 'expires_at')['type_name']));
    }

    public function test_link_creation_throttle(): void
    {
        $this->as('admin');
        $status = null;
        for ($i = 0; $i < 62; $i++) $status = $this->postJson('/api/guardian-portal/links', ['student_id' => 1, 'guardian_id' => 1])->getStatusCode();
        $this->assertSame(429, $status);
    }

    public function test_disabled_modules_are_hidden_from_guardians(): void
    {
        $this->bhv(1);
        DB::table('hlth_measurements')->insert(['school_id' => 1, 'student_id' => 1, 'term_id' => 1, 'round' => 1, 'measured_on' => $this->today(), 'weight_kg' => 20, 'height_cm' => 110, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $made = $this->makeLink(1, 1);
        $this->openAsGuardian($made['url']);
        $this->getJson('/guardian/api/children/1/behavior')->assertJsonPath('available', true);
        $this->getJson('/guardian/api/children/1/health')->assertJsonPath('available', true);

        config(['saan.modules.disable' => 'health,behavior,assessment,attendance']);
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $this->getJson('/guardian/api/children/1/behavior')->assertOk()->assertJsonPath('available', false)->assertJsonPath('records', []);
        $this->getJson('/guardian/api/children/1/health')->assertOk()->assertJsonPath('available', false)->assertJsonPath('measurements', []);
        $this->getJson('/guardian/api/children/1/results')->assertOk()->assertJsonPath('available', false);
        $this->getJson('/guardian/api/children/1/attendance')->assertOk()->assertJsonPath('available', false);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => 1])->assertNotFound();
    }

    public function test_teacher_invite_cannot_reset_an_existing_account(): void
    {
        $inv = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv['path'], ['email' => 'g1@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $hash = DB::table('gp_accounts')->where('guardian_id', 1)->value('password');
        $authVersion = DB::table('gp_accounts')->where('guardian_id', 1)->value('auth_version');

        // another teacher issues an invite for the same guardian and tries to set her own credentials
        $inv2 = $this->makeLink(2, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv2['path'], ['email' => 'attacker@example.test', 'password' => 'attacker-pass-1', 'password_confirmation' => 'attacker-pass-1'])->assertSessionHasErrors('password');
        $acc = DB::table('gp_accounts')->where('guardian_id', 1)->first();
        $this->assertSame($hash, $acc->password);
        $this->assertSame('g1@example.test', $acc->email);
        $this->assertSame((int) $authVersion, (int) $acc->auth_version);
        $this->assertNull(DB::table('gp_links')->where('id', $inv2['id'])->value('used_at')); // link not burned by the failed attempt

        // the real guardian proves ownership with the existing credentials: the child is attached, credentials stay
        $this->post($inv2['path'], ['email' => 'g1@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->assertSame($hash, DB::table('gp_accounts')->where('guardian_id', 1)->value('password'));
        $this->getJson('/guardian/api/children')->assertJsonCount(2, 'data');
    }

    public function test_teacher_invite_cannot_reopen_a_disabled_account(): void
    {
        $inv = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv['path'], ['email' => 'g1@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        DB::table('gp_accounts')->where('guardian_id', 1)->update(['is_active' => false]);
        $before = DB::table('gp_accounts')->where('guardian_id', 1)->first();

        // teacher 2 issues an invite for (G, B) and opens it herself, with or without the old password
        $inv2 = $this->makeLink(2, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv2['path'], ['email' => 'attacker@example.test', 'password' => 'attacker-pass-1'])->assertSessionHasErrors('password');
        $this->post($inv2['path'], ['email' => 'g1@example.test', 'password' => 'correct-horse-9'])->assertSessionHasErrors('password');
        $acc = DB::table('gp_accounts')->where('guardian_id', 1)->first();
        $this->assertSame($before->password, $acc->password);
        $this->assertSame($before->email, $acc->email);
        $this->assertSame(0, (int) $acc->is_active);
        $this->assertNull(DB::table('gp_links')->where('id', $inv2['id'])->value('used_at'));
    }

    public function test_unique_violation_helper_ignores_fk_and_not_null(): void
    {
        $e = function (string $state, int $drv, string $msg) {
            $pdo = new \PDOException($msg);
            $pdo->errorInfo = [$state, $drv, $msg];
            return new \Illuminate\Database\QueryException('mysql', 'insert', [], $pdo);
        };
        $u = fn ($x) => \App\Support\Saan\DbError::isUnique($x);
        $this->assertTrue($u($e('23000', 1062, 'Duplicate entry')));
        $this->assertTrue($u($e('23000', 19, 'UNIQUE constraint failed: t.c')));
        $this->assertTrue($u($e('23505', 0, 'duplicate key')));
        $this->assertFalse($u($e('23000', 1452, 'Cannot add or update a child row')));
        $this->assertFalse($u($e('23000', 1048, "Column 'x' cannot be null")));
        $this->assertFalse($u($e('23000', 19, 'NOT NULL constraint failed: t.c')));
        $this->assertFalse($u($e('23000', 19, 'FOREIGN KEY constraint failed')));
    }
    public function test_removing_the_relation_revokes_portal_links(): void
    {
        $made = $this->makeLink(1, 1);
        $rel = DB::table('reg_student_guardians')->where('student_id', 1)->where('guardian_id', 1)->first();
        $this->as('admin')->deleteJson('/api/registry/student-guardians/'.$rel->id, ['version' => $rel->version])->assertOk();
        $this->assertNotNull(DB::table('gp_links')->where('id', $made['id'])->value('revoked_at'));
    }

    public function test_guardian_ack_is_not_audited_as_the_staff_user_of_the_same_browser(): void
    {
        $rec = $this->bhv(1);
        $made = $this->makeLink(1, 1);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post(parse_url($made['url'], PHP_URL_PATH))->assertRedirect('/guardian');
        $this->as('teacher'); // a teacher is signed in on the same browser
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'behavior', 'ref_id' => $rec])->assertOk();
        $this->assertSame('guardian:1', DB::table('bhv_records')->where('id', $rec)->value('guardian_ack_by'));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'behavior.record.acknowledged')->count());
        $this->assertSame(1, DB::table('gp_events')->where('action', 'guardian.acknowledged')->count());
    }

    // ---------- student-area review fixes ----------

    public function test_invite_requires_matching_password_confirmation_for_a_new_account(): void
    {
        $inv = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv['path'], ['email' => 'c@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'different-horse-9'])->assertSessionHasErrors('password');
        $this->post($inv['path'], ['email' => 'c@example.test', 'password' => 'correct-horse-9'])->assertSessionHasErrors('password');
        $this->assertSame(0, DB::table('gp_accounts')->count());
        $this->assertNull(DB::table('gp_links')->where('id', $inv['id'])->value('used_at')); // the one-time link is not burned by a typo
        $this->post($inv['path'], ['email' => 'c@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->assertSame(1, DB::table('gp_accounts')->count());
    }

    public function test_admin_invite_that_resets_an_existing_account_requires_password_confirmation(): void
    {
        $first = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($first['path'], ['email' => 'forgot@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $hash = DB::table('gp_accounts')->where('guardian_id', 1)->value('password');

        $reset = $this->makeLink(1, 1, 'invite', 'admin');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($reset['path'], ['email' => 'forgot@example.test', 'password' => 'brand-new-pass-9'])->assertSessionHasErrors('password');
        $this->post($reset['path'], ['email' => 'forgot@example.test', 'password' => 'brand-new-pass-9', 'password_confirmation' => 'typo-new-pass-9'])->assertSessionHasErrors('password');
        $this->assertSame($hash, DB::table('gp_accounts')->where('guardian_id', 1)->value('password'));
        $this->assertNull(DB::table('gp_links')->where('id', $reset['id'])->value('used_at')); // the typo did not burn the link
        $this->post($reset['path'], ['email' => 'forgot@example.test', 'password' => 'brand-new-pass-9', 'password_confirmation' => 'brand-new-pass-9'])->assertRedirect('/guardian');
        $this->assertNotSame($hash, DB::table('gp_accounts')->where('guardian_id', 1)->value('password'));
    }

    public function test_revoking_one_invite_cuts_every_invite_of_the_pair(): void
    {
        $first = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($first['path'], ['email' => 'pair@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        // a second invite for the same pair (issued by an admin) is used as well
        $second = $this->makeLink(1, 1, 'invite', 'admin');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($second['path'], ['email' => 'pair@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertJsonCount(1, 'data');
        // staff sees an active invite for the pair and revokes the newest row
        $page = $this->as('teacher')->getJson('/api/guardian-portal/classrooms/1/guardians')->assertOk()->json('students');
        $inviteState = collect($page)->firstWhere('student_id', 1)['guardians'][0]['invite_link'];
        $this->assertSame('used', $inviteState['status']);
        $row = DB::table('gp_links')->where('id', $second['id'])->first();
        $this->as('teacher')->deleteJson('/api/guardian-portal/links/'.$second['id'], ['version' => $row->version])->assertOk();
        $this->assertSame(0, DB::table('gp_links')->where('guardian_id', 1)->where('student_id', 1)->where('purpose', 'invite')->whereNull('revoked_at')->count());
        $this->app['auth']->forgetGuards();
        $this->post('/guardian/login', ['email' => 'pair@example.test', 'password' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_special_results_override_the_numeric_grade_like_the_official_forms(): void
    {
        $this->assertTrue(Schema::hasTable('rpt_special_results'));
        $subject = DB::table('asm_subjects')->insertGetId(['school_id' => 1, 'code' => 'S1', 'code_key' => 's1', 'name' => 'วิทยาศาสตร์ (ทดสอบ)', 'created_at' => now(), 'updated_at' => now()]);
        $subject2 = DB::table('asm_subjects')->insertGetId(['school_id' => 1, 'code' => 'S2', 'code_key' => 's2', 'name' => 'สังคม (ทดสอบ)', 'created_at' => now(), 'updated_at' => now()]);
        $mk = fn (int $subj) => DB::table('asm_courses')->insertGetId(['school_id' => 1, 'term_id' => 1, 'subject_id' => $subj, 'classroom_id' => 1,
            'policy' => json_encode(GradePolicy::defaults()), 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
        $withScores = $mk($subject);
        $onlySpecial = $mk($subject2);
        foreach ([['continuous', 4000], ['exam', 2200]] as [$k, $v]) {
            DB::table('asm_scores')->insert(['school_id' => 1, 'course_id' => $withScores, 'student_id' => 1, 'component_key' => $k, 'score_centi' => $v, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$withScores, 'มส'], [$onlySpecial, 'ร']] as [$c, $code]) {
            DB::table('rpt_special_results')->insert(['school_id' => 1, 'course_id' => $c, 'student_id' => 1, 'code' => $code, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->announce();
        $this->openAsGuardian($this->makeLink(1, 1)['url']);
        $courses = collect($this->getJson('/guardian/api/children/1/results')->assertOk()->json('terms.0.courses'))->keyBy('subject');
        $this->assertSame('มส', $courses['วิทยาศาสตร์ (ทดสอบ)']['grade']);
        $this->assertFalse($courses['วิทยาศาสตร์ (ทดสอบ)']['passed']);
        $this->assertNull($courses['วิทยาศาสตร์ (ทดสอบ)']['total']);
        $this->assertSame('ร', $courses['สังคม (ทดสอบ)']['grade']); // no score rows at all, still listed
    }

    public function test_term_result_acknowledgement_works_for_a_term_shown_only_through_special_results(): void
    {
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2560, 'term' => 1, 'starts_on' => '2017-05-15', 'ends_on' => '2017-10-31']);
        $subject = DB::table('asm_subjects')->insertGetId(['school_id' => 1, 'code' => 'S9', 'code_key' => 's9', 'name' => 'ภาษาไทย (ทดสอบ)', 'created_at' => now(), 'updated_at' => now()]);
        $course = DB::table('asm_courses')->insertGetId(['school_id' => 1, 'term_id' => 2, 'subject_id' => $subject, 'classroom_id' => 1,
            'policy' => json_encode(GradePolicy::defaults()), 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rpt_special_results')->insert(['school_id' => 1, 'course_id' => $course, 'student_id' => 1, 'code' => 'มส', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->announce(2);
        $this->openAsGuardian($this->makeLink(1, 1)['url']);
        $this->assertContains(2, array_column($this->getJson('/guardian/api/children/1/results')->assertOk()->json('terms'), 'term_id'));
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'term_result', 'ref_id' => 2])->assertOk()->assertJsonPath('already', false);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'term_result', 'ref_id' => 3])->assertNotFound();
    }

    // ---------------------------------------------------------------- audit fixes (2026-11-07)

    public function test_a_teacher_invite_on_an_existing_account_counts_wrong_passwords_and_respects_the_lock(): void
    {
        $inv = $this->makeLink(1, 1, 'invite');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv['path'], ['email' => 'g1@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $inv2 = $this->makeLink(2, 1, 'invite');
        foreach (range(1, 3) as $i) {
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            $this->post($inv2['path'], ['email' => 'g1@example.test', 'password' => 'wrong-password-'.$i, 'password_confirmation' => 'wrong-password-'.$i])->assertSessionHasErrors('password');
        }
        $this->assertSame(3, (int) DB::table('gp_accounts')->where('guardian_id', 1)->value('failed_count'), 'wrong guesses through the invite feed the same counter as a sign-in');
        $this->assertNull(DB::table('gp_links')->where('id', $inv2['id'])->value('used_at'));
        // a locked account is not opened by the invite either, even with the right password
        DB::table('gp_accounts')->where('guardian_id', 1)->update(['locked_until' => now()->addMinutes(30), 'failed_count' => 0]);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($inv2['path'], ['email' => 'g1@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertSessionHasErrors('password');
        $this->assertNull(DB::table('gp_links')->where('id', $inv2['id'])->value('used_at'));
        // the lock over, the right password attaches the child
        DB::table('gp_accounts')->where('guardian_id', 1)->update(['locked_until' => null]);
        $this->post($inv2['path'], ['email' => 'g1@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
    }
}