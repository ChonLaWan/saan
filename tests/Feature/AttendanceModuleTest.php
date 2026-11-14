<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        DB::table('attendances')->delete();
        $this->day = $this->weekday(0);
    }

    private function weekday(int $ago): string
    {
        // the $ago-th school day before today (0 = today or the last weekday), counted in weekdays so
        // different $ago values never collapse onto the same Friday when the suite runs on a weekend
        $d = CarbonImmutable::now('Asia/Bangkok')->startOfDay();
        while ($d->isWeekend()) $d = $d->subDay();
        for ($i = 0; $i < $ago; $i++) {
            $d = $d->subDay();
            while ($d->isWeekend()) $d = $d->subDay();
        }
        return $d->toDateString();
    }

    private function user(string $role, array $extra = []): User
    {
        $this->n++;
        return User::forceCreate(['name' => 'Fictional '.$role.$this->n, 'email' => $role.$this->n.'@school.test', 'password' => 'Original-Password-2026', 'role' => $role,
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1] + $extra);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u);
    }

    private function admin(): User { return $this->user('admin'); }

    private function ids(int $classroom = 1): array { return DB::table('students')->where('classroom_id', $classroom)->orderBy('id')->pluck('id')->all(); }

    private function sheet(int $classroom = 1, ?string $date = null)
    {
        return $this->getJson('/api/attendance/sheet?classroom_id='.$classroom.'&date='.($date ?? $this->day));
    }

    private function save(array $rows, array $extra = [], int $classroom = 1, ?string $date = null, int $dayVersion = 0)
    {
        return $this->postJson('/api/attendance/sheet', ['classroom_id' => $classroom, 'date' => $date ?? $this->day, 'class_day_version' => $dayVersion, 'rows' => $rows] + $extra);
    }

    private function row(int $sid, string $status, int $version = 0, array $extra = []): array
    {
        return ['student_id' => $sid, 'status' => $status, 'version' => $version] + $extra;
    }

    public function test_a_teacher_cannot_read_the_history_of_a_graduated_pupil_still_in_the_old_classroom(): void
    {
        [$a] = $this->ids();
        $teacher = $this->user('teacher', ['teacher_id' => 1, 'classroom_id' => 1]);
        $this->as($teacher)->getJson('/api/attendance/reports/student/'.$a)->assertOk();
        DB::table('students')->where('id', $a)->update(['status' => 'graduated']);
        $this->as($teacher)->getJson('/api/attendance/reports/student/'.$a)->assertNotFound();
        $this->as($this->admin())->getJson('/api/attendance/reports/student/'.$a)->assertOk();
    }

    public function test_a_teacher_is_scoped_by_the_term_of_the_date_and_writes_only_in_the_current_term(): void
    {
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2567, 'term' => 1, 'starts_on' => '2024-05-01', 'ends_on' => '2025-03-31']);
        $old = '2024-09-02'; // a Monday of the earlier year
        [$a] = $this->ids();
        $teacher = $this->user('teacher', ['teacher_id' => 1, 'classroom_id' => 1]); // homeroom today, never taught classroom 1 in term 2
        $this->as($teacher);
        $this->sheet(1, $old)->assertForbidden();
        $this->getJson('/api/attendance/reports/month?classroom_id=1&month=2024-09')->assertForbidden();
        $this->get('/api/attendance/export?type=month&classroom_id=1&month=2024-09')->assertForbidden();
        $this->save([$this->row($a, 'absent')], ['reason' => 'ย้อนหลัง'], 1, $old)->assertForbidden();
        $this->assertSame(0, DB::table('attendances')->count());
        // the daily report (and its export) of that day lists no room for this teacher either, while today's report still shows the homeroom
        $this->assertSame([], $this->getJson('/api/attendance/reports/daily?date='.$old)->assertOk()->json('classrooms'));
        $this->assertContains(1, array_column($this->getJson('/api/attendance/reports/daily?date='.$this->day)->assertOk()->json('classrooms'), 'classroom_id'));
        // a teacher who taught the room in the old term may READ it, but never write into a past term
        DB::table('lessons')->insert(['school_id' => 1, 'term_id' => 2, 'teacher_id' => 1, 'classroom_id' => 1, 'period_id' => DB::table('bell_periods')->value('id'), 'weekday' => 1, 'room' => 'x', 'subject' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->sheet(1, $old)->assertOk();
        $this->getJson('/api/attendance/reports/month?classroom_id=1&month=2024-09')->assertOk();
        $this->save([$this->row($a, 'absent')], ['reason' => 'ย้อนหลัง'], 1, $old)->assertForbidden();
        $this->assertSame(0, DB::table('attendances')->count());
        // the current term still works for the same teacher; admin keeps the past-term path (with a reason)
        $this->save([$this->row($a, 'absent')])->assertOk();
        $this->as($this->admin());
        $this->save([$this->row($a, 'absent')], ['reason' => 'แก้ข้อมูลปีก่อน'], 1, $old)->assertOk();
        $this->getJson('/api/attendance/reports/month?classroom_id=1&month=2024-09')->assertOk();
    }

    public function test_a_teacher_can_still_record_a_day_that_lies_outside_every_term(): void
    {
        // term dates that do not cover today (not entered exactly, classes held after the last day): no other term owns the day, so the
        // "never write into another term" rule does not stop the daily roll call; the row keeps term_id null as before
        DB::table('academic_terms')->update(['starts_on' => '2020-05-01', 'ends_on' => '2020-09-30']);
        [$a] = $this->ids();
        $this->as($this->user('teacher', ['teacher_id' => 1, 'classroom_id' => 1]));
        $this->sheet()->assertOk();
        $this->save([$this->row($a, 'absent')])->assertOk();
        $this->assertSame(1, DB::table('attendances')->count());
        $this->assertNull(DB::table('attendances')->where('student_id', $a)->value('term_id'));
    }

    public function test_sheet_defaults_to_present_and_save_records_exceptions_with_class_day(): void
    {
        $this->as($this->admin());
        [$a, $b] = $this->ids();
        $s = $this->sheet()->assertOk()->json();
        $this->assertCount(8, $s['students']);
        $this->assertSame(['present'], array_values(array_unique(array_column($s['students'], 'status'))));
        $this->assertSame(0, $s['class_day']['version']);
        $out = $this->save([$this->row($a, 'absent', 0, ['note' => 'ไข้หวัด']), $this->row($b, 'present')], ['confirm' => true])->assertOk()->json();
        $this->assertSame(1, $out['saved']['created']);
        $this->assertTrue($out['sheet']['class_day']['confirmed']);
        $this->assertSame(1, $out['sheet']['class_day']['version']);
        $mine = collect($out['sheet']['students'])->firstWhere('id', $a);
        $this->assertSame('absent', $mine['status']);
        $this->assertSame(1, $mine['version']);
        $this->assertSame(1, DB::table('attendances')->count(), 'present rows without a note are not stored');
        $this->assertNotNull(DB::table('attendances')->first()->term_id);
        // history snapshot: the room of the mark and the roster size the day was confirmed for (read by the API attendance summary)
        $this->assertSame(1, (int) DB::table('attendances')->first()->classroom_id);
        $this->assertSame(8, (int) DB::table('att_class_days')->first()->student_count);
        $this->assertTrue(DB::table('audit_events')->where('action', 'attendance.sheet_saved')->exists());
        $this->save([$this->row($a, 'bogus')])->assertUnprocessable();
    }

    public function test_ops006_stale_screen_cannot_overwrite_and_nothing_is_written(): void
    {
        $teacherA = $this->admin();
        $teacherB = $this->admin();
        [$a, $b, $c] = $this->ids();
        $this->as($teacherA);
        $opened = $this->sheet()->assertOk()->json(); // both screens open with version 0
        $this->assertSame(0, collect($opened['students'])->firstWhere('id', $a)['version']);
        $this->as($teacherB)->save([$this->row($a, 'absent')])->assertOk();

        $res = $this->as($teacherA)->save([$this->row($a, 'late', 0), $this->row($b, 'absent', 0)])->assertStatus(409);
        $conflicts = $res->json('current.conflicts');
        $this->assertCount(1, $conflicts);
        $this->assertSame($a, $conflicts[0]['student_id']);
        $this->assertSame('late', $conflicts[0]['mine']['status']);
        $this->assertSame('absent', $conflicts[0]['current']['status']);
        $this->assertSame($teacherB->name, $conflicts[0]['current']['changed_by']);
        $this->assertSame(1, $conflicts[0]['current']['version']);
        $this->assertSame('absent', DB::table('attendances')->where('student_id', $a)->value('status'), 'stale row untouched');
        $this->assertNull(DB::table('attendances')->where('student_id', $b)->first(), 'non-conflicting row of a rejected save is not written either');
        $this->assertSame(0, DB::table('att_corrections')->count());

        // choose-and-resave with the fresh version
        $this->save([$this->row($a, 'late', 1), $this->row($b, 'absent', 0)])->assertOk();
        $this->assertSame('late', DB::table('attendances')->where('student_id', $a)->value('status'));
        $this->assertSame(1, DB::table('att_corrections')->count());

        // a row that was stale because somebody recorded it first (expected 0, exists) is also a conflict
        $this->save([$this->row($c, 'sick', 0)])->assertOk();
        $this->save([$this->row($c, 'leave', 0)])->assertStatus(409)->assertJsonPath('conflicts.0.current.status', 'sick');
    }

    public function test_stale_class_day_version_is_a_conflict(): void
    {
        $this->as($this->admin());
        $this->sheet()->assertOk();
        $this->as($this->admin())->save([], ['confirm' => true])->assertOk();
        $r = $this->save([], ['confirm' => true], 1, null, 0)->assertStatus(409);
        $this->assertSame([], $r->json('conflicts'));
        $this->assertSame(1, $r->json('class_day.version'));
        $this->assertNotNull($r->json('class_day.confirmed_by'));
        $this->save([], ['confirm' => true], 1, null, 1)->assertOk()->assertJsonPath('sheet.class_day.version', 2);
    }

    public function test_correction_log_records_old_and_new_status_and_reason(): void
    {
        $user = $this->admin();
        $this->as($user);
        [$a, $b] = $this->ids();
        $this->save([$this->row($a, 'absent'), $this->row($b, 'late')])->assertOk();
        $this->assertSame(0, DB::table('att_corrections')->count());
        // same day change: no reason needed
        $this->save([$this->row($a, 'sick', 1, ['note' => 'ใบรับรองแพทย์']), $this->row($b, 'late', 1)])->assertOk();
        $c = DB::table('att_corrections')->get();
        $this->assertCount(1, $c, 'unchanged status is not logged');
        $this->assertSame(['absent', 'sick', $user->id], [$c[0]->old_status, $c[0]->new_status, (int) $c[0]->changed_by]);
        $this->assertSame($this->day, substr($c[0]->date, 0, 10));
        $this->save([$this->row($a, 'present', 2)], ['reason' => 'มาสาย พบที่โรงเรียน'])->assertOk();
        $this->assertSame('มาสาย พบที่โรงเรียน', DB::table('att_corrections')->orderByDesc('id')->value('reason'));
        $this->assertSame(1, DB::table('attendances')->where('student_id', $a)->value('version') - 2);
        $hist = $this->getJson('/api/attendance/reports/student/'.$a)->assertOk()->json();
        $this->assertCount(2, $hist['rows'][0]['corrections']);
    }

    public function test_edit_window_requires_reason_and_is_configurable(): void
    {
        $this->as($this->admin());
        [$a] = $this->ids();
        $old = $this->weekday(10);
        $this->assertTrue($this->sheet(1, $old)->assertOk()->json('needs_reason'));
        $this->save([$this->row($a, 'absent')], [], 1, $old)->assertUnprocessable();
        $this->assertSame(0, DB::table('attendances')->count());
        $this->save([$this->row($a, 'absent')], ['reason' => 'ลืมบันทึก'], 1, $old)->assertOk();
        $this->assertSame(1, DB::table('attendances')->count());
        $this->assertFalse($this->sheet()->json('needs_reason'));
        \App\Support\Saan\Settings::put('attendance.edit_window_days', 30);
        $this->assertFalse($this->sheet(1, $this->weekday(12))->json('needs_reason'));
        $this->save([$this->row($a, 'late', 1)], [], 1, $old)->assertOk();
        $future = CarbonImmutable::now('Asia/Bangkok')->addDays(3)->toDateString();
        $this->save([], ['confirm' => true], 1, $future)->assertUnprocessable();
    }

    public function test_weekend_and_holiday_blocked_unless_forced_by_admin(): void
    {
        [$a] = $this->ids();
        $sat = CarbonImmutable::now('Asia/Bangkok')->startOfWeek()->subDay()->toDateString(); // last Sunday: weekend
        \App\Support\Saan\Settings::put('attendance.edit_window_days', 30);
        $teacher = $this->user('teacher', ['teacher_id' => 1, 'classroom_id' => 1]);
        $this->as($teacher);
        $this->assertSame('weekend', $this->sheet(1, $sat)->assertOk()->json('blocked.kind'));
        $this->save([$this->row($a, 'absent')], ['force' => true], 1, $sat)->assertUnprocessable();
        $this->as($this->admin());
        $this->save([$this->row($a, 'absent')], [], 1, $sat)->assertUnprocessable();
        $this->save([$this->row($a, 'absent')], ['force' => true], 1, $sat)->assertOk();
        $this->assertTrue(DB::table('audit_events')->where('action', 'attendance.sheet_saved')->get()->contains(fn ($e) => json_decode($e->detail, true)['forced'] === true));

        if (!Schema::hasTable('acad_calendar_days')) { $this->markTestIncomplete('acad_calendar_days (academic module) not present'); }
        DB::table('acad_calendar_days')->insert(['school_id' => 1, 'date' => $this->day, 'kind' => 'holiday', 'title' => 'วันหยุดทดสอบ', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($teacher);
        $this->assertSame('วันหยุดทดสอบ', $this->sheet()->assertOk()->json('blocked.title'));
        $this->save([$this->row($a, 'absent')])->assertUnprocessable();
        $this->as($this->admin())->save([$this->row($a, 'absent')], ['force' => true])->assertOk();
        DB::table('acad_calendar_days')->where('date', $this->day)->update(['kind' => 'event']);
        $this->assertNull($this->sheet()->json('blocked'));
    }

    public function test_teacher_scope_limits_sheet_save_and_reports(): void
    {
        DB::table('teachers')->insert(['id' => 90, 'school_id' => 1, 'name' => 'ครูสมมติ ทดสอบ', 'subject' => 'x']);
        DB::table('classrooms')->insert(['id' => 90, 'school_id' => 1, 'name' => 'ป.9/9', 'homeroom_teacher_id' => 90]);
        DB::table('students')->insert(['school_id' => 1, 'classroom_id' => 90, 'code' => 'FIC-90', 'name' => 'นักเรียนสมมติ เก้าสิบ']);
        $own = (int) DB::table('students')->where('code', 'FIC-90')->value('id');
        $other = $this->ids(1)[0];
        $this->as($this->user('teacher', ['teacher_id' => 90, 'classroom_id' => 90]));
        $classes = $this->getJson('/api/attendance/classes')->assertOk()->json();
        $this->assertSame([90], array_column($classes['classrooms'], 'id'));
        $this->assertSame(90, $classes['default_classroom_id']);
        $this->sheet(90)->assertOk();
        $this->sheet(1)->assertForbidden();
        $this->save([$this->row($other, 'absent')])->assertForbidden();
        $this->save([$this->row($other, 'absent')], [], 90)->assertUnprocessable(); // student of another class
        $this->save([$this->row($own, 'absent')], [], 90)->assertOk();
        $this->getJson('/api/attendance/reports/month?classroom_id=1&month='.substr($this->day, 0, 7))->assertForbidden();
        $this->getJson('/api/attendance/reports/month?classroom_id=90&month='.substr($this->day, 0, 7))->assertOk();
        $this->getJson('/api/attendance/reports/student/'.$other)->assertForbidden();
        $this->getJson('/api/attendance/reports/student/'.$own)->assertOk();
        $this->getJson('/api/attendance/export?type=month&classroom_id=1&month='.substr($this->day, 0, 7))->assertForbidden();
        $daily = $this->getJson('/api/attendance/reports/daily?date='.$this->day)->assertOk()->json();
        $this->assertSame([90], array_column($daily['classrooms'], 'classroom_id'));
        $this->assertSame(1, $daily['total']['absent']);
        // roles outside the module and other-school students never reach the data
        $this->as($this->user('editor'))->sheet(1)->assertForbidden();
        DB::table('students')->where('id', $other)->update(['school_id' => 2]);
        $this->as($this->admin())->getJson('/api/attendance/reports/student/'.$other)->assertNotFound();
    }

    public function test_only_studying_students_appear_when_registry_status_exists(): void
    {
        $this->as($this->admin());
        [$a, $b] = $this->ids();
        if (!Schema::hasColumn('students', 'status')) $this->markTestIncomplete('registry status column not present');
        DB::table('students')->where('id', $a)->update(['status' => 'transferred']);
        $this->assertCount(7, $this->sheet()->json('students'));
        $this->save([$this->row($a, 'absent')])->assertUnprocessable();
        DB::table('students')->where('id', $b)->update(['first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'prefix' => 'ด.ช.']);
        $this->assertSame('ด.ช.สมชาย ใจดี', collect($this->sheet()->json('students'))->firstWhere('id', $b)['name']);
    }

    public function test_reports_month_daily_and_csv_export(): void
    {
        $this->as($this->admin());
        [$a, $b] = $this->ids();
        DB::table('students')->where('id', $a)->update(['name' => '=HYPERLINK("x")']);
        $this->save([$this->row($a, 'absent'), $this->row($b, 'late')], ['confirm' => true])->assertOk();
        $month = substr($this->day, 0, 7);
        $m = $this->getJson('/api/attendance/reports/month?classroom_id=1&month='.$month)->assertOk()->json();
        $row = collect($m['students'])->firstWhere('id', $b);
        $this->assertSame('late', $row['cells'][$this->day]);
        $this->assertSame(1, $row['totals']['late']);
        $other = collect($m['students'])->firstWhere('id', $this->ids()[2]);
        $this->assertSame('present', $other['cells'][$this->day], 'confirmed day: unrecorded student is present');
        $this->getJson('/api/attendance/reports/month?classroom_id=1&month=2026-13')->assertUnprocessable();

        $d = $this->getJson('/api/attendance/reports/daily?date='.$this->day)->assertOk()->json();
        $c1 = collect($d['classrooms'])->firstWhere('classroom_id', 1);
        $this->assertSame([8, 6, 1, 1], [$c1['students'], $c1['present'], $c1['absent'], $c1['late']]);
        $this->assertTrue($c1['confirmed']);
        $c2 = collect($d['classrooms'])->firstWhere('classroom_id', 2);
        $this->assertFalse($c2['confirmed']);
        $this->assertSame(0, $c2['present']);
        $this->assertSame(1, $d['total']['checked']);

        $res = $this->get('/api/attendance/export?type=month&classroom_id=1&month='.$month)->assertOk();
        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('เลขที่,รหัส,ชื่อ-สกุล', $body);
        $this->assertStringContainsString("\"'=HYPERLINK(", $body, 'formula cells are neutralised');
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $dc = $this->get('/api/attendance/export?type=daily&date='.$this->day)->assertOk()->getContent();
        $this->assertStringContainsString('ห้องเรียน,จำนวนนักเรียน,มา,ขาด,สาย,ลา,ป่วย,สถานะการเช็ค', $dc);
        $this->assertStringContainsString('เช็คแล้ว', $dc);
        $sc = $this->get('/api/attendance/export?type=student&student_id='.$b)->assertOk()->getContent();
        $this->assertStringContainsString('วันที่,สถานะ,หมายเหตุ', $sc);
        $this->assertStringContainsString($this->day.',สาย', $sc);
    }

    public function test_marking_absent_on_a_confirmed_day_logs_present_to_absent_correction(): void
    {
        $user = $this->admin();
        $this->as($user);
        [$a, $b] = $this->ids();
        $this->save([], ['confirm' => true])->assertOk();
        $this->assertSame(0, DB::table('att_corrections')->count());
        $out = $this->save([$this->row($a, 'absent')], [], 1, null, 1)->assertOk()->json();
        $this->assertSame(1, $out['saved']['corrections']);
        $c = DB::table('att_corrections')->get();
        $this->assertCount(1, $c);
        $this->assertSame(['present', 'absent', $a, $user->id], [$c[0]->old_status, $c[0]->new_status, (int) $c[0]->student_id, (int) $c[0]->changed_by]);
        $this->assertSame((int) DB::table('attendances')->where('student_id', $a)->value('id'), (int) $c[0]->attendance_id);
        // an unconfirmed day has no implicit present: a first exception is not a correction
        $this->save([$this->row($b, 'late')], [], 1, $this->weekday(1))->assertOk();
        $this->assertSame(1, DB::table('att_corrections')->count());
        // past day beyond the window: the reason is stored on the correction
        $old = $this->weekday(10);
        $this->save([], ['confirm' => true, 'reason' => 'ยืนยันย้อนหลัง'], 1, $old)->assertOk();
        $this->save([$this->row($b, 'sick')], ['reason' => 'ใบรับรองแพทย์'], 1, $old, 1)->assertOk();
        $this->assertSame('ใบรับรองแพทย์', DB::table('att_corrections')->orderByDesc('id')->value('reason'));
    }

    public function test_race_insert_between_check_and_write_rolls_back_and_returns_409(): void
    {
        $this->as($this->admin());
        [$a, $b] = $this->ids();
        $audit = DB::table('audit_events')->count();
        $done = false;
        $day = $this->day;
        DB::beforeExecuting(function ($sql) use (&$done, $a, $day) {
            if (!$done && str_starts_with(strtolower(str_replace(['"', '`'], '', $sql)), 'insert into attendances')) {
                $done = true;
                DB::table('attendances')->insert(['school_id' => 1, 'student_id' => $a, 'date' => $day, 'status' => 'sick', 'recorded_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $res = $this->save([$this->row($a, 'absent', 0), $this->row($b, 'late', 0)])->assertStatus(409);
        $this->assertTrue($done);
        $this->assertNotEmpty($res->json('message'));
        $this->assertNull(DB::table('attendances')->where('student_id', $b)->first(), 'rejected save writes nothing for other rows');
        $this->assertSame($audit, DB::table('audit_events')->count(), 'no audit rows from a rolled back save');
        $this->assertSame(0, DB::table('att_corrections')->count());
    }

    public function test_race_version_bump_between_check_and_write_returns_409_and_keeps_data(): void
    {
        $this->as($this->admin());
        [$a, $b] = $this->ids();
        $this->save([$this->row($a, 'absent')])->assertOk();
        $audit = DB::table('audit_events')->count();
        $done = false;
        DB::beforeExecuting(function ($sql) use (&$done, $a) {
            if (!$done && str_starts_with(strtolower(str_replace(['"', '`'], '', $sql)), 'update attendances')) {
                $done = true;
                DB::table('attendances')->where('student_id', $a)->update(['status' => 'sick', 'version' => 2]);
            }
        });
        $this->save([$this->row($a, 'late', 1), $this->row($b, 'absent', 0)])->assertStatus(409);
        $this->assertTrue($done);
        $this->assertSame('absent', DB::table('attendances')->where('student_id', $a)->value('status'));
        $this->assertSame(1, (int) DB::table('attendances')->where('student_id', $a)->value('version'));
        $this->assertNull(DB::table('attendances')->where('student_id', $b)->first());
        $this->assertSame($audit, DB::table('audit_events')->count());
        $this->assertSame(0, DB::table('att_corrections')->count());
    }

    public function test_deadlock_between_concurrent_saves_is_a_409_not_a_500(): void
    {
        $this->as($this->admin());
        [$a] = $this->ids();
        $done = false;
        DB::beforeExecuting(function ($sql) use (&$done) {
            if (!$done && str_starts_with(strtolower(str_replace(['"', '`'], '', $sql)), 'insert into attendances')) {
                $done = true;
                $pdo = new \PDOException('Deadlock found when trying to get lock; try restarting transaction');
                $pdo->errorInfo = ['40001', 1213, 'Deadlock found'];
                throw new \Illuminate\Database\QueryException('mariadb', $sql, [], $pdo);
            }
        });
        $this->save([$this->row($a, 'absent')])->assertStatus(409);
        $this->assertTrue($done);
        $this->assertSame(0, DB::table('attendances')->count());
    }

    public function test_date_outside_every_term_is_stored_without_term_id(): void
    {
        $this->as($this->admin());
        [$a] = $this->ids();
        DB::table('academic_terms')->where('school_id', 1)->update(['starts_on' => '2020-01-01', 'ends_on' => '2020-03-01']);
        \App\Support\Saan\Settings::put('attendance.edit_window_days', 365);
        $this->save([$this->row($a, 'absent')])->assertOk();
        $this->assertNull(DB::table('attendances')->where('student_id', $a)->value('term_id'));
    }

    public function test_daily_summary_ignores_exceptions_of_students_who_left(): void
    {
        $this->as($this->admin());
        [$a, $b] = $this->ids();
        if (!Schema::hasColumn('students', 'status')) $this->markTestIncomplete('registry status column not present');
        $this->save([$this->row($a, 'absent'), $this->row($b, 'late')], ['confirm' => true])->assertOk();
        DB::table('students')->where('id', $a)->update(['status' => 'transferred']);
        $d = $this->getJson('/api/attendance/reports/daily?date='.$this->day)->assertOk()->json();
        $c1 = collect($d['classrooms'])->firstWhere('classroom_id', 1);
        // the day was confirmed with a frozen roll of 8: the pupil who left afterwards still counts for that date
        $this->assertSame([8, 6, 1, 1], [$c1['students'], $c1['present'], $c1['absent'], $c1['late']]);
        // legacy day without a frozen roll size: live count, leavers ignored
        DB::table('att_class_days')->where('classroom_id', 1)->update(['student_count' => null]);
        $c1 = collect($this->getJson('/api/attendance/reports/daily?date='.$this->day)->assertOk()->json('classrooms'))->firstWhere('classroom_id', 1);
        $this->assertSame([7, 6, 0, 1], [$c1['students'], $c1['present'], $c1['absent'], $c1['late']]);
    }

    public function test_month_report_follows_the_room_history_and_admission_date(): void
    {
        $this->as($this->admin());
        [$a, $b] = $this->ids();
        $this->save([$this->row($a, 'absent')], ['confirm' => true])->assertOk(); // day confirmed in room 1, 'a' absent
        $month = substr($this->day, 0, 7);
        // 'b' moved to room 2 AFTER the confirmed day: room 1 still shows b present, room 2 must not
        DB::table('students')->where('id', $b)->update(['classroom_id' => 2]);
        DB::table('student_classroom_moves')->insert(['school_id' => 1, 'student_id' => $b, 'from_classroom_id' => 1, 'to_classroom_id' => 2, 'effective_on' => CarbonImmutable::parse($this->day)->addDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $r1 = collect($this->getJson('/api/attendance/reports/month?classroom_id=1&month='.$month)->assertOk()->json('students'));
        $this->assertSame('present', ((array) $r1->firstWhere('id', $b)['cells'])[$this->day] ?? null);
        $r2 = collect($this->getJson('/api/attendance/reports/month?classroom_id=2&month='.$month)->assertOk()->json('students'));
        $this->assertNull(((array) ($r2->firstWhere('id', $b)['cells'] ?? []))[$this->day] ?? null);
        // a pupil admitted after the confirmed day gets no implicit presence before admission
        $c = $this->ids()[2];
        DB::table('students')->where('id', $c)->update(['admitted_on' => CarbonImmutable::parse($this->day)->addDay()->toDateString()]);
        $r1 = collect($this->getJson('/api/attendance/reports/month?classroom_id=1&month='.$month)->json('students'));
        $this->assertNull(((array) $r1->firstWhere('id', $c)['cells'])[$this->day] ?? null);
    }

    public function test_idempotent_retry_and_module_menu(): void
    {
        $this->as($this->admin());
        [$a] = $this->ids();
        $payload = ['classroom_id' => 1, 'date' => $this->day, 'class_day_version' => 0, 'rows' => [$this->row($a, 'absent')], 'confirm' => true];
        $this->withHeaders(['Idempotency-Key' => 'att-test-1'])->postJson('/api/attendance/sheet', $payload)->assertOk();
        $this->withHeaders(['Idempotency-Key' => 'att-test-1'])->postJson('/api/attendance/sheet', $payload)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('attendances')->count());
        $this->assertSame(1, (int) DB::table('attendances')->value('version'));
        $this->assertContains('attendance', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $page = $this->as($this->user('teacher', ['teacher_id' => 1, 'classroom_id' => 1]))->get('/work')->assertOk();
        $page->assertSee('assets/modules/attendance/attendance.js', false)->assertSee('เช็คชื่อ')->assertSee('รายงานการมาเรียน');
    }

    public function test_exports_are_audited(): void
    {
        $this->as($this->admin());
        $month = substr($this->day, 0, 7);
        $this->get('/api/attendance/export?type=month&classroom_id=1&month='.$month)->assertOk();
        $this->get('/api/attendance/export?type=daily&date='.$this->day)->assertOk();
        $rows = DB::table('audit_events')->where('action', 'attendance.exported')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['daily', 'month'], collect($rows)->map(fn ($r) => json_decode($r->detail, true)['type'])->sort()->values()->all());
    }
}