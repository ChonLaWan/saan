<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** activities module: clubs (capacity, waitlist, attendance result), secret-ballot election, certificates. All data is fictional. */
class ActivitiesModuleTest extends TestCase
{
    use RefreshDatabase;

    private const A = '/api/activities';
    private int $n = 0;
    private User $admin;
    private User $principal;
    private User $ta; // advisor, homeroom of room 1
    private User $tb; // homeroom of room 2
    private array $tid = [];
    private array $room = [];
    private array $stu = []; // room1: 0..4, room2: 5..6

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00', 'Asia/Bangkok'));
        DB::table('academic_terms')->insert(['id' => 1, 'school_id' => 1, 'year' => 2569, 'term' => 2, 'starts_on' => '2026-08-01', 'ends_on' => '2027-03-31']);
        foreach (['a' => 'ครู ก', 'b' => 'ครู ข'] as $k => $name) $this->tid[$k] = DB::table('teachers')->insertGetId(['school_id' => 1, 'name' => $name.' (สมมติ)', 'subject' => 'ทดสอบ']);
        foreach ([1 => ['ม.1/1', 'a', 'ม.1'], 2 => ['ม.2/1', 'b', 'ม.2']] as $i => [$name, $t, $grade]) {
            $id = DB::table('classrooms')->insertGetId(['school_id' => 1, 'name' => $name, 'homeroom_teacher_id' => $this->tid[$t]]);
            if (Schema::hasColumn('classrooms', 'grade_level')) DB::table('classrooms')->where('id', $id)->update(['grade_level' => $grade]);
            $this->room[$i] = $id;
        }
        foreach ([1, 1, 1, 1, 1, 2, 2] as $i => $r) {
            $this->stu[$i] = DB::table('students')->insertGetId(['school_id' => 1, 'classroom_id' => $this->room[$r], 'code' => 'ACT-'.($i + 1), 'name' => 'นักเรียนสมมติ '.($i + 1)]);
        }
        $this->admin = $this->mk('admin', null);
        $this->principal = $this->mk('principal', null);
        $this->ta = $this->mk('teacher', $this->tid['a']);
        $this->tb = $this->mk('teacher', $this->tid['b']);
    }

    private function mk(string $role, ?int $teacher): User
    {
        $this->n++;
        return User::forceCreate(['name' => 'Fictional '.$role.$this->n, 'email' => $role.$this->n.'@school.test', 'password' => 'Original-Password-2026', 'role' => $role,
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1, 'teacher_id' => $teacher]);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u);
    }

    private function club(array $over = []): array
    {
        return $this->as($this->admin)->postJson(self::A.'/clubs', $over + ['name' => 'ชุมนุมหุ่นยนต์', 'advisor_teacher_id' => $this->tid['a'], 'capacity' => 2])->assertCreated()->json();
    }

    private function enroll(int $club, array $ids, ?User $by = null)
    {
        return $this->as($by ?? $this->admin)->postJson(self::A.'/clubs/'.$club.'/enroll', ['student_ids' => $ids]);
    }

    private function mkSession(int $club, string $date): array
    {
        return $this->as($this->ta)->postJson(self::A.'/clubs/'.$club.'/sessions', ['date' => $date, 'topic' => 'กิจกรรม '.$date])->assertCreated()->json();
    }

    private function mark(array $session, array $present): void
    {
        $roster = $this->as($this->ta)->getJson(self::A.'/sessions/'.$session['id'].'/attendance')->assertOk()->json('roster');
        $records = array_map(fn ($r) => ['student_id' => $r['student_id'], 'present' => in_array($r['student_id'], $present, true)], $roster);
        $this->as($this->ta)->putJson(self::A.'/sessions/'.$session['id'].'/attendance', ['version' => $session['version'], 'records' => $records])->assertOk();
    }

    // ---- clubs ---------------------------------------------------------------------------------

    public function test_capacity_waitlist_and_promotion(): void
    {
        $club = $this->club();
        $r = $this->enroll($club['id'], [$this->stu[0], $this->stu[1], $this->stu[2]])->assertOk()->json();
        $this->assertSame(2, $r['enrolled']);
        $this->assertSame(1, $r['waitlisted']);
        $this->assertSame('waitlist', DB::table('act_members')->where('student_id', $this->stu[2])->value('status'));
        // one active club per student per term
        $other = $this->club(['name' => 'ชุมนุมดนตรี', 'capacity' => 5]);
        $r2 = $this->enroll($other['id'], [$this->stu[0], $this->stu[3]])->assertOk()->json();
        $this->assertSame(1, $r2['enrolled']);
        $this->assertCount(1, $r2['rejected']);
        $this->assertSame($this->stu[0], $r2['rejected'][0]['student_id']);
        // same list again is rejected, not duplicated
        $this->assertCount(2, $this->enroll($club['id'], [$this->stu[0], $this->stu[1]])->json('rejected'));
        // an enrolled student leaves: the oldest waitlisted student gets the seat
        $m = DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[0])->first();
        $this->as($this->admin)->postJson(self::A.'/members/'.$m->id.'/leave', ['version' => $m->version])->assertOk();
        $this->assertSame('enrolled', DB::table('act_members')->where('student_id', $this->stu[2])->value('status'));
        $this->assertNull(DB::table('act_members')->where('id', $m->id)->value('active_key'));
        // the student who left is free to join another club
        $this->enroll($other['id'], [$this->stu[0]])->assertOk()->assertJsonPath('enrolled', 1);
        // raising capacity pulls waitlisted people in
        $c3 = $this->club(['name' => 'ชุมนุมศิลปะ', 'capacity' => 1]);
        $this->enroll($c3['id'], [$this->stu[5], $this->stu[6]])->assertOk()->assertJsonPath('waitlisted', 1);
        $this->assertSame(1, DB::table('act_members')->where('club_id', $c3['id'])->where('status', 'waitlist')->count());
        $this->as($this->admin)->putJson(self::A.'/clubs/'.$c3['id'], ['version' => $c3['version'], 'capacity' => 2])->assertOk();
        $this->assertSame(2, DB::table('act_members')->where('club_id', $c3['id'])->where('status', 'enrolled')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'activities.member.promote')->where('entity_id', DB::table('act_members')->where('club_id', $c3['id'])->where('student_id', $this->stu[6])->value('id'))->count());
    }

    public function test_bulk_by_classroom_grade_limit_and_teacher_scope(): void
    {
        $club = $this->club(['capacity' => 50, 'grade_levels' => ['ม.1']]);
        // homeroom teacher of room 1 enrols the whole classroom
        $r = $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/enroll', ['classroom_id' => $this->room[1]])->assertOk()->json();
        $this->assertSame(5, $r['enrolled']);
        // teacher of room 2 cannot enrol room 1 students, and room 2 is the wrong grade
        $x = $this->enroll($club['id'], [$this->stu[0]], $this->tb)->assertOk()->json();
        $this->assertSame(0, $x['enrolled']);
        $this->as($this->tb)->postJson(self::A.'/clubs/'.$club['id'].'/enroll', ['classroom_id' => $this->room[1]])->assertForbidden();
        $y = $this->enroll($club['id'], [$this->stu[5]], $this->tb)->assertOk()->json();
        $this->assertSame('ระดับชั้นไม่ตรงกับที่ชุมนุมรับ', $y['rejected'][0]['reason']);
        // a closed club takes nobody
        $this->as($this->admin)->putJson(self::A.'/clubs/'.$club['id'], ['version' => $club['version'], 'is_open' => false])->assertOk();
        $this->enroll($club['id'], [$this->stu[6]])->assertUnprocessable();
        // only the advisor / admin read the member list
        $this->as($this->tb)->getJson(self::A.'/clubs/'.$club['id'])->assertForbidden();
        $this->as($this->ta)->getJson(self::A.'/clubs/'.$club['id'])->assertOk()->assertJsonCount(5, 'members');
        // teachers cannot create clubs
        $this->as($this->ta)->postJson(self::A.'/clubs', ['name' => 'x'])->assertForbidden();
    }

    public function test_club_name_unique_ignoring_case_and_stale_version(): void
    {
        $club = $this->club(['name' => 'Chess  Club']);
        $this->as($this->admin)->postJson(self::A.'/clubs', ['name' => ' chess club '])->assertStatus(409);
        $this->as($this->admin)->putJson(self::A.'/clubs/'.$club['id'], ['version' => $club['version'], 'description' => 'a'])->assertOk();
        $this->as($this->admin)->putJson(self::A.'/clubs/'.$club['id'], ['version' => $club['version'], 'description' => 'b'])->assertStatus(409);
    }

    public function test_result_from_attendance_threshold_and_audited_override(): void
    {
        $club = $this->club(['capacity' => 10]);
        $this->enroll($club['id'], [$this->stu[0], $this->stu[1], $this->stu[2]])->assertOk();
        $days = ['2026-11-02', '2026-10-26', '2026-10-19', '2026-10-12', '2026-10-05'];
        foreach ($days as $i => $d) {
            $s = $this->mkSession($club['id'], $d);
            // student 0 present 5/5, student 1 present 4/5 (exactly 80 percent), student 2 present 3/5
            $present = [$this->stu[0]];
            if ($i < 4) $present[] = $this->stu[1];
            if ($i < 3) $present[] = $this->stu[2];
            $this->mark($s, $present);
        }
        $sum = $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/results/compute')->assertOk()->json();
        $this->assertSame(80, $sum['pass_percent']);
        $res = fn ($i) => DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[$i])->value('result');
        $this->assertSame('ผ', $res(0));
        $this->assertSame('ผ', $res(1));
        $this->assertSame('มผ', $res(2));
        // manual override needs a reason and is audited; compute no longer touches it
        $m = DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[2])->first();
        $this->as($this->ta)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'ผ'])->assertUnprocessable();
        $o = $this->as($this->ta)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'ผ', 'reason' => 'ป่วยมีใบรับรองแพทย์'])->assertOk()->json();
        $this->assertTrue($o['result_manual']);
        $audit = DB::table('audit_events')->where('action', 'activities.result.override')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('ป่วยมีใบรับรองแพทย์', $audit->detail);
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/results/compute')->assertOk()->assertJsonPath('manual_kept', 1);
        $this->assertSame('ผ', $res(2));
        // dropping the override recomputes from attendance
        $m = DB::table('act_members')->where('id', $m->id)->first();
        $this->as($this->ta)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => null, 'reason' => 'ยกเลิกการแก้'])->assertOk()->assertJsonPath('result', 'มผ');
        // threshold is configurable (admin only)
        $this->as($this->ta)->putJson(self::A.'/settings', ['pass_percent' => 50])->assertForbidden();
        $this->as($this->admin)->putJson(self::A.'/settings', ['pass_percent' => 60])->assertOk();
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/results/compute')->assertOk()->assertJsonPath('pass_percent', 60);
        $this->assertSame('ผ', $res(2));
    }

    public function test_clubs_of_a_past_term_are_locked_for_advisors_but_not_for_admin(): void
    {
        $club = $this->club(['capacity' => 10]);
        $this->enroll($club['id'], [$this->stu[0]])->assertOk();
        $s = $this->mkSession($club['id'], '2026-11-02');
        $this->mark($s, [$this->stu[0]]);
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2569, 'term' => 1, 'starts_on' => '2026-05-15', 'ends_on' => '2026-10-31']);
        DB::table('act_clubs')->where('id', $club['id'])->update(['term_id' => 2]); // the club now belongs to last term
        $roster = [['student_id' => $this->stu[0], 'present' => false]];
        $cur = DB::table('act_sessions')->where('id', $s['id'])->value('version');
        $this->as($this->ta)->putJson(self::A.'/sessions/'.$s['id'].'/attendance', ['version' => $cur, 'records' => $roster])->assertForbidden();
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/sessions', ['date' => '2026-10-20'])->assertForbidden();
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/results/compute')->assertForbidden();
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/certificates')->assertForbidden();
        $this->enroll($club['id'], [$this->stu[1]], $this->ta)->assertForbidden();
        $this->enroll($club['id'], [$this->stu[1]])->assertOk(); // admin keeps full access
        $this->as($this->admin)->postJson(self::A.'/clubs/'.$club['id'].'/results/compute')->assertOk();
    }

    public function test_attendance_rules(): void
    {
        $club = $this->club(['capacity' => 10]);
        $this->enroll($club['id'], [$this->stu[0]])->assertOk();
        $future = $this->mkSession($club['id'], '2026-11-09');
        $this->as($this->ta)->putJson(self::A.'/sessions/'.$future['id'].'/attendance', ['version' => $future['version'], 'records' => [['student_id' => $this->stu[0], 'present' => true]]])->assertUnprocessable();
        $s = $this->mkSession($club['id'], '2026-11-02');
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/sessions', ['date' => '2026-11-02'])->assertStatus(409);
        $this->as($this->ta)->putJson(self::A.'/sessions/'.$s['id'].'/attendance', ['version' => $s['version'], 'records' => [['student_id' => $this->stu[1], 'present' => true]]])->assertUnprocessable();
        $this->as($this->tb)->putJson(self::A.'/sessions/'.$s['id'].'/attendance', ['version' => $s['version'], 'records' => [['student_id' => $this->stu[0], 'present' => true]]])->assertForbidden();
        $this->mark($s, [$this->stu[0]]);
        $this->as($this->ta)->putJson(self::A.'/sessions/'.$s['id'].'/attendance', ['version' => $s['version'], 'records' => [['student_id' => $this->stu[0], 'present' => false]]])->assertStatus(409);
    }

    public function test_result_ignores_future_and_unmarked_sessions(): void
    {
        $club = $this->club(['capacity' => 10]);
        $this->enroll($club['id'], [$this->stu[0]])->assertOk();
        $this->mkSession($club['id'], '2026-11-09'); // not held yet
        $this->mkSession($club['id'], '2026-10-26'); // held but no roll call yet
        $done = $this->mkSession($club['id'], '2026-11-02');
        $this->mark($done, [$this->stu[0]]);
        $sum = $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/results/compute')->assertOk()->json();
        $this->assertSame(1, $sum['sessions']);
        $this->assertSame(1, $sum['passed']);
        $this->assertSame('ผ', DB::table('act_members')->where('club_id', $club['id'])->value('result'));
        $d = $this->as($this->ta)->getJson(self::A.'/clubs/'.$club['id'])->assertOk()->json('members.0');
        $this->assertSame(1, $d['sessions']);
        $this->assertSame(100.0, (float) $d['percent']);
        // clearing a manual override uses the same denominator
        $m = DB::table('act_members')->where('club_id', $club['id'])->first();
        $o = $this->as($this->ta)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'มผ', 'reason' => 'ทดสอบ'])->assertOk()->json();
        $this->as($this->ta)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $o['version'], 'result' => null, 'reason' => 'ยกเลิก'])->assertOk()->assertJsonPath('result', 'ผ');
    }

    public function test_promoted_member_is_scored_from_the_day_of_the_seat(): void
    {
        $club = $this->club(['capacity' => 1]);
        $this->enroll($club['id'], [$this->stu[0], $this->stu[1]])->assertOk()->assertJsonPath('waitlisted', 1);
        $this->mark($this->mkSession($club['id'], '2026-10-19'), [$this->stu[0]]);
        $this->mark($this->mkSession($club['id'], '2026-10-26'), [$this->stu[0]]);
        $m0 = DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[0])->first();
        $this->as($this->admin)->postJson(self::A.'/members/'.$m0->id.'/leave', ['version' => $m0->version])->assertOk();
        $this->assertSame('2026-11-02', substr((string) DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[1])->value('enrolled_on'), 0, 10));
        $this->mark($this->mkSession($club['id'], '2026-11-02'), [$this->stu[1]]);
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/results/compute')->assertOk()->assertJsonPath('passed', 1);
        $this->assertSame('ผ', DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[1])->value('result'));
    }

    public function test_session_date_must_be_inside_the_club_term(): void
    {
        $club = $this->club();
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/sessions', ['date' => '2026-07-01'])->assertUnprocessable();
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/sessions', ['date' => '2027-04-01'])->assertUnprocessable();
        $s = $this->mkSession($club['id'], '2026-11-02');
        $this->as($this->ta)->putJson(self::A.'/sessions/'.$s['id'], ['version' => $s['version'], 'date' => '2026-07-01'])->assertUnprocessable();
        $this->as($this->ta)->putJson(self::A.'/sessions/'.$s['id'], ['version' => $s['version'], 'date' => '2026-11-03'])->assertOk();
    }

    public function test_student_lookup_by_code_for_managers_only(): void
    {
        $this->as($this->admin)->getJson(self::A.'/students/lookup?code=act-1')->assertOk()->assertJsonPath('id', $this->stu[0])->assertJsonPath('name', 'นักเรียนสมมติ 1');
        $this->as($this->admin)->getJson(self::A.'/students/lookup?code=NOPE')->assertNotFound();
        $this->as($this->ta)->getJson(self::A.'/students/lookup?code=ACT-1')->assertForbidden();
    }

    // ---- elections -----------------------------------------------------------------------------

    private function election(array $over = [], bool $open = true): array
    {
        $e = $this->as($this->admin)->postJson(self::A.'/elections', $over + ['title' => 'เลือกตั้งสภานักเรียน 2569', 'opens_at' => '2026-11-02T07:00', 'closes_at' => '2026-11-02T16:00'])->assertCreated()->json();
        $this->as($this->admin)->postJson(self::A.'/elections/'.$e['id'].'/candidates', ['number' => 1, 'student_id' => $this->stu[0], 'slogan' => 'ทดสอบ'])->assertCreated();
        $this->as($this->admin)->postJson(self::A.'/elections/'.$e['id'].'/candidates', ['number' => 2, 'student_id' => $this->stu[1]])->assertCreated();
        if ($open) $e = $this->as($this->admin)->postJson(self::A.'/elections/'.$e['id'].'/status', ['version' => $e['version'], 'action' => 'open'])->assertOk()->json();
        return $e;
    }

    private function cast(User $by, int $election, int $student, ?int $candidate)
    {
        $t = $this->as($by)->postJson(self::A.'/elections/'.$election.'/unlock', ['student_id' => $student]);
        if ($t->status() !== 200) return $t;
        return $this->as($by)->postJson(self::A.'/elections/'.$election.'/vote', ['ticket' => $t->json('ticket'), 'candidate_id' => $candidate]);
    }

    public function test_election_past_closing_time_closes_itself(): void
    {
        $e = $this->election();
        $cands = DB::table('act_candidates')->where('election_id', $e['id'])->orderBy('number')->pluck('id')->all();
        $this->cast($this->ta, $e['id'], $this->stu[2], $cands[0])->assertCreated();
        $this->assertNull(DB::table('act_elections')->where('id', $e['id'])->value('key_secret'));
        Carbon::setTestNow(Carbon::parse('2026-11-02 17:00:00', 'Asia/Bangkok'));
        $this->as($this->ta)->getJson(self::A.'/elections/'.$e['id'].'/turnout')->assertOk()->assertJsonPath('election.status', 'closed');
        $row = DB::table('act_elections')->where('id', $e['id'])->first();
        $this->assertSame('closed', $row->status);
        $this->assertNull($row->key_secret);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'activities.election.autoclose')->count());
        $this->as($this->ta)->getJson(self::A.'/elections/'.$e['id'].'/results')->assertOk()->assertJsonPath('ballots', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'activities.election.autoclose')->count()); // only once
    }

    public function test_a_teacher_who_is_not_the_advisor_cannot_wipe_a_manual_result_by_leaving_and_re_enrolling(): void
    {
        $club = $this->club(['capacity' => 10, 'advisor_teacher_id' => $this->tid['b']]); // ta is the homeroom teacher of the students but not the advisor
        $this->enroll($club['id'], [$this->stu[0]])->assertOk();
        $m = DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[0])->first();
        $this->as($this->admin)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'ผ', 'reason' => 'ผลงานดีเยี่ยม'])->assertOk();
        $m = DB::table('act_members')->where('id', $m->id)->first();
        $this->as($this->ta)->postJson(self::A.'/members/'.$m->id.'/leave', ['version' => $m->version])->assertForbidden();
        $this->assertSame('enrolled', DB::table('act_members')->where('id', $m->id)->value('status'));
        // even if the member left (by the advisor or the admin), re-enrolling by anybody keeps the result the advisor set
        $this->as($this->admin)->postJson(self::A.'/members/'.$m->id.'/leave', ['version' => $m->version])->assertOk();
        $this->enroll($club['id'], [$this->stu[0]], $this->ta)->assertOk()->assertJsonPath('enrolled', 1);
        $row = DB::table('act_members')->where('id', $m->id)->first();
        $this->assertSame('ผ', $row->result);
        $this->assertTrue((bool) $row->result_manual);
    }

    public function test_secret_ballot_has_no_link_to_the_student(): void
    {
        $e = $this->election();
        $cands = DB::table('act_candidates')->where('election_id', $e['id'])->orderBy('number')->pluck('id')->all();
        $this->cast($this->ta, $e['id'], $this->stu[2], $cands[0])->assertCreated()->assertExactJson(['ok' => true]);
        $this->cast($this->ta, $e['id'], $this->stu[3], $cands[1])->assertCreated();
        $this->cast($this->ta, $e['id'], $this->stu[4], null)->assertCreated(); // abstain
        $this->assertSame(3, DB::table('act_ballots')->count());
        $this->assertSame(3, DB::table('act_voters')->count());
        foreach (DB::table('act_voters')->get() as $v) $this->assertSame('2026-11-02 00:00:00', (string) $v->voted_at); // no exact time next to the ballot's cast_at
        // structure: ballots carry no student reference and no sequential id
        $cols = Schema::getColumnListing('act_ballots');
        $this->assertSame([], array_values(array_filter($cols, fn ($c) => str_contains($c, 'student') || str_contains($c, 'voter_id') || str_contains($c, 'user'))));
        foreach (DB::table('act_ballots')->get() as $b) {
            $this->assertFalse(is_numeric($b->id));
            $this->assertSame(32, strlen($b->id));
            $this->assertSame('2026-11-02', substr((string) $b->cast_at, 0, 10)); // day only: no timestamp to match with act_voters.voted_at
            foreach ($this->stu as $sid) {
                $this->assertNotSame(hash('sha256', (string) $sid), $b->voter_key);
                $this->assertNotSame(sha1((string) $sid), $b->voter_key);
            }
        }
        // the voters table has no candidate column
        $this->assertSame([], array_values(array_filter(Schema::getColumnListing('act_voters'), fn ($c) => str_contains($c, 'candidate') || str_contains($c, 'ballot'))));
        // audit trail and responses never name the choice
        foreach (DB::table('audit_events')->where('action', 'activities.vote')->get() as $a) $this->assertStringNotContainsString('candidate', (string) $a->detail);
        $this->assertNull(DB::table('act_elections')->where('id', $e['id'])->value('key_secret')); // nothing derivable is stored while voting is open
        foreach (DB::table('act_ballots')->get() as $b) foreach ($this->stu as $sid) foreach ([config('app.key'), ''] as $k) $this->assertNotSame(hash_hmac('sha256', 'act-ballot|'.$e['id'].'|'.$sid, $k), $b->voter_key);
        $show = $this->as($this->admin)->getJson(self::A.'/elections/'.$e['id'])->assertOk();
        $this->assertStringNotContainsString('key_secret', $show->getContent());
        $cur = $this->as($this->admin)->getJson(self::A.'/elections/'.$e['id'])->json('election');
        $this->as($this->admin)->postJson(self::A.'/elections/'.$e['id'].'/status', ['version' => $cur['version'], 'action' => 'close'])->assertOk();
        $this->assertNull(DB::table('act_elections')->where('id', $e['id'])->value('key_secret'));
    }

    public function test_double_vote_is_blocked_and_ticket_is_single_use(): void
    {
        $e = $this->election();
        $c = DB::table('act_candidates')->where('election_id', $e['id'])->orderBy('number')->pluck('id')->all();
        $t = $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[2]])->assertOk()->json('ticket');
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => $t, 'candidate_id' => $c[0]])->assertCreated();
        // replaying the ticket, or unlocking the same student again, cannot add a second ballot
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => $t, 'candidate_id' => $c[1]])->assertForbidden();
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[2]])->assertStatus(409);
        // two tickets issued before either vote: the database still refuses the second ballot
        $t1 = $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[3]])->assertOk()->json('ticket');
        $t2 = $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[3]])->assertOk()->json('ticket');
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => $t1, 'candidate_id' => $c[0]])->assertCreated();
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => $t2, 'candidate_id' => $c[1]])->assertStatus(409);
        $this->assertSame(2, DB::table('act_ballots')->count());
        $this->assertSame(2, DB::table('act_voters')->count());
        // a candidate of another election is refused, a forged ticket is refused
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => 'nope', 'candidate_id' => null])->assertForbidden();
        $t3 = $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[4]])->assertOk()->json('ticket');
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => $t3, 'candidate_id' => 999999])->assertUnprocessable();
    }

    public function test_results_hidden_until_close_and_live_turnout(): void
    {
        $e = $this->election();
        $c = DB::table('act_candidates')->where('election_id', $e['id'])->orderBy('number')->pluck('id')->all();
        $this->cast($this->ta, $e['id'], $this->stu[0], $c[1])->assertCreated();
        $this->cast($this->ta, $e['id'], $this->stu[1], $c[1])->assertCreated();
        $this->cast($this->ta, $e['id'], $this->stu[2], $c[0])->assertCreated();
        $this->cast($this->tb, $e['id'], $this->stu[5], null)->assertCreated();
        foreach ([$this->admin, $this->principal, $this->ta] as $u) $this->as($u)->getJson(self::A.'/elections/'.$e['id'].'/results')->assertStatus(423);
        $this->assertStringNotContainsString('votes', $this->as($this->admin)->getJson(self::A.'/elections/'.$e['id'])->getContent());
        // turnout is live: all rooms for admin, own rooms for a teacher
        $all = $this->as($this->admin)->getJson(self::A.'/elections/'.$e['id'].'/turnout')->assertOk()->json();
        $this->assertSame(7, $all['eligible']);
        $this->assertSame(4, $all['voted']);
        $mine = $this->as($this->ta)->getJson(self::A.'/elections/'.$e['id'].'/turnout')->assertOk()->json();
        $this->assertSame(5, $mine['eligible']);
        $this->assertSame(3, $mine['voted']);
        $this->assertCount(1, $mine['classrooms']);
        $this->assertStringNotContainsString('votes', json_encode($all));
        // teachers cannot open/close
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/status', ['version' => $e['version'], 'action' => 'close'])->assertForbidden();
        $cur = $this->as($this->admin)->getJson(self::A.'/elections/'.$e['id'])->json('election');
        $this->as($this->admin)->postJson(self::A.'/elections/'.$e['id'].'/status', ['version' => $cur['version'], 'action' => 'close'])->assertOk();
        $res = $this->as($this->ta)->getJson(self::A.'/elections/'.$e['id'].'/results')->assertOk()->json();
        $this->assertSame([2, 1], array_column($res['results'], 'votes'));
        $this->assertSame(1, $res['abstain']);
        $this->assertSame(4, $res['ballots']);
        // voting after close is refused
        $this->cast($this->ta, $e['id'], $this->stu[3], null)->assertUnprocessable();
    }

    public function test_kiosk_scope_window_and_grade_eligibility(): void
    {
        $e = $this->election(['eligible_grade_levels' => ['ม.1']]);
        $this->as($this->tb)->getJson(self::A.'/elections/'.$e['id'].'/kiosk/'.$this->room[1])->assertForbidden();
        $k = $this->as($this->ta)->getJson(self::A.'/elections/'.$e['id'].'/kiosk/'.$this->room[1])->assertOk()->json();
        $this->assertSame(5, $k['total']);
        $this->assertCount(2, $k['candidates']);
        // a teacher cannot unlock another classroom's student; a student of a non-eligible grade cannot vote
        $this->as($this->tb)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[0]])->assertForbidden();
        $this->as($this->tb)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[5]])->assertUnprocessable();
        // a ticket belongs to the teacher who unlocked it
        $t = $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[0]])->assertOk()->json('ticket');
        $this->as($this->tb)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => $t, 'candidate_id' => null])->assertForbidden();
        // outside the voting window nobody can unlock
        Carbon::setTestNow(Carbon::parse('2026-11-02 15:59:00', 'Asia/Bangkok'));
        $late = $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[1]])->assertOk()->json('ticket');
        Carbon::setTestNow(Carbon::parse('2026-11-02 16:01:00', 'Asia/Bangkok'));
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/unlock', ['student_id' => $this->stu[2]])->assertUnprocessable();
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => $late, 'candidate_id' => null])->assertUnprocessable();
        $this->assertSame(0, DB::table('act_ballots')->count());
        // tickets expire on their own
        Carbon::setTestNow(Carbon::parse('2026-11-02 17:00:00', 'Asia/Bangkok'));
        $this->as($this->ta)->postJson(self::A.'/elections/'.$e['id'].'/vote', ['ticket' => $t, 'candidate_id' => null])->assertForbidden();
    }

    public function test_setup_rules(): void
    {
        $e = $this->election([], false);
        $this->as($this->admin)->postJson(self::A.'/elections/'.$e['id'].'/candidates', ['number' => 1, 'student_id' => $this->stu[2]])->assertStatus(409);
        $this->as($this->admin)->postJson(self::A.'/elections/'.$e['id'].'/candidates', ['number' => 3, 'student_id' => $this->stu[0]])->assertStatus(409);
        $this->as($this->admin)->postJson(self::A.'/elections', ['title' => 'x', 'opens_at' => '2026-11-02T10:00', 'closes_at' => '2026-11-02T09:00'])->assertUnprocessable();
        $none = $this->as($this->admin)->postJson(self::A.'/elections', ['title' => 'ว่าง', 'opens_at' => '2026-11-02T07:00', 'closes_at' => '2026-11-02T16:00'])->assertCreated()->json();
        $this->as($this->admin)->postJson(self::A.'/elections/'.$none['id'].'/status', ['version' => $none['version'], 'action' => 'open'])->assertUnprocessable();
        $opened = $this->as($this->admin)->postJson(self::A.'/elections/'.$e['id'].'/status', ['version' => $e['version'], 'action' => 'open'])->assertOk()->json();
        $kid = DB::table('act_candidates')->where('election_id', $e['id'])->first();
        $this->as($this->admin)->deleteJson(self::A.'/candidates/'.$kid->id, ['version' => $kid->version])->assertUnprocessable();
        $this->as($this->admin)->deleteJson(self::A.'/elections/'.$e['id'], ['version' => $opened['version']])->assertUnprocessable();
        $this->as($this->admin)->putJson(self::A.'/elections/'.$e['id'], ['version' => $opened['version'], 'title' => 'เปลี่ยนชื่อ'])->assertUnprocessable();
        $this->as($this->admin)->putJson(self::A.'/elections/'.$e['id'], ['version' => $opened['version'], 'closes_at' => '2026-11-02T18:00'])->assertOk();
    }

    // ---- certificates --------------------------------------------------------------------------

    public function test_certificate_numbering_issue_rules_and_print(): void
    {
        $club = $this->club(['capacity' => 10]);
        $this->enroll($club['id'], [$this->stu[0], $this->stu[1], $this->stu[2]])->assertOk();
        $this->as($this->admin)->putJson(self::A.'/settings', ['cert_prefix' => 'tst', 'signer_name' => 'ผู้อำนวยการสมมติ', 'signer_title' => 'ผู้อำนวยการโรงเรียน'])->assertOk();
        foreach ([0, 1] as $i) {
            $m = DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[$i])->first();
            $this->as($this->ta)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'ผ', 'reason' => 'เข้าร่วมครบ'])->assertOk();
        }
        $m = DB::table('act_members')->where('club_id', $club['id'])->where('student_id', $this->stu[2])->first();
        $this->as($this->ta)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'มผ', 'reason' => 'ขาดบ่อย'])->assertOk();
        $this->as($this->tb)->postJson(self::A.'/clubs/'.$club['id'].'/certificates')->assertForbidden();
        $r = $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/certificates')->assertCreated()->json();
        $be = now('Asia/Bangkok')->year + 543;
        $this->assertSame(2, $r['count']);
        $this->assertSame(['TST-'.$be.'-000001', 'TST-'.$be.'-000002'], array_column($r['issued'], 'number'));
        // re-issuing creates nothing new (idempotent), a later pass continues the sequence
        $this->as($this->ta)->postJson(self::A.'/clubs/'.$club['id'].'/certificates')->assertCreated()->assertJsonPath('count', 0);
        $club2 = $this->club(['name' => 'ชุมนุมดนตรี', 'capacity' => 5]);
        $this->enroll($club2['id'], [$this->stu[3]])->assertOk();
        $m = DB::table('act_members')->where('club_id', $club2['id'])->first();
        $this->as($this->admin)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'ผ', 'reason' => 'เข้าร่วมครบ'])->assertOk();
        $this->as($this->admin)->postJson(self::A.'/clubs/'.$club2['id'].'/certificates')->assertCreated()->assertJsonPath('issued.0.number', 'TST-'.$be.'-000003');
        $this->assertSame(3, DB::table('act_certificates')->count());
        $this->assertSame(3, (int) DB::table('act_counters')->where('school_id', 1)->where('year', $be)->value('last'));
        // printable A4 page: names, wording frozen in the snapshot, signer
        $page = $this->as($this->ta)->get('/activities/clubs/'.$club['id'].'/certificates/print')->assertOk();
        $page->assertSee('นักเรียนสมมติ 1')->assertSee('นักเรียนสมมติ 2')->assertSee('TST-'.$be.'-000001')->assertSee('ผู้อำนวยการสมมติ')->assertSee('A4 landscape', false)->assertDontSee('นักเรียนสมมติ 3');
        $this->as($this->tb)->get('/activities/clubs/'.$club['id'].'/certificates/print')->assertForbidden();
        $this->as($this->ta)->getJson(self::A.'/clubs/'.$club['id'].'/certificates')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_default_certificate_prefix_never_collides_with_surveys(): void
    {
        $club = $this->club(['capacity' => 10]);
        $this->enroll($club['id'], [$this->stu[0]])->assertOk();
        $m = DB::table('act_members')->where('club_id', $club['id'])->first();
        $this->as($this->admin)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'ผ', 'reason' => 'เข้าร่วมครบ'])->assertOk();
        $be = now('Asia/Bangkok')->year + 543;
        $no = $this->as($this->admin)->postJson(self::A.'/clubs/'.$club['id'].'/certificates')->assertCreated()->json('issued.0.number');
        $this->assertSame('1ACT-'.$be.'-000001', $no);
        $this->assertNotSame('1-'.$be.'-000001', $no); // what surveys would issue for the same school
        // the surveys prefix itself cannot be chosen
        $this->as($this->admin)->putJson(self::A.'/settings', ['cert_prefix' => '1'])->assertUnprocessable();
        $this->as($this->admin)->putJson(self::A.'/settings', ['cert_prefix' => 'abc'])->assertOk();
    }

    public function test_certificate_numbers_never_repeat_when_an_issue_rolls_back(): void
    {
        $club = $this->club(['capacity' => 10]);
        $this->enroll($club['id'], [$this->stu[0]])->assertOk();
        $m = DB::table('act_members')->where('club_id', $club['id'])->first();
        $this->as($this->admin)->putJson(self::A.'/members/'.$m->id.'/result', ['version' => $m->version, 'result' => 'ผ', 'reason' => 'เข้าร่วมครบ'])->assertOk();
        $be = now('Asia/Bangkok')->year + 543;
        DB::table('act_certificates')->insert(['school_id' => 1, 'club_id' => 999, 'member_id' => 999, 'student_id' => 999, 'number' => '1ACT-'.$be.'-000001', 'snapshot' => '{}', 'issued_at' => now()]);
        // number 1 is taken by another row: the unique index turns the collision into a 409 and the counter bump is rolled back with it
        $this->as($this->admin)->postJson(self::A.'/clubs/'.$club['id'].'/certificates')->assertStatus(409);
        $this->assertSame(0, (int) DB::table('act_counters')->where('school_id', 1)->where('year', $be)->value('last'));
        $this->assertSame(0, DB::table('act_certificates')->where('club_id', $club['id'])->count());
    }

    // ---- scoping -------------------------------------------------------------------------------

    public function test_other_school_data_is_invisible_and_term_scoped(): void
    {
        $now = now();
        $foreignClub = DB::table('act_clubs')->insertGetId(['school_id' => 2, 'term_id' => 1, 'name' => 'ชุมนุมโรงเรียนอื่น', 'name_key' => 'ชุมนุมโรงเรียนอื่น', 'capacity' => 5, 'is_open' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreignElection = DB::table('act_elections')->insertGetId(['school_id' => 2, 'title' => 'อื่น', 'opens_at' => $now, 'closes_at' => $now->copy()->addHour(), 'status' => 'closed', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreignStudent = DB::table('students')->insertGetId(['school_id' => 2, 'classroom_id' => $this->room[1], 'code' => 'ACT-X', 'name' => 'นักเรียนโรงเรียนอื่น']);
        $mine = $this->club();
        $this->as($this->admin)->getJson(self::A.'/clubs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id']);
        $this->as($this->admin)->getJson(self::A.'/clubs/'.$foreignClub)->assertNotFound();
        $this->as($this->admin)->putJson(self::A.'/clubs/'.$foreignClub, ['version' => 1, 'description' => 'x'])->assertNotFound();
        $this->as($this->admin)->postJson(self::A.'/clubs/'.$foreignClub.'/sessions', ['date' => '2026-11-02'])->assertNotFound();
        $this->as($this->admin)->getJson(self::A.'/elections/'.$foreignElection.'/results')->assertNotFound();
        $this->as($this->admin)->getJson(self::A.'/elections')->assertOk()->assertJsonCount(0, 'data');
        $r = $this->enroll($mine['id'], [$foreignStudent])->assertOk()->json();
        $this->assertSame(0, $r['enrolled']);
        $this->assertCount(1, $r['rejected']);
        // another term's clubs are listed only when asked for
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2569, 'term' => 1, 'starts_on' => '2026-05-15', 'ends_on' => '2026-10-31']);
        $this->as($this->admin)->getJson(self::A.'/clubs?term_id=2')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->admin)->getJson(self::A.'/clubs?term_id=99')->assertUnprocessable();
        // the same student may join a club in a different term
        $old = $this->as($this->admin)->postJson(self::A.'/clubs', ['name' => 'ชุมนุมหุ่นยนต์', 'term_id' => 2, 'capacity' => 3])->assertCreated()->json();
        $this->enroll($mine['id'], [$this->stu[0]])->assertOk()->assertJsonPath('enrolled', 1);
        $this->enroll($old['id'], [$this->stu[0]])->assertOk()->assertJsonPath('enrolled', 1);
        // roles
        $this->as($this->mk('editor', null))->getJson(self::A.'/clubs')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::A.'/clubs')->assertUnauthorized();
    }

    public function test_work_menu_entries_for_staff_roles(): void
    {
        $this->as($this->ta)->get('/work')->assertOk()->assertSee('ชุมนุม')->assertSee('เลือกตั้งสภานักเรียน');
        $this->assertFileExists(public_path('assets/modules/activities/activities.js'));
        $this->assertFileExists(public_path('assets/modules/activities/activities.css'));
    }
}
