<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Health\Measurements;
use Tests\TestCase;

class HealthModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->first());
    }

    private function admin(): void { $this->actingAs(User::where('role', 'admin')->first()); }
    private function teacher(): User { return User::where('role', 'teacher')->first(); } // homeroom = classroom 1

    private function grid(int $class = 1, int $round = 1): array
    {
        return $this->getJson("/api/health/classrooms/$class/grid?round=$round")->assertOk()->json();
    }

    private function saveGrid(array $rows, int $class = 1, int $round = 1, array $extra = [])
    {
        return $this->putJson("/api/health/classrooms/$class/grid", array_merge(['round' => $round, 'measured_on' => '2026-11-05', 'rows' => $rows], $extra));
    }

    private function sid(int $n, int $class = 1): int
    {
        return (int) DB::table('students')->where('classroom_id', $class)->orderBy('id')->skip($n)->value('id');
    }

    public function test_grid_save_read_back_bmi_and_summary(): void
    {
        $g = $this->grid();
        $this->assertCount(8, $g['rows']);
        $this->assertSame(0, $g['summary']['measured']);
        $this->assertSame(8, $g['summary']['missing']);
        $rows = [];
        foreach ($g['rows'] as $i => $r) $rows[] = ['student_id' => $r['student_id'], 'weight_kg' => 30 + $i, 'height_cm' => 130.5 + $i, 'version' => $r['version']];
        $rows[7]['height_cm'] = null; // partial row
        $res = $this->saveGrid($rows)->assertOk()->json();
        $this->assertSame(8, $res['inserted']);
        $this->assertSame(0, $res['updated']);
        $this->assertEqualsWithDelta(round(30 / (1.305 ** 2), 1), $res['rows'][0]['bmi'], 0.001);
        $this->assertNull($res['rows'][7]['bmi']);
        $g2 = $this->grid();
        $this->assertSame(1, $g2['rows'][0]['version']);
        $this->assertEquals(30.0, $g2['rows'][0]['weight_kg']);
        $this->assertSame(7, $g2['summary']['measured']);
        $this->assertSame(1, $g2['summary']['partial']);
        $this->assertSame('2026-11-05', $g2['measured_on']);
        $s = $this->getJson('/api/health/classrooms/1/summary?round=1')->assertOk()->json();
        $this->assertSame(1, $s['missing'] + $s['partial']);
        $this->assertCount(1, $s['missing_students']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'health.grid.save')->count()); // one audit row per grid save

        // update: same version, new weight; unchanged rows are skipped and not bumped
        $rows2 = [['student_id' => $rows[0]['student_id'], 'weight_kg' => 31.25, 'height_cm' => 130.5, 'version' => 1], ['student_id' => $rows[1]['student_id'], 'weight_kg' => 31, 'height_cm' => 131.5, 'version' => 1]];
        $r2 = $this->saveGrid($rows2)->assertOk()->json();
        $this->assertSame([1, 1], [$r2['updated'], $r2['unchanged']]);
        $this->assertSame(2, $r2['rows'][0]['version']);
        $this->assertSame(1, (int) DB::table('hlth_measurements')->where('student_id', $rows[1]['student_id'])->value('version'));
        $this->assertEquals(31.25, (float) DB::table('hlth_measurements')->where('student_id', $rows[0]['student_id'])->value('weight_kg'));
    }

    public function test_conflict_returns_rows_and_writes_nothing(): void
    {
        $a = $this->sid(0); $b = $this->sid(1); $c = $this->sid(2);
        $this->saveGrid([['student_id' => $a, 'weight_kg' => 30, 'height_cm' => 130, 'version' => 0], ['student_id' => $b, 'weight_kg' => 31, 'height_cm' => 131, 'version' => 0]])->assertOk();
        // someone else edits b
        DB::table('hlth_measurements')->where('student_id', $b)->update(['weight_kg' => 40, 'version' => 2]);
        $res = $this->saveGrid([
            ['student_id' => $a, 'weight_kg' => 35, 'height_cm' => 135, 'version' => 1],
            ['student_id' => $b, 'weight_kg' => 36, 'height_cm' => 136, 'version' => 1],
            ['student_id' => $c, 'weight_kg' => 37, 'height_cm' => 137, 'version' => 0],
        ])->assertStatus(409)->assertJsonStructure(['message', 'current', 'conflicts']);
        $this->assertSame([$b], array_column($res->json('conflicts'), 'student_id'));
        $this->assertEquals(40, $res->json('conflicts.0.weight_kg'));
        $this->assertSame(2, $res->json('conflicts.0.version'));
        $this->assertEquals(30, (float) DB::table('hlth_measurements')->where('student_id', $a)->value('weight_kg'));
        $this->assertSame(0, DB::table('hlth_measurements')->where('student_id', $c)->count());
        // client thought the row was empty but it exists
        $this->saveGrid([['student_id' => $a, 'weight_kg' => 35, 'height_cm' => 135, 'version' => 0]])->assertStatus(409);
        // and retry with the fresh version succeeds
        $this->saveGrid([['student_id' => $b, 'weight_kg' => 36, 'height_cm' => 136, 'version' => 2]])->assertOk();
    }

    public function test_validation_ranges_and_shape(): void
    {
        $a = $this->sid(0);
        $row = fn (array $o) => [array_merge(['student_id' => $a, 'weight_kg' => 30, 'height_cm' => 130, 'version' => 0], $o)];
        $this->saveGrid($row(['weight_kg' => 7.9]))->assertUnprocessable()->assertJsonValidationErrors(['rows.0.weight_kg']);
        $this->saveGrid($row(['weight_kg' => 150.1]))->assertUnprocessable();
        $this->saveGrid($row(['height_cm' => 69]))->assertUnprocessable()->assertJsonValidationErrors(['rows.0.height_cm']);
        $this->saveGrid($row(['height_cm' => 211]))->assertUnprocessable();
        $this->saveGrid($row(['weight_kg' => 'abc']))->assertUnprocessable();
        $this->saveGrid($row([]), 1, 5)->assertUnprocessable()->assertJsonValidationErrors(['round']);
        $this->saveGrid($row([]), 1, 0)->assertUnprocessable();
        $this->saveGrid($row([]), 1, 1, ['measured_on' => '2999-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['measured_on']);
        $this->saveGrid($row([]), 1, 1, ['measured_on' => 'not-a-date'])->assertUnprocessable();
        $this->saveGrid([])->assertUnprocessable();
        $this->saveGrid([['student_id' => $a, 'weight_kg' => 30, 'height_cm' => 130], ['student_id' => $a, 'weight_kg' => 31, 'height_cm' => 131]])->assertUnprocessable();
        // student from another classroom
        $this->saveGrid($row(['student_id' => $this->sid(0, 2)]))->assertUnprocessable()->assertJsonValidationErrors(['rows.0.student_id']);
        // too many rows
        $many = [];
        for ($i = 1; $i <= 61; $i++) $many[] = ['student_id' => $i, 'weight_kg' => 30, 'height_cm' => 130];
        $this->saveGrid($many)->assertUnprocessable()->assertJsonValidationErrors(['rows']);
        $this->assertSame(0, DB::table('hlth_measurements')->count());
        // boundaries are accepted
        $this->saveGrid($row(['weight_kg' => 8, 'height_cm' => 70]))->assertOk();
        $this->saveGrid([['student_id' => $this->sid(1), 'weight_kg' => 150, 'height_cm' => 210, 'version' => 0]])->assertOk();
        $this->getJson('/api/health/classrooms/1/grid?round=9')->assertUnprocessable();
    }

    public function test_warnings_are_not_errors(): void
    {
        $a = $this->sid(0); $b = $this->sid(1);
        $this->saveGrid([['student_id' => $a, 'weight_kg' => 40, 'height_cm' => 140, 'version' => 0], ['student_id' => $b, 'weight_kg' => 40, 'height_cm' => 140, 'version' => 0]])->assertOk();
        $res = $this->saveGrid([
            ['student_id' => $a, 'weight_kg' => 50, 'height_cm' => 139, 'version' => 0], // +10 kg, -1 cm vs round 1
            ['student_id' => $b, 'weight_kg' => 41, 'height_cm' => 141, 'version' => 0],   // normal
        ], 1, 2)->assertOk()->json();
        $this->assertSame(2, $res['inserted']);
        $fields = array_map(fn ($w) => $w['student_id'].':'.$w['field'], $res['warnings']);
        $this->assertContains("$a:weight_kg", $fields);
        $this->assertContains("$a:height_cm", $fields);
        $this->assertNotContains("$b:weight_kg", $fields);
        $this->assertCount(2, $res['warnings']);
        $this->assertSame(2, DB::table('hlth_measurements')->where('round', 2)->count());
        // previous round values are served for comparison
        $g = $this->grid(1, 2);
        $this->assertEquals(40, $g['rows'][0]['previous']['weight_kg']);
        $this->assertNull($this->grid(1, 1)['rows'][0]['previous']);
        // implausible BMI warns
        $c = $this->sid(2);
        $w = $this->saveGrid([['student_id' => $c, 'weight_kg' => 9, 'height_cm' => 200, 'version' => 0]])->assertOk()->json('warnings');
        $this->assertSame('bmi', $w[0]['field']);
    }

    public function test_save_and_read_query_budget_on_large_school(): void
    {
        $now = now();
        $classes = $students = [];
        for ($c = 4; $c <= 73; $c++) $classes[] = ['id' => $c, 'school_id' => 1, 'name' => 'ทดสอบ '.$c, 'homeroom_teacher_id' => 1];
        DB::table('classrooms')->insert($classes);
        $n = 0;
        foreach ($classes as $i => $cl) {
            $per = $i === 0 ? 45 : 36;
            for ($j = 1; $j <= $per; $j++) { $n++; $students[] = ['school_id' => 1, 'classroom_id' => $cl['id'], 'code' => sprintf('PERF-%04d', $n), 'name' => 'นักเรียนทดสอบ '.$n]; }
        }
        foreach (array_chunk($students, 300) as $chunk) DB::table('students')->insert($chunk);
        $this->assertGreaterThanOrEqual(2500, DB::table('students')->where('code', 'like', 'PERF-%')->count());
        $meas = [];
        foreach (DB::table('students')->where('code', 'like', 'PERF-%')->pluck('id') as $sid) foreach ([1, 2] as $round) {
            $meas[] = ['school_id' => 1, 'term_id' => 1, 'round' => $round, 'student_id' => $sid, 'measured_on' => '2026-06-01', 'weight_kg' => 30 + $round, 'height_cm' => 130, 'recorded_by' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($meas, 500) as $chunk) DB::table('hlth_measurements')->insert($chunk);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $g = $this->getJson('/api/health/classrooms/4/grid?round=2')->assertOk()->json();
        $readQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(45, $g['rows']);
        $this->assertSame(45, $g['summary']['measured']);
        $this->assertLessThanOrEqual(5, $readQueries, 'class grid read used '.$readQueries.' queries');

        $rows = array_map(fn ($r) => ['student_id' => $r['student_id'], 'weight_kg' => 33.5, 'height_cm' => 131.2, 'version' => $r['version']], $g['rows']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = microtime(true);
        $res = $this->saveGrid($rows, 4, 2)->assertOk()->json();
        $elapsed = microtime(true) - $t;
        $saveQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(45, $res['updated']);
        $this->assertLessThanOrEqual(10, $saveQueries, 'class grid save (updates) used '.$saveQueries.' queries');
        $this->assertLessThan(5.0, $elapsed);
        $this->assertSame(45, DB::table('hlth_measurements')->where('round', 2)->where('weight_kg', 33.5)->count());
        $this->assertSame(2, (int) DB::table('hlth_measurements')->where('round', 2)->whereIn('student_id', array_column($rows, 'student_id'))->min('version'));

        // fresh inserts (round 3, 45 new rows) also stay in budget
        $rows3 = array_map(fn ($r) => ['student_id' => $r['student_id'], 'weight_kg' => 34, 'height_cm' => 132, 'version' => 0], $g['rows']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->saveGrid($rows3, 4, 3)->assertOk()->assertJsonPath('inserted', 45);
        $insertQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(10, $insertQueries, 'class grid save (inserts) used '.$insertQueries.' queries');

        $o = $this->getJson('/api/health/summary?round=1')->assertOk()->json();
        $this->assertCount(73, $o['classrooms']);
        $row = collect($o['classrooms'])->firstWhere('classroom_id', 4);
        $this->assertSame([45, 45, 0], [$row['total'], $row['measured'], $row['missing']]);

        // the real users are homeroom teachers: same budgets, mixed insert+update payload
        $this->actingAs($this->teacher());
        DB::table('classrooms')->where('id', 5)->update(['homeroom_teacher_id' => $this->teacher()->teacher_id]);
        $g5 = $this->grid(5, 2);
        $ids5 = array_column($g5['rows'], 'student_id');
        DB::table('hlth_measurements')->where('round', 2)->whereIn('student_id', array_slice($ids5, 0, 20))->delete(); // 20 inserts + 16 updates
        DB::flushQueryLog();
        DB::enableQueryLog();
        $g5 = $this->grid(5, 2);
        $tRead = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(5, $tRead, 'teacher grid read used '.$tRead.' queries');
        $mixed = array_map(fn ($r) => ['student_id' => $r['student_id'], 'weight_kg' => 35.5, 'height_cm' => 133.3, 'version' => $r['version']], $g5['rows']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->saveGrid($mixed, 5, 2)->assertOk()->json();
        $tSave = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([20, 16], [$res['inserted'], $res['updated']]);
        $this->assertLessThanOrEqual(10, $tSave, 'teacher mixed save used '.$tSave.' queries');
        $this->assertArrayHasKey('term_id', $res);
    }

    public function test_previous_comparison_falls_back_to_previous_term(): void
    {
        $cur = \App\Support\Saan\TermScope::current();
        DB::table('academic_terms')->insert(['id' => 99, 'school_id' => 1, 'year' => $cur->year - 1, 'term' => 2, 'starts_on' => '2025-01-01', 'ends_on' => '2025-04-01']);
        $a = $this->sid(0); $b = $this->sid(1);
        foreach ([[1, 38], [2, 40]] as [$round, $w]) DB::table('hlth_measurements')->insert(['school_id' => 1, 'term_id' => 99, 'round' => $round, 'student_id' => $a, 'measured_on' => '2025-03-01', 'weight_kg' => $w, 'height_cm' => 140, 'version' => 1]);
        $g = $this->grid(1, 1);
        $this->assertSame(99, $g['rows'][0]['previous']['term_id']);
        $this->assertEquals(40, $g['rows'][0]['previous']['weight_kg']); // latest round of the previous term
        $this->assertNull($g['rows'][1]['previous']);
        $res = $this->saveGrid([['student_id' => $a, 'weight_kg' => 50, 'height_cm' => 141, 'version' => 0], ['student_id' => $b, 'weight_kg' => 50, 'height_cm' => 141, 'version' => 0]])->assertOk()->json();
        $this->assertSame(["$a:weight_kg"], array_map(fn ($w) => $w['student_id'].':'.$w['field'], $res['warnings']));
        // round 2 without a round-1 row for the student also compares with the previous term
        DB::table('hlth_measurements')->where('term_id', 1)->where('student_id', $a)->delete();
        $w2 = $this->saveGrid([['student_id' => $a, 'weight_kg' => 30, 'height_cm' => 141, 'version' => 0]], 1, 2)->assertOk()->json('warnings');
        $this->assertSame('weight_kg', $w2[0]['field']);
    }

    public function test_profile_conflict_body_has_no_health_content(): void
    {
        $s1 = $this->sid(0);
        $this->putJson("/api/health/students/$s1/profile", ['version' => 0, 'allergies' => 'ถั่วลิสง (ข้อมูลสมมติ)', 'medications' => 'ยาตัวอย่าง'])->assertOk();
        foreach ([0, 7] as $stale) {
            $res = $this->putJson("/api/health/students/$s1/profile", ['version' => $stale, 'allergies' => 'x'])->assertStatus(409);
            $this->assertStringNotContainsString('ถั่วลิสง', $res->getContent());
            $this->assertStringNotContainsString('ยาตัวอย่าง', $res->getContent());
            $this->assertArrayNotHasKey('allergies', (array) $res->json('current'));
            $this->assertSame(1, $res->json('current.version'));
        }
        $this->assertSame(0, DB::table('audit_events')->where('action', 'health.profile.read')->count());
        $this->getJson("/api/health/students/$s1/profile")->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'health.profile.read')->where('entity', 'students')->where('entity_id', $s1)->count());
    }

    public function test_save_endpoint_turns_unique_violation_race_into_409_with_rows(): void
    {
        $a = $this->sid(0); $b = $this->sid(1);
                $fired = false; $committed = false;
        $winner = fn () => DB::table('hlth_measurements')->insert(['school_id' => 1, 'term_id' => 1, 'round' => 1, 'student_id' => $a, 'measured_on' => '2026-11-05', 'weight_kg' => 41, 'height_cm' => 141, 'version' => 1]);
        // another device writes student A right after our existence check, so our INSERT hits the unique index ...
        DB::listen(function ($q) use (&$fired, $winner) {
            if ($fired || !str_contains($q->sql, 'hlth_measurements') || !str_starts_with(strtolower($q->sql), 'select')) return;
            $fired = true;
            $winner();
        });
        // ... and its row is still there after our failed attempt rolled back (it was committed by the other device)
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, function () use (&$committed, $winner, $a) {
            if ($committed) return;
            $committed = true;
            if (!DB::table('hlth_measurements')->where('student_id', $a)->exists()) $winner();
        });
$res = $this->saveGrid([['student_id' => $a, 'weight_kg' => 30, 'height_cm' => 130, 'version' => 0], ['student_id' => $b, 'weight_kg' => 31, 'height_cm' => 131, 'version' => 0]])->assertStatus(409);
        $this->assertTrue($fired);
        $this->assertTrue($committed);
        $this->assertSame([$a], array_column($res->json('conflicts'), 'student_id'));
        $this->assertEquals(41, $res->json('conflicts.0.weight_kg'));
        $this->assertSame(1, $res->json('conflicts.0.version'));
        $this->assertSame(0, DB::table('hlth_measurements')->where('student_id', $b)->count()); // nothing of ours written
    }

    public function test_concurrency_error_codes_are_recognised(): void
    {
        $e = new \Illuminate\Database\QueryException('mysql', 'x', [], tap(new \PDOException('Deadlock'), fn ($p) => $p->errorInfo = ['40001', 1213, 'Deadlock']));
        $this->assertTrue(Measurements::isConcurrencyError($e));
        $e2 = new \Illuminate\Database\QueryException('mysql', 'x', [], tap(new \PDOException('Lock wait'), fn ($p) => $p->errorInfo = ['HY000', 1205, 'Lock wait']));
        $this->assertTrue(Measurements::isConcurrencyError($e2));
        $this->assertFalse(Measurements::isConcurrencyError(new \RuntimeException('x')));
    }

    public function test_classroom_list_hides_inactive_and_sorts_naturally(): void
    {
        DB::table('classrooms')->insert([['id' => 40, 'school_id' => 1, 'name' => 'ม.1/10', 'homeroom_teacher_id' => 1], ['id' => 41, 'school_id' => 1, 'name' => 'ม.1/2', 'homeroom_teacher_id' => 1]]);
        $names = array_column($this->getJson('/api/health/classrooms')->json('data'), 'name');
        $this->assertLessThan(array_search('ม.1/10', $names), array_search('ม.1/2', $names));
        if (\Illuminate\Support\Facades\Schema::hasColumn('classrooms', 'is_active')) {
            DB::table('classrooms')->where('id', 41)->update(['is_active' => false]);
            $this->assertNotContains(41, array_column($this->getJson('/api/health/classrooms')->json('data'), 'id'));
            $this->assertNotContains(41, array_column($this->getJson('/api/health/summary?round=1')->json('classrooms'), 'classroom_id'));
        }
    }

    public function test_teacher_scope_homeroom_only_and_other_roles_blocked(): void
    {
        $t = $this->teacher();
        $this->actingAs($t);
        $list = $this->getJson('/api/health/classrooms')->assertOk()->json('data');
        $this->assertSame([1], array_column($list, 'id')); // teaches classrooms 2 and 3 too (lessons) but only homeroom is health-scoped
        $this->getJson('/api/health/classrooms/1/grid')->assertOk();
        $this->getJson('/api/health/classrooms/2/grid')->assertForbidden();
        $this->saveGrid([['student_id' => $this->sid(0, 2), 'weight_kg' => 30, 'height_cm' => 130]], 2)->assertForbidden();
        $this->getJson('/api/health/classrooms/2/export.csv')->assertForbidden();
        $this->getJson('/api/health/summary?round=1')->assertOk()->assertJsonCount(1, 'classrooms');
        $this->saveGrid([['student_id' => $this->sid(0), 'weight_kg' => 30, 'height_cm' => 130, 'version' => 0]])->assertOk();
        $this->actingAs(User::where('role', 'principal')->first())->getJson('/api/health/classrooms/2/grid')->assertOk();
        DB::table('users')->insert(['school_id' => 1, 'name' => 'บรรณาธิการ (สมมติ)', 'email' => 'editor@demo.school.test', 'password' => 'x', 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::where('role', 'editor')->first())->getJson('/api/health/classrooms/1/grid')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/health/classrooms/1/grid')->assertUnauthorized();
    }

    public function test_a_client_sent_enc1_value_is_encrypted_again_and_never_decrypted_on_read(): void
    {
        $this->actingAs($this->teacher());
        $s1 = $this->sid(0);
        $secret = \Illuminate\Support\Facades\Crypt::encryptString('marker-not-a-health-note');
        $this->putJson("/api/health/students/$s1/profile", ['version' => 0, 'allergies' => 'enc1:'.$secret, 'emergency_note' => 'enc1:plain-looking note'])->assertOk();
        $p = $this->getJson("/api/health/students/$s1/profile")->assertOk()->json('profile');
        $this->assertSame('enc1:'.$secret, $p['allergies']);
        $this->assertSame('enc1:plain-looking note', $p['emergency_note']);
        $this->assertNotSame('enc1:'.$secret, DB::table('hlth_profiles')->where('student_id', $s1)->value('allergies'));
    }
    public function test_a_teacher_cannot_read_or_write_the_health_profile_of_a_graduate_in_the_old_classroom(): void
    {
        $this->actingAs($this->teacher());
        $s1 = $this->sid(0);
        $this->getJson("/api/health/students/$s1/profile")->assertOk();
        DB::table('students')->where('id', $s1)->update(['status' => 'graduated']);
        $this->getJson("/api/health/students/$s1/profile")->assertNotFound();
        $this->putJson("/api/health/students/$s1/profile", ['version' => 0, 'allergies' => 'x'])->assertNotFound();
        $this->actingAs(User::where('role', 'principal')->first())->getJson("/api/health/students/$s1/profile")->assertOk();
    }

    public function test_school_scoping(): void
    {
        DB::table('teachers')->insert(['id' => 90, 'school_id' => 2, 'name' => 'ครูโรงเรียนอื่น', 'subject' => 'x']);
        DB::table('classrooms')->insert(['id' => 90, 'school_id' => 2, 'name' => 'อีกโรงเรียน', 'homeroom_teacher_id' => 90]);
        DB::table('students')->insert(['id' => 900, 'school_id' => 2, 'classroom_id' => 90, 'code' => 'OTHER-1', 'name' => 'นักเรียนโรงเรียนอื่น']);
        DB::table('hlth_measurements')->insert(['school_id' => 2, 'term_id' => 1, 'round' => 1, 'student_id' => 900, 'measured_on' => '2026-06-01', 'weight_kg' => 40, 'height_cm' => 140, 'version' => 1]);
        $this->getJson('/api/health/classrooms/90/grid')->assertNotFound();
        $this->getJson('/api/health/students/900/profile')->assertForbidden();
        $this->saveGrid([['student_id' => 900, 'weight_kg' => 30, 'height_cm' => 130]], 90)->assertNotFound();
        $this->saveGrid([['student_id' => 900, 'weight_kg' => 30, 'height_cm' => 130]])->assertUnprocessable(); // not in classroom 1
        $this->assertNotContains(90, array_column($this->getJson('/api/health/classrooms')->json('data'), 'id'));
        $this->assertSame(1, DB::table('hlth_measurements')->count());
        $this->assertNotContains('OTHER-1', array_column($this->grid()['rows'], 'code'));
    }

    public function test_profile_privacy_audit_and_versioning(): void
    {
        $s1 = $this->sid(0, 1); $s2 = $this->sid(0, 2);
        $this->actingAs($this->teacher());
        $this->getJson("/api/health/students/$s2/profile")->assertForbidden();
        $this->putJson("/api/health/students/$s2/profile", ['version' => 0, 'allergies' => 'x'])->assertForbidden();
        $p = $this->getJson("/api/health/students/$s1/profile")->assertOk()->assertHeader('Cache-Control')->json();
        $this->assertSame(0, $p['profile']['version']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'health.profile.read')->where('entity_id', $s1)->count());
        $saved = $this->putJson("/api/health/students/$s1/profile", ['version' => 0, 'blood_group' => 'AB+', 'allergies' => 'ถั่วลิสง (ข้อมูลสมมติ)'])->assertOk()->json();
        $this->assertSame(1, $saved['profile']['version']);
        $this->putJson("/api/health/students/$s1/profile", ['version' => 0, 'allergies' => 'ซ้ำ'])->assertStatus(409);
        $this->putJson("/api/health/students/$s1/profile", ['version' => 1, 'blood_group' => 'Z'])->assertUnprocessable();
        $this->putJson("/api/health/students/$s1/profile", ['version' => 5, 'allergies' => 'stale'])->assertStatus(409);
        $upd = $this->putJson("/api/health/students/$s1/profile", ['version' => 1, 'blood_group' => 'O', 'allergies' => null, 'medications' => 'ยาตัวอย่าง'])->assertOk()->json();
        $this->assertSame(2, $upd['profile']['version']);
        $this->assertNull($upd['profile']['allergies']);
        // audit detail never contains the health content
        $details = DB::table('audit_events')->whereIn('action', ['health.profile.create', 'health.profile.update', 'health.profile.read'])->pluck('detail')->implode(' ');
        $this->assertStringNotContainsString('ถั่วลิสง', $details);
        $this->assertStringNotContainsString('ยาตัวอย่าง', $details);
        // admin and principal can read any class; every read audited
        $this->actingAs(User::where('role', 'principal')->first())->getJson("/api/health/students/$s2/profile")->assertOk();
        $this->admin();
        $this->getJson("/api/health/students/$s1/profile")->assertOk()->assertJsonPath('profile.blood_group', 'O');
        $this->assertSame(3, DB::table('audit_events')->where('action', 'health.profile.read')->count());
        // a measurement-only endpoint never leaks profile fields
        $this->assertArrayNotHasKey('allergies', $this->grid()['rows'][0]);
    }

    public function test_csv_export_neutralises_formulas_and_is_audited(): void
    {
        DB::table('students')->where('id', $this->sid(0))->update(['name' => '=HYPERLINK("x")']);
        $this->saveGrid([['student_id' => $this->sid(0), 'weight_kg' => 30, 'height_cm' => 130, 'version' => 0]])->assertOk();
        $res = $this->get('/api/health/classrooms/1/export.csv?round=1')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString("\"'=HYPERLINK", $body);
        $this->assertStringContainsString('30.00,130.0,17.8', $body);
        $this->assertSame(9, count(array_filter(explode("\n", trim($body)))));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'health.export')->count());
    }

    public function test_profile_replay_cache_holds_no_health_text(): void
    {
        config(['cache.default' => 'database']); // the production store: the cache table is inspected below
        $s = $this->sid(0);
        $h = ['Idempotency-Key' => 'profile-replay-1'];
        $body = ['version' => 0, 'allergies' => 'ALLERGY-MARKER-XYZ'];
        $this->putJson("/api/health/students/$s/profile", $body, $h)->assertOk();
        $again = $this->putJson("/api/health/students/$s/profile", $body, $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $again->assertJsonPath('profile.allergies', 'ALLERGY-MARKER-XYZ'); // rebuilt from the database
        $this->assertSame(1, DB::table('hlth_profiles')->count());
        $this->assertGreaterThan(0, DB::table('cache')->count());
        foreach (DB::table('cache')->get() as $row) $this->assertStringNotContainsString('ALLERGY-MARKER-XYZ', (string) $row->value);
    }

    public function test_idempotent_retry_saves_once_and_unique_helper(): void
    {
        $rows = [['student_id' => $this->sid(0), 'weight_kg' => 30, 'height_cm' => 130, 'version' => 0]];
        $h = ['Idempotency-Key' => 'health-test-1'];
        $this->putJson('/api/health/classrooms/1/grid', ['round' => 1, 'measured_on' => '2026-11-05', 'rows' => $rows], $h)->assertOk();
        $this->putJson('/api/health/classrooms/1/grid', ['round' => 1, 'measured_on' => '2026-11-05', 'rows' => $rows], $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('hlth_measurements')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'health.grid.save')->count());
        // the unique index itself rejects a duplicate row, and the helper recognises that error
        try {
            DB::table('hlth_measurements')->insert(['school_id' => 1, 'term_id' => 1, 'round' => 1, 'student_id' => $rows[0]['student_id'], 'measured_on' => '2026-11-05', 'version' => 1]);
            $this->fail('duplicate accepted');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(Measurements::isUniqueViolation($e));
        }
    }

    public function test_module_listed_and_work_menu(): void
    {
        $this->assertContains('health', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->actingAs($this->teacher())->get('/work')->assertOk()->assertSee('น้ำหนัก ส่วนสูง')->assertSee('assets/modules/health/health.js', false);
        $this->assertFileExists(public_path('assets/modules/health/health.js'));
    }

    public function test_profile_text_is_encrypted_at_rest_and_legacy_rows_still_read(): void
    {
        $s1 = $this->sid(0, 1); $s2 = $this->sid(0, 1);
        $this->actingAs($this->teacher());
        $this->putJson("/api/health/students/$s1/profile", ['version' => 0, 'blood_group' => 'O', 'allergies' => 'ALLERGY-AT-REST', 'emergency_note' => 'NOTE-AT-REST'])->assertOk();
        $raw = DB::table('hlth_profiles')->where('student_id', $s1)->first();
        $this->assertSame('O', $raw->blood_group);
        $this->assertStringNotContainsString('ALLERGY-AT-REST', (string) $raw->allergies);
        $this->assertStringNotContainsString('NOTE-AT-REST', (string) $raw->emergency_note);
        $this->assertNull($raw->medications);
        $this->getJson("/api/health/students/$s1/profile")->assertOk()->assertJsonPath('profile.allergies', 'ALLERGY-AT-REST')->assertJsonPath('profile.emergency_note', 'NOTE-AT-REST');
        // a legacy plaintext row is still readable, and the one-off migration encrypts it in place (twice is harmless)
        DB::table('hlth_profiles')->where('student_id', $s1)->update(['medications' => 'LEGACY-PLAIN']);
        $this->getJson("/api/health/students/$s1/profile")->assertJsonPath('profile.medications', 'LEGACY-PLAIN');
        $mig = require base_path('modules/health/database/migrations/2026_11_10_000001_health_encrypt_profiles.php');
        $mig->up(); $mig->up();
        $this->assertStringNotContainsString('LEGACY-PLAIN', (string) DB::table('hlth_profiles')->where('student_id', $s1)->value('medications'));
        $this->getJson("/api/health/students/$s1/profile")->assertJsonPath('profile.medications', 'LEGACY-PLAIN')->assertJsonPath('profile.allergies', 'ALLERGY-AT-REST');
    }
}