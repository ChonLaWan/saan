<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\Assessment\GradePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssessmentModuleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/assessment';
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed(); // term 1 (2569/1) current, teachers 1-6, classrooms 1-3 with students 1-24
    }

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

    private function subject(string $code = 'MA101', array $over = []): array
    {
        return $this->postJson(self::BASE.'/subjects', $over + ['code' => $code, 'name' => 'คณิตศาสตร์พื้นฐาน', 'group_name' => 'คณิตศาสตร์', 'credit' => 1.5, 'hours_per_year' => 60, 'grade_levels' => ['ป.4', 'ป.5']])->assertCreated()->json();
    }

    private function course(int $subjectId, int $classroomId, ?int $teacherId, array $over = []): array
    {
        return $this->postJson(self::BASE.'/courses', $over + ['subject_id' => $subjectId, 'classroom_id' => $classroomId, 'teacher_id' => $teacherId])->assertCreated()->json();
    }

    private function cell(int $student, string $key, mixed $score, int $version = 0): array
    {
        return ['student_id' => $student, 'component_key' => $key, 'score' => $score, 'version' => $version];
    }

    private function term2568(): int
    {
        return DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2568, 'term' => 2, 'starts_on' => '2025-11-01', 'ends_on' => '2026-03-31']);
    }

    // ---- GradePolicy (pure) -------------------------------------------------------------------------------------

    public static function boundaries(): array
    {
        // [continuous centi, exam centi, expected whole total, expected grade]
        return [
            'exactly 80' => [5600, 2400, 80, '4'], '79.50 rounds half up to 80' => [5600, 2350, 80, '4'], '79.49 stays 79' => [5600, 2349, 79, '3.5'],
            'exactly 75' => [5250, 2250, 75, '3.5'], '74.50 -> 75' => [5200, 2250, 75, '3.5'], '74.49 -> 74' => [5200, 2249, 74, '3'],
            'exactly 70' => [4900, 2100, 70, '3'], '69.50 -> 70' => [4850, 2100, 70, '3'], '69.49 -> 69' => [4849, 2100, 69, '2.5'],
            'exactly 65' => [4550, 1950, 65, '2.5'], '64.49 -> 64' => [4500, 1949, 64, '2'],
            'exactly 60' => [4200, 1800, 60, '2'], '59.49 -> 59' => [4200, 1749, 59, '1.5'],
            'exactly 55' => [3850, 1650, 55, '1.5'], '54.49 -> 54' => [3800, 1649, 54, '1'],
            'exactly 50' => [3500, 1500, 50, '1'], '49.50 -> 50' => [3500, 1450, 50, '1'], '49.49 -> 49' => [3500, 1449, 49, '0'],
            'zero' => [0, 0, 0, '0'], 'full' => [7000, 3000, 100, '4'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_thai_eight_level_grade_boundaries(int $c, int $e, int $whole, string $grade): void
    {
        $r = GradePolicy::compute(GradePolicy::defaults(), ['continuous' => $c, 'exam' => $e]);
        $this->assertSame($whole, $r['total']);
        $this->assertSame($grade, $r['grade']);
        $this->assertSame('complete', $r['status']);
    }

    public function test_grade_is_rounded_once_from_the_exact_value_with_a_non_default_policy(): void
    {
        $p = ['components' => [['key' => 'a', 'label' => 'A', 'max' => 40, 'group' => 'continuous'], ['key' => 'x', 'label' => 'X', 'max' => 60, 'group' => 'exam']], 'continuous_ratio' => 60, 'exam_ratio' => 40, 'pass_mark' => 50];
        // exact 79.495: displayed hundredths 79.50, but the whole point is rounded once from the exact value -> 79 -> grade 3.5
        $r = GradePolicy::compute($p, ['a' => 3997, 'x' => 2931]);
        $this->assertSame(7950, $r['total_centi']);
        $this->assertSame(79, $r['total']);
        $this->assertSame('3.5', $r['grade']);
        // a little more (exact 79.508) crosses the half point -> 80 -> grade 4
        $r = GradePolicy::compute($p, ['a' => 3997, 'x' => 2933]);
        $this->assertSame(80, $r['total']);
        $this->assertSame('4', $r['grade']);
        // same rule on a single group policy
        $one = ['components' => [['key' => 'a', 'label' => 'A', 'max' => 30, 'group' => 'continuous']], 'continuous_ratio' => 100, 'exam_ratio' => 0, 'pass_mark' => 50];
        $this->assertSame([7950, 80], [GradePolicy::compute($one, ['a' => 2385])['total_centi'], GradePolicy::compute($one, ['a' => 2385])['total']]);
        $this->assertSame(79, GradePolicy::compute($one, ['a' => 2384])['total']);
    }

    public function test_grade_policy_weights_missing_values_and_validation(): void
    {
        // components scaled to the ratios: 36/40 continuous at 60% = 54, 50/60 exam at 40% = 33.33 -> 87.33 -> 87
        $p = ['components' => [['key' => 'a', 'label' => 'A', 'max' => 40, 'group' => 'continuous'], ['key' => 'x', 'label' => 'X', 'max' => 60, 'group' => 'exam']], 'continuous_ratio' => 60, 'exam_ratio' => 40, 'pass_mark' => 50];
        $r = GradePolicy::compute($p, ['a' => 3600, 'x' => 5000]);
        $this->assertSame(8733, $r['total_centi']);
        $this->assertSame(87, $r['total']);
        $this->assertTrue($r['passed']);
        $this->assertSame('incomplete', GradePolicy::compute($p, ['a' => 3600])['status']);
        $this->assertNull(GradePolicy::compute($p, ['a' => 3600])['grade']);
        // an entered zero is a real zero, a missing value is not
        $this->assertSame('0', GradePolicy::compute($p, ['a' => 0, 'x' => 0])['grade']);
        $this->assertSame(1234, GradePolicy::parseCenti('12.34', 70));
        $this->assertSame(1250, GradePolicy::parseCenti('12.5', 70));
        $this->assertSame(7000, GradePolicy::parseCenti(70, 70));
        foreach (['', '-1', '1.234', '12,5', 'abc', '70.01', ' 5'] as $bad) {
            try { GradePolicy::parseCenti($bad, 70); $this->fail('accepted '.$bad); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        }
        $this->expectException(\InvalidArgumentException::class);
        GradePolicy::normalize(['components' => $p['components'], 'continuous_ratio' => 50, 'exam_ratio' => 40]);
    }

    public function test_passed_always_agrees_with_the_grade_and_pass_mark_cannot_diverge(): void
    {
        foreach ([40, 50, 60] as $mark) {
            $p = GradePolicy::normalize(['components' => GradePolicy::defaults()['components'], 'continuous_ratio' => 70, 'exam_ratio' => 30, 'pass_mark' => $mark]);
            $this->assertSame(50, $p['pass_mark']);
            $low = GradePolicy::compute($p, ['continuous' => 3850, 'exam' => 1650]);     // 55 -> grade 1.5
            $this->assertSame(['1.5', true], [$low['grade'], $low['passed']]);
            $zero = GradePolicy::compute($p, ['continuous' => 3150, 'exam' => 1350]);    // 45 -> grade 0
            $this->assertSame(['0', false], [$zero['grade'], $zero['passed']]);
        }
    }

    public function test_subject_code_key_ignores_inner_spaces(): void
    {
        $this->as('admin');
        $this->subject('ท 11101');
        $this->postJson(self::BASE.'/subjects', ['code' => 'ท11101', 'name' => 'ซ้ำ'])->assertStatus(409);
        $this->postJson(self::BASE.'/subjects', ['code' => 'ท  11101', 'name' => 'ซ้ำ'])->assertStatus(409);
    }

    public function test_course_keeps_a_snapshot_of_the_subject_and_policy_cannot_change_once_scores_exist(): void
    {
        $this->as('admin');
        $s = $this->subject('MA901');
        $c = $this->course($s['id'], 1, 1);
        $row = DB::table('asm_courses')->where('id', $c['id'])->first();
        $this->assertNotNull($row->snap_at);
        $this->assertSame('MA901', $row->subject_code_snap);
        $this->assertEquals(1.5, (float) $row->credit_snap);
        $student = (int) DB::table('students')->where('classroom_id', 1)->value('id');
        $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => [$this->cell($student, 'continuous', '10')]])->assertOk();
        $policy = GradePolicy::defaults();
        $policy['components'][0]['max'] = 60;
        $policy['components'][1]['max'] = 40;
        $this->putJson(self::BASE.'/courses/'.$c['id'], ['version' => $c['version'], 'policy' => $policy])->assertUnprocessable();
    }

    public function test_subject_edit_refreshes_snapshot_of_current_term_courses_but_not_past_terms(): void
    {
        $this->as('admin');
        $s = $this->subject('MA950');
        $c = $this->course($s['id'], 1, 1);
        $past = DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2550, 'term' => 1, 'starts_on' => '2007-05-15', 'ends_on' => '2007-09-30']);
        $old = DB::table('asm_courses')->insertGetId(['school_id' => 1, 'term_id' => $past, 'subject_id' => $s['id'], 'classroom_id' => 2, 'status' => 'open', 'policy' => '{}', 'version' => 1,
            'snap_at' => now(), 'subject_code_snap' => 'MA950', 'subject_name_snap' => 'เดิม', 'credit_snap' => 1.5, 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson(self::BASE.'/subjects/'.$s['id'], ['version' => $s['version'], 'code' => 'MA950', 'name' => 'ชื่อใหม่', 'credit' => 2.0])->assertOk();
        $row = DB::table('asm_courses')->where('id', $c['id'])->first();
        $this->assertEquals(2.0, (float) $row->credit_snap);
        $this->assertSame('ชื่อใหม่', $row->subject_name_snap);
        $listed = collect($this->getJson(self::BASE.'/courses')->assertOk()->json('data') ?? [])->firstWhere('id', $c['id']);
        $this->assertSame('ชื่อใหม่', $listed['subject']['name'] ?? null);
        $frozen = DB::table('asm_courses')->where('id', $old)->first();
        $this->assertEquals(1.5, (float) $frozen->credit_snap);
        $this->assertSame('เดิม', $frozen->subject_name_snap);
    }

    // ---- setup ---------------------------------------------------------------------------------------------------
    public function test_subjects_crud_with_normalised_unique_key_and_delete_guard(): void
    {
        $this->as('admin');
        $s = $this->subject('MA101');
        $this->assertSame(1, $s['version']);
        // case and spacing never produce a second row, and it is a 409 (not a 500)
        $this->postJson(self::BASE.'/subjects', ['code' => ' ma101 ', 'name' => 'ซ้ำ'])->assertStatus(409);
        $other = $this->subject('TH101', ['name' => 'ภาษาไทย']);
        $this->putJson(self::BASE.'/subjects/'.$other['id'], ['version' => 1, 'code' => 'Ma101', 'name' => 'ชนรหัส'])->assertStatus(409);
        $this->postJson(self::BASE.'/subjects', ['code' => '', 'name' => 'x'])->assertUnprocessable();
        $this->postJson(self::BASE.'/subjects', ['code' => 'X1', 'name' => 'x', 'credit' => 120])->assertUnprocessable();
        $up = $this->putJson(self::BASE.'/subjects/'.$s['id'], ['version' => 1, 'code' => 'MA101', 'name' => 'คณิตศาสตร์ ป.4', 'is_active' => true])->assertOk()->json();
        $this->assertSame(2, $up['version']);
        $this->putJson(self::BASE.'/subjects/'.$s['id'], ['version' => 1, 'code' => 'MA101', 'name' => 'stale'])->assertStatus(409)->assertJsonStructure(['message', 'current']);
        $this->course($s['id'], 1, 1);
        $this->deleteJson(self::BASE.'/subjects/'.$s['id'], ['version' => 2])->assertUnprocessable();
        $this->deleteJson(self::BASE.'/subjects/'.$other['id'], ['version' => 1])->assertOk();
        $this->as('teacher', 1);
        $this->getJson(self::BASE.'/subjects')->assertOk();
        $this->postJson(self::BASE.'/subjects', ['code' => 'ZZ', 'name' => 'x'])->assertForbidden();
    }

    public function test_courses_create_bulk_copy_and_update(): void
    {
        $this->as('admin');
        $look = $this->getJson(self::BASE.'/lookups')->assertOk()->json();
        $this->assertContains('ป.4', $look['grade_levels']);
        $this->assertCount(6, $look['teachers']);
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 2);
        $this->assertSame(2569, $c['term']['year']);
        $this->assertSame('MA101', $c['subject']['code']);
        $this->assertSame(70, $c['policy']['components'][0]['max']);
        $this->postJson(self::BASE.'/courses', ['subject_id' => $s['id'], 'classroom_id' => 1])->assertStatus(409);
        $this->postJson(self::BASE.'/courses', ['subject_id' => $s['id'], 'classroom_id' => 99])->assertUnprocessable();
        $this->postJson(self::BASE.'/courses', ['subject_id' => $s['id'], 'classroom_id' => 2, 'teacher_id' => 99])->assertUnprocessable();
        $this->postJson(self::BASE.'/courses', ['subject_id' => $s['id'], 'classroom_id' => 2, 'policy' => ['components' => [['key' => 'a', 'label' => 'A', 'max' => 50, 'group' => 'continuous']], 'continuous_ratio' => 70, 'exam_ratio' => 30]])->assertUnprocessable();

        // bulk: every ป.4 room (only ป.4/1 in the seed) plus a new ป.4/2; the existing course is skipped
        DB::table('classrooms')->insert(['school_id' => 1, 'name' => 'ป.4/2', 'homeroom_teacher_id' => 2, 'name_key' => 'ป.4/2']);
        $bulk = $this->postJson(self::BASE.'/courses/bulk', ['subject_id' => $s['id'], 'grade_level' => 'ป.4', 'teacher_id' => 3])->assertCreated()->json();
        $this->assertSame(['created' => 1, 'skipped' => 1], $bulk);
        $this->postJson(self::BASE.'/courses/bulk', ['subject_id' => $s['id'], 'grade_level' => 'ป.9'])->assertUnprocessable();
        $this->assertSame(1, DB::table('asm_courses')->where('classroom_id', 1)->count());

        // update teacher, close and reopen
        $u = $this->putJson(self::BASE.'/courses/'.$c['id'], ['version' => 1, 'teacher_id' => 4])->assertOk()->json();
        $this->assertSame(4, $u['teacher']['id']);
        $this->putJson(self::BASE.'/courses/'.$c['id'], ['version' => 1, 'teacher_id' => 5])->assertStatus(409);
        $closed = $this->putJson(self::BASE.'/courses/'.$c['id'], ['version' => 2, 'status' => 'closed'])->assertOk()->json();
        $this->assertSame('closed', $closed['status']);

        // copy previous term into a new one: teacher and policy follow, scores do not
        $prev = $this->term2568();
        $copy = $this->postJson(self::BASE.'/courses/copy', ['from_term_id' => 1, 'to_term_id' => $prev])->assertCreated()->json();
        $this->assertSame(['created' => 2, 'skipped' => 0], $copy);
        $this->assertSame(0, DB::table('asm_scores')->count());
        $again = $this->postJson(self::BASE.'/courses/copy', ['from_term_id' => 1, 'to_term_id' => $prev])->assertCreated()->json();
        $this->assertSame(['created' => 0, 'skipped' => 2], $again);
        $this->postJson(self::BASE.'/courses/copy', ['from_term_id' => 1, 'to_term_id' => 1])->assertUnprocessable();
        $this->assertSame('open', DB::table('asm_courses')->where('term_id', $prev)->first()->status);

        // delete is refused once scores exist
        $fresh = $this->course($s['id'], 3, 1);
        $this->putJson(self::BASE.'/courses/'.$fresh['id'].'/scores', ['cells' => [$this->cell(17, 'continuous', '10')]])->assertOk();
        $this->deleteJson(self::BASE.'/courses/'.$fresh['id'], ['version' => 1])->assertUnprocessable();
        $this->putJson(self::BASE.'/courses/'.$fresh['id'], ['version' => 1, 'policy' => ['components' => [['key' => 'a', 'label' => 'A', 'max' => 100, 'group' => 'continuous']], 'continuous_ratio' => 100, 'exam_ratio' => 0]])->assertUnprocessable();
    }

    // ---- GAP-OPS-002: teaching assignment, not homeroom ---------------------------------------------------------

    public function test_gap_ops_002_teacher_scope_follows_assigned_course_not_homeroom(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $mine = $this->course($s['id'], 2, 1);     // teacher 1 teaches classroom 2 (not homeroom)
        $theirs = $this->course($s['id'], 1, 2);   // classroom 1 is teacher 1's homeroom, but teacher 2 teaches this subject
        $teacher = $this->user('teacher', 1, 1);
        // reproduction: the shared homeroom rule still treats classroom 1 as "mine" ...
        $this->assertContains(1, TeacherScope::homeroomIds($teacher));
        $this->app['auth']->forgetGuards();
        $this->actingAs($teacher);
        // ... and the old prototype is homeroom-only: it refuses the class this teacher really teaches and lists only the homeroom
        $state = $this->getJson('/api/state');
        if ($state->status() !== 200) $this->markTestSkipped('legacy /api/state retired; the GAP-OPS-002 reproduction no longer applies');
        $this->assertSame([1], collect($state->json('classrooms'))->pluck('id')->all());
        $this->postJson('/api/grades', ['student_id' => 9, 'continuous' => 10, 'exam' => 5])->assertStatus(410);   // N8: the prototype write path is retired (it used to refuse student 9 of classroom 2, taught by teacher 1)
        // ... but the course gate only follows asm_courses.teacher_id
        $this->getJson(self::BASE.'/courses/'.$theirs['id'].'/scores')->assertForbidden();
        $this->putJson(self::BASE.'/courses/'.$theirs['id'].'/scores', ['cells' => [$this->cell(1, 'continuous', '10')]])->assertForbidden();
        $this->getJson(self::BASE.'/courses/'.$theirs['id'].'/revisions')->assertForbidden();
        $this->getJson(self::BASE.'/courses/'.$mine['id'].'/scores')->assertOk()->assertJsonCount(8, 'students');
        $this->putJson(self::BASE.'/courses/'.$mine['id'].'/scores', ['cells' => [$this->cell(9, 'continuous', '10')]])->assertOk();
        $ids = collect($this->getJson(self::BASE.'/courses')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$mine['id']], $ids);
        $this->getJson(self::BASE.'/courses/9999/scores')->assertNotFound();
        $this->postJson(self::BASE.'/courses', ['subject_id' => $s['id'], 'classroom_id' => 3])->assertForbidden();
        // a teacher account without a teacher link, and other roles, see nothing
        $this->as('teacher')->getJson(self::BASE.'/courses')->assertOk()->assertJsonCount(0, 'data');
        $this->as('editor')->getJson(self::BASE.'/courses')->assertForbidden();
        // admin and principal reach everything
        $this->as('admin')->getJson(self::BASE.'/courses/'.$theirs['id'].'/scores')->assertOk();
        $this->as('principal', 6)->getJson(self::BASE.'/courses')->assertOk()->assertJsonCount(2, 'data');
    }

    // ---- OPS-001: term scope -------------------------------------------------------------------------------------

    public function test_ops_001_current_term_endpoints_never_return_other_terms(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::where('role', 'admin')->first());
        // reproduction: the prototype grade list is not filtered by term
        $old = $this->term2568();
        DB::table('grade_entries')->insert(['school_id' => 1, 'term_id' => $old, 'student_id' => 1, 'subject' => 'old term', 'continuous_centi' => 100, 'exam_centi' => 100,
            'policy_version' => 'x', 'recorded_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $legacy = $this->getJson('/api/state');
        if ($legacy->status() !== 200) $this->markTestSkipped('legacy /api/state retired; the OPS-001 reproduction no longer applies');
        $legacy->assertOk();
        // OPS-010: the overview is now current-term scoped, so the old-term row no longer leaks
        $this->assertNotContains('old term', collect($legacy->json('grades'))->pluck('subject')->all());

        $this->as('admin');
        $s = $this->subject();
        $now = $this->course($s['id'], 1, 1);
        $past = $this->course($s['id'], 1, 1, ['term_id' => $old]);
        DB::table('asm_scores')->insert(['school_id' => 1, 'course_id' => $past['id'], 'student_id' => 1, 'component_key' => 'continuous', 'score_centi' => 3300, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $list = $this->getJson(self::BASE.'/courses')->assertOk()->json();
        $this->assertSame([$now['id']], array_column($list['data'], 'id'));
        $this->assertSame(['id' => 1, 'year' => 2569, 'term' => 1], $list['term']);
        $this->assertSame('MA101', $list['data'][0]['subject']['code']);
        $this->assertSame(2569, $list['data'][0]['term']['year']);
        // a term_id in the query string cannot widen the current endpoint
        $this->assertSame([$now['id']], array_column($this->getJson(self::BASE.'/courses?term_id='.$old)->assertOk()->json('data'), 'id'));
        // history needs an explicit term and is labelled
        $this->getJson(self::BASE.'/history/courses')->assertUnprocessable();
        $this->getJson(self::BASE.'/history/courses?term_id=9999')->assertNotFound();
        $h = $this->getJson(self::BASE.'/history/courses?term_id='.$old)->assertOk()->json();
        $this->assertSame([$past['id']], array_column($h['data'], 'id'));
        $this->assertSame(2568, $h['term']['year']);
        $this->assertTrue($h['history']);
        $this->assertSame(1, $h['data'][0]['cells_entered']);
        // each course carries its own term so the UI can always print subject + year/term
        $this->assertSame(2568, $this->getJson(self::BASE.'/courses/'.$past['id'].'/scores')->json('course.term.year'));
    }

    // ---- OPS-004 / OPS-008: compare-and-swap, revisions, fresh rows -----------------------------------------------

    public function test_ops_004_stale_write_is_rejected_and_every_change_keeps_a_revision(): void
    {
        $this->as('admin');
        // reproduction: the prototype grade write has no version column and silently overwrites
        $this->assertFalse(Schema::hasColumn('grade_entries', 'version'));
        // N8: the prototype overwrite path is gone: it answers 410 and writes nothing
        $this->postJson('/api/grades', ['student_id' => 1, 'continuous' => 60, 'exam' => 20])->assertStatus(410);
        $this->postJson('/api/grades', ['student_id' => 1, 'continuous' => 10, 'exam' => 5])->assertStatus(410);
        $this->assertSame(0, DB::table('grade_entries')->where('student_id', 1)->count());

        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $url = self::BASE.'/courses/'.$c['id'].'/scores';
        $first = $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '50.5'), $this->cell(1, 'exam', 20)], 'reason' => 'กรอกครั้งแรก'])->assertOk()->json();
        $this->assertSame(2, $first['saved']);
        $cellA = collect($first['scores'])->firstWhere('component_key', 'continuous');
        $this->assertSame(5050, $cellA['score_centi']);
        $this->assertSame(1, $cellA['version']);

        // a second editor still holding version 0 is rejected as a whole and learns the current values
        $stale = $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '10'), $this->cell(2, 'continuous', '40')]])->assertStatus(409)->json();
        $this->assertSame(5050, $stale['current']['conflicts'][0]['score_centi']);
        $this->assertSame(1, $stale['current']['conflicts'][0]['version']);
        $this->assertSame(2, DB::table('asm_scores')->count(), 'no cell of a rejected batch is written');

        $second = $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '55.25', 1)], 'reason' => 'ตรวจกระดาษใหม่'])->assertOk()->json();
        $this->assertSame(1, $second['saved']);
        $this->assertSame(2, collect($second['scores'])->firstWhere('component_key', 'continuous')['version']);
        // same value again is not a change: no version bump, no revision
        $same = $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '55.25', 2)]])->assertOk()->json();
        $this->assertSame(0, $same['saved']);
        $this->assertSame(2, collect($same['scores'])->firstWhere('component_key', 'continuous')['version']);

        $rev = $this->getJson($url = self::BASE.'/courses/'.$c['id'].'/revisions?student_id=1&component_key=continuous')->assertOk()->json('data');
        $this->assertCount(2, $rev);
        $this->assertSame([5050, 5525], [$rev[1]['new_centi'], $rev[0]['new_centi']]);
        $this->assertNull($rev[1]['old_centi']);
        $this->assertSame(5050, $rev[0]['old_centi']);
        $this->assertSame('ตรวจกระดาษใหม่', $rev[0]['reason']);
        $this->assertNotNull($rev[0]['changed_by']);
        $this->assertSame(3, DB::table('asm_score_revisions')->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'assessment.scores.saved')->where('entity_id', $c['id'])->count());

        // clearing keeps history; validation rejects bad numbers and out-of-class students atomically
        $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => [$this->cell(1, 'exam', null, 1)]])->assertOk();
        $this->assertSame(1, DB::table('asm_scores')->whereNotNull('score_centi')->count());
        $this->assertSame(2, DB::table('asm_scores')->count(), 'a cleared cell keeps its row so its version keeps growing');
        $this->assertNull(DB::table('asm_score_revisions')->where('component_key', 'exam')->orderByDesc('id')->first()->new_centi);
        $bad = fn (array $cells) => $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => $cells])->assertUnprocessable();
        $bad([$this->cell(3, 'continuous', '10'), $this->cell(3, 'continuous', '71')]);   // over max 70 (and duplicate)
        $bad([$this->cell(3, 'continuous', '1.234')]);
        $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => [$this->cell(3, 'continuous', '')]])->assertOk()->assertJsonPath('saved', 0); // blank is null: never stored as zero
        $bad([$this->cell(3, 'nope', '1')]);
        $bad([$this->cell(20, 'continuous', '1')]);                                        // student of another room
        $bad([$this->cell(3, 'continuous', ['x'])]);
        $this->assertSame(1, DB::table('asm_scores')->whereNotNull('score_centi')->count());
        $this->assertSame(0, DB::table('asm_scores')->where('student_id', 3)->count());
    }

    public function test_ops_004_cleared_cell_keeps_its_version_so_a_stale_editor_cannot_win_after_aba(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $url = self::BASE.'/courses/'.$c['id'].'/scores';
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '50', 0)]])->assertOk();                 // v1 = 50 (the stale editor loads this)
        $cleared = $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', null, 1)]])->assertOk()->json();  // v2 = empty
        $row = collect($cleared['scores'])->firstWhere('component_key', 'continuous');
        $this->assertNull($row['score_centi']);
        $this->assertSame(2, $row['version']);
        $this->assertSame('incomplete', collect($cleared['totals'])->firstWhere('student_id', 1)['status']);
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '60', 2)]])->assertOk();                  // v3 = 60 by someone else
        // the stale editor still holds version 1: must be a conflict, value untouched
        $stale = $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '55', 1)]])->assertStatus(409)->json();
        $this->assertSame(6000, $stale['current']['conflicts'][0]['score_centi']);
        $this->assertSame(3, $stale['current']['conflicts'][0]['version']);
        $this->assertSame(6000, (int) DB::table('asm_scores')->where('student_id', 1)->value('score_centi'));
        $this->assertSame(1, DB::table('asm_scores')->count());
        // history keeps every step: null -> 50 -> null -> 60
        $this->assertSame(3, DB::table('asm_score_revisions')->count());
        // clearing an empty cell is not a change; the course lists only filled cells
        $this->putJson($url, ['cells' => [$this->cell(2, 'continuous', null, 0)]])->assertOk()->assertJsonPath('saved', 0);
        $this->assertSame(1, $this->getJson(self::BASE.'/courses')->json('data.0.cells_entered'));
    }

    public function test_cell_without_score_key_is_rejected_instead_of_clearing(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $url = self::BASE.'/courses/'.$c['id'].'/scores';
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '50', 0)]])->assertOk();
        $this->putJson($url, ['cells' => [['student_id' => 1, 'component_key' => 'continuous', 'version' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['cells.0.score']);
        $this->assertSame(5000, (int) DB::table('asm_scores')->where('student_id', 1)->value('score_centi'));
        $this->assertSame(1, (int) DB::table('asm_scores')->where('student_id', 1)->value('version'));
    }

    // ---- OPS-001: a past term is read-only for teachers, reasoned correction for admin ---------------------------------

    public function test_past_term_course_rejects_teacher_writes_and_needs_a_reason_from_admin(): void
    {
        $old = $this->term2568();
        $this->as('admin');
        $s = $this->subject();
        $past = $this->course($s['id'], 1, 1, ['term_id' => $old]);
        $now = $this->course($s['id'], 1, 1);
        DB::table('asm_scores')->insert(['school_id' => 1, 'course_id' => $past['id'], 'student_id' => 1, 'component_key' => 'continuous', 'score_centi' => 4000, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $url = self::BASE.'/courses/'.$past['id'].'/scores';
        $read = $this->getJson($url)->assertOk()->json();
        $this->assertSame(1, $read['current_term_id']);
        $this->assertSame(2568, $read['course']['term']['year']);

        $this->as('teacher', 1);
        $this->getJson($url)->assertOk();
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '41', 1)], 'reason' => 'ขอแก้'])->assertStatus(423);
        $this->putJson(self::BASE.'/courses/'.$past['id'].'/attributes', ['cells' => [['student_id' => 1, 'kind' => 'reading', 'item_code' => 'R1', 'level' => 2, 'version' => 0]]])->assertStatus(423);
        $this->assertSame(4000, (int) DB::table('asm_scores')->where('course_id', $past['id'])->value('score_centi'));
        // the same teacher can still write the current-term course
        $this->putJson(self::BASE.'/courses/'.$now['id'].'/scores', ['cells' => [$this->cell(1, 'continuous', '41')]])->assertOk();

        $this->as('admin');
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '41', 1)]])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '41', 1)], 'reason' => 'แก้ตามคำร้องผู้ปกครอง'])->assertOk()->assertJsonPath('saved', 1);
        $this->assertSame('แก้ตามคำร้องผู้ปกครอง', DB::table('asm_score_revisions')->where('course_id', $past['id'])->value('reason'));
    }

    public function test_past_term_roster_is_its_own_cohort_and_moved_students_stay_visible(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $url = self::BASE.'/courses/'.$c['id'].'/scores';
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '40'), $this->cell(2, 'continuous', '30')]])->assertOk();
        // student 1 moves to another class (promotion / transfer); student 9 of another class is now in classroom 1
        DB::table('students')->where('id', 1)->update(['classroom_id' => 2]);
        DB::table('students')->where('id', 9)->update(['classroom_id' => 1]);
        $r = $this->getJson($url)->assertOk()->json();
        $byId = collect($r['students'])->keyBy('id');
        $this->assertTrue($byId->has(1), 'the moved student keeps showing in the course that holds their score');
        $this->assertFalse($byId[1]['in_class']);
        $this->assertTrue($byId[2]['in_class']);
        $this->assertTrue($byId->has(9));
        $this->assertTrue($byId[9]['in_class']);
        $this->assertSame(4000, collect($r['scores'])->first(fn ($x) => $x['student_id'] === 1)['score_centi']);
        // a correction for the moved student is still accepted
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '41', 1)], 'reason' => 'แก้ไข'])->assertOk();
        // a student of an unrelated class is not
        $this->putJson($url, ['cells' => [$this->cell(17, 'continuous', '5')]])->assertUnprocessable();

        // past-term course: today's class list is another cohort, only students with rows count
        $old = $this->term2568();
        $past = $this->course($s['id'], 1, 1, ['term_id' => $old]);
        DB::table('asm_scores')->insert(['school_id' => 1, 'course_id' => $past['id'], 'student_id' => 2, 'component_key' => 'continuous', 'score_centi' => 2500, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $purl = self::BASE.'/courses/'.$past['id'].'/scores';
        $this->assertSame([2], collect($this->getJson($purl)->assertOk()->json('students'))->pluck('id')->all());
        $this->putJson($purl, ['cells' => [$this->cell(9, 'continuous', '5')], 'reason' => 'ทดสอบ'])->assertUnprocessable();   // new cohort cannot get scores in an old course
        $this->putJson($purl, ['cells' => [$this->cell(2, 'continuous', '26', 1)], 'reason' => 'ทดสอบ'])->assertOk();
        $this->assertSame(1, $this->getJson(self::BASE.'/history/courses?term_id='.$old)->json('data.0.students_count'));
    }

    public function test_course_delete_is_refused_when_history_or_attributes_exist(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => [$this->cell(1, 'continuous', '10')]])->assertOk();
        $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => [$this->cell(1, 'continuous', null, 1)]])->assertOk();
        $this->deleteJson(self::BASE.'/courses/'.$c['id'], ['version' => 1])->assertUnprocessable();    // cleared cell row + revisions remain
        $d = $this->course($s['id'], 2, 1);
        $this->putJson(self::BASE.'/courses/'.$d['id'].'/attributes', ['cells' => [['student_id' => 9, 'kind' => 'reading', 'item_code' => 'R1', 'level' => 2, 'version' => 0]]])->assertOk();
        $this->deleteJson(self::BASE.'/courses/'.$d['id'], ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors(['id']);
        $e = $this->course($s['id'], 3, 1);
        $this->deleteJson(self::BASE.'/courses/'.$e['id'], ['version' => 1])->assertOk();
    }

    public function test_term_level_attributes_by_homeroom_teacher_with_versions(): void
    {
        $this->as('admin');
        $url = self::BASE.'/terms/current/attributes';
        $cell = fn (int $sid, string $item, int $level, int $v) => ['student_id' => $sid, 'kind' => 'characteristic', 'item_code' => $item, 'level' => $level, 'version' => $v];
        // teacher 1 is homeroom of classroom 1 only
        $this->as('teacher', 1, 1);
        $this->getJson($url.'?classroom_id=2')->assertForbidden();
        $this->putJson($url, ['classroom_id' => 2, 'cells' => [$cell(9, 'C1', 3, 0)]])->assertForbidden();
        $this->getJson($url.'?classroom_id=999')->assertNotFound();
        $r = $this->putJson($url, ['classroom_id' => 1, 'cells' => [$cell(1, 'C1', 3, 0), $cell(2, 'C1', 2, 0)]])->assertOk()->json();
        $this->assertCount(2, $r['data']);
        $this->assertSame(0, DB::table('asm_attributes')->where('course_id', '!=', 0)->count());
        $this->assertSame(1, (int) DB::table('asm_attributes')->where('student_id', 1)->value('term_id'));
        $this->putJson($url, ['classroom_id' => 1, 'cells' => [$cell(1, 'C1', 1, 0)]])->assertStatus(409);
        $this->putJson($url, ['classroom_id' => 1, 'cells' => [$cell(9, 'C1', 1, 0)]])->assertUnprocessable();    // not a student of this class
        $up = $this->putJson($url, ['classroom_id' => 1, 'cells' => [$cell(1, 'C1', 1, 1)]])->assertOk()->json('data');
        $this->assertSame([1, 2], [$up[0]['level'], $up[0]['version']]);
        $this->assertCount(2, $this->getJson($url.'?classroom_id=1&kind=characteristic')->assertOk()->json('data'));
        // admin reaches any class; other roles none
        $this->as('admin')->getJson($url.'?classroom_id=2')->assertOk()->assertJsonCount(8, 'students');
        $this->as('editor')->getJson($url.'?classroom_id=1')->assertForbidden();
    }

    public function test_lookup_grade_levels_prefer_the_academic_grade_level_column(): void
    {
        $this->as('admin');
        DB::table('classrooms')->insert(['school_id' => 1, 'name' => 'ห้องพิเศษ A', 'homeroom_teacher_id' => 2, 'name_key' => 'ห้องพิเศษ a']);
        if (!Schema::hasColumn('classrooms', 'grade_level')) {
            $g = $this->getJson(self::BASE.'/lookups')->assertOk()->json('grade_levels');
            $this->assertContains('ป.4', $g);
            $this->markTestSkipped('classrooms.grade_level is added by the academic module; the name-prefix fallback was checked');
        }
        DB::table('classrooms')->where('name', 'ห้องพิเศษ A')->update(['grade_level' => 'ป.6']);
        $g = $this->getJson(self::BASE.'/lookups')->assertOk()->json('grade_levels');
        $this->assertContains('ป.6', $g);
        $this->assertNotContains('ห้องพิเศษ A', $g);
    }

    public function test_ops_008_response_is_persisted_data_and_idempotency_key_replays(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $url = self::BASE.'/courses/'.$c['id'].'/scores';
        $r = $this->withHeader('Idempotency-Key', 'save-1')->putJson($url, ['cells' => [$this->cell(1, 'continuous', '70'), $this->cell(1, 'exam', '30')]])->assertOk()->json();
        // fresh rows come from the database (centi + version + timestamp) and totals/grade are computed by the server
        $this->assertSame(['student_id' => 1, 'component_key' => 'continuous', 'score_centi' => 7000, 'version' => 1], array_intersect_key(collect($r['scores'])->firstWhere('component_key', 'continuous'), array_flip(['student_id', 'component_key', 'score_centi', 'version'])));
        $this->assertNotSame('', collect($r['scores'])->first()['updated_at']);
        $t = collect($r['totals'])->firstWhere('student_id', 1);
        $this->assertSame(['complete', 100, '4'], [$t['status'], $t['total'], $t['grade']]);
        $this->assertSame('incomplete', collect($r['totals'])->firstWhere('student_id', 2)['status']);
        $this->assertCount(8, $r['totals']);
        // a retry with the same key replays the answer instead of conflicting with its own first write
        $again = $this->withHeader('Idempotency-Key', 'save-1')->putJson($url, ['cells' => [$this->cell(1, 'continuous', '70'), $this->cell(1, 'exam', '30')]])->assertOk();
        $again->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(2, DB::table('asm_score_revisions')->count());
        // GET returns the same persisted values the PUT returned
        $this->assertSame($r['scores'], $this->getJson($url)->json('scores'));
    }

    public function test_closed_course_is_read_only_until_reopened(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $url = self::BASE.'/courses/'.$c['id'].'/scores';
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '40')]])->assertOk();
        $this->putJson(self::BASE.'/courses/'.$c['id'], ['version' => 1, 'status' => 'closed'])->assertOk();
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '41', 1)]])->assertStatus(423);
        $this->putJson(self::BASE.'/courses/'.$c['id'].'/attributes', ['cells' => [['student_id' => 1, 'kind' => 'reading', 'item_code' => 'R1', 'level' => 2, 'version' => 0]]])->assertStatus(423);
        $this->getJson($url)->assertOk()->assertJsonPath('course.status', 'closed');
        $this->assertSame(1, DB::table('asm_score_revisions')->count());
        // the assigned teacher cannot reopen
        $this->as('teacher', 1)->putJson(self::BASE.'/courses/'.$c['id'], ['version' => 2, 'status' => 'open'])->assertForbidden();
        $this->as('admin');
        $this->putJson(self::BASE.'/courses/'.$c['id'], ['version' => 2, 'status' => 'open'])->assertOk();
        $this->putJson($url, ['cells' => [$this->cell(1, 'continuous', '41', 1)], 'reason' => 'เปิดแก้ตามคำสั่ง'])->assertOk();
    }

    public function test_attributes_are_saved_per_course_with_versions(): void
    {
        $this->as('teacher', 1);
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $this->as('teacher', 1);
        $url = self::BASE.'/courses/'.$c['id'].'/attributes';
        $cells = [['student_id' => 1, 'kind' => 'characteristic', 'item_code' => 'C1', 'level' => 3, 'version' => 0], ['student_id' => 1, 'kind' => 'reading', 'item_code' => 'R1', 'level' => 0, 'version' => 0]];
        $saved = $this->putJson($url, ['cells' => $cells])->assertOk()->json();
        $this->assertCount(2, $saved['attributes']);
        $this->putJson($url, ['cells' => [$cells[0]]])->assertStatus(409);
        $this->putJson($url, ['cells' => [['student_id' => 1, 'kind' => 'characteristic', 'item_code' => 'C1', 'level' => 4, 'version' => 1]]])->assertUnprocessable();
        $this->putJson($url, ['cells' => [['student_id' => 1, 'kind' => 'bogus', 'item_code' => 'C1', 'level' => 1, 'version' => 1]]])->assertUnprocessable();
        $this->putJson($url, ['cells' => [['student_id' => 20, 'kind' => 'competency', 'item_code' => 'K1', 'level' => 1, 'version' => 0]]])->assertUnprocessable();
        $up = $this->putJson($url, ['cells' => [['student_id' => 1, 'kind' => 'characteristic', 'item_code' => 'C1', 'level' => 2, 'version' => 1]]])->assertOk()->json('attributes');
        $this->assertSame([2, 2], [$up[0]['level'], $up[0]['version']]);
        $this->assertCount(1, $this->getJson($url.'?kind=reading')->assertOk()->json('data'));
    }

    public function test_a_mistaken_attribute_cell_can_be_deleted_with_its_version_and_is_audited(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $this->as('teacher', 1);
        $url = self::BASE.'/courses/'.$c['id'].'/attributes';
        $this->putJson($url, ['cells' => [['student_id' => 1, 'kind' => 'reading', 'item_code' => 'TYPO1', 'level' => 2, 'version' => 0], ['student_id' => 1, 'kind' => 'reading', 'item_code' => 'R1', 'level' => 1, 'version' => 0]]])->assertOk();
        $this->deleteJson($url, ['cells' => [['student_id' => 1, 'kind' => 'reading', 'item_code' => 'TYPO1', 'version' => 5]]])->assertStatus(409);
        $this->assertSame(2, DB::table('asm_attributes')->where('course_id', $c['id'])->count());
        $this->deleteJson($url, ['cells' => [['student_id' => 1, 'kind' => 'reading', 'item_code' => 'TYPO1', 'version' => 1]]])->assertOk()->assertJsonCount(1, 'attributes');
        $this->assertSame(0, DB::table('asm_attributes')->where('item_code', 'TYPO1')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'assessment.attributes.deleted')->count());
        $this->deleteJson($url, ['cells' => [['student_id' => 1, 'kind' => 'reading', 'item_code' => 'TYPO1', 'version' => 1]]])->assertNotFound();
        // a closed course takes no deletes either
        $this->as('admin')->putJson(self::BASE.'/courses/'.$c['id'], ['version' => $c['version'], 'status' => 'closed'])->assertOk();
        $this->as('teacher', 1)->deleteJson($url, ['cells' => [['student_id' => 1, 'kind' => 'reading', 'item_code' => 'R1', 'version' => 1]]])->assertStatus(423);
    }

    public function test_course_delete_with_a_stale_version_is_a_conflict_and_keeps_the_course(): void
    {
        $this->as('admin');
        $s = $this->subject();
        $c = $this->course($s['id'], 1, 1);
        $this->deleteJson(self::BASE.'/courses/'.$c['id'], ['version' => 7])->assertStatus(409);
        $this->assertSame(1, DB::table('asm_courses')->where('id', $c['id'])->count());
    }
    // ---- G8 performance ------------------------------------------------------------------------------------------

    private function bigClass(): array
    {
        DB::table('classrooms')->insert(['id' => 10, 'school_id' => 1, 'name' => 'ป.6/1', 'homeroom_teacher_id' => 1, 'name_key' => 'ป.6/1']);
        $rows = [];
        for ($i = 1; $i <= 45; $i++) $rows[] = ['school_id' => 1, 'classroom_id' => 10, 'code' => sprintf('BIG-%02d', $i), 'name' => 'นักเรียนสมมติ '.$i, 'code_key' => sprintf('big-%02d', $i)];
        DB::table('students')->insert($rows);
        $s = $this->subject('SC101');
        $comp = fn ($k, $max, $g) => ['key' => $k, 'label' => strtoupper($k), 'max' => $max, 'group' => $g];
        $policy = ['components' => [$comp('a1', 14, 'continuous'), $comp('a2', 14, 'continuous'), $comp('a3', 14, 'continuous'), $comp('a4', 14, 'continuous'), $comp('a5', 14, 'continuous'), $comp('e1', 30, 'exam')],
            'continuous_ratio' => 70, 'exam_ratio' => 30, 'pass_mark' => 50];
        $c = $this->course($s['id'], 10, 1, ['policy' => $policy]);
        return [$c, DB::table('students')->where('classroom_id', 10)->orderBy('id')->pluck('id')->all()];
    }

    public function test_bulk_grid_save_of_45_students_x_6_components_stays_within_query_budget(): void
    {
        $this->as('teacher', 1)->as('admin');
        [$c, $students] = $this->bigClass();
        $this->as('teacher', 1);
        $cells = [];
        foreach ($students as $i => $id) foreach (['a1', 'a2', 'a3', 'a4', 'a5', 'e1'] as $k) $cells[] = $this->cell($id, $k, (string) (($i + 1) % 10 + 1) . '.5');
        $this->assertCount(270, $cells);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => $cells, 'reason' => 'กรอกทั้งห้อง'])->assertOk()->json();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(15, $n, "bulk save used $n queries");
        $this->assertSame(270, $r['saved']);
        $this->assertCount(270, $r['scores']);
        $this->assertSame(270, DB::table('asm_score_revisions')->count());
        // editing 270 existing cells is also one constant-size batch
        $cells = array_map(fn ($x) => ['version' => 1, 'score' => '5'] + $x, $cells);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => $cells])->assertOk()->assertJsonPath('saved', 270);
        $n2 = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(15, $n2, "bulk update used $n2 queries");
    }

    public function test_class_read_uses_constant_queries_and_indexes_exist(): void
    {
        $this->as('admin');
        [$c, $students] = $this->bigClass();
        $count = function () use ($c) {
            DB::flushQueryLog(); DB::enableQueryLog();
            $this->getJson(self::BASE.'/courses/'.$c['id'].'/scores')->assertOk();
            $n = count(DB::getQueryLog()); DB::disableQueryLog();
            return $n;
        };
        $empty = $count();
        $cells = [];
        foreach ($students as $id) foreach (['a1', 'a2', 'a3', 'a4', 'a5', 'e1'] as $k) $cells[] = $this->cell($id, $k, '1');
        $this->putJson(self::BASE.'/courses/'.$c['id'].'/scores', ['cells' => $cells])->assertOk();
        $this->assertSame($empty, $count(), 'read query count must not depend on the number of students or scores');
        $this->assertLessThanOrEqual(6, $empty);

        $has = function (string $table, array $columns): bool {
            foreach (Schema::getIndexes($table) as $ix) if (array_slice($ix['columns'], 0, count($columns)) === $columns) return true;
            return false;
        };
        $this->assertTrue($has('asm_scores', ['course_id', 'student_id']));
        $this->assertTrue($has('asm_scores', ['course_id', 'student_id', 'component_key']));
        $this->assertTrue($has('asm_scores', ['student_id']));
        $this->assertTrue($has('asm_score_revisions', ['score_id']));
        $this->assertTrue($has('asm_score_revisions', ['course_id', 'student_id']));
        $this->assertTrue($has('asm_attributes', ['course_id', 'student_id']));
        $this->assertTrue($has('asm_attributes', ['term_id', 'course_id', 'student_id', 'kind', 'item_code']));
        $this->assertTrue($has('asm_courses', ['term_id', 'subject_id', 'classroom_id']));
        $this->assertTrue($has('asm_courses', ['school_id', 'term_id', 'teacher_id']));
        $this->assertTrue($has('asm_subjects', ['school_id', 'code_key']));
    }

    public function test_module_menu_assets_and_old_prototype_table_untouched(): void
    {
        $reg = app(\App\Support\Modules\ModuleRegistry::class);
        $this->assertContains('assessment', array_column($reg->summary(), 'id'));
        $this->assertContains('scores', array_column($reg->workMenu('teacher'), 'key'));
        $this->assertNotContains('courses', array_column($reg->workMenu('teacher'), 'key'));
        $this->assertContains('courses', array_column($reg->workMenu('admin'), 'key'));
        $this->assertContains('assets/modules/assessment/assessment.js', $reg->workAssets());
        $this->as('teacher', 1)->get('/work')->assertOk()->assertSee('assets/modules/assessment/assessment.js', false);
        $this->assertTrue(Schema::hasColumn('grade_entries', 'continuous_centi'));
        $js = file_get_contents(public_path('assets/modules/assessment/assessment.js'));
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|input|submit)=/i', $js);
        $this->assertStringContainsString("SaanWork.register('scores'", $js);
        $this->assertStringContainsString("SaanWork.register('courses'", $js);
    }
}
