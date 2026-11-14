<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\TermScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** OPS-010: /api/state is a term-scoped, capped, count-based read-only overview (query and payload budget). */
class OperationsStateBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06 10:00'));
        config(['school.demo' => true]);
        $this->seed();
    }

    private function bulk(): array
    {
        $school = (int) config('school.school_id');
        $term = TermScope::currentId();
        $class = (int) DB::table('classrooms')->where('school_id', $school)->orderBy('id')->value('id');
        $admin = (int) User::where('role', 'admin')->value('id');
        $teacher = (int) DB::table('teachers')->where('school_id', $school)->value('id');
        $now = now();
        $hasKey = \Illuminate\Support\Facades\Schema::hasColumn('students', 'code_key');
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $code = 'BUDGET'.$i;
            $rows[] = ['school_id' => $school, 'classroom_id' => $class, 'code' => $code, 'name' => 'Budget Student '.$i] + ($hasKey ? ['code_key' => strtolower($code)] : []);
        }
        foreach (array_chunk($rows, 100) as $c) DB::table('students')->insert($c);
        $ids = DB::table('students')->where('code', 'like', 'BUDGET%')->pluck('id');
        // 90 days of history for every one of those students
        $att = [];
        foreach ($ids as $sid) for ($d = 0; $d < 90; $d++) {
            $att[] = ['school_id' => $school, 'student_id' => $sid, 'date' => now()->subDays($d)->toDateString(), 'status' => 'present', 'recorded_by' => $admin, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($att, 500) as $c) DB::table('attendances')->insert($c);
        // an old term with its own grades and duties from past years
        $old = DB::table('academic_terms')->insertGetId(['school_id' => $school, 'year' => 2001, 'term' => 1, 'starts_on' => '2001-05-01', 'ends_on' => '2001-09-30']);
        $g = [];
        foreach ($ids->take(300) as $sid) $g[] = ['school_id' => $school, 'term_id' => $old, 'student_id' => $sid, 'subject' => 'OLD', 'continuous_centi' => 1, 'exam_centi' => 1, 'policy_version' => 'x', 'recorded_by' => $admin, 'created_at' => $now, 'updated_at' => $now];
        foreach (array_chunk($g, 100) as $c) DB::table('grade_entries')->insert($c);
        $du = [];
        for ($i = 0; $i < 400; $i++) $du[] = ['school_id' => $school, 'teacher_id' => $teacher, 'date' => now()->subDays(100 + $i)->toDateString(), 'starts_at' => '08:00', 'ends_at' => '09:00', 'location' => 'old', 'status' => 'assigned', 'created_at' => $now, 'updated_at' => $now];
        foreach (array_chunk($du, 100) as $c) DB::table('duties')->insert($c);

        return ['term' => $term, 'old' => $old, 'class' => $class];
    }

    public function test_state_is_bounded_in_queries_rows_and_bytes_with_large_history(): void
    {
        $f = $this->bulk();
        $this->actingAs(User::where('role', 'admin')->first());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->getJson('/api/state')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(40, $queries, 'query budget');
        $this->assertLessThan(400 * 1024, strlen($res->getContent()), 'payload budget (bytes)');
        foreach (['students' => 200, 'attendances' => 200, 'grades' => 400, 'duties' => 150, 'leaves' => 100, 'posts' => 8, 'audit' => 8] as $k => $max) {
            $this->assertLessThanOrEqual($max, count($res->json($k)), $k);
        }
        $this->assertSame($f['term'], (int) $res->json('term.id'));
        $this->assertSame([], $res->json('grades'), 'grades of other terms never leak into the current-term overview');
        $term = DB::table('academic_terms')->find($f['term']);
        foreach ($res->json('attendances') as $a) $this->assertTrue($a['date'] >= $term->starts_on && $a['date'] <= $term->ends_on);
        foreach ($res->json('duties') as $d) $this->assertTrue($d['date'] >= $term->starts_on && $d['date'] <= $term->ends_on);
        $this->assertNotEmpty($res->json('students'));
        $this->assertSame(['id', 'classroom_id', 'code', 'name'], array_keys($res->json('students.0')));
        $this->assertArrayNotHasKey('birth_date', $res->json('students.0'));
        $this->assertArrayNotHasKey('external_ref', $res->json('students.0'));
        // whole-school totals are exposed as counts, not rows
        $this->assertGreaterThanOrEqual(600, $res->json('counts.students'));
        $this->assertIsInt($res->json('counts.lessons'));
        $this->assertArrayHasKey('selected_classroom_id', $res->json());
    }

    public function test_roster_follows_requested_classroom_and_ignores_foreign_ids(): void
    {
        $f = $this->bulk();
        $this->actingAs(User::where('role', 'admin')->first());
        $other = (int) DB::table('classrooms')->where('id', '!=', $f['class'])->value('id');
        $r = $this->getJson('/api/state?classroom_id='.$other)->assertOk();
        $this->assertSame($other, $r->json('selected_classroom_id'));
        foreach ($r->json('students') as $s) $this->assertSame($other, $s['classroom_id']);
        $r = $this->getJson('/api/state?classroom_id=999999')->assertOk();
        $this->assertNotSame(999999, $r->json('selected_classroom_id'));
    }

    public function test_teacher_still_sees_only_own_class(): void
    {
        $this->bulk();
        $t = User::where('role', 'teacher')->first();
        $this->actingAs($t);
        $r = $this->getJson('/api/state?classroom_id=999999')->assertOk();
        $this->assertCount(1, $r->json('classrooms'));
        $this->assertSame((int) $t->classroom_id, $r->json('selected_classroom_id'));
        foreach ($r->json('students') as $s) $this->assertSame((int) $t->classroom_id, $s['classroom_id']);
    }

    public function test_current_term_grades_are_returned_and_other_school_rows_never_leak(): void
    {
        $f = $this->bulk();
        $school = (int) config('school.school_id');
        $admin = (int) User::where('role', 'admin')->value('id');
        $now = now();
        $sid = (int) DB::table('students')->where('classroom_id', $f['class'])->orderBy('id')->value('id');
        DB::table('grade_entries')->insert(['school_id' => $school, 'term_id' => $f['term'], 'student_id' => $sid, 'subject' => 'NOW', 'continuous_centi' => 1, 'exam_centi' => 1, 'policy_version' => 'x', 'recorded_by' => $admin, 'created_at' => $now, 'updated_at' => $now]);
        $foreignClass = DB::table('classrooms')->insertGetId(['school_id' => 2, 'name' => 'FOREIGN-CLASS', 'grade_level' => 1] + (\Illuminate\Support\Facades\Schema::hasColumn('classrooms', 'name_key') ? ['name_key' => 'foreign-class'] : []));
        $foreignStudent = DB::table('students')->insertGetId(['school_id' => 2, 'classroom_id' => $foreignClass, 'code' => 'FOREIGN1', 'name' => 'Foreign'] + (\Illuminate\Support\Facades\Schema::hasColumn('students', 'code_key') ? ['code_key' => 'foreign1'] : []));
        $foreignAtt = DB::table('attendances')->insertGetId(['school_id' => 2, 'student_id' => $foreignStudent, 'date' => '2026-11-05', 'status' => 'present', 'recorded_by' => $admin, 'created_at' => $now, 'updated_at' => $now]);
        $this->actingAs(User::where('role', 'admin')->first());
        $r = $this->getJson('/api/state?classroom_id='.$f['class'])->assertOk();
        $this->assertNotEmpty($r->json('grades'));
        foreach ($r->json('grades') as $g) $this->assertSame($f['term'], (int) $g['term_id']);
        $this->assertNotContains($foreignClass, array_column($r->json('classrooms'), 'id'));
        $this->assertNotContains($foreignStudent, array_column($r->json('students'), 'id'));
        $this->assertNotContains($foreignAtt, array_column($r->json('attendances'), 'id'));
        $r2 = $this->getJson('/api/state?classroom_id='.$foreignClass)->assertOk();
        $this->assertNotSame($foreignClass, $r2->json('selected_classroom_id'));
    }

    public function test_teacher_without_classroom_counts_no_students_and_cannot_pick_another_real_class(): void
    {
        $f = $this->bulk();
        $t = User::where('role', 'teacher')->first();
        $other = (int) DB::table('classrooms')->where('id', '!=', (int) $t->classroom_id)->value('id');
        $this->actingAs($t);
        $this->assertSame((int) $t->classroom_id, $this->getJson('/api/state?classroom_id='.$other)->assertOk()->json('selected_classroom_id'));
        DB::table('users')->where('id', $t->id)->update(['classroom_id' => null]);
        $this->actingAs(User::find($t->id));
        $r = $this->getJson('/api/state')->assertOk();
        $this->assertSame(0, $r->json('counts.students'));
    }
}