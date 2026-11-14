<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saan\Modules\ReportsPp\Calc;
use Saan\Modules\ReportsPp\ReportsPp;
use Tests\TestCase;

class ReportsPpModuleTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/reports-pp';
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed(); // term 1 (2569/1) current, teachers 1-6, classrooms 1-3, students 1-24
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    private function user(string $role, ?int $teacherId = null, ?int $classroomId = null): User
    {
        $this->n++;
        return User::forceCreate(['name' => 'Fictional '.$role.$this->n, 'email' => $role.$this->n.'@school.test', 'password' => 'Original-Password-2026', 'role' => $role,
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1, 'teacher_id' => $teacherId, 'classroom_id' => $classroomId]);
    }

    private function as(string $role, ?int $teacherId = null, ?int $classroomId = null): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($this->user($role, $teacherId, $classroomId));
    }

    private function subject(string $code, string $name, ?string $credit, int $school = 1): int
    {
        return DB::table('asm_subjects')->insertGetId(['school_id' => $school, 'code' => $code, 'code_key' => mb_strtolower($code).$school, 'name' => $name, 'group_name' => 'ทดลอง', 'credit' => $credit,
            'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function course(int $subject, int $classroom, ?int $teacher, int $term = 1, int $school = 1, ?array $policy = null): int
    {
        return DB::table('asm_courses')->insertGetId(['school_id' => $school, 'term_id' => $term, 'subject_id' => $subject, 'classroom_id' => $classroom, 'teacher_id' => $teacher,
            'policy' => json_encode($policy ?? \Saan\Modules\Assessment\GradePolicy::defaults()), 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function score(int $course, int $student, string $key, ?int $centi): void
    {
        DB::table('asm_scores')->insert(['school_id' => 1, 'course_id' => $course, 'student_id' => $student, 'component_key' => $key, 'score_centi' => $centi, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** continuous + exam whole points */
    private function grade(int $course, int $student, int $continuous, int $exam): void
    {
        $this->score($course, $student, 'continuous', $continuous * 100);
        $this->score($course, $student, 'exam', $exam * 100);
    }

    private function room(int $classroomId = 1): array
    {
        $teacher = (int) DB::table('classrooms')->where('id', $classroomId)->value('homeroom_teacher_id');
        $students = DB::table('students')->where('classroom_id', $classroomId)->orderBy('code')->pluck('id')->map(fn ($v) => (int) $v)->all();
        return [$teacher, $students];
    }

    private function otherTeacher(int $notIn): int
    {
        $homerooms = DB::table('classrooms')->pluck('homeroom_teacher_id')->all();
        return (int) DB::table('teachers')->whereNotIn('id', array_merge($homerooms, [$notIn]))->value('id');
    }

    private function term(int $year, int $term = 1): int
    {
        return DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => $year, 'term' => $term, 'starts_on' => ($year - 543).'-05-15', 'ends_on' => ($year - 543).'-09-30']);
    }

    // ---- pure calculation ----------------------------------------------------------------------------------------

    public function test_gpa_uses_credit_weights_and_excludes_special_incomplete_and_creditless(): void
    {
        $g = Calc::gpa([
            ['credit' => 15, 'grade' => '4'],
            ['credit' => 10, 'grade' => '2'],
            ['credit' => 10, 'grade' => 'ร'],   // special: excluded
            ['credit' => null, 'grade' => '3'], // no credit: excluded
            ['credit' => 10, 'grade' => null],  // incomplete: excluded
            ['credit' => 10, 'grade' => 'มผ'],
        ]);
        $this->assertSame(320, $g['gpa_centi']); // (1.5*4 + 1.0*2) / 2.5 = 3.20
        $this->assertSame(25, $g['credits_tenths']);
        $this->assertSame(2, $g['counted']);
        $this->assertSame(383, Calc::gpa([['credit' => 10, 'grade' => '4'], ['credit' => 10, 'grade' => '4'], ['credit' => 10, 'grade' => '3.5']])['gpa_centi']);
        $this->assertSame(0, Calc::gpa([['credit' => 10, 'grade' => '0']])['gpa_centi']); // a real 0 counts
        $this->assertNull(Calc::gpa([['credit' => 10, 'grade' => 'ร']])['gpa_centi']);
        $this->assertNull(Calc::gpa([])['gpa_centi']);
        $this->assertSame([1 => 1, 2 => 2, 3 => 2, 4 => 4, 5 => null], Calc::ranks([1 => 400, 2 => 350, 3 => 350, 4 => 300, 5 => null]));
    }

    public function test_grade_boundaries_come_from_grade_policy_and_special_wins(): void
    {
        $policy = \Saan\Modules\Assessment\GradePolicy::defaults();
        foreach ([[56, 24, '4', 80], [55, 24, '3.5', 79], [50, 25, '3.5', 75], [49, 25, '3', 74], [35, 15, '1', 50], [34, 15, '0', 49]] as [$c, $e, $grade, $total]) {
            $r = Calc::result($policy, ['continuous' => $c * 100, 'exam' => $e * 100], null);
            $this->assertSame($grade, $r['grade'], "$c+$e");
            $this->assertSame($total, $r['total']);
        }
        $this->assertSame('incomplete', Calc::result($policy, ['continuous' => 5000, 'exam' => null], null)['state']);
        $this->assertSame('none', Calc::result($policy, [], null)['state']);
        $sp = Calc::result($policy, ['continuous' => 7000, 'exam' => 3000], ['code' => 'ร', 'note' => null]);
        $this->assertSame('special', $sp['state']);
        $this->assertSame('ร', $sp['grade']);
        $this->assertNull($sp['total']);
        $this->assertSame("'=SUM(1)", Calc::csvCell('=SUM(1)'));
        $this->assertSame('12.5', Calc::csvCell('12.5'));
    }

    // ---- ปพ.5 -----------------------------------------------------------------------------------------------------

    public function test_pp5_page_renders_scores_grades_headings_and_signers(): void
    {
        [$homeroom, $students] = $this->room(1);
        $math = $this->subject('MA101', 'คณิตศาสตร์พื้นฐาน', '1.5');
        $course = $this->course($math, 1, 1);
        $this->grade($course, $students[0], 56, 24); // 80 -> 4
        $this->grade($course, $students[1], 20, 10); // 30 -> 0
        $this->score($course, $students[2], 'continuous', 4000); // incomplete
        DB::table('asm_attributes')->insert(['school_id' => 1, 'term_id' => 1, 'course_id' => $course, 'student_id' => $students[0], 'kind' => 'characteristic', 'item_code' => 'C1', 'level' => 3, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Settings::put('reports.signers', [['label' => 'ครูผู้สอน', 'name' => ''], ['label' => 'ผู้อำนวยการโรงเรียน', 'name' => 'นายทดสอบ ใจดี']]);

        $this->as('admin')->get('/print/pp/course/'.$course)->assertOk()
            ->assertSee('แบบบันทึกผลการเรียนประจำรายวิชา (ปพ.5)')->assertSee('MA101 คณิตศาสตร์พื้นฐาน')->assertSee('คะแนนระหว่างภาค')->assertSee('คะแนนปลายภาค')
            ->assertSee('80.00')->assertSee('30.00')->assertSee('คะแนนยังไม่ครบ')->assertSee('ผู้อำนวยการโรงเรียน')->assertSee('(นายทดสอบ ใจดี)', false)
            ->assertSee('C1')->assertSee('ปีการศึกษา 2569 ภาคเรียนที่ 1')->assertSee('@page', false)->assertSee('size: A4 landscape', false);
        $this->get('/print/pp/course/999999')->assertNotFound();
    }

    public function test_pp5_roles_course_teacher_only_and_school_scoping(): void
    {
        [$homeroom] = $this->room(1);
        $sub = $this->subject('TH101', 'ภาษาไทย', '1.0');
        $mine = $this->course($sub, 1, 2);
        $theirs = $this->course($this->subject('SC101', 'วิทยาศาสตร์', '1.0'), 1, 3);
        $this->as('teacher', 2)->get('/print/pp/course/'.$mine)->assertOk();
        $this->as('teacher', 2)->get('/print/pp/course/'.$theirs)->assertForbidden();
        $this->as('teacher', 2)->get(self::API.'/courses/'.$theirs.'/csv')->assertForbidden();
        $this->as('teacher', 2)->getJson(self::API.'/courses/'.$theirs.'/special')->assertForbidden();
        // the homeroom teacher is not automatically the course teacher
        $this->as('teacher', $homeroom === 2 ? 4 : $homeroom, 1)->get('/print/pp/course/'.$mine)->assertForbidden();
        $this->as('principal')->get('/print/pp/course/'.$theirs)->assertOk();
        $this->as('editor')->get('/print/pp/course/'.$mine)->assertForbidden();
        // another school's course never resolves
        DB::table('classrooms')->insert(['id' => 90, 'school_id' => 2, 'name' => 'ห้องโรงเรียนอื่น', 'homeroom_teacher_id' => 1]);
        $foreign = $this->course($this->subject('XX1', 'วิชาโรงเรียนอื่น', '1.0', 2), 90, 2, 1, 2);
        $this->as('admin')->get('/print/pp/course/'.$foreign)->assertNotFound();
        $this->get('/print/pp/classroom/90')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get('/print/pp/course/'.$mine)->assertRedirect();
    }

    // ---- special results -------------------------------------------------------------------------------------------

    public function test_special_results_are_versioned_audited_and_replace_the_numeric_grade(): void
    {
        [$homeroom, $students] = $this->room(1);
        $course = $this->course($this->subject('MA101', 'คณิตศาสตร์', '1.0'), 1, 2);
        $this->grade($course, $students[0], 60, 25);
        $this->as('teacher', 2);
        $url = self::API.'/courses/'.$course.'/special';
        $this->putJson($url, ['student_id' => $students[0], 'code' => 'ดี', 'version' => 0])->assertUnprocessable();
        $row = $this->putJson($url, ['student_id' => $students[0], 'code' => 'ร', 'note' => 'ขาดสอบปลายภาค', 'version' => 0])->assertOk()->json();
        $this->assertSame(1, $row['version']);
        $this->putJson($url, ['student_id' => $students[0], 'code' => 'มผ', 'version' => 0])->assertConflict();       // duplicate create
        $this->putJson($url, ['student_id' => $students[0], 'code' => 'มผ', 'version' => 7])->assertConflict();       // stale version
        $upd = $this->putJson($url, ['student_id' => $students[0], 'code' => 'มผ', 'version' => 1])->assertOk()->json();
        $this->assertSame(2, $upd['version']);
        $this->putJson($url, ['student_id' => 999, 'code' => 'ร', 'version' => 0])->assertUnprocessable();            // not in the course
        $this->assertSame(1, DB::table('rpt_special_results')->count());
        $this->assertGreaterThan(0, DB::table('audit_events')->where('action', 'like', 'reports.special.%')->count());
        $list = collect($this->getJson($url)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('มผ', $list[$students[0]]['special']);
        $this->assertNull($list[$students[1]]['special']);
        $this->get('/print/pp/course/'.$course)->assertSee('มผ')->assertSee('ไม่ผ่านเกณฑ์');
        // another teacher cannot touch it
        $this->as('teacher', 3)->putJson($url, ['student_id' => $students[0], 'code' => 'ร', 'version' => 2])->assertForbidden();
        // clearing needs the current version and brings the numeric grade back (60+25 = 85 -> 4)
        $this->as('teacher', 2)->putJson($url, ['student_id' => $students[0], 'code' => null, 'version' => 1])->assertConflict();
        $cleared = $this->putJson($url, ['student_id' => $students[0], 'code' => null, 'version' => 2])->assertOk()->json();
        $this->assertNull($cleared['code']);
        $this->assertSame(3, $cleared['version']);                                       // the row is kept, its version keeps growing
        $this->assertSame(1, DB::table('rpt_special_results')->count());
        $this->assertNull(DB::table('rpt_special_results')->value('code'));
        $this->get('/print/pp/course/'.$course)->assertSee('85.00');
    }

    public function test_special_result_set_clear_set_again_rejects_a_stale_version(): void
    {
        [$homeroom, $students] = $this->room(1);
        $course = $this->course($this->subject('MA101', 'คณิตศาสตร์', '1.0'), 1, 2);
        $this->grade($course, $students[0], 60, 25);
        $this->as('teacher', 2);
        $url = self::API.'/courses/'.$course.'/special';
        $b = ['student_id' => $students[0]];
        $this->putJson($url, $b + ['code' => 'ร', 'version' => 0])->assertOk()->assertJsonPath('version', 1);
        $this->putJson($url, $b + ['code' => null, 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->putJson($url, $b + ['code' => 'มผ', 'version' => 0])->assertConflict();                    // row exists (cleared): create refused
        $this->putJson($url, $b + ['code' => 'มผ', 'version' => 2])->assertOk()->assertJsonPath('version', 3);
        $this->putJson($url, $b + ['code' => 'ร', 'version' => 1])->assertConflict();                    // stale client holding version 1
        $this->assertSame('มผ', DB::table('rpt_special_results')->value('code'));
        $this->assertSame(1, DB::table('rpt_special_results')->count());
        // a cleared row does not count as a special result anywhere
        $this->putJson($url, $b + ['code' => null, 'version' => 3])->assertOk();
        $this->get('/print/pp/course/'.$course)->assertSee('85.00');
        $d = app(ReportsPp::class)->pp5($course);
        $this->assertSame('graded', $d['rows'][0]['result']['state']);
        $this->assertNull(collect($this->getJson($url)->json('data'))->firstWhere('id', $students[0])['special']);
    }

    public function test_closed_course_special_results_are_manager_only(): void
    {
        [$homeroom, $students] = $this->room(1);
        $course = $this->course($this->subject('MA101', 'คณิตศาสตร์', '1.0'), 1, 2);
        $this->grade($course, $students[0], 60, 25);
        $url = self::API.'/courses/'.$course.'/special';
        $this->as('teacher', 2)->putJson($url, ['student_id' => $students[0], 'code' => 'ร', 'version' => 0])->assertOk();
        DB::table('asm_courses')->where('id', $course)->update(['status' => 'closed']);
        $this->as('teacher', 2)->putJson($url, ['student_id' => $students[0], 'code' => 'มผ', 'version' => 1])->assertForbidden();
        $this->putJson($url, ['student_id' => $students[0], 'code' => null, 'version' => 1])->assertForbidden();
        $this->assertSame('ร', DB::table('rpt_special_results')->value('code'));
        $this->getJson($url)->assertOk();                                                              // reading stays allowed
        $this->as('admin')->putJson($url, ['student_id' => $students[0], 'code' => 'มผ', 'version' => 1])->assertOk();
        $this->assertSame('มผ', DB::table('rpt_special_results')->value('code'));
    }

    // ---- ปพ.6 -----------------------------------------------------------------------------------------------------

    private function classFixture(): array
    {
        [$homeroom, $students] = $this->room(1);
        $ma = $this->course($this->subject('MA101', 'คณิตศาสตร์', '1.5'), 1, 2);
        $th = $this->course($this->subject('TH101', 'ภาษาไทย', '1.0'), 1, 3);
        $pe = $this->course($this->subject('PE101', 'สุขศึกษา', null), 1, 4);
        // student 0: ma 4, th 2 -> GPA (1.5*4 + 1.0*2)/2.5 = 3.20 ; pe has no credit
        $this->grade($ma, $students[0], 56, 24); $this->grade($th, $students[0], 40, 20); $this->grade($pe, $students[0], 30, 15);
        // student 1: ma 3, th ร -> GPA 3.00 (ร excluded)
        $this->grade($ma, $students[1], 49, 21); $this->grade($th, $students[1], 70, 30);
        DB::table('rpt_special_results')->insert(['school_id' => 1, 'course_id' => $th, 'student_id' => $students[1], 'code' => 'ร', 'note' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // student 2: ma 0
        $this->grade($ma, $students[2], 10, 5);
        return [$homeroom, $students, $ma, $th, $pe];
    }

    public function test_pp6_computes_credit_weighted_gpa_and_shows_every_section(): void
    {
        [$homeroom, $students] = $this->classFixture();
        DB::table('attendances')->delete(); // drop the seeded demo rows so the expected numbers are exact
        // room 1 confirmed 3 school days; Saturday and a holiday are confirmed too but never count; one exception row (absent)
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-06'] as $day) {
            DB::table('att_class_days')->insert(['school_id' => 1, 'classroom_id' => 1, 'date' => $day, 'confirmed_by' => 1, 'confirmed_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('acad_calendar_days')->insert(['school_id' => 1, 'date' => '2026-06-04', 'kind' => 'holiday', 'title' => 'วันหยุดทดสอบ', 'title_key' => 'วันหยุดทดสอบ', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendances')->insert([
            ['school_id' => 1, 'student_id' => $students[0], 'date' => '2026-06-02', 'status' => 'absent', 'recorded_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => 1, 'student_id' => $students[0], 'date' => '2026-06-10', 'status' => 'late', 'recorded_by' => 1, 'created_at' => now(), 'updated_at' => now()], // unconfirmed day: counted by status
        ]);
        DB::table('asm_attributes')->insert(['school_id' => 1, 'term_id' => 1, 'course_id' => 0, 'student_id' => $students[0], 'kind' => 'reading', 'item_code' => 'R1', 'level' => 2, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $d = (function () use ($students) { $this->as('admin'); return app(ReportsPp::class)->pp6Class(1, app(ReportsPp::class)->term(1)); })();
        $by = array_column($d['pages'], null, null);
        $gpa = array_map(fn ($p) => $p['gpa']['gpa_centi'], $d['pages']);
        $this->assertSame(320, $gpa[0]);
        $this->assertSame(300, $gpa[1]);
        $this->assertSame(0, $gpa[2]);
        $a0 = $d['pages'][0]['attendance'];
        ksort($a0);
        $this->assertSame(['absent' => 1, 'late' => 1, 'present' => 2], $a0);   // 3 confirmed days: 2 implicit present + 1 absent
        $this->assertSame(['present' => 3], $d['pages'][1]['attendance']);       // no exception rows: present on every confirmed school day

        $this->get('/print/pp/student/'.$students[0])->assertOk()
            ->assertSee('แบบรายงานผลการพัฒนาคุณภาพผู้เรียนรายบุคคล (ปพ.6)')->assertSee('ผลการเรียนเฉลี่ยประจำภาคเรียน (GPA)')->assertSee('3.20')
            ->assertSee('สรุปเวลาเรียน')->assertSee('การอ่าน คิดวิเคราะห์ และเขียน')->assertSee('ดี')->assertSee('ความเห็นของครูประจำชั้น')->assertSee('ครูประจำชั้น')
            ->assertDontSee('อันดับที่');
        $this->get('/print/pp/classroom/1')->assertOk()->assertSee('ปพ.6')->assertSee('class="sheet portrait break"', false);
        $this->assertGreaterThan(5, substr_count($this->get('/print/pp/classroom/1')->getContent(), 'class="sheet portrait'));
    }

    public function test_attendance_uses_room_history_to_attribute_days(): void
    {
        [$homeroom, $students] = $this->classFixture();
        DB::table('attendances')->delete();
        $day = fn (int $room, string $date) => DB::table('att_class_days')->insert(['school_id' => 1, 'classroom_id' => $room, 'date' => $date, 'confirmed_by' => 1, 'confirmed_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['2026-06-03', '2026-06-04', '2026-06-05'] as $d) $day(1, $d);
        foreach (['2026-06-01', '2026-06-02'] as $d) $day(2, $d);
        $this->as('admin');
        $svc = app(ReportsPp::class);
        $term = $svc->term(1);
        // no history: the final room's days count for the whole term
        $this->assertSame(['present' => 3], $svc->attendance([$students[1]], $term, [$students[1] => 1])[$students[1]]);
        // moved from room 2 to room 1 on 3 June: room 2's first two days plus room 1's days from the move on
        \App\Support\Saan\ClassroomMoves::record(1, $students[1], 2, 1, '2026-06-03');
        $got = $svc->attendance([$students[1], $students[0]], $term, [$students[1] => 1, $students[0] => 1]);
        $this->assertSame(['present' => 5], $got[$students[1]]);
        $this->assertSame(['present' => 3], $got[$students[0]]);
        // moved the other way: room 1 days before the move only
        DB::table('student_classroom_moves')->delete();
        \App\Support\Saan\ClassroomMoves::record(1, $students[1], 1, 2, '2026-06-05');
        $got = $svc->attendance([$students[1]], $term, [$students[1] => 2]);
        $this->assertSame(['present' => 2], $got[$students[1]]);   // room 1 on 3 and 4 June only; room 2 days all precede the move
        // past term: the supplied room is a guess (dominant room, here 1) but the last recorded move says the student ended in room 2
        DB::table('student_classroom_moves')->delete();
        $day(1, '2026-06-02');
        $day(2, '2026-06-08');
        \App\Support\Saan\ClassroomMoves::record(1, $students[1], 1, 2, '2026-06-03');
        $got = $svc->attendance([$students[1]], $term, [$students[1] => 1]);
        $this->assertSame(['present' => 2], $got[$students[1]]);   // room 1 on 2 June (before the move) and room 2 on 8 June (after); old code gave 4
    }

    public function test_room_on_returns_last_destination_when_no_later_move(): void
    {
        $moves = [['on' => '2026-06-03', 'from' => 1, 'to' => 2]];
        $this->assertSame(1, \App\Support\Saan\ClassroomMoves::roomOn($moves, 1, '2026-06-02'));
        $this->assertSame(2, \App\Support\Saan\ClassroomMoves::roomOn($moves, 1, '2026-06-03'));
        $this->assertSame(2, \App\Support\Saan\ClassroomMoves::roomOn($moves, 1, '2026-06-30'));
        $this->assertSame(5, \App\Support\Saan\ClassroomMoves::roomOn([], 5, '2026-06-30'));
    }

    public function test_student_classroom_change_through_mutation_records_a_move(): void
    {
        [$homeroom, $students] = $this->classFixture();
        $row = DB::table('students')->where('id', $students[1])->first();
        $to = ((int) $row->classroom_id) === 2 ? 3 : 2;
        $this->as('admin');
        \App\Support\Saan\Mutation::update('students', $students[1], (int) $row->version, ['classroom_id' => $to], 'registry.student.updated');
        $m = DB::table('student_classroom_moves')->where('student_id', $students[1])->first();
        $this->assertSame($to, (int) $m->to_classroom_id);
        $this->assertSame($row->classroom_id === null ? null : (int) $row->classroom_id, $m->from_classroom_id === null ? null : (int) $m->from_classroom_id);
    }

    public function test_reports_js_uses_the_school_base_and_views_have_warn_and_colspan_rules(): void
    {
        $js = file_get_contents(public_path('assets/modules/reports-pp/reports.js'));
        $this->assertStringContainsString("meta[name=\"school-base\"]", $js);
        $this->assertDoesNotMatchRegularExpression('/href="\/print\/pp\//', $js);
        $this->assertDoesNotMatchRegularExpression('/href="\$\{BASE\}/', $js);
        $layout = file_get_contents(base_path('modules/reports-pp/resources/views/layout.blade.php'));
        $this->assertStringContainsString('.toolbar, .warn { display: none; }', $layout);
        $this->assertStringNotContainsString('colspan="14"', file_get_contents(base_path('modules/reports-pp/resources/views/summary.blade.php')));
    }
    public function test_pp6_roles_homeroom_only(): void
    {
        [$homeroom, $students] = $this->classFixture();
        $other = $this->otherTeacher($homeroom);
        $this->as('teacher', $homeroom, 1)->get('/print/pp/classroom/1')->assertOk();
        $this->as('teacher', $homeroom, 1)->get('/print/pp/student/'.$students[0])->assertOk();
        $this->as('teacher', $homeroom, 1)->get('/print/pp/classroom/2')->assertForbidden();
        $this->as('teacher', $other)->get('/print/pp/classroom/1')->assertForbidden();
        $this->as('teacher', $other)->get('/print/pp/student/'.$students[0])->assertForbidden();
        $this->as('teacher', 2)->get('/print/pp/classroom/1/summary')->assertForbidden(); // teaches there but is not homeroom
        $this->as('principal')->get('/print/pp/classroom/1/summary')->assertOk();
        $this->as('admin')->get('/print/pp/classroom/1?term_id=999')->assertNotFound();
        $this->as('admin')->get('/print/pp/student/999999')->assertNotFound();
    }

    public function test_pp6_is_authorised_on_the_students_own_classroom_not_on_stray_rows(): void
    {
        [$hr1, $students] = $this->classFixture();
        [$hr2, $room2] = $this->room(2);
        $this->assertNotSame($hr1, $hr2);
        // a mis-assigned score of a room-1 student in a room-2 course
        $c2 = $this->course($this->subject('SC201', 'วิทยาศาสตร์ ห้อง 2', '1.0'), 2, 3);
        $this->grade($c2, $students[0], 60, 25);
        $this->grade($c2, $room2[0], 50, 20);

        $this->as('teacher', $hr2, 2)->get('/print/pp/student/'.$students[0])->assertForbidden();
        $this->as('teacher', $hr1, 1)->get('/print/pp/student/'.$students[0])->assertOk();
        $this->as('admin')->get('/print/pp/student/'.$students[0])->assertOk();
        // room 2's bulk print, summary and remark list contain only room 2's own students
        $this->as('teacher', $hr2, 2);
        $svc = app(ReportsPp::class);
        $term = $svc->term(1);
        $same = function (array $got) use ($room2) { sort($got); $want = $room2; sort($want); $this->assertSame($want, $got); };
        $same(array_map(fn ($p) => $p['student']['id'], $svc->pp6Class(2, $term)['pages']));
        $same(array_column(array_column($svc->summary(2, $term)['students'], 'student'), 'id'));
        $same(array_column($svc->remarkList(2, $term)['data'], 'id'));
        // the course teacher's ปพ.5 still lists whoever has rows in that course
        $this->as('teacher', 3);
        $this->assertContains($students[0], array_column(array_column(app(ReportsPp::class)->pp5($c2)['rows'], 'student'), 'id'));
    }

    public function test_past_term_pp6_uses_the_classroom_of_that_term(): void
    {
        [$hr1, $room1] = $this->room(1);
        [$hr3, $room3] = $this->room(3);
        $past = $this->term(2568, 2);
        $a = $this->course($this->subject('MA301', 'คณิตศาสตร์ ม.ต้น', '1.0'), 3, 2, $past);
        $b = $this->course($this->subject('TH301', 'ภาษาไทย ม.ต้น', '1.0'), 3, 2, $past);
        // student in room 1 today, was in room 3 in term 2568/2; room-3 classmate scored higher
        $this->grade($a, $room1[0], 49, 21); $this->grade($b, $room1[0], 49, 21);   // 70 -> 3
        $this->grade($a, $room3[0], 56, 24); $this->grade($b, $room3[0], 56, 24);   // 80 -> 4
        Settings::put('reports.show_rank', true);

        $this->as('admin');
        $svc = app(ReportsPp::class);
        $p = $svc->pp6Student($room1[0], $svc->term($past));
        $roomName3 = (string) DB::table('classrooms')->where('id', 3)->value('name');
        $this->assertSame($roomName3, $p['classroom']);
        $this->assertSame(2, $p['pages'][0]['rank']);                                  // ranked against room 3's roster of that term
        $this->assertSame(2, count($p['pages'][0]['lines']));
        $sign = collect($p['signers'])->firstWhere('label', 'ครูประจำชั้น')['name'];
        $this->assertSame((string) DB::table('teachers')->where('id', $hr3)->value('name'), $sign);
        $this->get('/print/pp/student/'.$room1[0].'?term_id='.$past)->assertOk()->assertSee($roomName3);
        // bulk print of room 3 for that term includes both; room 1 (today's class list) has nobody for that term
        $this->assertCount(2, $svc->pp6Class(3, $svc->term($past))['pages']);
        $this->assertCount(0, $svc->pp6Class(1, $svc->term($past))['pages']);
        // homeroom is only known for today: no teacher (neither today's room 1 nor room 3 homeroom) may open a past term's reports
        $this->as('teacher', $hr3, 3)->get('/print/pp/student/'.$room1[0].'?term_id='.$past)->assertForbidden();
        $this->as('teacher', $hr1, 1)->get('/print/pp/student/'.$room1[0].'?term_id='.$past)->assertForbidden();
        foreach (['/print/pp/classroom/3', '/print/pp/classroom/3/summary', self::API.'/classrooms/3/csv', self::API.'/classrooms/3/summary/csv'] as $path) {
            $this->as('teacher', $hr3, 3)->get($path.'?term_id='.$past)->assertForbidden();
        }
        $this->as('teacher', $hr3, 3)->getJson(self::API.'/classrooms/3/remarks?term_id='.$past)->assertForbidden();
    }

    public function test_gpa_is_truncated_by_default_and_rounding_is_a_setting(): void
    {
        $items = [['credit' => 15, 'grade' => '4'], ['credit' => 15, 'grade' => '4'], ['credit' => 10, 'grade' => '3.5']];
        $this->assertSame(387, Calc::gpa($items)['gpa_centi']);             // 3.875 -> 3.87 (no rounding, Thai MOE)
        $this->assertSame(388, Calc::gpa($items, 'round')['gpa_centi']);
        $this->as('admin');
        $this->assertSame('truncate', app(ReportsPp::class)->settings()['gpa_rounding']);
    }

    public function test_past_term_special_results_are_locked_for_teachers_and_need_a_reason_from_managers(): void
    {
        [, $room1] = $this->room(1);
        $past = $this->term(2568, 2);
        $course = $this->course($this->subject('MA401', 'คณิตศาสตร์', '1.0'), 1, 2, $past);
        $this->grade($course, $room1[0], 60, 25);
        $url = self::API.'/courses/'.$course.'/special';
        $this->as('teacher', 2)->putJson($url, ['student_id' => $room1[0], 'code' => 'มส', 'version' => 0])->assertStatus(423);
        $this->assertSame(0, DB::table('rpt_special_results')->count());
        $this->as('admin')->putJson($url, ['student_id' => $room1[0], 'code' => 'มส', 'version' => 0])->assertStatus(422);
        $this->putJson($url, ['student_id' => $room1[0], 'code' => 'มส', 'version' => 0, 'reason' => 'แก้ตามมติคณะกรรมการ'])->assertOk();
        $detail = (string) DB::table('audit_events')->where('action', 'reports.special.created')->value('detail');
        $this->assertStringContainsString('แก้ตามมติคณะกรรมการ', json_encode(json_decode($detail), JSON_UNESCAPED_UNICODE));
    }

    public function test_editing_a_subject_credit_does_not_change_an_existing_courses_gpa(): void
    {
        [, $room1] = $this->room(1);
        $subject = $this->subject('MA501', 'คณิตศาสตร์', '1.5');
        $this->as('admin');
        $term = app(\Saan\Modules\Assessment\Assessment::class)->term();
        $created = app(\Saan\Modules\Assessment\Assessment::class)->courseCreate($term, $subject, 1, 2, null);
        $this->grade($created['id'], $room1[0], 56, 24);
        $other = $this->subject('TH501', 'ภาษาไทย', '1.0');
        $c2 = $this->course($other, 1, 3);
        $this->grade($c2, $room1[0], 40, 20);
        DB::table('asm_subjects')->where('id', $subject)->update(['credit' => '1.0', 'name' => 'เปลี่ยนชื่อ']);
        $svc = app(ReportsPp::class);
        $p = $svc->pp6Student($room1[0], $svc->term());
        $this->assertSame(320, $p['pages'][0]['gpa']['gpa_centi']);                     // still 1.5 credit: (1.5*4+1.0*2)/2.5
        $this->assertContains('คณิตศาสตร์', array_column(array_column($p['pages'][0]['lines'], 'course'), 'name'));
    }

    public function test_homeroom_teacher_cannot_open_or_annotate_a_pupil_who_is_no_longer_studying(): void
    {
        [$homeroom, $students] = $this->classFixture();
        $this->as('teacher', $homeroom, 1)->get('/print/pp/student/'.$students[0])->assertOk();
        DB::table('students')->where('id', $students[0])->update(['status' => 'graduated']);
        $this->as('teacher', $homeroom, 1)->get('/print/pp/student/'.$students[0])->assertNotFound();
        $this->putJson(self::API.'/remarks', ['student_id' => $students[0], 'term_id' => 1, 'remark' => 'x', 'version' => 0])->assertNotFound();
        $this->assertSame(0, DB::table('rpt_remarks')->where('student_id', $students[0])->count());
        $this->as('admin')->get('/print/pp/student/'.$students[0])->assertOk();
    }
    public function test_remarks_are_versioned_homeroom_only_escaped_and_printed(): void
    {
        [$homeroom, $students] = $this->classFixture();
        $other = $this->otherTeacher($homeroom);
        $body = ['student_id' => $students[0], 'term_id' => 1, 'remark' => '<script>alert(1)</script> ตั้งใจเรียนดี', 'version' => 0];
        $this->as('teacher', $other)->putJson(self::API.'/remarks', $body)->assertForbidden();
        $this->as('teacher', $homeroom, 1);
        $r = $this->putJson(self::API.'/remarks', $body)->assertOk()->json();
        $this->assertSame(1, $r['version']);
        $this->putJson(self::API.'/remarks', $body)->assertConflict();                                  // already exists
        $this->putJson(self::API.'/remarks', ['version' => 5] + $body)->assertConflict();               // stale
        $this->putJson(self::API.'/remarks', ['remark' => str_repeat('ก', 501)] + $body)->assertUnprocessable();
        $this->putJson(self::API.'/remarks', ['remark' => 'พัฒนาขึ้นมาก', 'version' => 1] + $body)->assertOk()->assertJsonPath('version', 2);
        $this->get('/print/pp/student/'.$students[0])->assertSee('พัฒนาขึ้นมาก');
        $this->putJson(self::API.'/remarks', ['remark' => 'x', 'version' => 2] + $body)->assertOk();
        $this->putJson(self::API.'/remarks', ['remark' => '', 'version' => 3] + $body)->assertOk()->assertJsonPath('remark', '')->assertJsonPath('version', 4);   // a remark can be cleared
        $this->assertSame('', DB::table('rpt_remarks')->value('remark'));
        $this->putJson(self::API.'/remarks', ['remark' => '<b>x</b>', 'version' => 4] + $body)->assertOk();
        $html = $this->get('/print/pp/student/'.$students[0])->getContent();
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        // a teacher cannot rewrite a past term's remark
        $past = $this->term(2568, 2);
        $this->putJson(self::API.'/remarks', ['term_id' => $past, 'version' => 0] + $body)->assertForbidden();
        $this->as('admin')->putJson(self::API.'/remarks', ['term_id' => $past, 'version' => 0] + $body)->assertOk();
        $this->getJson(self::API.'/classrooms/1/remarks')->assertOk()->assertJsonPath('data.0.id', $students[0]);
        $this->as('teacher', $other)->getJson(self::API.'/classrooms/1/remarks')->assertForbidden();
    }

    // ---- class summary ---------------------------------------------------------------------------------------------

    public function test_summary_counts_distribution_and_placeholders_and_rank_setting(): void
    {
        [$homeroom, $students, $ma, $th] = $this->classFixture();
        $this->as('admin');
        $d = app(ReportsPp::class)->summary(1, app(ReportsPp::class)->term(1));
        $math = $d['subjects'][array_search('MA101', array_column(array_column($d['subjects'], 'course'), 'code'))]['dist'];
        $this->assertSame(1, $math['4']);
        $this->assertSame(1, $math['3']);
        $this->assertSame(1, $math['0']);
        $this->assertSame(1, $d['placeholders']['r']);
        $this->assertSame(2, $d['placeholders']['zero']); // MA101 student 3 and PE101 student 1 (45)
        $this->assertSame(0, $d['placeholders']['mp']);
        // students with no score rows at all are "incomplete" everywhere
        $this->assertGreaterThan(0, $d['placeholders']['incomplete']);
        $this->assertFalse($d['show_rank']);

        $this->get('/print/pp/classroom/1/summary')->assertOk()->assertSee('รายงานสรุปผลการเรียนรายห้อง')->assertSee('การกระจายผลการเรียนรายวิชา')->assertSee('MA101')
            ->assertDontSee('อันดับ');
        $this->putJson(self::API.'/settings', ['show_rank' => true, 'signers' => [['label' => 'ครูประจำชั้น', 'name' => 'ครูทดสอบ']]])->assertUnprocessable(); // version required
        $this->putJson(self::API.'/settings', ['version' => 0, 'show_rank' => true, 'signers' => [['label' => 'ครูประจำชั้น', 'name' => 'ครูทดสอบ']]])->assertOk()->assertJsonPath('show_rank', true)->assertJsonPath('version', 1);
        $this->putJson(self::API.'/settings', ['version' => 0, 'show_rank' => false])->assertConflict()->assertJsonPath('current.version', 1);   // second manager with a stale form
        $this->assertTrue((bool) Settings::get('reports.show_rank'));
        $this->getJson(self::API.'/overview')->assertJsonPath('settings.version', 1);
        $d = app(ReportsPp::class)->summary(1, app(ReportsPp::class)->term(1));
        $this->assertSame($students[0], $d['students'][0]['student']['id']);   // best GPA first
        $this->assertSame(1, $d['students'][0]['rank']);
        $this->get('/print/pp/classroom/1/summary')->assertSee('อันดับ')->assertSee('(ครูทดสอบ)', false);
        $this->get('/print/pp/student/'.$students[0])->assertSee('อันดับที่ 1');
        // only managers change settings
        $this->as('teacher', $homeroom, 1)->putJson(self::API.'/settings', ['show_rank' => false])->assertForbidden();
    }

    // ---- templates -------------------------------------------------------------------------------------------------

    public function test_template_year_selection_keeps_the_layout_of_each_year(): void
    {
        [$homeroom, $students] = $this->classFixture();
        $t2568 = $this->as('admin')->putJson(self::API.'/templates', ['kind' => 'pp6', 'year_be' => 2568, 'version' => 0, 'options' => ['title' => 'ปพ.6 แบบปี 2568', 'font_size' => 13]])->assertOk()->json();
        $this->assertSame(1, $t2568['version']);
        $this->putJson(self::API.'/templates', ['kind' => 'pp6', 'year_be' => 2568, 'version' => 0, 'options' => ['title' => 'x']])->assertConflict();
        $this->putJson(self::API.'/templates', ['kind' => 'pp6', 'year_be' => 2568, 'version' => 9, 'options' => ['title' => 'x']])->assertConflict();
        $this->putJson(self::API.'/templates', ['kind' => 'pp6', 'year_be' => 2570, 'version' => 0, 'options' => ['title' => 'ปพ.6 แบบปี 2570', 'show_attendance' => false]])->assertOk();
        $this->putJson(self::API.'/templates', ['kind' => 'pp6', 'year_be' => 2570, 'version' => 0, 'options' => ['font_size' => 99]])->assertUnprocessable();
        $this->putJson(self::API.'/templates', ['kind' => 'bad', 'year_be' => 2570, 'version' => 0, 'options' => []])->assertUnprocessable();

        $svc = app(ReportsPp::class);
        $this->assertSame('ปพ.6 แบบปี 2568', $svc->template('pp6', 2569)['options']['title']);   // 2569 has none: last year's layout stays
        $this->assertSame(2568, $svc->template('pp6', 2569)['year_be']);
        $this->assertSame('ปพ.6 แบบปี 2570', $svc->template('pp6', 2571)['options']['title']);
        $this->assertSame(2568, $svc->template('pp6', 2568)['year_be']);
        $this->assertNull($svc->template('pp6', 2567)['year_be']);                               // before any template: default
        $this->assertSame(ReportsPp::TITLES['pp6'], $svc->template('pp6', 2567)['options']['title']);
        $this->assertSame(ReportsPp::TITLES['pp5'], $svc->template('pp5', 2571)['options']['title']); // kinds are independent

        // the page of term 2569 renders with the 2568 layout and a newer template does not change it
        $this->get('/print/pp/student/'.$students[0])->assertSee('ปพ.6 แบบปี 2568')->assertDontSee('ปพ.6 แบบปี 2570')->assertSee('data-template-year="2568"', false);
        $t2571 = $this->term(2571);
        $this->get('/print/pp/classroom/1?term_id='.$t2571)->assertSee('ปพ.6 แบบปี 2570')->assertDontSee('สรุปเวลาเรียน');
        // 2569 inherits the 2568 template: editing 2568 would silently change reports already printed for 2569, so it is refused
        $this->putJson(self::API.'/templates', ['kind' => 'pp6', 'year_be' => 2568, 'version' => 1, 'options' => ['title' => 'ปพ.6 แก้ย้อนหลัง']])->assertUnprocessable();
        $this->get('/print/pp/student/'.$students[0])->assertSee('ปพ.6 แบบปี 2568')->assertDontSee('ปพ.6 แก้ย้อนหลัง');
        $this->assertSame(1, (int) DB::table('rpt_templates')->where('year_be', 2568)->where('kind', 'pp6')->value('version'));
        $this->putJson(self::API.'/templates', ['kind' => 'pp6', 'year_be' => 2569, 'version' => 0, 'options' => ['title' => 'ปพ.6 แบบปี 2569']])->assertOk();
        $this->get('/print/pp/student/'.$students[0])->assertSee('ปพ.6 แบบปี 2569');
        $this->get('/print/pp/classroom/1?term_id='.$t2571)->assertSee('ปพ.6 แบบปี 2570');
        $this->as('teacher', $homeroom, 1)->putJson(self::API.'/templates', ['kind' => 'pp6', 'year_be' => 2569, 'version' => 1, 'options' => ['title' => 'hack']])->assertForbidden();
    }

    // ---- CSV, overview, module -------------------------------------------------------------------------------------

    public function test_csv_backslash_quote_in_a_name_stays_inside_its_field(): void
    {
        [$homeroom, $students, $ma] = $this->classFixture();
        DB::table('students')->where('id', $students[0])->update(['name' => 'ab\\"', 'first_name' => null, 'last_name' => null, 'prefix' => null]);
        $this->as('admin');
        $csv = $this->get(self::API.'/courses/'.$ma.'/csv')->assertOk()->getContent();
        $this->assertStringContainsString('"ab\\"""', $csv); // the quote is doubled, so no reader closes the field at \"
        $row = collect(preg_split('/\r?\n/', $csv))->first(fn ($l) => str_contains($l, 'ab\\'));
        $this->assertSame('ab\\"', str_getcsv($row, ',', '"', '')[2]);
    }
    public function test_csv_exports_have_bom_headers_and_are_formula_safe(): void
    {
        [$homeroom, $students, $ma] = $this->classFixture();
        DB::table('students')->where('id', $students[0])->update(['name' => '=HYPERLINK("http://example.invalid")']);
        $this->as('admin');
        $r = $this->get(self::API.'/courses/'.$ma.'/csv')->assertOk();
        $this->assertStringContainsString('text/csv', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $r->headers->get('Content-Disposition'));
        $csv = $r->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('คะแนนระหว่างภาค (70)', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString('80.00', $csv);
        $p6 = $this->get(self::API.'/classrooms/1/csv')->assertOk()->getContent();
        $this->assertStringContainsString('GPA', $p6);
        $this->assertStringContainsString('3.20', $p6);
        $this->assertStringContainsString(',ร,', $p6);
        $sum = $this->get(self::API.'/classrooms/1/summary/csv')->assertOk()->getContent();
        $this->assertStringContainsString('คะแนนเฉลี่ย', $sum);
        $this->as('teacher', $this->otherTeacher($homeroom))->get(self::API.'/classrooms/1/csv')->assertForbidden();
        $this->as('teacher', 2)->get(self::API.'/courses/'.$ma.'/csv')->assertOk();
    }

    public function test_overview_lists_only_what_the_account_may_open_and_module_is_registered(): void
    {
        [$homeroom] = $this->classFixture();
        $this->as('teacher', 2, null)->getJson(self::API.'/overview')->assertOk()->assertJsonCount(1, 'courses')->assertJsonCount(DB::table('classrooms')->where('homeroom_teacher_id', 2)->count(), 'classrooms')->assertJsonPath('manager', false)->assertJsonMissingPath('templates');
        $this->as('teacher', $homeroom, 1)->getJson(self::API.'/overview')->assertOk()->assertJsonCount(0, 'courses')->assertJsonCount(1, 'classrooms');
        $this->as('admin')->getJson(self::API.'/overview')->assertOk()->assertJsonCount(3, 'courses')->assertJsonPath('manager', true)->assertJsonStructure(['templates', 'settings' => ['show_rank', 'signers']]);
        $this->getJson(self::API.'/overview?term_id=999')->assertNotFound();
        $this->as('editor')->getJson(self::API.'/overview')->assertForbidden();
        $this->assertContains('reports-pp', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->as('teacher', 2)->get('/work')->assertOk()->assertSee('assets/modules/reports-pp/reports.js', false)->assertSee('ปพ. และรายงานผลการเรียน');
    }

    public function test_past_term_roster_only_contains_students_with_rows(): void
    {
        [$homeroom, $students] = $this->room(1);
        $past = $this->term(2568, 2);
        $sub = $this->subject('MA201', 'คณิตศาสตร์ ภาคเรียนเก่า', '1.0');
        $old = $this->course($sub, 1, 2, $past);
        $this->grade($old, $students[0], 56, 24);
        $this->as('admin');
        $d = app(ReportsPp::class)->pp5($old);
        $this->assertCount(1, $d['rows']);                 // today's whole class is another cohort
        $this->assertSame('4', $d['rows'][0]['result']['grade']);
        $this->get('/print/pp/course/'.$old)->assertOk()->assertSee('ปีการศึกษา 2568 ภาคเรียนที่ 2');
    }
}

