<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\LeaveDuty\Notifications\LdNotification;
use Tests\TestCase;

/** leave-duty module: leave lifecycle (M14), duty roster and swaps (M24). All data is fictional. */
class LeaveDutyModuleTest extends TestCase
{
    use RefreshDatabase;

    private const L = '/api/leave';
    private const D = '/api/duty';
    private int $n = 0;
    private User $ta;
    private User $tb;
    private User $tc;
    private User $principal;
    private User $admin;
    private User $adminTeacher;
    private array $tid = [];
    private string $diskRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00', 'Asia/Bangkok')); // a Monday, fiscal year 2570
        Notification::fake();
        // own private disk root: Storage::fake() shares one directory with other test classes that run at the same time
        $this->diskRoot = storage_path('framework/testing/ld-'.getmypid().'-'.uniqid());
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::forgetDisk('local');
        foreach (['a' => 'ครู ก', 'b' => 'ครู ข', 'c' => 'ครู ค', 'd' => 'ผู้ดูแล-ครู'] as $k => $name) {
            $this->tid[$k] = DB::table('teachers')->insertGetId(['school_id' => 1, 'name' => $name.' (สมมติ)', 'subject' => 'ทดสอบ']);
        }
        $this->ta = $this->mk('teacher', $this->tid['a']);
        $this->tb = $this->mk('teacher', $this->tid['b']);
        $this->tc = $this->mk('teacher', $this->tid['c']);
        $this->principal = $this->mk('principal', null);
        $this->admin = $this->mk('admin', null);
        $this->adminTeacher = $this->mk('admin', $this->tid['d']);
    }

    /** Wed 11 Nov 2026 as a school holiday. Only the tests that count days use it; skipped when the academic module's table is not there. */
    private function holiday(string $date = '2026-11-11'): void
    {
        if (!Schema::hasTable('acad_calendar_days')) $this->markTestSkipped('acad_calendar_days (academic module) is not installed');
        $row = ['school_id' => 1, 'date' => $date, 'kind' => 'holiday', 'title' => 'วันหยุดทดสอบ', 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        DB::table('acad_calendar_days')->insert(array_filter($row, fn ($k) => Schema::hasColumn('acad_calendar_days', $k), ARRAY_FILTER_USE_KEY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->diskRoot !== '') \Illuminate\Support\Facades\File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function mk(string $role, ?int $teacher, ?string $email = null): User
    {
        $this->n++;
        return User::forceCreate(['name' => 'Fictional '.$role.$this->n, 'email' => $email ?? $role.$this->n.'@school.test', 'password' => 'Original-Password-2026', 'role' => $role,
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1, 'teacher_id' => $teacher]);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u);
    }

    private function body(array $over = []): array
    {
        return $over + ['type' => 'personal', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-09', 'reason' => 'ธุระส่วนตัว (ข้อมูลทดสอบ)'];
    }

    private function draft(User $u, array $over = []): array
    {
        return $this->as($u)->postJson(self::L.'/requests', $this->body($over))->assertCreated()->json();
    }

    private function go(User $u, int $id, string $action, int $version, ?string $reason = null)
    {
        return $this->as($u)->postJson(self::L.'/requests/'.$id.'/transition', ['action' => $action, 'version' => $version, 'reason' => $reason]);
    }

    /** draft -> submitted, returns the submitted row */
    private function submitted(User $u, array $over = []): array
    {
        $d = $this->draft($u, $over);
        return $this->go($u, $d['id'], 'submit', $d['version'])->assertOk()->json();
    }

    private function approved(User $u, array $over = []): array
    {
        $s = $this->submitted($u, $over);
        return $this->go($this->principal, $s['id'], 'approve', $s['version'])->assertOk()->json();
    }

    private function duty(array $over = []): array
    {
        return $this->as($this->admin)->postJson(self::D.'/duties', $over + ['teacher_id' => $this->tid['a'], 'date' => '2026-11-10', 'starts_at' => '08:00', 'ends_at' => '08:30', 'location' => 'ประตูโรงเรียน', 'kind' => 'morning'])->assertCreated()->json();
    }

    private function dutyRow(int $id): ?object
    {
        return DB::table('duties')->where('id', $id)->first();
    }

    // ------------------------------------------------------------------ types

    public function test_leave_types_are_seeded_on_first_use_and_editable(): void
    {
        $this->assertSame(0, DB::table('ld_leave_types')->count());
        $types = $this->as($this->ta)->getJson(self::L.'/types')->assertOk()->json('data');
        $this->assertSame(['sick', 'personal', 'vacation', 'maternity', 'ordination', 'paternity'], array_column($types, 'code'));
        $this->assertSame('ลาป่วย', $types[0]['label']);
        $this->assertSame('ลาไปช่วยเหลือภริยาที่คลอดบุตร', $types[5]['label']);
        $this->getJson(self::L.'/types')->assertJsonCount(6, 'data'); // not seeded twice
        $this->assertSame(6, DB::table('ld_leave_types')->count());

        $this->as($this->ta)->postJson(self::L.'/types', ['code' => 'study', 'label' => 'ลาศึกษาต่อ'])->assertForbidden();
        $this->as($this->ta)->putJson(self::L.'/types/'.$types[1]['id'], ['version' => 1, 'label' => 'x'])->assertForbidden();

        $upd = $this->as($this->principal)->putJson(self::L.'/types/'.$types[1]['id'], ['version' => 1, 'label' => 'ลากิจ', 'yearly_quota_days' => 30, 'needs_attachment_after_days' => null])->assertOk()->json();
        $this->assertSame(2, $upd['version']);
        $this->assertEquals(30.0, $upd['yearly_quota_days']);
        $this->putJson(self::L.'/types/'.$types[1]['id'], ['version' => 1, 'label' => 'ลากิจ'])->assertConflict()->assertJsonPath('current.id', $types[1]['id']);
        // case/space-insensitive duplicate of an existing label
        $this->postJson(self::L.'/types', ['code' => 'dup', 'label' => '  ลาป่วย '])->assertUnprocessable();
        $this->postJson(self::L.'/types', ['code' => 'BAD CODE', 'label' => 'ลาอื่น'])->assertUnprocessable();
        $new = $this->postJson(self::L.'/types', ['code' => 'study', 'label' => 'ลาศึกษาต่อ', 'yearly_quota_days' => 5])->assertCreated()->json();
        $this->postJson(self::L.'/types', ['code' => 'study', 'label' => 'ลาศึกษาต่ออีกที'])->assertUnprocessable();
        // a type in use cannot be deleted; an unused one can
        $this->draft($this->ta, ['type' => 'study']);
        $this->as($this->principal)->deleteJson(self::L.'/types/'.$new['id'], ['version' => $new['version']])->assertUnprocessable();
        $free = $this->postJson(self::L.'/types', ['code' => 'tmp', 'label' => 'ชั่วคราว'])->assertCreated()->json();
        $this->deleteJson(self::L.'/types/'.$free['id'], ['version' => 1])->assertOk();
        $this->assertSame('leave.type_create', DB::table('audit_events')->where('entity', 'ld_leave_types')->where('action', 'leave.type_create')->value('action'));
    }

    // ------------------------------------------------------------------ calculation

    public function test_working_days_skip_weekends_holidays_and_handle_half_days(): void
    {
        $this->holiday();
        // Mon 9 - Fri 13 Nov, Wed 11 Nov is a holiday: 4 working days
        $p = $this->as($this->ta)->getJson(self::L.'/preview?'.http_build_query($this->body(['ends_on' => '2026-11-13'])))->assertOk()->json();
        $this->assertEquals(4.0, $p['days']);
        $this->assertSame(2570, $p['fiscal_year']);
        $this->assertContains('holiday', array_column($p['skipped'], 'reason'));
        // Fri 13 - Mon 16: weekend skipped, 2 days
        $d = $this->draft($this->ta, ['starts_on' => '2026-11-13', 'ends_on' => '2026-11-16']);
        $this->assertEquals(2.0, $d['days']);
        // only a weekend, or only the holiday: nothing to take leave on
        $this->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']))->assertUnprocessable();
        $this->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-11', 'ends_on' => '2026-11-11']))->assertUnprocessable();
        // half day: one day only, counts 0.5
        $h = $this->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'half_day' => 'am']))->assertCreated()->json();
        $this->assertEquals(0.5, $h['days']);
        $this->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'half_day' => 'pm']))->assertUnprocessable();
        $this->postJson(self::L.'/requests', $this->body(['ends_on' => '2026-11-08']))->assertUnprocessable(); // ends before it starts
        $this->postJson(self::L.'/requests', $this->body(['type' => 'nope']))->assertUnprocessable();
        // fiscal year boundary: Sep belongs to the old year, 1 Oct to the next; a request may not straddle them
        $this->assertSame(2569, $this->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-09-30', 'ends_on' => '2026-09-30']))->assertCreated()->json('fiscal_year'));
        $this->assertSame(2570, $this->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-01']))->assertCreated()->json('fiscal_year'));
        $this->postJson(self::L.'/requests', $this->body(['starts_on' => '2027-09-30', 'ends_on' => '2027-10-01']))->assertUnprocessable();
        // a teacher without a linked teacher record cannot file
        $this->as($this->principal)->postJson(self::L.'/requests', $this->body())->assertUnprocessable();
    }

    public function test_overlap_with_own_open_leave_is_rejected_but_morning_and_afternoon_can_share_a_day(): void
    {
        $a = $this->submitted($this->ta, ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-10']);
        $this->as($this->ta)->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']))->assertUnprocessable();
        // another teacher is not affected
        $this->as($this->tb)->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']))->assertCreated();
        // a withdrawn request frees the dates
        $w = $this->go($this->ta, $a['id'], 'withdraw', $a['version'])->assertOk()->json();
        $this->assertSame('withdrawn', $w['status']);
        $this->as($this->ta)->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']))->assertCreated();
        // half days
        $am = $this->submitted($this->ta, ['starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'half_day' => 'am']);
        $this->as($this->ta)->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'half_day' => 'pm']))->assertCreated();
        $this->postJson(self::L.'/requests', $this->body(['starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'half_day' => 'am']))->assertUnprocessable();
        $this->assertSame('submitted', $am['status']);
    }

    // ------------------------------------------------------------------ lifecycle

    public function test_full_lifecycle_with_every_allowed_and_forbidden_transition(): void
    {
        $d = $this->draft($this->ta);
        $this->assertSame('draft', $d['status']);
        $this->assertSame(['submit', 'edit', 'delete', 'attach'], $d['can']);

        // draft: only the owner may even see it, nobody else may transition it
        $this->as($this->tb)->getJson(self::L.'/requests/'.$d['id'])->assertForbidden();
        $this->go($this->tb, $d['id'], 'submit', $d['version'])->assertForbidden();
        $this->go($this->principal, $d['id'], 'approve', $d['version'])->assertForbidden(); // an approver never sees or decides another teacher's unsent draft
        $this->as($this->principal)->getJson(self::L.'/requests/'.$d['id'])->assertForbidden();
        $this->getJson(self::L.'/requests?scope=all')->assertJsonPath('total', 0);
        $this->getJson(self::L.'/requests?scope=all&status=draft')->assertJsonPath('total', 0);
        $this->go($this->ta, $d['id'], 'approve', $d['version'])->assertForbidden();

        // edit draft (version checked), then submit
        $e = $this->as($this->ta)->putJson(self::L.'/requests/'.$d['id'], $this->body(['version' => $d['version'], 'ends_on' => '2026-11-10']))->assertOk()->json();
        $this->assertEquals(2.0, $e['days']);
        $this->putJson(self::L.'/requests/'.$d['id'], $this->body(['version' => $d['version']]))->assertConflict();
        $this->go($this->ta, $d['id'], 'submit', $d['version'])->assertConflict()->assertJsonPath('current.id', $d['id']); // stale version
        $s = $this->go($this->ta, $d['id'], 'submit', $e['version'])->assertOk()->json();
        $this->assertSame('submitted', $s['status']);
        $this->assertNotNull($s['submitted_at']);
        $this->assertSame(['withdraw'], $s['can']);

        // editing / deleting after submit is not allowed
        $this->as($this->ta)->putJson(self::L.'/requests/'.$d['id'], $this->body(['version' => $s['version']]))->assertConflict();
        $this->deleteJson(self::L.'/requests/'.$d['id'], ['version' => $s['version']])->assertConflict();
        // forbidden / invalid transitions from submitted
        foreach (['submit', 'request_cancel', 'approve_cancel', 'reject_cancel'] as $bad) {
            $this->go($this->ta, $d['id'], $bad, $s['version'], 'เหตุผลทดสอบ')->assertStatus(in_array($bad, ['approve_cancel', 'reject_cancel'], true) ? 403 : 409);
        }
        $this->go($this->tb, $d['id'], 'withdraw', $s['version'])->assertForbidden();   // not the owner
        $this->go($this->ta, $d['id'], 'approve', $s['version'])->assertForbidden();    // owner is not an approver
        $this->go($this->tc, $d['id'], 'reject', $s['version'], 'ไม่ได้')->assertForbidden();
        $this->go($this->principal, $d['id'], 'reject', $s['version'])->assertUnprocessable(); // reason required
        $this->go($this->principal, $d['id'], 'reject', $s['version'], 'ab')->assertUnprocessable();
        $this->go($this->principal, $d['id'], 'bogus', $s['version'])->assertUnprocessable();

        // approve
        $a = $this->go($this->principal, $d['id'], 'approve', $s['version'], 'อนุมัติ')->assertOk()->json();
        $this->assertSame('approved', $a['status']);
        $this->assertSame($this->principal->id, (int) $a['decided_by']);
        $this->assertNotNull($a['decided_at']);
        $this->assertSame(['request_cancel'], $this->as($this->ta)->getJson(self::L.'/requests/'.$d['id'])->json('can'));
        // forbidden after approval
        $this->go($this->ta, $d['id'], 'withdraw', $a['version'])->assertConflict();
        $this->go($this->principal, $d['id'], 'approve', $a['version'])->assertConflict();
        $this->go($this->principal, $d['id'], 'reject', $a['version'], 'เปลี่ยนใจ')->assertConflict();
        $this->go($this->ta, $d['id'], 'request_cancel', $a['version'])->assertUnprocessable(); // reason required
        $this->go($this->tb, $d['id'], 'request_cancel', $a['version'], 'ขอยกเลิก')->assertForbidden();

        // cancellation request, rejected -> back to approved, then requested again and approved -> cancelled
        $c = $this->go($this->ta, $d['id'], 'request_cancel', $a['version'], 'ธุระยกเลิก')->assertOk()->json();
        $this->assertSame('cancel_requested', $c['status']);
        $this->assertSame('ธุระยกเลิก', $c['cancel_reason']);
        $this->go($this->ta, $d['id'], 'withdraw', $c['version'])->assertConflict();
        $this->go($this->ta, $d['id'], 'approve_cancel', $c['version'])->assertForbidden();
        $this->go($this->principal, $d['id'], 'reject_cancel', $c['version'])->assertUnprocessable();
        $r = $this->go($this->principal, $d['id'], 'reject_cancel', $c['version'], 'ยังต้องลาตามเดิม')->assertOk()->json();
        $this->assertSame('approved', $r['status']);
        $this->assertNull($r['cancel_requested_at']);
        $c2 = $this->go($this->ta, $d['id'], 'request_cancel', $r['version'], 'ยกเลิกจริง')->assertOk()->json();
        $x = $this->go($this->admin, $d['id'], 'approve_cancel', $c2['version'], 'รับทราบ')->assertOk()->json();
        $this->assertSame('cancelled', $x['status']);
        $this->assertNotNull($x['cancelled_at']);
        // terminal
        foreach (['submit', 'withdraw', 'approve', 'reject', 'request_cancel', 'approve_cancel', 'reject_cancel'] as $act) {
            $who = in_array($act, ['approve', 'reject', 'approve_cancel', 'reject_cancel'], true) ? $this->principal : $this->ta;
            $this->go($who, $d['id'], $act, $x['version'], 'เหตุผลทดสอบ')->assertConflict();
        }
        // history is written by the audit trail
        $hist = $this->as($this->ta)->getJson(self::L.'/requests/'.$d['id'])->json('history');
        $this->assertSame(['leave.create', 'leave.update', 'leave.submit', 'leave.approve', 'leave.request_cancel', 'leave.reject_cancel', 'leave.request_cancel', 'leave.approve_cancel'], array_column($hist, 'action'));
        $this->assertSame('ยังต้องลาตามเดิม', $hist[5]['reason']);
    }

    public function test_withdraw_reject_resubmit_and_delete_draft(): void
    {
        $s = $this->submitted($this->ta, ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-16']);
        $rej = $this->go($this->principal, $s['id'], 'reject', $s['version'], 'ช่วงนั้นสอบกลางภาค')->assertOk()->json();
        $this->assertSame('rejected', $rej['status']);
        $this->assertSame('ช่วงนั้นสอบกลางภาค', $rej['decision_note']);
        $this->assertSame(['resubmit'], $this->as($this->ta)->getJson(self::L.'/requests/'.$s['id'])->json('can'));
        $this->go($this->ta, $s['id'], 'withdraw', $rej['version'])->assertConflict();
        $this->go($this->ta, $s['id'], 'submit', $rej['version'])->assertConflict();
        // only the owner can resubmit, and only a rejected one
        $new = $this->body(['starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'version' => $rej['version']]);
        $this->as($this->tb)->postJson(self::L.'/requests/'.$s['id'].'/resubmit', $new)->assertForbidden();
        $this->as($this->ta)->postJson(self::L.'/requests/'.$s['id'].'/resubmit', ['version' => 1] + $new)->assertConflict();
        $re = $this->postJson(self::L.'/requests/'.$s['id'].'/resubmit', $new)->assertCreated()->json();
        $this->assertSame('draft', $re['status']); // a linked draft: the owner may still attach a file, then submits through the state machine
        $re = $this->go($this->ta, $re['id'], 'submit', $re['version'])->assertOk()->json();
        $this->assertSame('submitted', $re['status']);
        Notification::assertSentTo($this->principal, LdNotification::class, fn ($n) => $n->event === 'leave.submit' && collect($n->lines)->contains(fn ($l) => str_contains($l, '17/11/2569')));
        $this->assertSame($s['id'], $re['resubmitted_from']);
        $this->assertSame('2026-11-17', $re['starts_on']);
        $old = $this->getJson(self::L.'/requests/'.$s['id'])->json();
        $this->assertSame($re['id'], $old['resubmitted_to']);
        $this->assertNotContains('resubmit', $old['can']);
        $this->postJson(self::L.'/requests/'.$s['id'].'/resubmit', ['version' => $old['version']] + $new)->assertConflict(); // already resubmitted
        // resubmitting a non-rejected request
        $this->postJson(self::L.'/requests/'.$re['id'].'/resubmit', ['version' => $re['version']] + $new)->assertConflict();
        // draft delete
        $dr = $this->draft($this->ta, ['starts_on' => '2026-11-20', 'ends_on' => '2026-11-20']);
        $this->as($this->tb)->deleteJson(self::L.'/requests/'.$dr['id'], ['version' => $dr['version']])->assertForbidden();
        $this->as($this->ta)->deleteJson(self::L.'/requests/'.$dr['id'], ['version' => 99])->assertConflict();
        $this->deleteJson(self::L.'/requests/'.$dr['id'], ['version' => $dr['version']])->assertOk();
        $this->getJson(self::L.'/requests/'.$dr['id'])->assertNotFound();
    }

    public function test_approver_cannot_decide_own_leave_and_cancel_of_a_finished_leave_is_refused(): void
    {
        $own = $this->submitted($this->adminTeacher);
        $this->go($this->adminTeacher, $own['id'], 'approve', $own['version'])->assertForbidden();
        $this->assertNotContains('approve', $this->as($this->adminTeacher)->getJson(self::L.'/requests/'.$own['id'])->json('can'));
        $this->go($this->admin, $own['id'], 'approve', $own['version'])->assertOk(); // another approver can
        // leave that already ended (before "today" = 2 Nov) cannot be cancelled
        $past = $this->approved($this->ta, ['starts_on' => '2026-10-26', 'ends_on' => '2026-10-26']);
        $this->assertNotContains('request_cancel', $past['can']);
        $this->go($this->ta, $past['id'], 'request_cancel', $past['version'], 'ขอยกเลิกย้อนหลัง')->assertConflict();
        // approving re-checks overlap against leave approved in the meantime is covered by submit-time overlap; approver list needs scope=all
        $this->as($this->ta)->getJson(self::L.'/requests?scope=all')->assertForbidden();
        $this->as($this->principal)->getJson(self::L.'/requests?scope=all&status=approved')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_list_visibility_and_tenant_isolation(): void
    {
        $a = $this->submitted($this->ta);
        $this->submitted($this->tb, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
        $mine = $this->as($this->ta)->getJson(self::L.'/requests')->assertOk()->json();
        $this->assertSame(1, $mine['total']);
        $this->assertSame($a['id'], $mine['data'][0]['id']);
        $this->assertSame(2, $this->as($this->principal)->getJson(self::L.'/requests?scope=all')->json('total'));
        $this->assertSame(1, $this->getJson(self::L.'/requests?scope=all&teacher_id='.$this->tid['b'])->json('total'));
        $this->assertSame(1, $this->getJson(self::L.'/requests?scope=all&status=submitted&fiscal_year=2570&teacher_id='.$this->tid['a'])->json('total'));
        $this->getJson(self::L.'/requests?scope=all&status=bogus')->assertUnprocessable();
        // a row of another school is invisible even to approvers
        $other = DB::table('leave_requests')->insertGetId(['school_id' => 2, 'teacher_id' => $this->tid['a'], 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-09', 'type' => 'personal', 'reason' => 'โรงเรียนอื่น', 'status' => 'submitted', 'days' => 1, 'fiscal_year' => 2570]);
        $this->getJson(self::L.'/requests/'.$other)->assertNotFound();
        $this->go($this->principal, $other, 'approve', 1)->assertNotFound();
        $this->assertSame(2, $this->getJson(self::L.'/requests?scope=all')->json('total'));
        // a legacy prototype row (status pending, no version bump) behaves as submitted
        $legacy = DB::table('leave_requests')->insertGetId(['school_id' => 1, 'teacher_id' => $this->tid['c'], 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'type' => 'personal', 'reason' => 'ข้อมูลเดิมจากต้นแบบ', 'status' => 'pending', 'days' => 1, 'fiscal_year' => 2570]);
        $row = $this->getJson(self::L.'/requests/'.$legacy)->assertOk()->json();
        $this->assertSame('submitted', $row['status']);
        $this->assertSame('approved', $this->go($this->principal, $legacy, 'approve', $row['version'])->assertOk()->json('status'));
    }

    public function test_idempotency_key_prevents_a_double_create(): void
    {
        $this->as($this->ta);
        $h = ['Idempotency-Key' => 'ld-test-key-1'];
        $one = $this->postJson(self::L.'/requests', $this->body(), $h)->assertCreated()->json('id');
        $two = $this->postJson(self::L.'/requests', $this->body(), $h)->assertCreated()->json('id');
        $this->assertSame($one, $two);
        $this->assertSame(1, DB::table('leave_requests')->where('teacher_id', $this->tid['a'])->count());
    }

    // ------------------------------------------------------------------ notifications

    public function test_notifications_go_to_the_affected_accounts_only(): void
    {
        $d = $this->draft($this->ta);
        Notification::assertNothingSent();
        $s = $this->go($this->ta, $d['id'], 'submit', $d['version'])->json();
        foreach ([$this->principal, $this->admin, $this->adminTeacher] as $approver) {
            Notification::assertSentTo($approver, LdNotification::class, fn ($n) => $n->event === 'leave.submit');
        }
        // the reason for leave (may be health data) is never in the mail text, and a cancel reason of the teacher neither
        Notification::assertSentTo($this->principal, LdNotification::class, fn ($n) => !str_contains(implode("\n", $n->lines), 'ธุระส่วนตัว') && !str_contains(implode("\n", $n->lines), 'เหตุผลการลา'));
        Notification::assertNotSentTo($this->ta, LdNotification::class);
        Notification::assertNotSentTo($this->tb, LdNotification::class);
        Notification::assertSentToTimes($this->principal, LdNotification::class, 1);

        $this->go($this->principal, $d['id'], 'approve', $s['version'])->assertOk();
        Notification::assertSentTo($this->ta, LdNotification::class, fn ($n) => $n->event === 'leave.approve' && in_array('mail', $n->via($this->ta), true));
        Notification::assertNotSentTo($this->tb, LdNotification::class);

        $a = $this->getJson(self::L.'/requests/'.$d['id'])->json();
        $c = $this->go($this->ta, $d['id'], 'request_cancel', $a['version'], 'ยกเลิกทดสอบ')->json();
        Notification::assertSentTo($this->principal, LdNotification::class, fn ($n) => $n->event === 'leave.request_cancel' && !str_contains(implode("\n", $n->lines), 'ยกเลิกทดสอบ'));
        $this->go($this->principal, $d['id'], 'approve_cancel', $c['version'])->assertOk();
        Notification::assertSentTo($this->ta, LdNotification::class, fn ($n) => $n->event === 'leave.approve_cancel');

        $r = $this->submitted($this->tb, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
        $this->go($this->principal, $r['id'], 'reject', $r['version'], 'เหตุผลทดสอบ')->assertOk();
        Notification::assertSentTo($this->tb, LdNotification::class, fn ($n) => $n->event === 'leave.reject' && collect($n->lines)->contains('หมายเหตุ: เหตุผลทดสอบ'));
        $w = $this->submitted($this->tb, ['starts_on' => '2026-11-12', 'ends_on' => '2026-11-12']);
        $this->go($this->tb, $w['id'], 'withdraw', $w['version'])->assertOk();
        Notification::assertSentTo($this->principal, LdNotification::class, fn ($n) => $n->event === 'leave.withdraw');
    }

    public function test_accounts_without_an_email_are_skipped_and_mail_is_rendered_in_thai(): void
    {
        $noMail = $this->mk('principal', null, '');
        $this->assertSame([], (new LdNotification('x', 's', ['l']))->via($noMail));
        $this->assertSame(['mail'], (new LdNotification('x', 's', ['l']))->via($this->ta));
        $mail = (new LdNotification('leave.approve', 'ใบลาของคุณได้รับการอนุมัติ', ['บรรทัดหนึ่ง'], 'leave'))->toMail($this->ta);
        $this->assertSame('ใบลาของคุณได้รับการอนุมัติ', $mail->subject);
        $this->assertStringContainsString('/work#leave', $mail->actionUrl);
        // the no-email approver is not mailed when somebody submits
        $this->submitted($this->ta);
        Notification::assertSentToTimes($this->principal, LdNotification::class, 1);
        Notification::assertCount(3); // principal, admin, adminTeacher: the account with no email gets nothing
    }

    // ------------------------------------------------------------------ quota

    public function test_quota_is_enforced_per_fiscal_year_and_reported(): void
    {
        $types = $this->as($this->principal)->getJson(self::L.'/types')->json('data');
        $personal = collect($types)->firstWhere('code', 'personal');
        $this->putJson(self::L.'/types/'.$personal['id'], ['version' => $personal['version'], 'label' => $personal['label'], 'yearly_quota_days' => 3])->assertOk();

        $first = $this->approved($this->ta, ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-10']); // 2 days
        $this->assertEquals(2.0, $first['days']);
        $second = $this->draft($this->ta, ['starts_on' => '2026-11-12', 'ends_on' => '2026-11-13']);     // 2 more: 4 > 3
        $this->go($this->ta, $second['id'], 'submit', $second['version'])->assertUnprocessable()->assertJsonValidationErrors('days');
        $one = $this->as($this->ta)->putJson(self::L.'/requests/'.$second['id'], $this->body(['version' => $second['version'], 'starts_on' => '2026-11-12', 'ends_on' => '2026-11-12']))->assertOk()->json();
        $this->go($this->ta, $one['id'], 'submit', $one['version'])->assertOk(); // 2 + 1 = 3, exactly the quota

        // a leave of the previous fiscal year does not count against 2570 (Sep 2026 = BE 2569)
        $old = $this->approved($this->tb, ['starts_on' => '2026-09-28', 'ends_on' => '2026-09-29']);
        $this->assertSame(2569, $old['fiscal_year']);

        $q = $this->as($this->ta)->getJson(self::L.'/quota?fiscal_year=2570')->assertOk()->json();
        $row = collect($q['types'])->firstWhere('code', 'personal');
        $this->assertEquals([3.0, 2.0, 1.0, 0.0], [$row['quota'], $row['used'], $row['pending'], $row['remaining']]);
        $this->assertContains(2570, $q['fiscal_years']);
        $this->assertContains(2569, $q['fiscal_years']);
        $this->getJson(self::L.'/quota?teacher_id='.$this->tid['b'])->assertForbidden();
        $this->getJson(self::L.'/quota/report')->assertForbidden();

        $rep = $this->as($this->principal)->getJson(self::L.'/quota/report?fiscal_year=2570')->assertOk()->json();
        $byName = collect($rep['rows'])->keyBy('teacher_id');
        $this->assertEquals(2.0, $byName[$this->tid['a']]['by_type']['personal']['used']);
        $this->assertEquals(1.0, $byName[$this->tid['a']]['by_type']['personal']['pending']);
        $this->assertEquals(0.0, $byName[$this->tid['b']]['by_type']['personal']['used']);
        $rep69 = $this->getJson(self::L.'/quota/report?fiscal_year=2569')->json();
        $this->assertEquals(2.0, collect($rep69['rows'])->keyBy('teacher_id')[$this->tid['b']]['by_type']['personal']['used']);
        $this->assertSame(['sick', 'personal', 'vacation', 'maternity', 'ordination', 'paternity'], array_column($rep['types'], 'code'));
        // an approver reads another teacher's quota
        $this->getJson(self::L.'/quota?fiscal_year=2570&teacher_id='.$this->tid['a'])->assertOk();
        // cancelled leave no longer uses quota
        $c = $this->go($this->ta, $first['id'], 'request_cancel', $first['version'], 'ยกเลิกทดสอบ')->json();
        $this->go($this->principal, $first['id'], 'approve_cancel', $c['version'])->assertOk();
        $q2 = collect($this->as($this->ta)->getJson(self::L.'/quota?fiscal_year=2570')->json('types'))->firstWhere('code', 'personal');
        $this->assertEquals(0.0, $q2['used']);
    }

    // ------------------------------------------------------------------ attachments

    private function pdf(string $name = 'cert.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% fictional certificate\n%%EOF");
    }

    public function test_attachment_is_private_limited_and_required_after_the_threshold(): void
    {
        $this->holiday();
        // sick leave over 3 working days needs a file: 9-13 Nov = 4 days with the holiday skipped
        $d = $this->draft($this->ta, ['type' => 'sick', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13']);
        $this->assertEquals(4.0, $d['days']);
        $this->go($this->ta, $d['id'], 'submit', $d['version'])->assertUnprocessable()->assertJsonValidationErrors('attachment');

        // wrong type / not the owner / wrong version
        $this->as($this->ta)->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $d['version'], 'file' => UploadedFile::fake()->create('x.exe', 1)])->assertUnprocessable();
        $this->as($this->tb)->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $d['version'], 'file' => $this->pdf()])->assertForbidden();
        $this->as($this->ta)->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => 99, 'file' => $this->pdf()])->assertConflict();

        $att = $this->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $d['version'], 'file' => $this->pdf()])->assertOk()->json();
        $this->assertTrue($att['has_attachment']);
        $this->assertArrayNotHasKey('attachment_path', $att);
        $path = DB::table('leave_requests')->where('id', $d['id'])->value('attachment_path');
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('leave-duty/1/'.$d['id'].'/', $path);
        $this->assertFalse(Storage::disk('public')->exists($path));
        // an approver never reads another teacher's unsent draft or its file
        $this->as($this->principal)->get(self::L.'/requests/'.$d['id'].'/attachment')->assertForbidden();
        $this->as($this->ta);

        // replacing the file removes the old one
        $att2 = $this->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $att['version'], 'file' => $this->pdf('second.pdf')])->assertOk()->json();
        Storage::disk('local')->assertMissing($path);
        $path2 = DB::table('leave_requests')->where('id', $d['id'])->value('attachment_path');
        Storage::disk('local')->assertExists($path2);

        $s = $this->go($this->ta, $d['id'], 'submit', $att2['version'])->assertOk()->json();
        // after submit the file is frozen
        $this->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $s['version'], 'file' => $this->pdf()])->assertConflict();
        $this->deleteJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $s['version']])->assertConflict();

        // download: requester and approvers only
        $this->as($this->ta)->get(self::L.'/requests/'.$d['id'].'/attachment')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition');
        $this->as($this->principal)->get(self::L.'/requests/'.$d['id'].'/attachment')->assertOk();
        $this->as($this->admin)->get(self::L.'/requests/'.$d['id'].'/attachment')->assertOk();
        $this->as($this->tb)->get(self::L.'/requests/'.$d['id'].'/attachment')->assertForbidden();
        $this->as($this->tc)->getJson(self::L.'/requests/'.$d['id'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::L.'/requests/'.$d['id'].'/attachment')->assertUnauthorized();
        // there is no public URL for the file
        $this->assertNotSame(200, $this->get('/storage/'.$path2)->getStatusCode());
        $this->assertNotSame(200, $this->get('/'.$path2)->getStatusCode());
        // no attachment on another request -> 404 for an authorised viewer
        $other = $this->draft($this->ta, ['starts_on' => '2026-11-18', 'ends_on' => '2026-11-18']);
        $this->as($this->ta)->get(self::L.'/requests/'.$other['id'].'/attachment')->assertNotFound();
        // deleting a draft deletes its file
        $dr = $this->draft($this->ta, ['starts_on' => '2026-11-19', 'ends_on' => '2026-11-19']);
        $a3 = $this->postJson(self::L.'/requests/'.$dr['id'].'/attachment', ['version' => $dr['version'], 'file' => $this->pdf()])->assertOk()->json();
        $p3 = DB::table('leave_requests')->where('id', $dr['id'])->value('attachment_path');
        $this->deleteJson(self::L.'/requests/'.$dr['id'], ['version' => $a3['version']])->assertOk();
        Storage::disk('local')->assertMissing($p3);
    }

    public function test_attachment_size_limit_and_storage_quota(): void
    {
        config(['saan.storage.max_upload_mb' => 1]);
        $d = $this->draft($this->ta);
        $this->as($this->ta)->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $d['version'], 'file' => UploadedFile::fake()->create('big.pdf', 1500, 'application/pdf')])->assertUnprocessable();

        config(['saan.storage.max_upload_mb' => 10, 'saan.storage.quota_mb' => 1]);
        $a = $this->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $d['version'], 'file' => UploadedFile::fake()->create('a.pdf', 600, 'application/pdf')])->assertOk()->json();
        $this->assertSame(614400, $a['attachment_bytes']);
        $d2 = $this->draft($this->tb, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
        $this->as($this->tb)->postJson(self::L.'/requests/'.$d2['id'].'/attachment', ['version' => $d2['version'], 'file' => UploadedFile::fake()->create('b.pdf', 600, 'application/pdf')])->assertUnprocessable();
        // replacing the same row's file only needs room for the difference
        $this->as($this->ta)->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $a['version'], 'file' => UploadedFile::fake()->create('c.pdf', 700, 'application/pdf')])->assertOk();
    }

    public function test_resubmit_copies_the_attachment_for_the_new_row(): void
    {
        $d = $this->draft($this->ta, ['type' => 'sick', 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-20']);
        $a = $this->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $d['version'], 'file' => $this->pdf()])->assertOk()->json();
        $s = $this->go($this->ta, $d['id'], 'submit', $a['version'])->assertOk()->json();
        $rej = $this->go($this->principal, $d['id'], 'reject', $s['version'], 'ขอใบรับรองฉบับใหม่')->assertOk()->json();
        $re = $this->as($this->ta)->postJson(self::L.'/requests/'.$d['id'].'/resubmit', $this->body(['type' => 'sick', 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-20', 'version' => $rej['version']]))->assertCreated()->json();
        $this->assertSame('draft', $re['status']);
        $this->assertTrue($re['has_attachment']);
        $this->assertNotSame(DB::table('leave_requests')->where('id', $d['id'])->value('attachment_path'), DB::table('leave_requests')->where('id', $re['id'])->value('attachment_path'));
        $copy = DB::table('leave_requests')->where('id', $re['id'])->value('attachment_path');
        Storage::disk('local')->assertExists($copy);
        // the rejection asked for a NEW certificate: replace the copied file on the draft, then submit
        $new = $this->postJson(self::L.'/requests/'.$re['id'].'/attachment', ['version' => $re['version'], 'file' => $this->pdf('new-cert.pdf')])->assertOk()->json();
        $this->assertSame('new-cert.pdf', $new['attachment_name']);
        Storage::disk('local')->assertMissing($copy);
        $sub = $this->go($this->ta, $re['id'], 'submit', $new['version'])->assertOk()->json();
        $this->assertSame('submitted', $sub['status']);
        Notification::assertSentTo($this->principal, LdNotification::class, fn ($n) => $n->event === 'leave.submit');
    }

    public function test_resubmit_without_an_old_file_can_attach_one_and_copying_respects_the_storage_quota(): void
    {
        // sick leave over 3 days with no file at first: the owner could not even submit it
        $d = $this->draft($this->ta, ['type' => 'sick', 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-18']);
        $this->assertEquals(3.0, $d['days']);
        $d = $this->putJson(self::L.'/requests/'.$d['id'], $this->body(['type' => 'sick', 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-20', 'version' => $d['version']]))->assertOk()->json();
        DB::table('leave_requests')->where('id', $d['id'])->update(['status' => 'rejected', 'decision_note' => 'ต้องมีใบรับรองแพทย์']);
        $rej = $this->getJson(self::L.'/requests/'.$d['id'])->json();
        $re = $this->postJson(self::L.'/requests/'.$d['id'].'/resubmit', $this->body(['type' => 'sick', 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-20', 'version' => $rej['version']]))->assertCreated()->json();
        $this->assertSame('draft', $re['status']);
        $this->go($this->ta, $re['id'], 'submit', $re['version'])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $att = $this->postJson(self::L.'/requests/'.$re['id'].'/attachment', ['version' => $re['version'], 'file' => $this->pdf()])->assertOk()->json();
        $this->go($this->ta, $re['id'], 'submit', $att['version'])->assertOk();

        // copying the old attachment is checked against the school quota
        config(['saan.storage.quota_mb' => 1]);
        $d2 = $this->draft($this->tb, ['starts_on' => '2026-11-23', 'ends_on' => '2026-11-23']);
        $a2 = $this->as($this->tb)->postJson(self::L.'/requests/'.$d2['id'].'/attachment', ['version' => $d2['version'], 'file' => UploadedFile::fake()->create('a.pdf', 600, 'application/pdf')])->assertOk()->json();
        $s2 = $this->go($this->tb, $d2['id'], 'submit', $a2['version'])->assertOk()->json();
        $rej2 = $this->go($this->principal, $d2['id'], 'reject', $s2['version'], 'ส่งใหม่พร้อมแก้ไข')->assertOk()->json();
        $this->as($this->tb)->postJson(self::L.'/requests/'.$d2['id'].'/resubmit', $this->body(['starts_on' => '2026-11-23', 'ends_on' => '2026-11-23', 'version' => $rej2['version']]))->assertUnprocessable();
        $this->assertSame(0, DB::table('leave_requests')->where('resubmitted_from', $d2['id'])->count());
    }

    // ------------------------------------------------------------------ review fixes

    public function test_rows_without_derived_columns_are_normalised_and_counted(): void
    {
        // a row written by the old prototype route / seeder: status pending, days default 0, fiscal_year NULL
        $legacy = DB::table('leave_requests')->insertGetId(['school_id' => 1, 'teacher_id' => $this->tid['c'], 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-10', 'type' => 'personal', 'reason' => 'ข้อมูลเดิมจากต้นแบบ', 'status' => 'pending']);
        $row = $this->as($this->principal)->getJson(self::L.'/requests/'.$legacy)->assertOk()->json();
        $this->assertEquals(2.0, $row['days']);
        $this->assertSame(2570, $row['fiscal_year']);
        $this->assertSame(1, (int) $row['version']);
        // quota / report / "all leave" tab see the row, and approving keeps the days
        $rep = collect($this->getJson(self::L.'/quota/report?fiscal_year=2570')->json('rows'))->keyBy('teacher_id');
        $this->assertEquals(2.0, $rep[$this->tid['c']]['by_type']['personal']['pending']);
        $this->assertSame(1, $this->getJson(self::L.'/requests?scope=all&fiscal_year=2570&status=submitted')->json('total'));
        $this->assertEquals(2.0, $this->go($this->principal, $legacy, 'approve', $row['version'])->assertOk()->json('days'));
        $this->assertEquals(2.0, collect($this->as($this->tc)->getJson(self::L.'/quota?fiscal_year=2570')->json('types'))->firstWhere('code', 'personal')['used']);
        // a row that already has a fiscal year is "normalised": a zero-day range (weekend / holiday only) is left alone and never re-selected,
        // so 500+ such rows cannot starve the rows that still need a backfill
        $this->as($this->principal);
        $now = now();
        $bulk = [];
        for ($i = 0; $i < 520; $i++) $bulk[] = ['school_id' => 1, 'teacher_id' => $this->tid['b'], 'starts_on' => '2026-11-14', 'ends_on' => '2026-11-15', 'type' => 'personal', 'reason' => 'ข้อมูลเดิม (เสาร์-อาทิตย์)', 'status' => 'pending', 'fiscal_year' => 2570, 'days' => 0, 'created_at' => $now, 'updated_at' => $now];
        foreach (array_chunk($bulk, 100) as $chunk) DB::table('leave_requests')->insert($chunk);
        $late = DB::table('leave_requests')->insertGetId(['school_id' => 1, 'teacher_id' => $this->tid['b'], 'starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'type' => 'personal', 'reason' => 'ข้อมูลเดิมจากต้นแบบ', 'status' => 'pending']);
        $this->assertEquals(1.0, $this->getJson(self::L.'/requests/'.$late)->json('days'));
        $this->assertSame(2570, (int) DB::table('leave_requests')->where('id', $late)->value('fiscal_year'));
        $this->assertSame(520, DB::table('leave_requests')->where('days', 0)->count(), 'zero-day rows are not rewritten');
        // approving a pending row directly (no read first) also fills the columns
        $direct = DB::table('leave_requests')->insertGetId(['school_id' => 1, 'teacher_id' => $this->tid['b'], 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-17', 'type' => 'personal', 'reason' => 'ข้อมูลเดิมจากต้นแบบ', 'status' => 'pending']);
        $this->assertEquals(2.0, $this->go($this->principal, $direct, 'approve', 1)->assertOk()->json('days'));
        $this->assertSame(2570, (int) DB::table('leave_requests')->where('id', $direct)->value('fiscal_year'));
    }

    public function test_a_leave_that_has_started_cannot_be_cancelled_as_a_whole(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 08:00:00', 'Asia/Bangkok'));
        $l = $this->approved($this->ta, ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-06']); // Mon-Fri, today is Wednesday
        $this->assertEquals(5.0, $l['days']);
        $this->assertNotContains('request_cancel', $this->as($this->ta)->getJson(self::L.'/requests/'.$l['id'])->json('can'));
        $this->go($this->ta, $l['id'], 'request_cancel', $l['version'], 'ขอยกเลิกทั้งใบ')->assertConflict();
        $this->assertEquals(5.0, collect($this->as($this->ta)->getJson(self::L.'/quota?fiscal_year=2570')->json('types'))->firstWhere('code', 'personal')['used']);
        // a leave that starts tomorrow still can
        $f = $this->approved($this->tb, ['starts_on' => '2026-11-05', 'ends_on' => '2026-11-05']);
        $this->assertContains('request_cancel', $this->as($this->tb)->getJson(self::L.'/requests/'.$f['id'])->json('can'));
        $this->go($this->tb, $f['id'], 'request_cancel', $f['version'], 'ยกเลิกก่อนเริ่มลา')->assertOk();
    }

    public function test_calendar_count_types_count_every_day_and_the_mode_is_editable(): void
    {
        $this->holiday();
        // maternity counts continuous days: Mon 9 - Sun 15 Nov = 7 (the holiday on Wed is counted too)
        $m = $this->draft($this->ta, ['type' => 'maternity', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-15']);
        $this->assertEquals(7.0, $m['days']);
        $p = $this->getJson(self::L.'/preview?'.http_build_query($this->body(['type' => 'maternity', 'starts_on' => '2026-11-14', 'ends_on' => '2026-11-15'])))->assertOk()->json();
        $this->assertEquals(2.0, $p['days']);
        $this->assertSame('calendar', $p['count_mode']);
        // working types still skip weekends and the holiday
        $this->assertEquals(4.0, $this->getJson(self::L.'/preview?'.http_build_query($this->body(['starts_on' => '2026-11-09', 'ends_on' => '2026-11-15'])))->json('days'));
        $types = collect($this->getJson(self::L.'/types')->json('data'))->keyBy('code');
        $this->assertSame(['calendar', 'calendar', 'working'], [$types['maternity']['count_mode'], $types['ordination']['count_mode'], $types['personal']['count_mode']]);
        // an admin switches a type to calendar counting, and a bad value is refused
        $this->as($this->principal)->putJson(self::L.'/types/'.$types['personal']['id'], ['version' => $types['personal']['version'], 'label' => $types['personal']['label'], 'count_mode' => 'weekly'])->assertUnprocessable();
        $this->putJson(self::L.'/types/'.$types['personal']['id'], ['version' => $types['personal']['version'], 'label' => $types['personal']['label'], 'count_mode' => 'calendar'])->assertOk()->assertJsonPath('count_mode', 'calendar');
        $this->assertEquals(2.0, $this->as($this->ta)->getJson(self::L.'/preview?'.http_build_query($this->body(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15'])))->json('days'));
        // an approved calendar-counted leave takes the teacher away on the weekend too
        DB::table('duties')->insertGetId(['school_id' => 1, 'teacher_id' => $this->tid['b'], 'date' => '2026-11-14', 'starts_at' => '08:00', 'ends_at' => '09:00', 'location' => 'ประตู', 'status' => 'assigned', 'version' => 1]);
        $this->approved($this->tb, ['type' => 'maternity', 'starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']);
        $this->assertSame(true, $this->as($this->ta)->getJson(self::D.'/duties?from=2026-11-14&to=2026-11-14')->json('data.0.leave_conflict.on_leave'));
    }

    public function test_without_a_holiday_table_only_weekends_are_skipped(): void
    {
        $this->ddl(fn () => Schema::dropIfExists('acad_calendar_days')); // DDL commits on MariaDB/MySQL: TestCase::ddl rebuilds the schema after the test
        $p = $this->as($this->ta)->getJson(self::L.'/preview?'.http_build_query($this->body(['starts_on' => '2026-11-09', 'ends_on' => '2026-11-15'])))->assertOk()->json();
        $this->assertEquals(5.0, $p['days']);
        $this->assertSame(['weekend', 'weekend'], array_column($p['skipped'], 'reason'));
    }

    public function test_conflict_response_never_exposes_the_attachment_path(): void
    {
        $d = $this->draft($this->ta);
        $att = $this->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => $d['version'], 'file' => $this->pdf()])->assertOk()->json();
        $this->assertNotNull(DB::table('leave_requests')->where('id', $d['id'])->value('attachment_path'));
        $this->postJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => 1, 'file' => $this->pdf()])->assertConflict()->assertJsonPath('current.id', $d['id'])->assertJsonMissingPath('current.attachment_path');
        $this->putJson(self::L.'/requests/'.$d['id'], $this->body(['version' => 1]))->assertConflict()->assertJsonMissingPath('current.attachment_path');
        $this->go($this->ta, $d['id'], 'submit', 1)->assertConflict()->assertJsonMissingPath('current.attachment_path');
        $this->deleteJson(self::L.'/requests/'.$d['id'], ['version' => 1])->assertConflict()->assertJsonMissingPath('current.attachment_path');
        $this->deleteJson(self::L.'/requests/'.$d['id'].'/attachment', ['version' => 1])->assertConflict()->assertJsonMissingPath('current.attachment_path');
        $this->assertSame($att['version'], DB::table('leave_requests')->where('id', $d['id'])->value('version'));
    }

    public function test_mail_is_rendered_in_thai_without_the_english_template_text(): void
    {
        $html = (new LdNotification('leave.approve', 'ใบลาของคุณได้รับการอนุมัติ', ['บรรทัดหนึ่ง', 'บรรทัดสอง'], 'leave'))->toMail($this->ta)->render();
        $this->assertStringContainsString('บรรทัดหนึ่ง', $html);
        $this->assertStringContainsString('หากกดปุ่มไม่ได้', $html);
        $this->assertStringContainsString('/work#leave', $html);
        $this->assertStringNotContainsString('trouble clicking', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertStringNotContainsString('Regards', $html);
    }

    public function test_plain_text_part_of_the_mail_is_not_html_escaped(): void
    {
        $mail = (new LdNotification('leave.approve', 'x', ['เหตุผล: ไปงาน "บวช" & ทำบุญ \'ดี\''], 'leave'))->toMail($this->ta);
        $text = view($mail->view[1], $mail->viewData)->render();
        $this->assertStringContainsString('เหตุผล: ไปงาน "บวช" & ทำบุญ \'ดี\'', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringNotContainsString('&quot;', $text);
    }

    public function test_colleagues_only_see_that_a_teacher_is_on_leave_not_which_leave(): void
    {
        $this->duty(['date' => '2026-11-10']);
        $l = $this->approved($this->ta, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
        $url = self::D.'/duties?from=2026-11-10&to=2026-11-10';
        $other = $this->as($this->tb)->getJson($url)->json('data.0.leave_conflict');
        $this->assertSame(['on_leave' => true], $other);
        $this->assertSame($l['id'], $this->as($this->ta)->getJson($url)->json('data.0.leave_conflict.leave_id'));
        $this->assertSame($l['id'], $this->as($this->principal)->getJson($url)->json('data.0.leave_conflict.leave_id'));
    }

    public function test_cancelling_a_duty_with_a_cover_teacher_checks_availability_and_creates_the_cover_duty(): void
    {
        $duty = $this->duty(['date' => '2026-11-10']);
        // cover teacher on approved leave that day: refused
        $this->approved($this->tb, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
        $this->as($this->principal)->postJson(self::D.'/duties/'.$duty['id'].'/cancel', ['version' => $duty['version'], 'reason' => 'ครูเจ้าของเวรไม่สะดวก', 'replaced_by_teacher_id' => $this->tid['b']])->assertUnprocessable();
        // cover teacher with a clashing duty: refused
        $this->duty(['teacher_id' => $this->tid['c'], 'date' => '2026-11-10', 'starts_at' => '08:10', 'ends_at' => '08:40', 'location' => 'โรงอาหาร']);
        $this->postJson(self::D.'/duties/'.$duty['id'].'/cancel', ['version' => $duty['version'], 'reason' => 'ครูเจ้าของเวรไม่สะดวก', 'replaced_by_teacher_id' => $this->tid['c']])->assertUnprocessable();
        $this->assertSame('assigned', $this->dutyRow($duty['id'])->status);
        // a free cover teacher gets a real roster row
        $free = $this->duty(['teacher_id' => $this->tid['d'], 'date' => '2026-11-12']);
        $this->postJson(self::D.'/duties/'.$free['id'].'/cancel', ['version' => $free['version'], 'reason' => 'ครูเจ้าของเวรไม่สะดวก', 'replaced_by_teacher_id' => $this->tid['a']])->assertOk();
        $cover = DB::table('duties')->where('teacher_id', $this->tid['a'])->where('date', '2026-11-12')->where('status', 'assigned')->first();
        $this->assertNotNull($cover);
        $this->assertSame(['08:00', '08:30', 'ประตูโรงเรียน'], [$cover->starts_at, $cover->ends_at, $cover->location]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'duty.substitute')->where('entity_id', $cover->id)->count());
        // the cover duty is subject to the same clash and leave checks afterwards
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['a'], 'date' => '2026-11-12', 'starts_at' => '08:10', 'ends_at' => '08:20', 'location' => 'x'])->assertUnprocessable();
        $this->approved($this->ta, ['starts_on' => '2026-11-12', 'ends_on' => '2026-11-12']);
        $row = collect($this->getJson(self::D.'/duties?from=2026-11-12&to=2026-11-12&teacher_id='.$this->tid['a'])->json('data'))->firstWhere('id', $cover->id);
        $this->assertTrue($row['leave_conflict']['on_leave']);
    }

    public function test_other_school_rows_of_duties_swaps_and_types_are_invisible(): void
    {
        $now = now();
        $duty = DB::table('duties')->insertGetId(['school_id' => 2, 'teacher_id' => $this->tid['a'], 'date' => '2026-11-10', 'starts_at' => '08:00', 'ends_at' => '08:30', 'location' => 'โรงเรียนอื่น', 'status' => 'assigned', 'version' => 1]);
        $swap = DB::table('ld_duty_swaps')->insertGetId(['school_id' => 2, 'duty_id' => $duty, 'from_teacher_id' => $this->tid['a'], 'to_teacher_id' => $this->tid['b'], 'status' => 'accepted', 'open_duty_id' => $duty, 'version' => 1]);
        $type = DB::table('ld_leave_types')->insertGetId(['school_id' => 2, 'code' => 'other_school', 'label' => 'ประเภทของโรงเรียนอื่น', 'label_key' => 'ประเภทของโรงเรียนอื่น', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $body = ['teacher_id' => $this->tid['a'], 'date' => '2026-11-10', 'starts_at' => '08:00', 'ends_at' => '08:30', 'location' => 'x'];
        $this->as($this->principal)->getJson(self::D.'/duties?from=2026-11-10&to=2026-11-10')->assertJsonCount(0, 'data');
        $this->putJson(self::D.'/duties/'.$duty, ['version' => 1] + $body)->assertNotFound();
        $this->postJson(self::D.'/duties/'.$duty.'/cancel', ['version' => 1, 'reason' => 'ทดสอบข้ามโรงเรียน'])->assertNotFound();
        $this->deleteJson(self::D.'/duties/'.$duty, ['version' => 1])->assertNotFound();
        $this->as($this->ta)->postJson(self::D.'/duties/'.$duty.'/swaps', ['to_teacher_id' => $this->tid['b']])->assertNotFound();
        $this->as($this->principal)->getJson(self::D.'/swaps?scope=all')->assertJsonCount(0, 'data');
        $this->postJson(self::D.'/swaps/'.$swap.'/transition', ['action' => 'approve', 'version' => 1])->assertNotFound();
        $this->assertSame(1, (int) DB::table('duties')->where('id', $duty)->value('version'));
        $codes = array_column($this->getJson(self::L.'/types')->json('data'), 'code');
        $this->assertNotContains('other_school', $codes);
        $this->putJson(self::L.'/types/'.$type, ['version' => 1, 'label' => 'แก้ข้ามโรงเรียน'])->assertNotFound();
        $this->deleteJson(self::L.'/types/'.$type, ['version' => 1])->assertNotFound();
        $this->assertSame('ประเภทของโรงเรียนอื่น', DB::table('ld_leave_types')->where('id', $type)->value('label'));
    }

    // ------------------------------------------------------------------ duty roster

    public function test_duty_roster_crud_is_admin_or_principal_only_with_versions(): void
    {
        $body = ['teacher_id' => $this->tid['a'], 'date' => '2026-11-10', 'starts_at' => '08:00', 'ends_at' => '08:30', 'location' => 'ประตูโรงเรียน', 'kind' => 'morning'];
        $this->as($this->ta)->postJson(self::D.'/duties', $body)->assertForbidden();
        $c = $this->as($this->principal)->postJson(self::D.'/duties', $body)->assertCreated()->json();
        Notification::assertSentTo($this->ta, LdNotification::class, fn ($n) => $n->event === 'duty.assigned');
        $this->assertSame('assigned', $this->dutyRow($c['id'])->status);
        $this->assertSame('morning', $this->dutyRow($c['id'])->kind);
        // validation and overlap
        $this->postJson(self::D.'/duties', ['ends_at' => '07:59'] + $body)->assertUnprocessable();
        $this->postJson(self::D.'/duties', ['starts_at' => '08:15', 'ends_at' => '08:45'] + $body)->assertUnprocessable(); // overlaps A's own duty
        $this->postJson(self::D.'/duties', ['teacher_id' => 99999] + $body)->assertUnprocessable();
        $this->postJson(self::D.'/duties', ['kind' => 'nope'] + $body)->assertUnprocessable();
        $this->postJson(self::D.'/duties', ['starts_at' => '08:30', 'ends_at' => '09:00', 'location' => 'โรงอาหาร'] + $body)->assertCreated(); // back to back is fine
        // edit with version
        $u = $this->putJson(self::D.'/duties/'.$c['id'], ['version' => $c['version'], 'location' => 'ทางข้าม', 'teacher_id' => $this->tid['b']] + $body)->assertOk()->json();
        $this->assertSame($this->tid['b'], (int) $this->dutyRow($c['id'])->teacher_id);
        $this->putJson(self::D.'/duties/'.$c['id'], ['version' => $c['version']] + $body)->assertConflict();
        $this->as($this->tb)->putJson(self::D.'/duties/'.$c['id'], ['version' => $u['version']] + $body)->assertForbidden();
        // read by every staff member, with names
        $list = $this->as($this->tb)->getJson(self::D.'/duties?from=2026-11-09&to=2026-11-13')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertSame('ทางข้าม', $list[0]['location']);
        $this->assertTrue($list[0]['mine']);
        $this->getJson(self::D.'/duties?from=2026-11-13&to=2026-11-09')->assertUnprocessable();
        $this->getJson(self::D.'/duties?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
        $this->assertCount(1, $this->getJson(self::D.'/duties?from=2026-11-09&to=2026-11-13&teacher_id='.$this->tid['b'])->json('data'));
        // delete
        $this->as($this->principal)->deleteJson(self::D.'/duties/'.$c['id'], ['version' => $u['version']])->assertOk();
        $this->assertNull($this->dutyRow($c['id']));
        $this->getJson(self::D.'/teachers')->assertOk()->assertJsonFragment(['name' => 'ครู ก (สมมติ)']);
    }

    public function test_duty_cancel_requires_a_reason_and_is_final(): void
    {
        $c = $this->duty();
        $this->as($this->ta)->postJson(self::D.'/duties/'.$c['id'].'/cancel', ['version' => $c['version'], 'reason' => 'ไม่สะดวก'])->assertForbidden();
        $this->as($this->principal)->postJson(self::D.'/duties/'.$c['id'].'/cancel', ['version' => $c['version']])->assertUnprocessable();
        $this->postJson(self::D.'/duties/'.$c['id'].'/cancel', ['version' => $c['version'], 'reason' => 'ab'])->assertUnprocessable();
        $this->postJson(self::D.'/duties/'.$c['id'].'/cancel', ['version' => 99, 'reason' => 'งดกิจกรรมเนื่องจากฝนตก'])->assertConflict();
        $this->postJson(self::D.'/duties/'.$c['id'].'/cancel', ['version' => $c['version'], 'reason' => 'งดกิจกรรมเนื่องจากฝนตก', 'replaced_by_teacher_id' => $this->tid['a']])->assertUnprocessable();
        $x = $this->postJson(self::D.'/duties/'.$c['id'].'/cancel', ['version' => $c['version'], 'reason' => 'งดกิจกรรมเนื่องจากฝนตก', 'replaced_by_teacher_id' => $this->tid['b']])->assertOk()->json();
        $row = $this->dutyRow($c['id']);
        $this->assertSame(['cancelled', 'งดกิจกรรมเนื่องจากฝนตก', $this->tid['b']], [$row->status, $row->cancelled_reason, (int) $row->replaced_by_teacher_id]);
        Notification::assertSentTo($this->ta, LdNotification::class, fn ($n) => $n->event === 'duty.cancel');
        Notification::assertSentTo($this->tb, LdNotification::class, fn ($n) => $n->event === 'duty.cancel');
        Notification::assertNotSentTo($this->tc, LdNotification::class);
        $this->postJson(self::D.'/duties/'.$c['id'].'/cancel', ['version' => $x['version'], 'reason' => 'ยกเลิกซ้ำอีกครั้ง'])->assertConflict();
        $this->putJson(self::D.'/duties/'.$c['id'], ['version' => $x['version'], 'teacher_id' => $this->tid['a'], 'date' => '2026-11-10', 'starts_at' => '08:00', 'ends_at' => '08:30', 'location' => 'x'])->assertConflict();
        $this->as($this->ta)->postJson(self::D.'/duties/'.$c['id'].'/swaps', ['to_teacher_id' => $this->tid['b']])->assertConflict();
        // a cancelled duty no longer blocks the slot
        $this->duty();
        $this->assertSame('duty.cancel', DB::table('audit_events')->where('entity', 'duties')->where('entity_id', $c['id'])->where('action', 'duty.cancel')->value('action'));
    }

    public function test_duty_overlapping_an_approved_leave_is_flagged_and_blocked(): void
    {
        $duty = $this->duty(['date' => '2026-11-10']); // assigned first
        $this->as($this->ta)->getJson(self::D.'/duties?from=2026-11-09&to=2026-11-13')->assertJsonPath('data.0.leave_conflict', null);
        // a submitted leave is not yet a conflict, an approved one is
        $s = $this->submitted($this->ta, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
        $this->as($this->principal)->getJson(self::L.'/requests/'.$s['id'])->assertJsonPath('duty_conflicts.0.id', $duty['id']); // approver sees it before deciding
        $this->assertSame([], $this->as($this->ta)->getJson(self::L.'/requests/'.$s['id'])->json('duty_conflicts') ?? []);
        $this->go($this->principal, $s['id'], 'approve', $s['version'])->assertOk();
        $row = $this->as($this->tb)->getJson(self::D.'/duties?from=2026-11-09&to=2026-11-13')->json('data.0');
        $this->assertSame(['on_leave' => true], $row['leave_conflict']); // a colleague only learns the teacher is away
        $row = $this->as($this->principal)->getJson(self::D.'/duties?from=2026-11-09&to=2026-11-13')->json('data.0');
        $this->assertSame($s['id'], $row['leave_conflict']['leave_id']);
        // assigning a new duty on that day is refused
        $this->as($this->admin)->postJson(self::D.'/duties', ['teacher_id' => $this->tid['a'], 'date' => '2026-11-10', 'starts_at' => '13:00', 'ends_at' => '13:30', 'location' => 'ห้องสมุด'])->assertUnprocessable();
        // other days / other teachers are fine
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['a'], 'date' => '2026-11-12', 'starts_at' => '13:00', 'ends_at' => '13:30', 'location' => 'ห้องสมุด'])->assertCreated();
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['b'], 'date' => '2026-11-10', 'starts_at' => '13:00', 'ends_at' => '13:30', 'location' => 'ห้องสมุด'])->assertCreated();
        // cancelling the leave clears the flag
        $a = $this->getJson(self::L.'/requests/'.$s['id'])->json();
        $c = $this->go($this->ta, $s['id'], 'request_cancel', $a['version'], 'ยกเลิกทดสอบ')->json();
        $this->as($this->principal)->getJson(self::D.'/duties?from=2026-11-10&to=2026-11-10&teacher_id='.$this->tid['a'])->assertJsonPath('data.0.leave_conflict.status', 'cancel_requested'); // still on leave until approved
        $this->go($this->principal, $s['id'], 'approve_cancel', $c['version'])->assertOk();
        $this->as($this->tb)->getJson(self::D.'/duties?from=2026-11-10&to=2026-11-10&teacher_id='.$this->tid['a'])->assertJsonPath('data.0.leave_conflict', null);
    }

    public function test_half_day_leave_only_flags_duties_in_that_half(): void
    {
        $am = $this->duty(['date' => '2026-11-12', 'starts_at' => '08:00', 'ends_at' => '08:30']);
        $pm = $this->duty(['date' => '2026-11-12', 'starts_at' => '15:00', 'ends_at' => '15:30', 'location' => 'ทางข้าม']);
        $this->approved($this->ta, ['starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'half_day' => 'am']);
        $rows = collect($this->as($this->tb)->getJson(self::D.'/duties?from=2026-11-12&to=2026-11-12')->json('data'))->keyBy('id');
        $this->assertNotNull($rows[$am['id']]['leave_conflict']);
        $this->assertNull($rows[$pm['id']]['leave_conflict']);
    }

    // ------------------------------------------------------------------ overnight duties

    public function test_overnight_duty_may_cross_midnight_other_kinds_may_not(): void
    {
        $o = $this->duty(['date' => '2026-11-10', 'starts_at' => '18:00', 'ends_at' => '06:00', 'kind' => 'overnight', 'location' => 'หอพัก']);
        $this->assertSame('06:00', $this->dutyRow($o['id'])->ends_at);
        $this->as($this->admin)->postJson(self::D.'/duties', ['teacher_id' => $this->tid['b'], 'date' => '2026-11-10', 'starts_at' => '18:00', 'ends_at' => '06:00', 'location' => 'หอพัก', 'kind' => 'morning'])->assertUnprocessable();
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['b'], 'date' => '2026-11-10', 'starts_at' => '18:00', 'ends_at' => '18:00', 'location' => 'หอพัก', 'kind' => 'overnight'])->assertUnprocessable();
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['b'], 'date' => '2026-11-10', 'starts_at' => '09:00', 'ends_at' => '08:00', 'location' => 'ประตู', 'kind' => 'other'])->assertUnprocessable();
    }

    public function test_overnight_duty_clashes_with_duties_of_the_next_morning_both_ways(): void
    {
        $this->duty(['date' => '2026-11-10', 'starts_at' => '18:00', 'ends_at' => '06:00', 'kind' => 'overnight', 'location' => 'หอพัก']);
        $post = fn (array $o) => $this->as($this->admin)->postJson(self::D.'/duties', $o + ['teacher_id' => $this->tid['a'], 'location' => 'ประตู', 'kind' => 'morning']);
        $post(['date' => '2026-11-11', 'starts_at' => '05:00', 'ends_at' => '05:30'])->assertUnprocessable();   // inside 00:00-06:00 of the next day
        $post(['date' => '2026-11-11', 'starts_at' => '06:00', 'ends_at' => '06:30'])->assertCreated();          // starts exactly when the night ends
        $post(['date' => '2026-11-10', 'starts_at' => '17:00', 'ends_at' => '18:30'])->assertUnprocessable();   // same evening
        // the reverse: an existing early duty blocks a new overnight duty that would run into it
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['b'], 'date' => '2026-11-11', 'starts_at' => '05:00', 'ends_at' => '05:30', 'location' => 'ประตู', 'kind' => 'morning'])->assertCreated();
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['b'], 'date' => '2026-11-10', 'starts_at' => '20:00', 'ends_at' => '07:00', 'location' => 'หอพัก', 'kind' => 'overnight'])->assertUnprocessable();
        // an earlier day's overnight still spills into this day: a duty on 11-10 at 02:00 clashes with the night that began on 11-09
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['c'], 'date' => '2026-11-09', 'starts_at' => '22:00', 'ends_at' => '04:00', 'location' => 'หอพัก', 'kind' => 'overnight'])->assertCreated();
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['c'], 'date' => '2026-11-10', 'starts_at' => '03:00', 'ends_at' => '03:30', 'location' => 'ประตู', 'kind' => 'morning'])->assertUnprocessable();
    }

    public function test_overnight_duty_checks_leave_on_the_following_day_and_flags_it(): void
    {
        // approved leave on Wed 2026-11-11 blocks a night that started on Tue 10th
        $this->approved($this->tb, ['starts_on' => '2026-11-11', 'ends_on' => '2026-11-11']);
        $this->as($this->admin)->postJson(self::D.'/duties', ['teacher_id' => $this->tid['b'], 'date' => '2026-11-10', 'starts_at' => '18:00', 'ends_at' => '06:00', 'location' => 'หอพัก', 'kind' => 'overnight'])->assertUnprocessable();
        // leave approved after the night was assigned: the duty list flags it and the approver sees it as a conflict
        $night = $this->duty(['teacher_id' => $this->tid['c'], 'date' => '2026-11-10', 'starts_at' => '18:00', 'ends_at' => '06:00', 'kind' => 'overnight', 'location' => 'หอพัก']);
        $s = $this->submitted($this->tc, ['starts_on' => '2026-11-11', 'ends_on' => '2026-11-11']);
        $this->as($this->principal)->getJson(self::L.'/requests/'.$s['id'])->assertJsonPath('duty_conflicts.0.id', $night['id']);
        $this->go($this->principal, $s['id'], 'approve', $s['version'])->assertOk();
        $row = $this->as($this->principal)->getJson(self::D.'/duties?from=2026-11-10&to=2026-11-10&teacher_id='.$this->tid['c'])->json('data.0');
        $this->assertSame($s['id'], $row['leave_conflict']['leave_id']);
        // a leave on the evening only (Tue 10th) is a conflict as well
        $this->approved($this->ta, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['a'], 'date' => '2026-11-10', 'starts_at' => '18:00', 'ends_at' => '06:00', 'location' => 'หอพัก', 'kind' => 'overnight'])->assertUnprocessable();
        // a leave two days later does not touch the night
        $this->postJson(self::D.'/duties', ['teacher_id' => $this->tid['a'], 'date' => '2026-11-12', 'starts_at' => '18:00', 'ends_at' => '06:00', 'location' => 'หอพัก', 'kind' => 'overnight'])->assertCreated();
    }

    public function test_swap_approve_locks_the_duty_before_the_swap_like_cancel_does(): void
    {
        $duty = $this->duty();
        $s = $this->askSwap($this->ta, $duty['id'], $this->tid['b'])->assertCreated()->json();
        $acc = $this->sw($this->tb, $s['id'], 'accept', $s['version'])->assertOk()->json();
        $order = [];
        DB::listen(function ($q) use (&$order) {
            if (preg_match('/^select \* from [`"](duties|ld_duty_swaps)[`"] where [`"]school_id[`"] = \? and [`"]id[`"] = \?/', $q->sql, $m)) $order[] = $m[1];
        });
        $this->sw($this->principal, $s['id'], 'approve', $acc['version'])->assertOk();
        $locks = $order;
        $firstDuty = array_search('duties', $locks, true);
        $this->assertNotFalse($firstDuty);
        $swapAfter = array_keys(array_filter($locks, fn ($t, $i) => $t === 'ld_duty_swaps' && $i > $firstDuty, ARRAY_FILTER_USE_BOTH));
        $this->assertNotEmpty($swapAfter, 'the locking read of the swap row comes after the duty row lock');
        $this->assertSame($this->tid['b'], (int) $this->dutyRow($duty['id'])->teacher_id);
    }

    // ------------------------------------------------------------------ swaps

    private function askSwap(User $from, int $dutyId, int $toTeacher, string $note = 'ติดธุระช่วงเช้า')
    {
        return $this->as($from)->postJson(self::D.'/duties/'.$dutyId.'/swaps', ['to_teacher_id' => $toTeacher, 'note' => $note]);
    }

    private function sw(User $u, int $id, string $action, int $version, ?string $reason = null)
    {
        return $this->as($u)->postJson(self::D.'/swaps/'.$id.'/transition', ['action' => $action, 'version' => $version, 'reason' => $reason]);
    }

    public function test_swap_flow_request_accept_approve_reassigns_the_duty(): void
    {
        $duty = $this->duty();
        // only the duty owner may ask, and not for oneself
        $this->askSwap($this->tb, $duty['id'], $this->tid['c'])->assertForbidden();
        $this->askSwap($this->ta, $duty['id'], $this->tid['a'])->assertUnprocessable();
        $this->askSwap($this->ta, $duty['id'], 99999)->assertUnprocessable();
        $s = $this->askSwap($this->ta, $duty['id'], $this->tid['b'])->assertCreated()->json();
        $this->assertSame('requested', $s['status']);
        $this->assertSame($this->tid['b'], $s['to_teacher_id']);
        Notification::assertSentTo($this->tb, LdNotification::class, fn ($n) => $n->event === 'swap.request');
        Notification::assertNotSentTo($this->tc, LdNotification::class);
        // one open swap per duty
        $this->askSwap($this->ta, $duty['id'], $this->tid['c'])->assertConflict();
        $row = $this->as($this->tb)->getJson(self::D.'/duties?from=2026-11-10&to=2026-11-10')->json('data.0');
        $this->assertSame('requested', $row['open_swap']['status']);
        $this->assertNotContains('swap', $this->as($this->ta)->getJson(self::D.'/duties?from=2026-11-10&to=2026-11-10')->json('data.0.can'));
        // actor rules while requested
        $this->sw($this->ta, $s['id'], 'accept', $s['version'])->assertForbidden();    // requester cannot accept
        $this->sw($this->tc, $s['id'], 'accept', $s['version'])->assertForbidden();    // stranger
        $this->sw($this->principal, $s['id'], 'approve', $s['version'])->assertConflict(); // not accepted yet
        $this->sw($this->tb, $s['id'], 'approve', $s['version'])->assertForbidden();
        $this->sw($this->tb, $s['id'], 'cancel', $s['version'])->assertForbidden();    // only the requester cancels
        $this->sw($this->tb, $s['id'], 'accept', 99)->assertConflict();
        $this->assertSame(['accept', 'decline'], $this->as($this->tb)->getJson(self::D.'/swaps')->json('data.0.can'));
        $this->assertSame(['cancel'], $this->as($this->ta)->getJson(self::D.'/swaps')->json('data.0.can'));
        $this->assertSame([], $this->as($this->tc)->getJson(self::D.'/swaps')->json('data'));

        $acc = $this->sw($this->tb, $s['id'], 'accept', $s['version'])->assertOk()->json();
        $this->assertSame('accepted', $acc['status']);
        Notification::assertSentTo($this->ta, LdNotification::class, fn ($n) => $n->event === 'swap.accept');
        Notification::assertSentTo($this->principal, LdNotification::class, fn ($n) => $n->event === 'swap.accept');
        $this->assertSame($this->tid['a'], (int) $this->dutyRow($duty['id'])->teacher_id); // not yet reassigned
        $this->sw($this->tb, $s['id'], 'accept', $acc['version'])->assertConflict();
        $this->sw($this->tb, $s['id'], 'decline', $acc['version'])->assertConflict();
        $this->sw($this->tc, $s['id'], 'approve', $acc['version'])->assertForbidden();   // a teacher cannot approve
        $this->sw($this->ta, $s['id'], 'approve', $acc['version'])->assertForbidden();
        $this->assertSame(1, $this->as($this->principal)->getJson(self::D.'/swaps?scope=approval')->json('data.0.id') === $s['id'] ? 1 : 0);
        $this->as($this->ta)->getJson(self::D.'/swaps?scope=approval')->assertForbidden();
        $this->sw($this->principal, $s['id'], 'reject', $acc['version'])->assertUnprocessable(); // reason required

        $ap = $this->sw($this->principal, $s['id'], 'approve', $acc['version'], 'อนุมัติ')->assertOk()->json();
        $this->assertSame('approved', $ap['status']);
        $this->assertSame($this->tid['b'], (int) $this->dutyRow($duty['id'])->teacher_id); // reassigned
        $this->assertSame(2, (int) $this->dutyRow($duty['id'])->version);
        Notification::assertSentTo($this->ta, LdNotification::class, fn ($n) => $n->event === 'swap.approve');
        Notification::assertSentTo($this->tb, LdNotification::class, fn ($n) => $n->event === 'swap.approve');
        $this->assertNull(DB::table('ld_duty_swaps')->where('id', $s['id'])->value('open_duty_id'));
        $this->assertSame($this->principal->id, (int) DB::table('ld_duty_swaps')->where('id', $s['id'])->value('decided_by'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'duty.reassign')->where('entity_id', $duty['id'])->count());
        // closed: no more transitions; the duty can be swapped again by its new owner
        foreach (['accept', 'decline', 'cancel', 'approve', 'reject'] as $act) $this->sw($this->principal, $s['id'], $act, $ap['version'], 'เหตุผลทดสอบ')->assertStatus(in_array($act, ['accept', 'decline', 'cancel'], true) ? 403 : 409);
        $this->askSwap($this->tb, $duty['id'], $this->tid['c'])->assertCreated();
    }

    public function test_swap_decline_cancel_reject_and_auto_cancel(): void
    {
        $duty = $this->duty();
        $s = $this->askSwap($this->ta, $duty['id'], $this->tid['b'])->assertCreated()->json();
        $dec = $this->sw($this->tb, $s['id'], 'decline', $s['version'])->assertOk()->json();
        $this->assertSame('declined', $dec['status']);
        Notification::assertSentTo($this->ta, LdNotification::class, fn ($n) => $n->event === 'swap.decline');
        $this->assertSame($this->tid['a'], (int) $this->dutyRow($duty['id'])->teacher_id);

        $s2 = $this->askSwap($this->ta, $duty['id'], $this->tid['c'])->assertCreated()->json(); // closed swap frees the duty
        $can = $this->sw($this->ta, $s2['id'], 'cancel', $s2['version'])->assertOk()->json();
        $this->assertSame('cancelled', $can['status']);
        Notification::assertSentTo($this->tc, LdNotification::class, fn ($n) => $n->event === 'swap.cancel');

        $s3 = $this->askSwap($this->ta, $duty['id'], $this->tid['b'])->assertCreated()->json();
        $acc = $this->sw($this->tb, $s3['id'], 'accept', $s3['version'])->json();
        $cancelAccepted = $this->sw($this->ta, $s3['id'], 'cancel', $acc['version'])->assertOk()->json(); // requester may still back out
        $this->assertSame('cancelled', $cancelAccepted['status']);

        $s4 = $this->askSwap($this->ta, $duty['id'], $this->tid['b'])->assertCreated()->json();
        $acc4 = $this->sw($this->tb, $s4['id'], 'accept', $s4['version'])->json();
        $rej = $this->sw($this->admin, $s4['id'], 'reject', $acc4['version'], 'ครูแทนต้องอยู่ประชุม')->assertOk()->json();
        $this->assertSame('rejected', $rej['status']);
        $this->assertSame('ครูแทนต้องอยู่ประชุม', $rej['decision_note']);
        $this->assertSame($this->tid['a'], (int) $this->dutyRow($duty['id'])->teacher_id);
        Notification::assertSentTo($this->tb, LdNotification::class, fn ($n) => $n->event === 'swap.reject');

        // cancelling the duty itself closes an open swap
        $s5 = $this->askSwap($this->ta, $duty['id'], $this->tid['c'])->assertCreated()->json();
        $dv = $this->dutyRow($duty['id'])->version;
        $this->as($this->principal)->postJson(self::D.'/duties/'.$duty['id'].'/cancel', ['version' => $dv, 'reason' => 'งดเวรวันนี้'])->assertOk();
        $this->assertSame('cancelled', DB::table('ld_duty_swaps')->where('id', $s5['id'])->value('status'));
        $this->assertNull(DB::table('ld_duty_swaps')->where('id', $s5['id'])->value('open_duty_id'));
        $this->sw($this->tc, $s5['id'], 'accept', $s5['version'] + 1)->assertConflict();
    }

    public function test_swap_availability_and_self_approval_rules(): void
    {
        $duty = $this->duty();
        // B is on approved leave that day: cannot be asked
        $this->approved($this->tb, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
        $this->askSwap($this->ta, $duty['id'], $this->tid['b'])->assertUnprocessable();
        // C already has a clashing duty
        $this->duty(['teacher_id' => $this->tid['c'], 'starts_at' => '08:10', 'ends_at' => '08:40', 'location' => 'โรงอาหาร']);
        $this->askSwap($this->ta, $duty['id'], $this->tid['c'])->assertUnprocessable();
        // an approver who is one of the two teachers may not approve their own swap
        $d2 = $this->duty(['teacher_id' => $this->tid['d'], 'date' => '2026-11-12', 'starts_at' => '09:00', 'ends_at' => '09:30', 'location' => 'ห้องสมุด']);
        $s = $this->askSwap($this->adminTeacher, $d2['id'], $this->tid['a'])->assertCreated()->json();
        $acc = $this->sw($this->ta, $s['id'], 'accept', $s['version'])->assertOk()->json();
        $this->sw($this->adminTeacher, $s['id'], 'approve', $acc['version'])->assertForbidden();
        $this->assertSame(['approve', 'reject'], $this->as($this->principal)->getJson(self::D.'/swaps?scope=approval')->json('data.0.can'));
        // A takes a leave after accepting: approval is refused because the slot is no longer free
        $this->approved($this->ta, ['starts_on' => '2026-11-12', 'ends_on' => '2026-11-12']);
        $this->sw($this->principal, $s['id'], 'approve', $acc['version'])->assertUnprocessable();
        $this->assertSame($this->tid['d'], (int) $this->dutyRow($d2['id'])->teacher_id);
        // past duties cannot be swapped
        $past = DB::table('duties')->insertGetId(['school_id' => 1, 'teacher_id' => $this->tid['a'], 'date' => '2026-10-30', 'starts_at' => '08:00', 'ends_at' => '08:30', 'location' => 'x', 'status' => 'assigned', 'version' => 1]);
        $this->askSwap($this->ta, $past, $this->tid['c'])->assertConflict();
        // a duty may not be edited while a swap is open
        $d3 = $this->duty(['teacher_id' => $this->tid['a'], 'date' => '2026-11-17', 'starts_at' => '08:00', 'ends_at' => '08:30', 'location' => 'ประตู']);
        $this->askSwap($this->ta, $d3['id'], $this->tid['c'])->assertCreated();
        $this->as($this->principal)->putJson(self::D.'/duties/'.$d3['id'], ['version' => $d3['version'], 'teacher_id' => $this->tid['b'], 'date' => '2026-11-17', 'starts_at' => '08:00', 'ends_at' => '08:30', 'location' => 'ประตู'])->assertConflict();
        $this->deleteJson(self::D.'/duties/'.$d3['id'], ['version' => $d3['version']])->assertConflict(); // has swap history
    }

    public function test_unique_violation_on_the_open_swap_index_becomes_409_not_500(): void
    {
        $duty = $this->duty();
        // a stale open flag on a closed row: the code-level check passes, the unique index still fires
        DB::table('ld_duty_swaps')->insert(['school_id' => 1, 'duty_id' => $duty['id'], 'from_teacher_id' => $this->tid['a'], 'to_teacher_id' => $this->tid['b'], 'status' => 'cancelled', 'open_duty_id' => $duty['id'], 'version' => 1]);
        $this->askSwap($this->ta, $duty['id'], $this->tid['c'])->assertConflict();
        $this->assertSame(1, DB::table('ld_duty_swaps')->count());
        // same for leave types: a duplicate code that slips past the code check
        $this->as($this->principal)->getJson(self::L.'/types')->assertOk();
        $this->postJson(self::L.'/types', ['code' => 'sick', 'label' => 'ลาป่วยอีกแบบ'])->assertUnprocessable(); // the in-code duplicate check answers 422 first
    }

    public function test_guard_turns_only_unique_violations_into_409(): void
    {
        $g = new class {
            use \Saan\Modules\LeaveDuty\Common;

            public function run(\Closure $f): mixed
            {
                return $this->guard($f);
            }
        };
        $row = ['school_id' => 1, 'duty_id' => 1, 'from_teacher_id' => 1, 'to_teacher_id' => 2, 'status' => 'requested', 'open_duty_id' => 77, 'version' => 1];
        DB::table('ld_duty_swaps')->insert($row);
        try {
            $g->run(fn () => DB::table('ld_duty_swaps')->insert($row));
            $this->fail('duplicate open_duty_id was accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        // NOT NULL is a real bug and must surface as an exception (HTTP 500), not be disguised as a duplicate
        $this->expectException(\Illuminate\Database\QueryException::class);
        $g->run(fn () => DB::table('ld_duty_swaps')->insert(['to_teacher_id' => null] + $row));
    }

    // ------------------------------------------------------------------ module wiring

    public function test_module_is_registered_with_the_three_menu_entries_per_role(): void
    {
        $reg = app(\App\Support\Modules\ModuleRegistry::class);
        $this->assertTrue($reg->active('leave-duty'));
        $keys = fn (string $role) => array_column($reg->workMenu($role), 'key');
        $this->assertEqualsCanonicalizing(['leave', 'duty'], array_values(array_intersect($keys('teacher'), ['leave', 'leave-approve', 'duty'])));
        $this->assertEqualsCanonicalizing(['leave', 'leave-approve', 'duty'], array_values(array_intersect($keys('principal'), ['leave', 'leave-approve', 'duty'])));
        $this->assertEqualsCanonicalizing(['leave', 'leave-approve', 'duty'], array_values(array_intersect($keys('admin'), ['leave', 'leave-approve', 'duty'])));
        $labels = array_column($reg->workMenu('principal'), 'label', 'key');
        $this->assertSame('ใบลา', $labels['leave']);
        $this->assertSame('อนุมัติใบลา', $labels['leave-approve']);
        $this->assertSame('เวร', $labels['duty']);
        $this->assertContains('assets/modules/leave-duty/leave-duty.js', $reg->workAssets());
        $this->as($this->ta)->get('/work')->assertOk()->assertSee('assets/modules/leave-duty/leave-duty.js', false)->assertSee('ใบลา')->assertDontSee('อนุมัติใบลา');
        $this->as($this->principal)->get('/work')->assertOk()->assertSee('อนุมัติใบลา');
    }

    public function test_every_mutating_route_requires_login_and_a_staff_role(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson(self::L.'/requests', $this->body())->assertUnauthorized();
        $this->postJson(self::D.'/duties', [])->assertUnauthorized();
        $this->getJson(self::D.'/duties')->assertUnauthorized();
        $editor = $this->mk('editor', null);
        $this->as($editor)->getJson(self::L.'/types')->assertForbidden();
        $this->getJson(self::D.'/duties')->assertForbidden();
    }

    public function test_legacy_prototype_rows_keep_working_after_the_expand_migration(): void
    {
        $legacy = DB::table('duties')->insertGetId(['school_id' => 1, 'teacher_id' => $this->tid['a'], 'date' => '2026-11-10', 'starts_at' => '12:00', 'ends_at' => '12:40', 'location' => 'โรงอาหาร', 'status' => 'assigned']);
        $row = $this->as($this->ta)->getJson(self::D.'/duties?from=2026-11-10&to=2026-11-10')->json('data.0');
        $this->assertSame($legacy, $row['id']);
        $this->assertSame('other', $row['kind']);
        $this->assertSame(1, $row['version']);
        $this->assertContains('swap', $row['can']);
    }

    public function test_leave_list_does_not_query_resubmissions_per_rejected_row(): void
    {
        foreach (['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'] as $day) {
            $s = $this->submitted($this->ta, ['starts_on' => $day, 'ends_on' => $day]);
            $this->go($this->principal, $s['id'], 'reject', $s['version'], 'ไม่สะดวก')->assertOk();
        }
        $n = 0;
        DB::listen(function ($q) use (&$n) { if (str_contains($q->sql, 'resubmitted_from')) $n++; });
        $rows = $this->as($this->ta)->getJson(self::L.'/requests?per_page=100')->assertOk()->json('data');
        $this->assertCount(5, $rows);
        foreach ($rows as $r) $this->assertContains('resubmit', $r['can']);
        $this->assertSame(1, $n, 'one children lookup for the page, none per rejected row');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('leave_requests', 'leave_requests_resub'));
    }

    public function test_work_pages_bind_delegated_listeners_to_the_page_signal(): void
    {
        // SaanWork reuses #work-view: a listener on root that outlives the page swallows clicks after in-app navigation
        foreach (['leave-duty/leave-duty.js', 'timetable/timetable.js'] as $js) {
            $src = file_get_contents(public_path('assets/modules/'.$js));
            $this->assertGreaterThan(0, substr_count($src, 'root.addEventListener('), $js);
            $this->assertSame(substr_count($src, 'root.addEventListener('), substr_count($src, '}, {signal: ctx.signal});'), $js.': every root listener must carry the page abort signal');
        }
    }
}
