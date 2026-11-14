<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Edition;
use App\Support\Saan\Projection;
use App\Support\Saan\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\Assessment\GradePolicy;
use Saan\Modules\GuardianPortal\Mail\PortalLink;
use Saan\Modules\ReportsPp\Calc;
use Saan\Modules\ReportsPp\ReportsPp;
use Saan\Modules\StudentPortal\Http\StudentController;
use Tests\TestCase;

/**
 * F8: students and guardians see their own grades and timetable. Demo data only (seeded school, invented students DEMO-01 ... DEMO-24):
 * classroom 1 = students 1-8 (homeroom: the seeded teacher), classroom 2 = students 9-16, classroom 3 = students 17-24.
 */
class StudentPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        // the demo gate lets only 127.0.0.1 in; these tests use several client addresses (rate limits per network), so run as a normal install
        DB::table('users')->update(['is_demo' => false]);
        config(['school.demo' => false, 'school.local_only' => false]);
        $now = now();
        foreach ([[1, 'ผู้ปกครองทดสอบ ก'], [2, 'ผู้ปกครองทดสอบ ข'], [3, 'ผู้ปกครองทดสอบ ค']] as [$id, $name]) {
            DB::table('reg_guardians')->insert(['id' => $id, 'school_id' => 1, 'display_name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        // guardian 1: students 1 and 2 (same classroom); guardian 2: student 1 and student 9 (other classroom); guardian 3: student 9
        foreach ([[1, 1], [2, 1], [1, 2], [9, 2], [9, 3]] as [$student, $guardian]) {
            DB::table('reg_student_guardians')->insert(['school_id' => 1, 'student_id' => $student, 'guardian_id' => $guardian, 'relationship' => 'mother', 'can_pick_up' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function as(string $role): static
    {
        return $this->actingAs(User::where('role', $role)->first());
    }

    private function ip(string $ip = '127.0.0.1'): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function browser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function code(int $studentId): string
    {
        return (string) DB::table('students')->where('id', $studentId)->value('code');
    }

    /** Homeroom teacher issues a temporary secret; returns it (shown once). */
    private function issue(int $studentId, string $role = 'teacher'): string
    {
        return (string) $this->as($role)->postJson("/api/student-portal/students/$studentId/reset")->assertOk()->json('data.0.secret');
    }

    /** A fresh browser signs in; returns the response. */
    private function login(int $studentId, string $secret, string $ip = '127.0.0.1')
    {
        $this->browser();
        return $this->ip($ip)->post('/student/login', ['code' => $this->code($studentId), 'secret' => $secret]);
    }

    /** Student with a chosen (non temporary) secret, signed in. */
    private function signedIn(int $studentId = 1, string $secret = 'maple-river-42', string $role = 'teacher'): string
    {
        $tmp = $this->issue($studentId, $role);
        $this->login($studentId, $tmp)->assertRedirect('/student');
        $this->postJson('/student/api/secret', ['current' => $tmp, 'new' => $secret])->assertOk();
        return $secret;
    }

    private function announce(int $term = 1, ?string $at = null): void
    {
        Settings::put('student.announce.'.$term, ['at' => $at ?? now()->subMinute()->format('Y-m-d H:i:s')]);
    }

    /** @param array<int,array{0:int,1:int}> $scores student id => [continuous, exam] in centi-points */
    private function course(string $name, string $status, array $scores, int $term = 1, int $room = 1, string $credit = '1.0'): int
    {
        $code = 'T'.(DB::table('asm_subjects')->count() + 1);
        $subject = DB::table('asm_subjects')->insertGetId(['school_id' => 1, 'code' => $code, 'code_key' => strtolower($code), 'name' => $name, 'credit' => $credit, 'created_at' => now(), 'updated_at' => now()]);
        $course = DB::table('asm_courses')->insertGetId(['school_id' => 1, 'term_id' => $term, 'subject_id' => $subject, 'classroom_id' => $room,
            'policy' => json_encode(GradePolicy::defaults()), 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($scores as $sid => [$cont, $exam]) foreach ([['continuous', $cont], ['exam', $exam]] as [$k, $v]) {
            DB::table('asm_scores')->insert(['school_id' => 1, 'course_id' => $course, 'student_id' => $sid, 'component_key' => $k, 'score_centi' => $v, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $course;
    }

    /** Teacher issues a guardian account (username + temporary password) for guardian $g of student $s. */
    private function issueGuardian(int $s, int $g, string $role = 'teacher'): array
    {
        return $this->as($role)->postJson("/api/student-portal/students/$s/guardians/$g/issue")->assertOk()->json('data.0');
    }

    private function loginGuardian(string $name, string $secret, string $ip = '127.0.0.1')
    {
        $this->browser();
        return $this->ip($ip)->post('/guardian/login', ['email' => $name, 'password' => $secret]);
    }

    // ---------------------------------------------------------------- login

    public function test_staff_issue_then_student_signs_in_and_must_change_the_temporary_secret(): void
    {
        $res = $this->as('teacher')->postJson('/api/student-portal/classrooms/1/issue')->assertOk();
        $res->assertHeader('Cache-Control', 'no-store, private');
        $slips = $res->json('data');
        $this->assertCount(8, $slips);
        $this->assertSame('ป.4/1', $res->json('classroom.name'));
        foreach ($slips as $s) $this->assertMatchesRegularExpression('/^\d{6}$/', $s['secret']);
        // nothing in clear text: not in the table, not in the audit rows
        $dump = json_encode(DB::table('api_student_pins')->get()).json_encode(DB::table('audit_events')->get());
        foreach ($slips as $s) $this->assertStringNotContainsString($s['secret'], $dump);
        $this->assertSame(8, DB::table('audit_events')->where('action', 'student_portal.secret.issued')->count());
        // a second "only the missing ones" run issues nobody
        $this->as('teacher')->postJson('/api/student-portal/classrooms/1/issue')->assertOk()->assertJsonCount(0, 'data');

        $secret = $slips[0]['secret'];
        $before = session()->getId();
        $this->login(1, $secret)->assertRedirect('/student');
        $this->assertNotSame($before, session()->getId(), 'session id regenerated at sign-in');
        $this->getJson('/student/api/me')->assertOk()->assertJsonPath('must_change', true)->assertJsonPath('classroom', 'ป.4/1');
        // temporary secret: nothing else opens until it is changed
        foreach (['report', 'timetable', 'attendance', 'behavior'] as $part) $this->getJson("/student/api/$part")->assertStatus(403)->assertJsonPath('must_change', true);

        $this->postJson('/student/api/secret', ['current' => 'wrong-one', 'new' => 'maple-river-42'])->assertStatus(422)->assertJsonValidationErrors('current');
        $this->postJson('/student/api/secret', ['current' => $secret, 'new' => '111111'])->assertStatus(422)->assertJsonValidationErrors('new');
        $this->postJson('/student/api/secret', ['current' => $secret, 'new' => 'abc'])->assertStatus(422);
        $this->postJson('/student/api/secret', ['current' => $secret, 'new' => 'xx'.$this->code(1).'xx'])->assertStatus(422); // contains the student code
        $this->postJson('/student/api/secret', ['current' => $secret, 'new' => $secret])->assertStatus(422);
        $this->postJson('/student/api/secret', ['current' => $secret, 'new' => 'maple-river-42'])->assertOk();
        $this->getJson('/student/api/me')->assertOk()->assertJsonPath('must_change', false);
        $this->getJson('/student/api/report')->assertOk();
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('must_change'));
        // the old temporary secret is dead, the new one works from a fresh browser
        $this->login(1, $secret)->assertSessionHasErrors('code');
        $this->login(1, 'maple-river-42')->assertRedirect('/student');
        $this->getJson('/student/api/me')->assertOk()->assertJsonPath('must_change', false);
    }

    public function test_failed_sign_in_is_generic_and_does_not_reveal_whether_the_code_exists(): void
    {
        $this->issue(1);                                   // student 1 has a secret
        $this->issue(3);
        DB::table('api_student_pins')->where('student_id', 3)->update(['locked_until' => now()->addHour()]); // student 3 is locked
        $this->as('teacher');                              // student 2 has no secret at all
        $msgs = [];
        foreach ([[1, 'not-the-secret'], [2, 'whatever-123'], [3, 'whatever-123']] as [$sid, $secret]) {
            $this->login($sid, $secret)->assertSessionHasErrors('code');
            $msgs[] = session('errors')->first('code');
        }
        $this->browser();
        $this->post('/student/login', ['code' => 'NO-SUCH-CODE', 'secret' => 'whatever-123'])->assertSessionHasErrors('code');
        $msgs[] = session('errors')->first('code');
        $this->post('/student/login', ['code' => 'demo-17', 'secret' => 'whatever-123'])->assertSessionHasErrors('code'); // exists, no secret
        $msgs[] = session('errors')->first('code');
        $this->assertCount(1, array_unique($msgs));
        $this->assertSame(StudentController::LOGIN_FAILED, $msgs[0]);
        $this->getJson('/student/api/me')->assertUnauthorized();
        // a student who left school looks the same
        $s = $this->issue(4);
        DB::table('students')->where('id', 4)->update(['status' => 'transferred']);
        $this->login(4, $s)->assertSessionHasErrors('code');
        $this->assertSame(StudentController::LOGIN_FAILED, session('errors')->first('code'));
    }

    public function test_account_locks_after_repeated_failures_with_a_growing_delay_and_teacher_can_unlock(): void
    {
        $secret = $this->issue(1);
        // one network may add only 5 wrong tries per student, so the lock needs several networks (a single address cannot lock a student out)
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $n => $ip) {
            for ($i = 0; $i < ($n === 2 ? 3 : 5); $i++) $this->login(1, 'bad-guess-'.$i, $ip)->assertSessionHasErrors('code');
        }
        $row = DB::table('api_student_pins')->where('student_id', 1)->first();
        $this->assertNotNull($row->locked_until);
        $this->assertSame(1, (int) $row->lock_count);
        $this->assertEqualsWithDelta(30 * 60, strtotime($row->locked_until) - time(), 60);
        $this->login(1, $secret, '10.0.0.9')->assertSessionHasErrors('code'); // correct secret, still locked, same message
        $this->assertSame(StudentController::LOGIN_FAILED, session('errors')->first('code'));
        // lock expires on its own; the next lock is twice as long
        DB::table('api_student_pins')->where('student_id', 1)->update(['locked_until' => now()->subMinute()]);
        foreach (['10.0.1.1', '10.0.1.2', '10.0.1.3'] as $n => $ip) {
            for ($i = 0; $i < ($n === 2 ? 2 : 5); $i++) $this->login(1, 'bad-guess-'.$i, $ip);
        }
        $row = DB::table('api_student_pins')->where('student_id', 1)->first();
        $this->assertSame(2, (int) $row->lock_count);
        $this->assertEqualsWithDelta(60 * 60, strtotime($row->locked_until) - time(), 60);
        // homeroom teacher unlocks without changing the secret
        $this->as('teacher')->postJson('/api/student-portal/students/1/unlock')->assertOk();
        $this->assertNull(DB::table('api_student_pins')->where('student_id', 1)->value('locked_until'));
        $this->login(1, $secret, '10.0.2.1')->assertRedirect('/student');
        $this->assertDatabaseHas('audit_events', ['action' => 'student_portal.secret.unlocked']);
    }

    public function test_network_and_school_rate_limits_refuse_even_the_right_secret_without_touching_the_account(): void
    {
        $secret = $this->issue(1);
        // per network (IPv6 cut to /64)
        for ($i = 0; $i < StudentController::NET_FAILS; $i++) RateLimiter::hit('stu-login-net:2001:db8:1:2::/64', 600);
        $this->login(1, $secret, '2001:db8:1:2:aaaa::7')->assertSessionHasErrors('code');
        $this->assertStringContainsString('หลายครั้ง', session('errors')->first('code'));
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('failed_count'));
        $this->login(1, $secret, '2001:db8:1:3::1')->assertRedirect('/student'); // another /64 is unaffected
        // per school: once the school-wide failure budget is spent only networks that already failed repeatedly are refused; a clean network still signs in
        for ($i = 0; $i < StudentController::SCHOOL_FAILS; $i++) RateLimiter::hit('stu-login-school', 600);
        $this->login(1, $secret, '10.7.7.7')->assertRedirect('/student');
        // a hot network (already failing while the school budget is spent) is never refused up front: the right secret still gets in,
        // a wrong one is answered "too many" and no longer feeds the school counter (a flood cannot keep itself hot)
        for ($i = 0; $i < StudentController::HOT_NETWORK_MIN_FAILS; $i++) RateLimiter::hit('stu-login-net:10.7.7.8', 600);
        $this->browser();
        $this->login(1, $secret, '10.7.7.8')->assertRedirect('/student');
        $this->browser();
        $schoolBefore = RateLimiter::attempts('stu-login-school');
        $this->login(1, 'wrong-wrong-1', '10.7.7.8')->assertSessionHasErrors('code');
        $this->assertStringContainsString('หลายครั้ง', session('errors')->first('code'));
        $this->assertSame($schoolBefore, RateLimiter::attempts('stu-login-school'));
        // while another request of the same hot network holds the check slot, a parallel one is refused at once without a bcrypt
        $held = \Illuminate\Support\Facades\Cache::lock(sha1('stu-hot-slot:10.7.7.8'), 5);
        $this->assertTrue($held->get());
        $this->browser();
        $this->login(1, $secret, '10.7.7.8')->assertSessionHasErrors('code');
        $held->release();
        $this->browser();
        $this->login(1, $secret, '10.7.7.8')->assertRedirect('/student');
        // the route throttle is only a very high outer wall per address: 35 failures from one school address are not answered with 429
        $this->browser();
        foreach (range(0, 34) as $i) $this->ip('10.8.8.8')->post('/student/login', ['code' => 'X-'.$i, 'secret' => 'wrong-wrong'])->assertSessionHasErrors('code');
    }

    public function test_reset_by_staff_revokes_every_session_and_gives_a_new_temporary_secret(): void
    {
        $secret = $this->signedIn(1);
        $this->getJson('/student/api/report')->assertOk();
        $new = $this->issue(1); // reset while the student is signed in
        $this->assertNotSame($secret, $new);
        $this->getJson('/student/api/me')->assertUnauthorized();
        $this->getJson('/student/api/report')->assertUnauthorized();
        $this->login(1, $secret)->assertSessionHasErrors('code');
        $this->login(1, $new)->assertRedirect('/student');
        $this->getJson('/student/api/me')->assertOk()->assertJsonPath('must_change', true);
        $this->assertDatabaseHas('audit_events', ['action' => 'student_portal.secret.reset', 'entity' => 'api_student_pins']);
        $detail = DB::table('audit_events')->where('action', 'student_portal.secret.reset')->value('detail');
        $this->assertStringNotContainsString($new, (string) $detail);
        $this->assertStringNotContainsString($secret, (string) $detail);
        // learning-app tokens of the student are revoked too
        $this->assertSame(0, DB::table('api_tokens')->where('user_kind', 'student')->where('subject_id', 1)->whereNull('revoked_at')->count());
        // changing the secret ends the other devices but keeps this one
        $this->postJson('/student/api/secret', ['current' => $new, 'new' => 'quiet-forest-9'])->assertOk();
        $this->getJson('/student/api/me')->assertOk();
        $this->login(1, 'quiet-forest-9')->assertRedirect('/student');
    }

    public function test_school_sets_the_minimum_length_of_a_short_password(): void
    {
        $this->assertSame(6, \Saan\Modules\GuardianPortal\SecretPolicy::min());
        Settings::put('portal.password_min', 9);
        $tmp = $this->issue(1);
        $this->login(1, $tmp)->assertRedirect('/student');
        $this->postJson('/student/api/secret', ['current' => $tmp, 'new' => 'lake-77'])->assertStatus(422); // 7 characters < 9
        $this->postJson('/student/api/secret', ['current' => $tmp, 'new' => '482916'])->assertOk();         // a numeric PIN of 6 digits stays allowed
        $this->as('admin')->putJson('/api/guardian-portal/settings', ['password_min' => 7])->assertOk()->assertJsonPath('settings.password_min', 7);
        $this->assertSame(7, \Saan\Modules\GuardianPortal\SecretPolicy::min());
    }

    // ---------------------------------------------------------------- staff scope

    public function test_teacher_manages_only_the_own_homeroom_and_admin_manages_all(): void
    {
        // classroom 2 / student 9 belong to another homeroom teacher
        $this->as('teacher')->getJson('/api/student-portal/classrooms/2/accounts')->assertForbidden();
        $this->postJson('/api/student-portal/classrooms/2/issue')->assertForbidden();
        $this->postJson('/api/student-portal/students/9/reset')->assertForbidden();
        $this->postJson('/api/student-portal/students/9/unlock')->assertForbidden();
        $this->postJson('/api/student-portal/students/9/guardians/3/issue')->assertForbidden();
        $this->postJson('/api/student-portal/students/9999/reset')->assertNotFound();
        $this->getJson('/api/student-portal/classrooms/999/accounts')->assertNotFound();
        $this->assertSame(0, DB::table('api_student_pins')->count());
        $this->assertSame([1], array_column($this->getJson('/api/student-portal/context')->assertOk()->json('classrooms'), 'id'));
        // own homeroom works
        $this->getJson('/api/student-portal/classrooms/1/accounts')->assertOk()->assertJsonCount(8, 'data');
        // student ids in the body that are not in the classroom are ignored
        $this->postJson('/api/student-portal/classrooms/1/issue', ['student_ids' => [1, 9]])->assertOk()->assertJsonCount(1, 'data');
        // admin and principal: everywhere
        foreach (['admin', 'principal'] as $role) {
            $this->as($role)->getJson('/api/student-portal/classrooms/2/accounts')->assertOk()->assertJsonCount(8, 'data');
            $this->postJson('/api/student-portal/students/9/reset')->assertOk();
            $this->postJson('/api/student-portal/students/9/unlock')->assertOk();
        }
        $this->as('admin')->postJson('/api/student-portal/classrooms/3/issue')->assertOk()->assertJsonCount(8, 'data');
        $this->assertCount(3, $this->as('admin')->getJson('/api/student-portal/context')->json('classrooms'));
        // a role without staff rights is refused
        $teacher = User::where('role', 'teacher')->first();
        DB::table('users')->where('id', $teacher->id)->update(['role' => 'parent']);
        $this->actingAs(User::find($teacher->id))->getJson('/api/student-portal/context')->assertForbidden();
    }

    public function test_status_list_shows_secret_lock_temporary_and_verified_email(): void
    {
        $this->issue(1);
        $this->issue(2);
        $tmp = $this->issue(3);
        $this->login(3, $tmp);
        $this->postJson('/student/api/secret', ['current' => $tmp, 'new' => 'river-stone-81'])->assertOk();
        DB::table('api_student_pins')->where('student_id', 2)->update(['locked_until' => now()->addHour()]);
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'student', 'subject_id' => 3, 'email' => 'x@example.invalid', 'email_key' => 'x@example.invalid', 'verified_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $rows = collect($this->as('teacher')->getJson('/api/student-portal/classrooms/1/accounts')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[1]['has_secret'] && $rows[1]['temporary'] && !$rows[1]['locked'] && !$rows[1]['has_verified_email']);
        $this->assertTrue($rows[2]['locked']);
        $this->assertTrue($rows[3]['has_secret'] && !$rows[3]['temporary'] && $rows[3]['has_verified_email'] && $rows[3]['last_login_at'] !== null);
        $this->assertFalse($rows[4]['has_secret']);
        $this->assertSame(2, count($rows[1]['guardians'])); // guardians 1 and 2 of student 1
        $this->assertStringNotContainsString('x@example.invalid', json_encode($rows->all())); // staff never see the address
    }

    // ---------------------------------------------------------------- forgot password / e-mail

    private function resetUrl(array $sent): string
    {
        $n = $sent[0];
        $ref = new \ReflectionProperty($n, 'url');
        return (string) $ref->getValue($n);
    }

    public function test_forgot_password_answers_the_same_with_or_without_a_verified_email(): void
    {
        Notification::fake();
        $this->issue(1);
        $this->issue(2);
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'student', 'subject_id' => 1, 'email' => 'kid1@example.invalid', 'email_key' => 'kid1@example.invalid', 'verified_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'student', 'subject_id' => 2, 'email' => 'kid2@example.invalid', 'email_key' => 'kid2@example.invalid', 'verified_at' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->browser();
        $this->get('/student/forgot')->assertOk()->assertSee('แจ้งครูประจำชั้น');
        $out = [];
        foreach (['DEMO-01', 'DEMO-02', 'DEMO-17', 'NO-SUCH', 'demo-01'] as $i => $code) {
            RateLimiter::clear('portal-forgot-net:127.0.0.1');
            $r = $this->post('/student/forgot', ['code' => $code]);
            $out[] = [$r->getStatusCode(), $r->headers->get('Location'), session('status')];
            if ($i === 0) Notification::assertSentOnDemandTimes(PortalLink::class, 1);
        }
        $this->assertCount(1, array_unique(array_map('serialize', $out)), 'identical response for every code');
        $this->assertSame(\Saan\Modules\GuardianPortal\Contacts::GENERIC_RESET, $out[0][2]);
        // one mail only, to the verified address, once (the second request for the same student is throttled by the broker)
        Notification::assertSentOnDemandTimes(PortalLink::class, 1);
        Notification::assertSentOnDemand(PortalLink::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'kid1@example.invalid');
    }

    public function test_reset_link_expires_after_60_minutes_works_once_and_uses_the_configured_app_url(): void
    {
        Notification::fake();
        $old = $this->issue(1);
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'student', 'subject_id' => 1, 'email' => 'kid1@example.invalid', 'email_key' => 'kid1@example.invalid', 'verified_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->browser();
        $this->post('/student/forgot', ['code' => 'DEMO-01'])->assertRedirect('/student/forgot');
        $sent = [];
        Notification::assertSentOnDemand(PortalLink::class, function ($n) use (&$sent) { $sent[] = $n; return true; });
        $url = $this->resetUrl($sent);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/student/reset/', $url);
        $path = substr($url, strlen(rtrim(config('app.url'), '/')));
        $this->get($path)->assertOk()->assertSee('ตั้งรหัสผ่านใหม่');
        $token = basename(parse_url($url, PHP_URL_PATH));
        // weak secret, wrong token, wrong identity
        $this->post('/student/reset', ['token' => $token, 'i' => 1, 'password' => '123456', 'password_confirmation' => '123456'])->assertSessionHasErrors('password');
        $this->post('/student/reset', ['token' => str_repeat('a', 40), 'i' => 1, 'password' => 'new-secret-55', 'password_confirmation' => 'new-secret-55'])->assertSessionHasErrors('password');
        $this->post('/student/reset', ['token' => $token, 'i' => 2, 'password' => 'new-secret-55', 'password_confirmation' => 'new-secret-55'])->assertSessionHasErrors('password');
        // expired after 60 minutes
        $this->travel(61)->minutes();
        $this->post('/student/reset', ['token' => $token, 'i' => 1, 'password' => 'new-secret-55', 'password_confirmation' => 'new-secret-55'])->assertSessionHasErrors('password');
        $this->travelBack();
        // valid inside the hour, once
        $this->travel(59)->minutes();
        $this->post('/student/reset', ['token' => $token, 'i' => 1, 'password' => 'new-secret-55', 'password_confirmation' => 'new-secret-55'])->assertRedirect('/student');
        $this->post('/student/reset', ['token' => $token, 'i' => 1, 'password' => 'other-secret-66', 'password_confirmation' => 'other-secret-66'])->assertSessionHasErrors('password');
        $this->travelBack();
        $this->login(1, $old)->assertSessionHasErrors('code');
        $this->login(1, 'new-secret-55')->assertRedirect('/student');
        $this->getJson('/student/api/me')->assertOk()->assertJsonPath('must_change', false);
        $this->assertDatabaseHas('stu_events', ['action' => 'student.password.reset', 'student_id' => 1]);
    }

    public function test_student_adds_an_email_and_it_counts_only_after_verification(): void
    {
        Notification::fake();
        $this->signedIn(1);
        $this->postJson('/student/api/email', ['email' => 'not-an-email'])->assertStatus(422);
        $this->postJson('/student/api/email', ['email' => 'kid1@example.invalid'])->assertStatus(422)->assertJsonValidationErrors('current'); // the current secret is required
        $this->postJson('/student/api/email', ['email' => 'kid1@example.invalid', 'current' => 'not-my-secret'])->assertStatus(422)->assertJsonValidationErrors('current');
        $this->assertNull(DB::table('gp_contacts')->where('subject_id', 1)->first());
        $this->postJson('/student/api/email', ['email' => 'kid1@example.invalid', 'current' => 'maple-river-42'])->assertOk()->assertJsonPath('contact.verified', false);
        $sent = [];
        Notification::assertSentOnDemand(PortalLink::class, function ($n) use (&$sent) { $sent[] = $n; return true; });
        $url = $this->resetUrl($sent);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/student/verify/', $url);
        $this->assertFalse((bool) DB::table('gp_contacts')->where('subject_id', 1)->value('verified_at'));
        // unverified: forgot sends nothing
        $this->browser();
        Notification::fake();
        $this->post('/student/forgot', ['code' => 'DEMO-01']);
        Notification::assertNothingSent();
        // the link only opens a confirm page (a chat-app preview must not verify); the POST verifies
        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('ยืนยันอีเมล');
        $this->assertNull(DB::table('gp_contacts')->where('subject_id', 1)->value('verified_at'));
        $this->post($path)->assertOk()->assertSee('ยืนยันอีเมลแล้ว');
        $this->assertNotNull(DB::table('gp_contacts')->where('subject_id', 1)->value('verified_at'));
        $this->post($path)->assertNotFound(); // one time
        RateLimiter::clear('portal-forgot-net:127.0.0.1');
        $this->post('/student/forgot', ['code' => 'DEMO-01']);
        Notification::assertSentOnDemandTimes(PortalLink::class, 1);
    }

    // ---------------------------------------------------------------- results

    public function test_results_are_hidden_before_announcement_and_for_unclosed_subjects(): void
    {
        $this->course('คณิตศาสตร์ (ทดสอบ)', 'closed', [1 => [6000, 2500]]);
        $this->course('ภาษาไทย (ทดสอบ)', 'open', [1 => [6000, 2500]]);
        $this->signedIn(1);
        // not announced: nothing of the term
        $r = $this->getJson('/student/api/report')->assertOk()->json();
        $this->assertSame([], $r['terms']);
        $this->assertNull($r['gpax']);
        $this->assertStringNotContainsString('คณิตศาสตร์', json_encode($r, JSON_UNESCAPED_UNICODE));
        // scheduled for the future: still hidden; at the time: visible
        $this->announce(1, now()->addHour()->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->getJson('/student/api/report')->json('terms'));
        $this->travel(61)->minutes();
        $r = $this->getJson('/student/api/report')->assertOk()->json();
        $this->travelBack();
        $this->assertCount(1, $r['terms']);
        $this->assertSame(['คณิตศาสตร์ (ทดสอบ)'], array_column($r['terms'][0]['courses'], 'subject')); // the open course never shows
        $this->assertStringNotContainsString('ภาษาไทย', json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame('4', $r['terms'][0]['courses'][0]['grade']);
        $this->assertSame(1, $r['terms'][0]['courses'][0]['credit']);
        // the school takes the announcement back
        $this->as('admin')->putJson('/api/guardian-portal/terms/1/announce', ['mode' => 'clear'])->assertOk();
        $this->login(1, 'maple-river-42')->assertRedirect('/student');
        $this->assertSame([], $this->getJson('/student/api/report')->json('terms'));
        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_portal.results.announce']);
    }

    public function test_announcing_is_for_admin_and_principal_only_and_validates_the_time(): void
    {
        $this->as('teacher')->getJson('/api/guardian-portal/terms')->assertOk()->assertJsonPath('can_manage', false);
        $this->putJson('/api/guardian-portal/terms/1/announce', ['mode' => 'now'])->assertForbidden();
        $this->as('principal')->putJson('/api/guardian-portal/terms/1/announce', ['mode' => 'schedule', 'at' => now()->subDay()->format('Y-m-d\TH:i')])->assertStatus(422);
        $this->putJson('/api/guardian-portal/terms/1/announce', ['mode' => 'schedule'])->assertStatus(422);
        $this->putJson('/api/guardian-portal/terms/99/announce', ['mode' => 'now'])->assertNotFound();
        $this->putJson('/api/guardian-portal/terms/1/announce', ['mode' => 'schedule', 'at' => now()->addDay()->format('Y-m-d\TH:i')])->assertOk()->assertJsonPath('data.0.announced', false);
        $this->putJson('/api/guardian-portal/terms/1/announce', ['mode' => 'now'])->assertOk()->assertJsonPath('data.0.announced', true);
    }

    public function test_gpa_and_gpax_are_the_same_numbers_as_the_reports_pp_forms(): void
    {
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2568, 'term' => 2, 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);
        // term 1 (current): grades 4, 4, 3 with credits 1 each = 3.666 -> truncated 3.66 (rounded would be 3.67); a special result ร is not counted
        $this->course('คณิต ท1', 'closed', [1 => [6000, 2500]]);
        $this->course('ไทย ท1', 'closed', [1 => [6000, 2500]]);
        $this->course('วิทย์ ท1', 'closed', [1 => [5000, 2200]]);
        $special = $this->course('สังคม ท1', 'closed', [1 => [1000, 500]]);
        DB::table('rpt_special_results')->insert(['school_id' => 1, 'course_id' => $special, 'student_id' => 1, 'code' => 'ร', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // term 2 (past): the classroom of that term was classroom 1 as well
        $this->course('คณิต ท2', 'closed', [1 => [4000, 1800]], 2, 1, '2.0');
        $this->course('ไทย ท2', 'published', [1 => [6000, 2500]], 2, 1, '1.0');
        $this->announce(1);
        $this->announce(2);
        $this->signedIn(1);
        $r = $this->getJson('/student/api/report')->assertOk()->json();
        $this->assertCount(2, $r['terms']);

        // the printed ปพ.6 of the same data (as admin): term GPA and, from the same items, the cumulative GPAX
        $this->as('admin');
        $rp = new ReportsPp();
        $items = [];
        foreach ([1, 2] as $tid) {
            $term = DB::table('academic_terms')->find($tid);
            $page = $rp->pp6Student(1, $term)['pages'][0];
            $mine = collect($r['terms'])->firstWhere('term_id', $tid);
            $this->assertSame($page['gpa']['gpa_centi'] === null ? null : Calc::fmtCenti($page['gpa']['gpa_centi']), $mine['gpa'], "term $tid GPA");
            $this->assertEqualsWithDelta($page['gpa']['credits_tenths'] / 10, $mine['credits'], 0.001);
            foreach ($page['lines'] as $l) $items[] = ['credit' => $l['course']['credit_tenths'], 'grade' => $l['result']['state'] === 'graded' ? $l['result']['grade'] : null];
        }
        $expectedGpax = Calc::gpa($items, 'truncate');
        $this->assertSame(Calc::fmtCenti($expectedGpax['gpa_centi']), $r['gpax']['value']);
        $t1 = collect($r['terms'])->firstWhere('term_id', 1);
        $this->assertSame('3.66', $t1['gpa'], 'truncated, not rounded');
        $by = collect($t1['courses'])->keyBy('subject');
        $this->assertSame('ร', $by['สังคม ท1']['special']);
        $this->assertSame('ไม่สมบูรณ์', $by['สังคม ท1']['special_label']);
        $this->assertNull($by['สังคม ท1']['total']);
        $this->assertEqualsWithDelta(1.0, $by['วิทย์ ท1']['credit'], 0.001);

        // the rounding option of the school applies to students as well
        Settings::put('reports.gpa_rounding', 'round');
        $this->login(1, 'maple-river-42');
        $this->assertSame('3.67', collect($this->getJson('/student/api/report')->json('terms'))->firstWhere('term_id', 1)['gpa']);
    }

    public function test_class_rank_shows_only_when_the_school_enables_it(): void
    {
        $this->course('คณิต', 'closed', [1 => [6000, 2500], 2 => [5000, 2200], 3 => [3000, 1000]]);
        $this->announce();
        $this->signedIn(2);
        $r = $this->getJson('/student/api/report')->assertOk()->json();
        $this->assertFalse($r['show_rank']);
        $this->assertNull($r['terms'][0]['rank']);
        $this->assertStringNotContainsString('"rank":{', json_encode($r));
        Settings::put('reports.show_rank', true);
        $r = $this->getJson('/student/api/report')->json();
        $this->assertTrue($r['show_rank']);
        $this->assertSame(2, $r['terms'][0]['rank']['position']); // student 1 has 4.0, student 2 has 3.0 (72)
        $this->assertSame(3, $r['terms'][0]['rank']['of']);
        // the guardian of student 1 sees the same rule
        $acc = $this->issueGuardian(1, 1);
        $this->loginGuardian($acc['code'], $acc['secret'])->assertRedirect('/guardian');
        $this->postJson('/guardian/api/secret', ['current' => $acc['secret'], 'new' => 'guardian-pass-7'])->assertOk();
        $this->assertSame(1, $this->getJson('/guardian/api/children/1/report')->assertOk()->json('terms.0.rank.position'));
        Settings::put('reports.show_rank', false);
        $this->assertNull($this->getJson('/guardian/api/children/1/report')->json('terms.0.rank'));
    }

    public function test_characteristics_and_reading_assessments_come_with_the_announced_term(): void
    {
        $this->course('คณิต', 'closed', [1 => [6000, 2500]]);
        foreach ([['characteristic', 'C1', 3], ['reading', 'R1', 2], ['competency', 'K1', 1]] as [$kind, $item, $level]) {
            DB::table('asm_attributes')->insert(['school_id' => 1, 'term_id' => 1, 'course_id' => 0, 'student_id' => 1, 'kind' => $kind, 'item_code' => $item, 'level' => $level, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->signedIn(1);
        $this->assertSame([], $this->getJson('/student/api/report')->json('terms'));
        $this->announce();
        $attrs = collect($this->getJson('/student/api/report')->json('terms.0.attributes'));
        $this->assertSame(['C1', 'R1'], $attrs->pluck('code')->all());
        $this->assertSame('ดีเยี่ยม', $attrs[0]['level_label']);
    }

    public function test_pp6_is_read_only_and_only_when_the_school_enables_it(): void
    {
        $this->course('คณิต', 'closed', [1 => [6000, 2500]]);
        DB::table('rpt_remarks')->insert(['school_id' => 1, 'term_id' => 1, 'student_id' => 1, 'remark' => 'ตั้งใจเรียนดี (ทดสอบ)', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->announce();
        $this->signedIn(1);
        $r = $this->getJson('/student/api/report')->json();
        $this->assertFalse($r['pp6']);
        $this->assertArrayNotHasKey('pp6', $r['terms'][0]);
        $this->as('admin')->putJson('/api/guardian-portal/settings', ['show_pp6' => true])->assertOk()->assertJsonPath('settings.show_pp6', true);
        $this->login(1, 'maple-river-42');
        $r = $this->getJson('/student/api/report')->json();
        $this->assertTrue($r['pp6']);
        $this->assertSame('ตั้งใจเรียนดี (ทดสอบ)', $r['terms'][0]['pp6']['remark']);
        $this->postJson('/student/api/report', [])->assertStatus(405); // nothing writable
    }

    // ---------------------------------------------------------------- timetable, attendance, behaviour

    public function test_timetable_is_the_classroom_of_the_current_term_without_deleted_periods(): void
    {
        $live = DB::table('lessons')->where('school_id', 1)->where('term_id', 1)->where('classroom_id', 1)->count();
        $victim = DB::table('lessons')->where('classroom_id', 1)->orderBy('id')->first();
        DB::table('lessons')->where('id', $victim->id)->update(['subject' => 'วิชาที่ลบแล้ว (ทดสอบ)', 'deleted_at' => now()]);
        DB::table('lessons')->where('classroom_id', 2)->orderBy('id')->limit(1)->update(['subject' => 'วิชาห้องอื่น (ทดสอบ)']);
        $this->signedIn(1);
        $d = $this->getJson('/student/api/timetable')->assertOk()->json();
        $this->assertTrue($d['available']);
        $this->assertSame('ป.4/1', $d['classroom']);
        $this->assertSame($live - 1, array_sum(array_map(fn ($x) => count($x['lessons']), $d['days'])));
        $body = json_encode($d, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('วิชาที่ลบแล้ว', $body);
        $this->assertStringNotContainsString('วิชาห้องอื่น', $body);
        $this->assertStringNotContainsString('ครูอรุณ', $body); // no staff names
        $this->assertSame(['period', 'starts_at', 'ends_at', 'subject', 'room'], array_keys($d['days'][0]['lessons'][0])); // lessons.note (free teacher text) is never sent
        $this->assertSame('จันทร์', $d['days'][0]['name']);
        $this->assertCount(7, $d['periods']);
        // a student of another classroom gets that classroom's table
        $this->signedIn(9, 'maple-river-42', 'admin');
        $this->assertSame('ป.5/1', $this->getJson('/student/api/timetable')->json('classroom'));
    }

    public function test_attendance_and_behaviour_are_the_students_own_and_visible_ones_only(): void
    {
        DB::table('attendances')->where('student_id', 1)->update(['status' => 'late']);
        $today = now('Asia/Bangkok')->toDateString();
        foreach ([[1, 'ช่วยงานโรงเรียน (ทดสอบ)', true], [1, 'บันทึกภายในครู (ทดสอบ)', false], [2, 'ของเพื่อนคนอื่น (ทดสอบ)', true]] as [$sid, $note, $visible]) {
            DB::table('bhv_records')->insert(['school_id' => 1, 'term_id' => 1, 'student_id' => $sid, 'occurred_on' => $today, 'points' => 5, 'note' => $note, 'recorded_by' => 1, 'status' => 'active',
                'guardian_visible' => $visible, 'classroom_id' => 1, 'rule_label' => 'ช่วยงาน', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->signedIn(1);
        $b = json_encode($this->getJson('/student/api/behavior')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('ช่วยงาน', $b); // the rule label is shown
        $this->assertStringNotContainsString('ช่วยงานโรงเรียน', $b); // the teacher note of a visible record is not sent to the student
        $this->assertStringNotContainsString('family_acknowledged', $b);
        $this->assertStringNotContainsString('"note"', $b);
        $this->assertStringNotContainsString('บันทึกภายในครู', $b);
        $this->assertStringNotContainsString('ของเพื่อนคนอื่น', $b);
        $this->getJson('/student/api/attendance?month=2999-01')->assertStatus(422);
        $this->getJson('/student/api/attendance')->assertOk()->assertJsonPath('available', true);
    }

    // ---------------------------------------------------------------- IDOR, audit, public side

    public function test_a_student_cannot_read_another_student_on_any_endpoint(): void
    {
        $this->course('คณิตศาสตร์ (ทดสอบ)', 'closed', [1 => [6000, 2500], 2 => [3000, 1000]]);
        $this->course('วิชาเพื่อน (ทดสอบ)', 'closed', [2 => [6000, 2500]]);
        $this->announce();
        DB::table('bhv_records')->insert(['school_id' => 1, 'term_id' => 1, 'student_id' => 2, 'occurred_on' => now('Asia/Bangkok')->toDateString(), 'points' => 3, 'note' => 'MARKER-OF-STUDENT-2', 'recorded_by' => 1,
            'status' => 'active', 'guardian_visible' => true, 'classroom_id' => 1, 'rule_label' => 'x', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('students')->where('id', 2)->update(['name' => 'ชื่อเพื่อนลับ MARKER-NAME-2', 'first_name' => 'เพื่อนลับ', 'last_name' => 'MARKER-NAME-2']);
        $this->signedIn(1);
        $own = [];
        foreach (['me', 'report', 'timetable', 'attendance', 'behavior'] as $part) {
            $own[$part] = $this->getJson("/student/api/$part")->assertOk()->getContent();
            foreach (['student_id=2', 'id=2', 'student=2', 'sid=2', 'code='.$this->code(2)] as $qs) {
                $this->getJson("/student/api/$part?$qs")->assertOk()->assertContent($own[$part]); // a foreign id changes nothing
            }
            $this->getJson("/student/api/$part/2")->assertNotFound();
            $this->getJson("/student/api/students/2/$part")->assertNotFound();
        }
        $all = implode('', $own);
        foreach (['MARKER-OF-STUDENT-2', 'MARKER-NAME-2', 'วิชาเพื่อน'] as $m) $this->assertStringNotContainsString($m, $all);
        $this->assertStringContainsString('"name":"', $own['me']);
        // writes: only the own secret and e-mail, and a foreign id in the body is not read
        $this->postJson('/student/api/secret', ['student_id' => 2, 'current' => 'nope-nope', 'new' => 'brand-new-77'])->assertStatus(422);
        $this->assertSame(0, DB::table('api_student_pins')->where('student_id', 2)->count());
        // not signed in at all
        $this->browser();
        foreach (['me', 'report', 'timetable', 'attendance', 'behavior'] as $part) $this->getJson("/student/api/$part")->assertUnauthorized()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->postJson('/student/api/secret', ['current' => 'a', 'new' => 'b'])->assertUnauthorized();
        $this->postJson('/student/api/email', ['email' => 'a@example.invalid'])->assertUnauthorized();
        // a student session is not a staff session
        $this->getJson('/api/student-portal/context')->assertUnauthorized();
    }

    public function test_views_and_account_events_are_audited_without_secrets(): void
    {
        $this->course('คณิต', 'closed', [1 => [6000, 2500]]);
        $this->announce();
        $secret = $this->signedIn(1);
        for ($i = 0; $i < 4; $i++) $this->getJson('/student/api/report')->assertOk();
        $this->assertSame(1, DB::table('stu_events')->where('action', 'student.results.viewed')->count(), 'rate-limited: one row per 10 minutes');
        $this->travel(11)->minutes();
        $this->getJson('/student/api/report')->assertOk();
        $this->travelBack();
        $this->assertSame(2, DB::table('stu_events')->where('action', 'student.results.viewed')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('stu_events')->where('action', 'student.login')->where('student_id', 1)->count());
        $this->assertSame(1, DB::table('stu_events')->where('action', 'student.password.changed')->count());
        $this->login(1, 'wrong-wrong-1');
        $this->assertGreaterThanOrEqual(1, DB::table('stu_events')->where('action', 'student.login.failed')->count());
        $dump = json_encode(DB::table('stu_events')->get());
        $this->assertStringNotContainsString($secret, $dump);
        $this->assertStringNotContainsString('wrong-wrong-1', $dump);
        $this->assertStringNotContainsString('127.0.0.1', $dump, 'only a keyed hash of the address');
    }

    public function test_nothing_of_this_reaches_the_public_side(): void
    {
        $this->assertFalse(Edition::servesIn('public', 'student'));
        $this->assertTrue(Edition::servesIn('internal', 'student'));
        foreach (['/student', '/student/api/me', '/student/forgot', '/student/reset/abc', '/api/student-portal/classrooms/1/accounts'] as $p) $this->assertTrue(Edition::blockedInPublic($p), $p);
        foreach (['stu_events', 'gp_contacts', 'api_student_pins', 'gp_accounts'] as $t) $this->assertTrue(Projection::isForbiddenTable($t), $t);
        $declared = array_column(Projection::declared(true), 'table');
        foreach (['stu_events', 'gp_contacts', 'api_student_pins', 'gp_accounts', 'password_reset_tokens'] as $t) $this->assertNotContains($t, $declared, $t);
        $module = app(\App\Support\Modules\ModuleRegistry::class)->get('student-portal');
        $this->assertSame('internal', $module['edition']);
        $this->assertTrue($module['active']);
        config(['saan.edition' => 'public']);
        $this->get('/student')->assertNotFound();
        $this->post('/student/login', ['code' => 'DEMO-01', 'secret' => 'x'])->assertNotFound();
    }

    // ---------------------------------------------------------------- guardians

    public function test_guardian_without_email_gets_a_username_and_temporary_password_from_the_homeroom_teacher(): void
    {
        $slip = $this->issueGuardian(1, 1);
        $this->assertMatchesRegularExpression('/^g\d{6}$/', $slip['code']);
        $this->assertSame(8, strlen($slip['secret']));
        $acc = DB::table('gp_accounts')->where('guardian_id', 1)->first();
        $this->assertNull($acc->email);
        $this->assertSame(1, (int) $acc->must_change);
        $this->assertStringNotContainsString($slip['secret'], json_encode(DB::table('audit_events')->get()).json_encode($acc));
        $this->assertDatabaseHas('audit_events', ['action' => 'student_portal.guardian.issued']);

        // generic failures: unknown username, wrong password, e-mail style name
        $msgs = [];
        foreach ([['g000000', 'whatever-1'], [$slip['code'], 'wrong-pass-1'], ['nobody@example.invalid', 'whatever-1']] as [$u, $p]) {
            $this->loginGuardian($u, $p)->assertSessionHasErrors('email');
            $msgs[] = session('errors')->first('email');
        }
        $this->assertCount(1, array_unique($msgs));
        $this->loginGuardian(strtoupper($slip['code']), $slip['secret'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/me')->assertOk()->assertJsonPath('must_change', true)->assertJsonPath('username', $slip['code']);
        $this->getJson('/guardian/api/children')->assertStatus(403)->assertJsonPath('must_change', true);
        $this->postJson('/guardian/api/secret', ['current' => $slip['secret'], 'new' => '111111'])->assertStatus(422);
        $this->postJson('/guardian/api/secret', ['current' => 'wrong', 'new' => 'guardian-pass-7'])->assertStatus(422);
        $this->postJson('/guardian/api/secret', ['current' => $slip['secret'], 'new' => 'guardian-pass-7'])->assertOk();
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1); // only the child the teacher issued it for

        // reset by the teacher ends the session and demands a new change
        $new = $this->issueGuardian(1, 1);
        $this->assertSame($slip['code'], $new['code']);
        $this->getJson('/guardian/api/children')->assertUnauthorized();
        $this->loginGuardian($slip['code'], 'guardian-pass-7')->assertSessionHasErrors('email');
        $this->loginGuardian($slip['code'], $new['secret'])->assertRedirect('/guardian');
        $this->assertSame(1, (int) DB::table('gp_accounts')->where('guardian_id', 1)->value('must_change'));
    }

    public function test_guardian_sees_only_linked_children_and_the_same_announced_set(): void
    {
        $this->course('คณิต (ทดสอบ)', 'closed', [1 => [6000, 2500], 2 => [5000, 2200], 9 => [6000, 2500]]);
        $this->announce();
        // guardian 1 is linked to students 1 and 2 in the registry, but the teacher vouched only for student 1
        $acc = $this->issueGuardian(1, 1);
        $this->loginGuardian($acc['code'], $acc['secret'])->assertRedirect('/guardian');
        $this->postJson('/guardian/api/secret', ['current' => $acc['secret'], 'new' => 'guardian-pass-7'])->assertOk();
        $this->getJson('/guardian/api/children')->assertJsonCount(1, 'data');
        foreach (['report', 'timetable', 'results', 'attendance', 'behavior'] as $part) {
            $this->getJson("/guardian/api/children/1/$part")->assertOk();
            $this->getJson("/guardian/api/children/2/$part")->assertNotFound();
            $this->getJson("/guardian/api/children/9/$part")->assertNotFound();
            $this->getJson("/guardian/api/children/999/$part")->assertNotFound();
        }
        $r = $this->getJson('/guardian/api/children/1/report')->assertOk()->json();
        $this->assertSame(['คณิต (ทดสอบ)'], array_column($r['terms'][0]['courses'], 'subject'));
        $this->assertSame('4.00', $r['terms'][0]['gpa']);
        $this->assertSame('4.00', $r['gpax']['value']);
        $this->assertFalse($r['terms'][0]['acknowledged']);
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'term_result', 'ref_id' => 1])->assertOk();
        $this->assertTrue($this->getJson('/guardian/api/children/1/report')->json('terms.0.acknowledged'));
        $this->assertGreaterThan(0, count($this->getJson('/guardian/api/children/1/timetable')->assertOk()->json('days')));
        // the same gate for guardians: an announcement taken back hides results and the acknowledgement
        Settings::put('student.announce.1', null);
        $this->assertSame([], $this->getJson('/guardian/api/children/1/report')->json('terms'));
        $this->assertSame([], $this->getJson('/guardian/api/children/1/results')->json('terms'));
        $this->postJson('/guardian/api/children/1/ack', ['kind' => 'term_result', 'ref_id' => 1])->assertNotFound();
        $this->assertSame(1, DB::table('gp_events')->where('action', 'guardian.results.viewed')->where('guardian_id', 1)->where('student_id', 1)->count() > 0 ? 1 : 0);
        // student 9 is in another classroom: the teacher of classroom 1 cannot issue for the guardian of 9
        $this->as('teacher')->postJson('/api/student-portal/students/9/guardians/3/issue')->assertForbidden();
    }

    public function test_teacher_cannot_take_over_a_guardian_account_that_sees_another_classroom(): void
    {
        // guardian 2: student 1 (classroom 1) and student 9 (classroom 2). The admin attached both children.
        $first = $this->issueGuardian(9, 2, 'admin');
        $this->issueGuardian(1, 2, 'admin');
        // the classroom-1 teacher may not reset it (it would open the child of classroom 2) and may not unlock it either
        $this->as('teacher')->postJson('/api/student-portal/students/1/guardians/2/issue')->assertForbidden();
        $this->assertSame(1, (int) DB::table('gp_accounts')->where('guardian_id', 2)->value('must_change'));
        $this->loginGuardian($first['code'], $first['secret']);
        $again = $this->issueGuardian(1, 2, 'admin'); // admin may
        $this->assertSame($first['code'], $again['code']);
        // a guardian who also appears in the registry for a restricted pickup relation is refused when the school does not allow it
        DB::table('reg_student_guardians')->where('student_id', 2)->where('guardian_id', 1)->update(['can_pick_up' => false]);
        $this->as('teacher')->postJson('/api/student-portal/students/2/guardians/1/issue')->assertStatus(422);
        // not linked in the registry
        $this->postJson('/api/student-portal/students/3/guardians/1/issue')->assertNotFound();
    }

    public function test_teacher_cannot_unlock_or_disable_a_guardian_account_that_also_sees_another_classroom(): void
    {
        // guardian 2 looks after student 1 (classroom 1, the teacher's) and student 9 (classroom 2)
        $this->issueGuardian(9, 2, 'admin');
        $this->issueGuardian(1, 2, 'admin');
        DB::table('gp_accounts')->where('guardian_id', 2)->update(['failed_count' => 4, 'locked_until' => now()->addHour()]);
        $acc = DB::table('gp_accounts')->where('guardian_id', 2)->first();
        $this->as('teacher')->postJson('/api/student-portal/guardian-accounts/2/unlock')->assertForbidden();
        $this->assertNotNull(DB::table('gp_accounts')->where('guardian_id', 2)->value('locked_until'));
        $this->as('teacher')->putJson('/api/guardian-portal/accounts/2', ['version' => (int) $acc->version, 'active' => false])->assertForbidden();
        $this->assertTrue((bool) DB::table('gp_accounts')->where('guardian_id', 2)->value('is_active'));
        // the admin still may
        $this->as('admin')->postJson('/api/student-portal/guardian-accounts/2/unlock')->assertOk();
        $this->assertNull(DB::table('gp_accounts')->where('guardian_id', 2)->value('locked_until'));
    }

    public function test_guardian_account_locks_with_growing_delay_and_teacher_can_unlock(): void
    {
        $slip = $this->issueGuardian(1, 1);
        foreach (['10.1.0.1', '10.1.0.2', '10.1.0.3'] as $n => $ip) {
            for ($i = 0; $i < ($n === 2 ? 3 : 5); $i++) $this->loginGuardian($slip['code'], 'bad-guess-'.$i.$n, $ip)->assertSessionHasErrors('email');
        }
        $acc = DB::table('gp_accounts')->where('guardian_id', 1)->first();
        $this->assertNotNull($acc->locked_until);
        $this->assertSame(1, (int) $acc->lock_count);
        $this->assertEqualsWithDelta(30 * 60, strtotime($acc->locked_until) - time(), 60);
        $this->loginGuardian($slip['code'], $slip['secret'], '10.1.0.9')->assertSessionHasErrors('email'); // right password, still locked
        $this->as('teacher')->postJson('/api/student-portal/guardian-accounts/1/unlock')->assertOk();
        $this->loginGuardian($slip['code'], $slip['secret'], '10.1.0.9')->assertRedirect('/guardian');
        // a teacher cannot unlock a guardian of another homeroom
        $this->as('teacher')->postJson('/api/student-portal/guardian-accounts/3/unlock')->assertNotFound(); // no account
        $this->issueGuardian(9, 3, 'admin');
        $this->as('teacher')->postJson('/api/student-portal/guardian-accounts/3/unlock')->assertForbidden();
        // network and school limits answer even before the account is looked at
        for ($i = 0; $i < 300; $i++) RateLimiter::hit('gp-login-net:10.5.5.5', 600);
        $this->loginGuardian($slip['code'], $slip['secret'], '10.5.5.5')->assertSessionHasErrors('email');
        $this->assertStringContainsString('หลายครั้ง', session('errors')->first('email'));
    }

    public function test_guardian_forgot_password_is_identical_for_every_name_and_needs_a_verified_email(): void
    {
        Notification::fake();
        $slip = $this->issueGuardian(1, 1);
        $this->loginGuardian($slip['code'], $slip['secret']);
        $this->postJson('/guardian/api/secret', ['current' => $slip['secret'], 'new' => 'guardian-pass-7'])->assertOk();
        $this->postJson('/guardian/api/email', ['email' => 'mum@example.invalid'])->assertStatus(422)->assertJsonValidationErrors('current');
        $this->postJson('/guardian/api/email', ['email' => 'mum@example.invalid', 'current' => 'wrong-password-1'])->assertStatus(422)->assertJsonValidationErrors('current');
        $this->postJson('/guardian/api/email', ['email' => 'mum@example.invalid', 'current' => 'guardian-pass-7'])->assertOk()->assertJsonPath('contact.verified', false);
        $sent = [];
        Notification::assertSentOnDemand(PortalLink::class, function ($n) use (&$sent) { $sent[] = $n; return true; });
        $verify = parse_url($this->resetUrl($sent), PHP_URL_PATH);
        $this->browser();
        $out = [];
        foreach ([$slip['code'], 'g999999', 'mum@example.invalid'] as $name) {
            RateLimiter::clear('portal-forgot-net:127.0.0.1');
            $r = $this->post('/guardian/forgot', ['login' => $name]);
            $out[] = [$r->getStatusCode(), $r->headers->get('Location'), session('status')];
        }
        $this->assertCount(1, array_unique(array_map('serialize', $out)));
        $this->get('/guardian/forgot')->assertOk()->assertSee('แจ้งครูประจำชั้น');
        Notification::assertSentOnDemandTimes(PortalLink::class, 1); // only the verification mail so far
        $this->post($verify)->assertOk()->assertSee('ยืนยันอีเมลแล้ว');
        Notification::fake();
        RateLimiter::clear('portal-forgot-net:127.0.0.1');
        $this->post('/guardian/forgot', ['login' => $slip['code']])->assertRedirect('/guardian/forgot');
        Notification::assertSentOnDemandTimes(PortalLink::class, 1);
        $sent = [];
        Notification::assertSentOnDemand(PortalLink::class, function ($n) use (&$sent) { $sent[] = $n; return true; });
        $url = $this->resetUrl($sent);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/guardian/reset/', $url);
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->post('/guardian/reset', ['token' => $token, 'i' => 1, 'password' => 'mum-new-secret-3', 'password_confirmation' => 'mum-new-secret-3'])->assertRedirect('/guardian');
        $this->loginGuardian($slip['code'], 'mum-new-secret-3')->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertOk(); // not temporary any more
        $this->post('/guardian/reset', ['token' => $token, 'i' => 1, 'password' => 'again-new-secret-3', 'password_confirmation' => 'again-new-secret-3'])->assertSessionHasErrors('password');
    }

    public function test_existing_invite_links_and_email_login_still_work(): void
    {
        $inv = $this->as('teacher')->postJson('/api/guardian-portal/links', ['student_id' => 1, 'guardian_id' => 1, 'purpose' => 'invite', 'days' => 14])->assertCreated()->json();
        $this->browser();
        $path = parse_url($inv['url'], PHP_URL_PATH);
        $this->get($path)->assertOk();
        $this->post($path, ['email' => 'parent@example.test', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(1, 'data');
        $this->loginGuardian('PARENT@example.test', 'correct-horse-9')->assertRedirect('/guardian');
        $this->getJson('/guardian/api/me')->assertOk()->assertJsonPath('must_change', false);
    }

    // ---------------------------------------------------------------- review fixes (limits, timing, e-mail binding, scope, backfill)

    private function countingHasher(): object
    {
        return new class(app('hash')) implements \Illuminate\Contracts\Hashing\Hasher {
            public int $n = 0;
            public function __construct(private $real) {}
            public function info($hashedValue) { return $this->real->info($hashedValue); }
            public function make($value, array $options = []) { $this->n++; return $this->real->make($value, $options); }
            public function check($value, $hashedValue, array $options = []) { $this->n++; return $this->real->check($value, $hashedValue, $options); }
            public function needsRehash($hashedValue, array $options = []) { return $this->real->needsRehash($hashedValue, $options); }
            public function __call($m, $a) { return $this->real->$m(...$a); }
        };
    }

    public function test_a_class_behind_one_school_address_shares_neither_the_sign_in_nor_the_api_budget(): void
    {
        $s1 = $this->signedIn(1);
        // the API budget belongs to the signed-in student: 120 reads, then 429 for that student only, from the same address
        $ok = 0;
        while ($ok < 200 && $this->ip('10.20.0.1')->getJson('/student/api/me')->getStatusCode() === 200) $ok++;
        $this->assertGreaterThan(100, $ok);
        $this->assertLessThanOrEqual(120, $ok);
        $this->ip('10.20.0.1')->getJson('/student/api/me')->assertStatus(429);
        $s2 = $this->signedIn(2);
        $this->ip('10.20.0.1')->getJson('/student/api/me')->assertOk();
        // typos of one student (40+ wrong tries from the school address) do not close the sign-in for the others
        foreach (range(0, 44) as $i) $this->login(3, 'typo-'.$i, '10.20.0.1');
        $this->login(2, $s2, '10.20.0.1')->assertRedirect('/student');
        // a flood from other networks fills the school budget: a network with a clean record still gets in, a hot one does not
        for ($i = 0; $i < StudentController::SCHOOL_FAILS; $i++) RateLimiter::hit('stu-login-school', 600);
        $this->login(2, $s2, '10.20.0.77')->assertRedirect('/student');
        // guardians: the same rule
        $slip = $this->issueGuardian(1, 1);
        for ($i = 0; $i < 400; $i++) RateLimiter::hit('gp-login-school', 600);
        $this->loginGuardian($slip['code'], $slip['secret'], '10.20.0.88')->assertRedirect('/guardian');
        // a hot guardian network: a wrong password is "too many" and does not feed the school counter, the right one still gets in
        for ($i = 0; $i < 3; $i++) RateLimiter::hit('gp-login-net:10.20.0.99', 600);
        $before = RateLimiter::attempts('gp-login-school');
        $this->browser();
        $this->loginGuardian($slip['code'], 'not-the-password-1', '10.20.0.99')->assertSessionHasErrors('email');
        $this->assertStringContainsString('หลายครั้ง', session('errors')->first('email'));
        $this->assertSame($before, RateLimiter::attempts('gp-login-school'));
        $this->browser();
        $this->loginGuardian($slip['code'], $slip['secret'], '10.20.0.99')->assertRedirect('/guardian');
    }

    public function test_a_locked_or_capped_pin_costs_the_same_for_the_right_and_a_wrong_secret_with_two_key_candidates(): void
    {
        config(['saan.hash_key' => 'dedicated-hash-key-for-this-test']); // candidates: the dedicated key and the app key
        $this->assertCount(2, \App\Support\Saan\HashKey::candidates());
        $secret = $this->issue(1);
        $code = $this->code(1);
        $counter = $this->countingHasher();
        \Illuminate\Support\Facades\Hash::swap($counter);
        $cost = function (string $s, ?string $net = null) use ($counter, $code) { $counter->n = 0; $r = \Saan\Modules\ApiGateway\Pins::attempt($code, $s, $net, true); return [$r, $counter->n]; };
        // locked: neither answer signs in, and both do the same work (else the lock would stop nothing: the time would tell the right PIN)
        DB::table('api_student_pins')->where('student_id', 1)->update(['locked_until' => now()->addHour()]);
        [$okR, $right] = $cost($secret);
        [$okW, $wrong] = $cost('not-the-secret-1');
        $this->assertNull($okR); $this->assertNull($okW);
        $this->assertSame($wrong, $right, 'locked: right '.$right.' vs wrong '.$wrong);
        $this->assertSame(2, $right, 'one bcrypt per key candidate');
        // network cap: the same rule
        DB::table('api_student_pins')->where('student_id', 1)->update(['locked_until' => null, 'failed_count' => 0]);
        $hash = (string) DB::table('api_student_pins')->where('student_id', 1)->value('pin_hash');
        $netKey = 'pin-net:1:'.sha1('net-x|'.$hash);
        for ($i = 0; $i < \Saan\Modules\ApiGateway\Pins::MAX_PER_NETWORK; $i++) RateLimiter::hit($netKey, 600);
        [$capR, $right] = $cost($secret, 'net-x');
        [, $wrong] = $cost('not-the-secret-1', 'net-x');
        $this->assertNull($capR);
        $this->assertSame($wrong, $right, 'capped: right '.$right.' vs wrong '.$wrong);
    }

    public function test_sign_in_and_forgot_do_the_same_work_for_unknown_and_known_codes_and_send_no_mail_inside_the_request(): void
    {
        $counter = $this->countingHasher();
        \Illuminate\Support\Facades\Hash::swap($counter); // before the password broker is built, so its token hash is counted too
        Notification::fake();
        $this->issue(1);
        $this->issue(2);
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'student', 'subject_id' => 1, 'email' => 'kid1@example.invalid', 'email_key' => 'kid1@example.invalid', 'verified_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'student', 'subject_id' => 2, 'email' => 'kid2@example.invalid', 'email_key' => 'kid2@example.invalid', 'verified_at' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // no warm-up: under PHP-FPM every request starts cold, so the very first request of each kind must already do the same work
        // sign-in: unknown code, known code without a secret, known code with the wrong secret
        $work = [];
        foreach ([['NO-SUCH-CODE', 'whatever-123'], ['demo-17', 'whatever-123'], [$this->code(1), 'whatever-123'], [$this->code(2), 'whatever-123']] as [$code, $secret]) {
            $this->browser();
            $counter->n = 0;
            $this->post('/student/login', ['code' => $code, 'secret' => $secret])->assertSessionHasErrors('code');
            $work[] = $counter->n;
        }
        $this->assertGreaterThan(0, $work[0]);
        $this->assertCount(1, array_unique($work), 'sign-in: '.json_encode($work));
        // forgot: unknown code, code without e-mail, unverified e-mail, verified e-mail (the one that really creates a token)
        $during = null;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function () use (&$during) {
            try { Notification::assertSentOnDemandTimes(PortalLink::class, 0); $during = true; } catch (\Throwable) { $during = false; }
        });
        $work = [];
        foreach (['DEMO-01', 'NO-SUCH', 'DEMO-17', 'DEMO-02'] as $i => $code) {
            RateLimiter::clear('portal-forgot-net:127.0.0.1');
            $this->browser();
            $counter->n = 0;
            $this->post('/student/forgot', ['code' => $code])->assertRedirect('/student/forgot');
            $work[] = $counter->n;
            if ($i === 0) {
                $this->assertTrue($during, 'the mail is not sent before the response is built');
                Notification::assertSentOnDemandTimes(PortalLink::class, 1); // ... but it is sent
            }
        }
        $this->assertGreaterThan(0, $work[0]);
        $this->assertCount(1, array_unique($work), 'forgot: '.json_encode($work));
        Notification::assertSentOnDemandTimes(PortalLink::class, 1);
        // guardians: unknown name, username without e-mail, username with a verified e-mail
        $slip = $this->issueGuardian(1, 1);
        $other = $this->issueGuardian(9, 3, 'admin');
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'guardian', 'subject_id' => 3, 'email' => 'g3@example.invalid', 'email_key' => 'g3@example.invalid', 'verified_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Hash::swap($counter);
        $work = [];
        foreach ([$other['code'], 'g999999', $slip['code']] as $name) {
            RateLimiter::clear('portal-forgot-net:127.0.0.1');
            $this->browser();
            $counter->n = 0;
            $this->post('/guardian/forgot', ['login' => $name])->assertRedirect('/guardian/forgot');
            $work[] = $counter->n;
        }
        $this->assertGreaterThan(0, $work[0]);
        $this->assertCount(1, array_unique($work), 'guardian forgot: '.json_encode($work));
    }

    public function test_a_pin_reset_from_the_learning_app_page_also_unbinds_the_email(): void
    {
        $this->issue(1);
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'student', 'subject_id' => 1, 'email' => 'kid1@example.invalid', 'email_key' => 'kid1@example.invalid', 'verified_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->as('admin')->postJson('/api/api-gateway/pins/students/1/reset')->assertOk();
        $this->assertNull(DB::table('gp_contacts')->where('kind', 'student')->where('subject_id', 1)->value('verified_at'));
        $this->assertSame(1, (int) DB::table('api_student_pins')->where('student_id', 1)->value('must_change'));
    }

    public function test_a_verified_email_needs_the_current_secret_and_a_staff_reset_unbinds_it(): void
    {
        Notification::fake();
        $secret = $this->signedIn(1);
        $this->postJson('/student/api/email', ['email' => 'kid1@example.invalid', 'current' => $secret])->assertOk();
        DB::table('gp_contacts')->where('kind', 'student')->where('subject_id', 1)->update(['verified_at' => now()]);
        // somebody at the open session replaces the address: the OLD address is warned, the account is unverified until the new one is confirmed
        $this->postJson('/student/api/email', ['email' => 'thief@example.invalid', 'current' => 'guess-guess-1'])->assertStatus(422);
        $this->assertSame('kid1@example.invalid', DB::table('gp_contacts')->where('subject_id', 1)->value('email'));
        Notification::fake();
        $this->postJson('/student/api/email', ['email' => 'thief@example.invalid', 'current' => $secret])->assertOk();
        Notification::assertSentOnDemand(PortalLink::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'kid1@example.invalid');
        Notification::assertSentOnDemand(PortalLink::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'thief@example.invalid');
        // the thief confirms; the teacher resets the account: the e-mail is unverified again and forgot-password sends nothing
        DB::table('gp_contacts')->where('kind', 'student')->where('subject_id', 1)->update(['verified_at' => now()]);
        $this->browser();
        RateLimiter::clear('portal-forgot-net:127.0.0.1');
        $this->post('/student/forgot', ['code' => 'DEMO-01']);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'student-1@portal.invalid')->count());
        $this->issue(1);
        $this->assertNull(DB::table('gp_contacts')->where('kind', 'student')->where('subject_id', 1)->value('verified_at'));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'student-1@portal.invalid')->count());
        Notification::fake();
        $this->browser();
        RateLimiter::clear('portal-forgot-net:127.0.0.1');
        $this->post('/student/forgot', ['code' => 'DEMO-01']);
        Notification::assertNothingSent();
        // guardian side: password needed, and issueGuardian unbinds
        $slip = $this->issueGuardian(1, 1);
        $this->loginGuardian($slip['code'], $slip['secret']);
        $this->postJson('/guardian/api/secret', ['current' => $slip['secret'], 'new' => 'guardian-pass-7'])->assertOk();
        $this->postJson('/guardian/api/email', ['email' => 'mum@example.invalid', 'current' => 'wrong-password-1'])->assertStatus(422);
        $this->postJson('/guardian/api/email', ['email' => 'mum@example.invalid', 'current' => 'guardian-pass-7'])->assertOk();
        DB::table('gp_contacts')->where('kind', 'guardian')->where('subject_id', 1)->update(['verified_at' => now()]);
        $this->issueGuardian(1, 1);
        $this->assertNull(DB::table('gp_contacts')->where('kind', 'guardian')->where('subject_id', 1)->value('verified_at'));
    }

    public function test_the_same_secret_store_keeps_existing_slips_temporary_after_the_upgrade(): void
    {
        $this->issue(1);
        DB::table('api_student_pins')->where('student_id', 1)->update(['must_change' => false]);
        \Illuminate\Support\Facades\Schema::table('api_student_pins', fn ($t) => $t->dropColumn('must_change')); // the table as it was before the portal
        $migration = require base_path('modules/student-portal/database/migrations/2026_11_20_600001_student_portal_tables.php');
        $migration->up();
        $this->assertSame(1, (int) DB::table('api_student_pins')->where('student_id', 1)->value('must_change'), 'a PIN printed before the upgrade is temporary');
        DB::table('api_student_pins')->where('student_id', 1)->update(['must_change' => false]);
        $migration->up();
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('must_change'), 'a second run changes nothing');
    }

    public function test_a_guardian_with_a_child_that_has_no_classroom_cannot_be_taken_over_by_a_teacher(): void
    {
        $this->issueGuardian(1, 1, 'admin'); // guardian 1 has an account and the child of the teacher's homeroom
        $row = (array) DB::table('students')->where('id', 17)->first();
        unset($row['id']);
        $row['code'] = 'DEMO-90';
        if (array_key_exists('code_key', $row)) $row['code_key'] = 'demo-90';
        $row['classroom_id'] = null;
        $row['status'] = 'studying';
        $new = DB::table('students')->insertGetId($row);
        DB::table('reg_student_guardians')->insert(['school_id' => 1, 'student_id' => $new, 'guardian_id' => 1, 'relationship' => 'mother', 'can_pick_up' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gp_links')->insert(['school_id' => 1, 'guardian_id' => 1, 'student_id' => $new, 'token_hash' => hash('sha256', 'x'), 'purpose' => 'invite', 'expires_at' => now(), 'used_at' => now(), 'created_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->as('teacher')->postJson('/api/student-portal/students/1/guardians/1/issue')->assertForbidden();
        // once the child is out of the picture (left school) the homeroom teacher may reset again
        DB::table('students')->where('id', $new)->update(['status' => 'transferred']);
        $this->as('teacher')->postJson('/api/student-portal/students/1/guardians/1/issue')->assertOk();
    }

    public function test_a_username_only_guardian_account_takes_a_second_child_from_a_teacher_invite_and_resets_by_a_verified_email(): void
    {
        Notification::fake();
        $slip = $this->issueGuardian(1, 1);
        $this->loginGuardian($slip['code'], $slip['secret']);
        $this->postJson('/guardian/api/secret', ['current' => $slip['secret'], 'new' => 'guardian-pass-7'])->assertOk();
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(1, 'data');
        $inv = $this->as('teacher')->postJson('/api/guardian-portal/links', ['student_id' => 2, 'guardian_id' => 1, 'purpose' => 'invite', 'days' => 14])->assertCreated()->json();
        $this->browser();
        $path = parse_url($inv['url'], PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('กรอกชื่อผู้ใช้และรหัสผ่านเดิม');
        $this->post($path, ['email' => $slip['code'], 'password' => 'wrong-password-1'])->assertSessionHasErrors('password');
        $this->post($path, ['email' => $slip['code'], 'password' => 'guardian-pass-7'])->assertRedirect('/guardian');
        $this->getJson('/guardian/api/children')->assertOk()->assertJsonCount(2, 'data');
        $this->assertNull(DB::table('gp_accounts')->where('guardian_id', 1)->value('email_key'), 'credentials stay as they were');
        // forgot-password with the verified contact e-mail finds the username-only account
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'guardian', 'subject_id' => 1, 'email' => 'mum@example.invalid', 'email_key' => 'mum@example.invalid', 'verified_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->browser();
        Notification::fake();
        RateLimiter::clear('portal-forgot-net:127.0.0.1');
        $this->post('/guardian/forgot', ['login' => 'Mum@example.invalid'])->assertRedirect('/guardian/forgot');
        Notification::assertSentOnDemandTimes(PortalLink::class, 1);
        Notification::assertSentOnDemand(PortalLink::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'mum@example.invalid');
        // ... but a contact e-mail is not a sign-in name
        $this->loginGuardian('mum@example.invalid', 'guardian-pass-7')->assertSessionHasErrors('email');
    }

    public function test_the_old_results_endpoint_is_audited_like_the_report(): void
    {
        $this->course('คณิต', 'closed', [1 => [6000, 2500]]);
        $this->announce();
        $slip = $this->issueGuardian(1, 1);
        $this->loginGuardian($slip['code'], $slip['secret']);
        $this->postJson('/guardian/api/secret', ['current' => $slip['secret'], 'new' => 'guardian-pass-7'])->assertOk();
        foreach (range(1, 3) as $i) $this->getJson('/guardian/api/children/1/results')->assertOk();
        $this->assertSame(1, DB::table('gp_events')->where('action', 'guardian.results.viewed')->where('guardian_id', 1)->where('student_id', 1)->count(), 'one row per 10 minutes');
        $this->getJson('/guardian/api/children/1/report')->assertOk(); // the report has its own key: its view is a second row
        $this->assertSame(2, DB::table('gp_events')->where('action', 'guardian.results.viewed')->count());
    }

    public function test_timetable_json_never_carries_the_free_text_note_of_a_lesson(): void
    {
        $lesson = DB::table('lessons')->where('classroom_id', 1)->orderBy('id')->first();
        DB::table('lessons')->where('id', $lesson->id)->update(['note' => 'ครูสมชายลา ให้ครูสมหญิงสอนแทน (ทดสอบ)']);
        $this->signedIn(1);
        $this->assertStringNotContainsString('ครูสมชายลา', $this->getJson('/student/api/timetable')->assertOk()->getContent());
        $slip = $this->issueGuardian(1, 1);
        $this->loginGuardian($slip['code'], $slip['secret']);
        $this->postJson('/guardian/api/secret', ['current' => $slip['secret'], 'new' => 'guardian-pass-7'])->assertOk();
        $this->assertStringNotContainsString('ครูสมชายลา', $this->getJson('/guardian/api/children/1/timetable')->assertOk()->getContent());
    }

    public function test_upgrade_backfill_announces_terms_that_already_have_closed_courses_once(): void
    {
        DB::table('saan_settings')->where('key', 'like', 'student.announce.%')->delete();
        $this->course('คณิต', 'closed', [1 => [6000, 2500]], 1);
        $this->course('ไทย', 'published', [1 => [6000, 2500]], 98);
        $this->course('อังกฤษ', 'open', [1 => [6000, 2500]], 99);
        Settings::put('student.announce.98', ['at' => '2030-01-01 00:00:00']); // a school that already chose keeps its choice
        $migration = require base_path('modules/guardian-portal/database/migrations/2026_11_21_400004_guardian_portal_announce_existing_terms.php');
        $migration->up();
        $this->assertNotNull(\Saan\Modules\GuardianPortal\Announce::at(1));
        $this->assertTrue(\Saan\Modules\GuardianPortal\Announce::isAnnounced(1));
        $this->assertSame('2030-01-01 00:00:00', \Saan\Modules\GuardianPortal\Announce::at(98));
        $this->assertNull(\Saan\Modules\GuardianPortal\Announce::at(99), 'a term with only open courses is not announced');
        $at = \Saan\Modules\GuardianPortal\Announce::at(1);
        $this->travel(2)->minutes();
        $migration->up();
        $this->assertSame($at, \Saan\Modules\GuardianPortal\Announce::at(1), 'a second run changes nothing');
        $this->travelBack();
        // the report now shows the old term
        $this->signedIn(1);
        $this->assertCount(1, $this->getJson('/student/api/report')->assertOk()->json('terms'));
    }

    public function test_rolling_back_an_import_keeps_students_that_have_portal_data(): void
    {
        $svc = app(\Saan\Modules\Registry\ImportService::class);
        $has = new \ReflectionMethod($svc, 'hasDependents');
        $this->assertFalse($has->invoke($svc, 'students', 5000));
        DB::table('stu_events')->insert(['school_id' => 1, 'student_id' => 5000, 'action' => 'student.login', 'created_at' => now()]);
        $this->assertTrue($has->invoke($svc, 'students', 5000), 'stu_events is declared in module.json');
        DB::table('stu_events')->where('student_id', 5000)->delete();
        $this->assertFalse($has->invoke($svc, 'students', 5000));
        foreach ([['student', 'students'], ['guardian', 'guardians']] as [$kind, $import]) {
            DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => $kind, 'subject_id' => 5000, 'email' => 'x@example.invalid', 'email_key' => 'x@example.invalid', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->assertTrue($has->invoke($svc, $import, 5000), 'a contact e-mail of a '.$kind);
            DB::table('gp_contacts')->where('subject_id', 5000)->delete();
        }
    }

    // ---------------------------------------------------------------- audit fixes (2026-11-07)

    public function test_a_reset_link_mailed_to_the_old_address_dies_when_the_email_is_changed_and_a_password_change_ends_every_link(): void
    {
        Notification::fake();
        $secret = $this->signedIn(1);
        DB::table('gp_contacts')->insert(['school_id' => 1, 'kind' => 'student', 'subject_id' => 1, 'email' => 'old@example.invalid', 'email_key' => 'old@example.invalid', 'verified_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->browser();
        $this->post('/student/forgot', ['code' => 'DEMO-01'])->assertRedirect('/student/forgot');
        $urls = [];
        Notification::assertSentOnDemand(PortalLink::class, function ($n) use (&$urls) { $urls[] = $this->resetUrl([$n]); return true; });
        $resetToken = basename(parse_url($urls[0], PHP_URL_PATH));
        // the owner notices and changes the address, then confirms the new one
        $this->login(1, $secret)->assertRedirect('/student');
        $this->postJson('/student/api/email', ['email' => 'new@example.invalid', 'current' => $secret])->assertOk();
        $verify = null;
        Notification::assertSentOnDemand(PortalLink::class, function ($n) use (&$verify) { $u = $this->resetUrl([$n]); if (str_contains($u, '/student/verify/')) $verify = $u; return true; });
        $this->assertNotNull($verify);
        $this->browser();
        $this->post(parse_url($verify, PHP_URL_PATH))->assertOk();
        // the old link must not work now that a verified address exists again
        $this->post('/student/reset', ['token' => $resetToken, 'i' => 1, 'password' => 'takeover-secret-77', 'password_confirmation' => 'takeover-secret-77'])->assertSessionHasErrors('password');
        $this->login(1, 'takeover-secret-77')->assertSessionHasErrors('code');
        $this->login(1, $secret)->assertRedirect('/student');
        // a self-service password change also ends links that are still out
        RateLimiter::clear('portal-forgot-net:127.0.0.1');
        $this->browser();
        $this->post('/student/forgot', ['code' => 'DEMO-01']);
        $tokens = [];
        Notification::assertSentOnDemand(PortalLink::class, function ($n) use (&$tokens) { $u = $this->resetUrl([$n]); if (str_contains($u, '/student/reset/')) $tokens[] = basename(parse_url($u, PHP_URL_PATH)); return true; });
        $this->login(1, $secret)->assertRedirect('/student');
        $this->postJson('/student/api/secret', ['current' => $secret, 'new' => 'fresh-secret-88'])->assertOk();
        $this->browser();
        $this->post('/student/reset', ['token' => end($tokens), 'i' => 1, 'password' => 'takeover-secret-78', 'password_confirmation' => 'takeover-secret-78'])->assertSessionHasErrors('password');
    }

    public function test_every_failed_sign_in_branch_costs_the_same_number_of_bcrypt_operations_with_a_rotated_key(): void
    {
        config(['saan.hash_key' => 'dedicated-test-key-for-timing']); // two candidates: the dedicated key and the app key
        $this->issue(1);
        $this->issue(3);
        DB::table('api_student_pins')->where('student_id', 3)->update(['locked_until' => now()->addHour()]);
        $counter = new class($this->app) extends \Illuminate\Hashing\HashManager {
            public int $n = 0;
            public function check($value, $hashedValue, array $options = []) { $this->n++; return parent::check($value, $hashedValue, $options); }
            public function make($value, array $options = []) { $this->n++; return parent::make($value, $options); }
        };
        \Illuminate\Support\Facades\Hash::swap($counter);
        $cost = function (int $sid, string $ip) use ($counter) {
            $this->browser();
            $counter->n = 0;
            $this->ip($ip)->post('/student/login', ['code' => $this->code($sid), 'secret' => 'wrong-secret-1']);
            return $counter->n;
        };
        $unknown = (function () use ($counter) { $this->browser(); $counter->n = 0; $this->post('/student/login', ['code' => 'NO-SUCH-CODE', 'secret' => 'wrong-secret-1']); return $counter->n; })();
        $this->assertSame(2, $unknown);
        $this->assertSame($unknown, $cost(3, '198.51.100.7'), 'a locked student costs the same as an unknown code');
        for ($i = 1; $i <= 5; $i++) $this->assertSame($unknown, $cost(1, '198.51.100.8'), "wrong try $i");
        $this->assertSame($unknown, $cost(1, '198.51.100.8'), 'a capped network costs the same as an unknown code');
    }

    public function test_every_tab_of_the_student_page_script_reads_an_endpoint_that_exists(): void
    {
        // The results tab once asked for /student/api/results while the route is /student/api/report: the page showed "404" after sign-in.
        $js = (string) file_get_contents(base_path('public/assets/modules/student-portal/student.js'));
        $this->assertSame(1, preg_match("/const ENDPOINT = \{([^}]*)\}/", $js, $map), 'student.js must keep the tab-to-endpoint map');
        preg_match_all("/(\w+): '(\/[a-z]+)'/", $map[1], $pairs, PREG_SET_ORDER);
        preg_match("/const TABS = \[(.*?)\];/", $js, $tabs);
        preg_match_all("/\['(\w+)', '/", $tabs[1] ?? '', $tabKeys);
        $this->assertSame(['results', 'timetable', 'attendance', 'behavior'], array_values(array_diff($tabKeys[1], ['account'])));
        $this->assertCount(4, $pairs);
        $this->signedIn(1);
        foreach ($pairs as [, $tab, $path]) {
            $this->getJson('/student/api' . $path)->assertOk();
        }
    }
}