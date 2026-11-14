<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Behavior\BehaviorAcknowledgement;
use Saan\Modules\Behavior\BehaviorReadModel;
use Tests\TestCase;

class BehaviorModuleTest extends TestCase
{
    use RefreshDatabase;

    private function today(): string
    {
        return Carbon::now('Asia/Bangkok')->toDateString();
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::where('role', $role)->first());
    }

    /** Teacher whose only classroom is ป.5/1 (id 2): cannot touch classroom 1 students (ids 1-8). */
    private function otherTeacher(): User
    {
        DB::table('teachers')->insert(['id' => 7, 'school_id' => 1, 'name' => 'ครูทดสอบ (สมมติ)', 'subject' => 'ศิลปะ']);
        DB::table('users')->insert(['id' => 9, 'school_id' => 1, 'name' => 'ครูอีกคน (สมมติ)', 'email' => 'other@demo.school.test', 'password' => 'x', 'role' => 'teacher',
            'is_demo' => true, 'teacher_id' => 7, 'classroom_id' => 2, 'created_at' => now(), 'updated_at' => now()]);
        return User::find(9);
    }

    private function ruleId(string $code): int
    {
        return (int) DB::table('bhv_rules')->where('school_id', 1)->where('code', $code)->value('id');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
    }

    public function test_default_rules_seeded_once_and_rule_management(): void
    {
        $this->as('teacher');
        $rules = $this->getJson('/api/behavior/rules')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(8, count($rules));
        $this->assertContains('ช่วยงานโรงเรียน', array_column($rules, 'label'));
        $this->assertContains('มาสาย', array_column($rules, 'label'));
        $this->getJson('/api/behavior/rules')->assertOk();
        $this->assertSame(count($rules), DB::table('bhv_rules')->count());

        $this->postJson('/api/behavior/rules', ['code' => 'x', 'label' => 'x', 'category' => 'good', 'points' => 3])->assertForbidden();

        $this->as('admin');
        $new = $this->postJson('/api/behavior/rules', ['code' => 'Reading', 'label' => 'อ่านหนังสือ', 'category' => 'good', 'points' => 4])->assertCreated()->json();
        $this->postJson('/api/behavior/rules', ['code' => ' reading ', 'label' => 'ซ้ำ', 'category' => 'good', 'points' => 4])->assertStatus(409);
        $this->postJson('/api/behavior/rules', ['code' => 'READING', 'label' => 'ซ้ำ', 'category' => 'good', 'points' => 4])->assertStatus(409);
        $this->postJson('/api/behavior/rules', ['code' => 'bad', 'label' => 'ผิดเครื่องหมาย', 'category' => 'good', 'points' => -4])->assertUnprocessable();
        $this->postJson('/api/behavior/rules', ['code' => 'zero', 'label' => 'ศูนย์', 'category' => 'improve', 'points' => 0])->assertUnprocessable();

        $upd = $this->putJson('/api/behavior/rules/'.$new['id'], ['version' => $new['version'], 'points' => 6, 'is_active' => false])->assertOk()->json();
        $this->assertSame(6, $upd['points']);
        $this->assertFalse($upd['is_active']);
        $this->putJson('/api/behavior/rules/'.$new['id'], ['version' => $new['version'], 'points' => 7])->assertStatus(409)->assertJsonStructure(['message', 'current']);
        $this->putJson('/api/behavior/rules/'.$new['id'], ['version' => $upd['version'], 'category' => 'improve'])->assertUnprocessable();

        // inactive rules are hidden from teachers and cannot be used
        $this->assertNotContains('Reading', array_column($this->as('teacher')->getJson('/api/behavior/rules')->json('data'), 'code'));
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $new['id'], 'occurred_on' => $this->today()])->assertUnprocessable();
        $this->as('admin');
        $this->assertContains('Reading', array_column($this->getJson('/api/behavior/rules?all=1')->json('data'), 'code'));
        $this->assertGreaterThan(0, DB::table('audit_events')->where('action', 'behavior.rule.created')->count());
    }

    public function test_multi_record_is_idempotent_and_audited(): void
    {
        $this->as('teacher');
        $this->getJson('/api/behavior/rules');
        $late = $this->ruleId('late');
        $body = ['student_ids' => [1, 2, 3, 3], 'rule_id' => $late, 'occurred_on' => $this->today(), 'note' => 'เข้าแถวไม่ทัน'];
        $first = $this->withHeaders(['Idempotency-Key' => 'bhv-test-1'])->postJson('/api/behavior/records', $body)->assertCreated();
        $this->assertSame(3, $first->json('count'));
        $this->assertSame(-2, $first->json('data.0.points'));
        $replay = $this->withHeaders(['Idempotency-Key' => 'bhv-test-1'])->postJson('/api/behavior/records', $body)->assertCreated();
        $replay->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.0.id'), $replay->json('data.0.id'));
        $this->assertSame(3, DB::table('bhv_records')->count());
        $this->assertSame(3, DB::table('audit_events')->where('action', 'behavior.record.created')->count());
        $this->assertSame(1, DB::table('bhv_records')->where('student_id', 1)->value('version'));

        $this->withHeaders(['Idempotency-Key' => '']); // headers persist on the test client
        // custom points need a note and a sensible value
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'points' => 3, 'occurred_on' => $this->today()])->assertUnprocessable();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'points' => 3, 'note' => 'ช่วยครู', 'occurred_on' => $this->today()])->assertCreated();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'occurred_on' => $this->today()])->assertUnprocessable();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $late, 'occurred_on' => Carbon::now('Asia/Bangkok')->addDays(2)->toDateString()])->assertUnprocessable();
        $this->postJson('/api/behavior/records', ['student_ids' => [1, 99999], 'rule_id' => $late, 'occurred_on' => $this->today()])->assertUnprocessable();
        $this->assertSame(4, DB::table('bhv_records')->count());
    }

    public function test_void_keeps_the_row_and_removes_it_from_totals(): void
    {
        $this->as('teacher');
        $this->getJson('/api/behavior/rules');
        $rows = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('help-school'), 'occurred_on' => $this->today()])->json('data');
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->assertCreated();
        $id = $rows[0]['id'];

        $this->postJson("/api/behavior/records/$id/void", ['version' => 1, 'reason' => ''])->assertUnprocessable();
        $this->postJson("/api/behavior/records/$id/void", ['version' => 5, 'reason' => 'บันทึกผิดคน'])->assertStatus(409);
        $v = $this->postJson("/api/behavior/records/$id/void", ['version' => 1, 'reason' => 'บันทึกผิดคน'])->assertOk()->json();
        $this->assertSame('voided', $v['status']);
        $this->postJson("/api/behavior/records/$id/void", ['version' => $v['version'], 'reason' => 'ซ้ำอีกครั้ง'])->assertUnprocessable();
        $this->assertSame(2, DB::table('bhv_records')->count());
        $this->assertSame('บันทึกผิดคน', DB::table('bhv_records')->where('id', $id)->value('voided_reason'));

        $tl = $this->getJson('/api/behavior/students/1/timeline')->assertOk()->json();
        $this->assertSame(['good_points' => 0, 'improve_points' => -2, 'net_points' => -2, 'records' => 1, 'voided' => 1], $tl['totals']);
        $this->assertCount(2, $tl['records']);
        $this->assertSame('นักเรียนตัวอย่าง 01', $tl['student']['name']);

        // a teacher voids only own records; admin may void any
        $this->as('admin');
        $mine = $this->postJson('/api/behavior/records', ['student_ids' => [2], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->json('data.0.id');
        $this->as('teacher');
        $this->postJson("/api/behavior/records/$mine/void", ['version' => 1, 'reason' => 'ไม่ใช่ของฉัน'])->assertForbidden();
        $this->as('admin');
        $this->postJson("/api/behavior/records/$mine/void", ['version' => 1, 'reason' => 'ผู้ดูแลยกเลิก'])->assertOk();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'behavior.record.voided')->count());
    }

    public function test_totals_are_per_term_and_ranked(): void
    {
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2570, 'term' => 1,
            'starts_on' => Carbon::now('Asia/Bangkok')->subDays(30)->toDateString(), 'ends_on' => Carbon::now('Asia/Bangkok')->subDays(10)->toDateString()]);
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $old = Carbon::now('Asia/Bangkok')->subDays(20)->toDateString();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('represent'), 'occurred_on' => $old])->assertCreated();
        $this->assertSame(2, (int) DB::table('bhv_records')->value('term_id'));
        $this->postJson('/api/behavior/records', ['student_ids' => [1, 2], 'rule_id' => $this->ruleId('help-school'), 'occurred_on' => $this->today()])->assertCreated();
        $this->postJson('/api/behavior/records', ['student_ids' => [2], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->assertCreated();

        $cur = $this->getJson('/api/behavior/classrooms/1/summary')->assertOk()->json();
        $this->assertSame(1, $cur['term_id']);
        $this->assertCount(8, $cur['students']);
        $this->assertSame([1, 5, 0, 5, 1], [$cur['students'][0]['id'], $cur['students'][0]['net_points'], $cur['students'][0]['improve_points'], $cur['students'][0]['good_points'], $cur['students'][0]['rank']]);
        $this->assertSame(3, $cur['students'][1]['net_points']);
        $this->assertSame(2, $cur['students'][1]['rank']);
        $this->assertSame(3, $cur['students'][2]['rank']); // zero-point students share a rank
        $this->assertSame(3, $cur['students'][7]['rank']);

        $past = $this->getJson('/api/behavior/classrooms/1/summary?term_id=2')->assertOk()->json();
        $this->assertSame(10, collect($past['students'])->firstWhere('id', 1)['net_points']);
        $this->assertSame(0, collect($past['students'])->firstWhere('id', 2)['net_points']);
        $this->getJson('/api/behavior/classrooms/1/summary?term_id=999')->assertNotFound();

        $tl = $this->getJson('/api/behavior/students/1/timeline')->json();
        $this->assertSame(5, $tl['totals']['net_points']);
        $this->assertCount(1, $tl['records']);
        $this->assertSame(10, $this->getJson('/api/behavior/students/1/timeline?term_id=2')->json('totals.net_points'));
    }

    public function test_new_homeroom_teacher_cannot_read_the_previous_cohort_of_a_past_term(): void
    {
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2570, 'term' => 1,
            'starts_on' => Carbon::now('Asia/Bangkok')->subDays(30)->toDateString(), 'ends_on' => Carbon::now('Asia/Bangkok')->subDays(10)->toDateString()]);
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $old = Carbon::now('Asia/Bangkok')->subDays(20)->toDateString();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('represent'), 'occurred_on' => $old])->assertCreated();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->assertCreated();
        // teacher 9 became homeroom of classroom 1 only this year; never taught it in term 2
        $this->actingAs($this->otherTeacher());
        DB::table('users')->where('id', 9)->update(['classroom_id' => 1]);
        $this->actingAs(User::find(9));
        $this->getJson('/api/behavior/classrooms/1/summary')->assertOk();
        $this->getJson('/api/behavior/students/1/timeline')->assertOk();
        $this->getJson('/api/behavior/classrooms/1/summary?term_id=2')->assertForbidden();
        $this->getJson('/api/behavior/students/1/timeline?term_id=2')->assertForbidden();
        $this->getJson('/api/behavior/export.csv?term_id=2&classroom_id=1')->assertForbidden();
        $this->assertStringNotContainsString('DEMO-01,', $this->get('/api/behavior/export.csv?term_id=2')->assertOk()->streamedContent());
    }

    public function test_a_new_homeroom_teacher_cannot_back_date_a_record_into_a_term_they_did_not_teach(): void
    {
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2570, 'term' => 1,
            'starts_on' => Carbon::now('Asia/Bangkok')->subDays(30)->toDateString(), 'ends_on' => Carbon::now('Asia/Bangkok')->subDays(10)->toDateString()]);
        $this->actingAs($this->otherTeacher());
        DB::table('users')->where('id', 9)->update(['classroom_id' => 1]);
        $this->actingAs(User::find(9));
        $this->getJson('/api/behavior/rules');
        $old = Carbon::now('Asia/Bangkok')->subDays(20)->toDateString();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('represent'), 'occurred_on' => $old])->assertForbidden();
        $this->assertSame(0, DB::table('bhv_records')->count());
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->assertCreated();
    }

    public function test_a_teacher_cannot_open_the_timeline_of_a_pupil_who_is_no_longer_studying(): void
    {
        $this->actingAs($this->otherTeacher());
        DB::table('users')->where('id', 9)->update(['classroom_id' => 1]);
        $this->actingAs(User::find(9));
        $this->getJson('/api/behavior/students/1/timeline')->assertOk();
        DB::table('students')->where('id', 1)->update(['status' => 'graduated']);
        $this->getJson('/api/behavior/students/1/timeline')->assertNotFound();
        $this->as('admin')->getJson('/api/behavior/students/1/timeline')->assertOk();
    }
    public function test_void_and_ack_store_plain_datetime_strings(): void
    {
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $id = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->json('data.0.id');
        $this->postJson("/api/behavior/records/$id/void", ['version' => 1, 'reason' => 'ทดสอบ'])->assertOk();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) DB::table('bhv_records')->where('id', $id)->value('voided_at'));
        $id2 = $this->postJson('/api/behavior/records', ['student_ids' => [2], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->json('data.0.id');
        (new BehaviorAcknowledgement())->acknowledge($id2, 1, 'guardian:1', null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) DB::table('bhv_records')->where('id', $id2)->value('guardian_ack_at'));
    }

    public function test_bulk_records_are_hidden_from_guardians_by_default(): void
    {
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $fight = $this->ruleId('fight');
        $bulk = $this->postJson('/api/behavior/records', ['student_ids' => [1, 2, 3], 'rule_id' => $fight, 'occurred_on' => $this->today(), 'note' => 'ทะเลาะกับเพื่อน'])->assertCreated()->json('data');
        foreach ($bulk as $r) $this->assertFalse($r['guardian_visible']);
        $single = $this->postJson('/api/behavior/records', ['student_ids' => [4], 'rule_id' => $fight, 'occurred_on' => $this->today()])->assertCreated()->json('data.0');
        $this->assertTrue($single['guardian_visible']);
        $ticked = $this->postJson('/api/behavior/records', ['student_ids' => [5, 6], 'rule_id' => $fight, 'occurred_on' => $this->today(), 'guardian_visible' => true])->assertCreated()->json('data');
        foreach ($ticked as $r) $this->assertTrue($r['guardian_visible']);
    }

    public function test_timeline_uses_the_classroom_snapshot_of_each_record(): void
    {
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $late = $this->ruleId('late');
        // student 9 is now in classroom 2 (the other teacher's); one record was made while the student was in classroom 1
        $mine = $this->postJson('/api/behavior/records', ['student_ids' => [9], 'rule_id' => $late, 'occurred_on' => $this->today(), 'note' => 'ห้องนี้'])->json('data.0.id');
        $old = $this->postJson('/api/behavior/records', ['student_ids' => [9], 'rule_id' => $late, 'occurred_on' => $this->today(), 'note' => 'ห้องเก่า'])->json('data.0.id');
        DB::table('bhv_records')->where('id', $old)->update(['classroom_id' => 1]);
        $this->actingAs($this->otherTeacher());
        $res = $this->getJson('/api/behavior/students/9/timeline')->assertOk()->json();
        $this->assertSame([$mine], array_column($res['records'], 'id'));
        $this->assertSame(1, $res['totals']['records']);
        $this->assertStringNotContainsString('ห้องเก่า', json_encode($res));
        $this->as('admin');
        $this->assertCount(2, $this->getJson('/api/behavior/students/9/timeline')->json('records'));
    }

    public function test_unique_violation_helper_ignores_other_constraint_errors(): void
    {
        $e = new \Illuminate\Database\QueryException('mysql', 'insert', [], tap(new \PDOException('Duplicate entry'), fn ($p) => $p->errorInfo = ['23000', 1062, 'Duplicate entry']));
        $this->assertTrue(\Saan\Modules\Behavior\BehaviorRepository::isDuplicate($e));
        $fk = new \Illuminate\Database\QueryException('mysql', 'insert', [], tap(new \PDOException('FK fails'), fn ($p) => $p->errorInfo = ['23000', 1452, 'Cannot add or update a child row']));
        $this->assertFalse(\Saan\Modules\Behavior\BehaviorRepository::isDuplicate($fk));
    }

    public function test_teacher_scope_is_enforced(): void
    {
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $late = $this->ruleId('late');
        $rec = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $late, 'occurred_on' => $this->today()])->json('data.0.id');
        $this->postJson('/api/behavior/records', ['student_ids' => [9], 'rule_id' => $late, 'occurred_on' => $this->today()])->assertCreated();

        $this->actingAs($this->otherTeacher());
        $ctx = $this->getJson('/api/behavior/context')->assertOk()->json();
        $this->assertFalse($ctx['can_manage']);
        $this->assertSame([2], array_column($ctx['classrooms'], 'id'));
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $late, 'occurred_on' => $this->today()])->assertForbidden();
        $this->postJson('/api/behavior/records', ['student_ids' => [9, 1], 'rule_id' => $late, 'occurred_on' => $this->today()])->assertForbidden();
        $this->assertSame(2, DB::table('bhv_records')->count());
        $this->getJson('/api/behavior/classrooms/1/summary')->assertForbidden();
        $this->getJson('/api/behavior/students/1/timeline')->assertForbidden();
        $this->getJson('/api/behavior/export.csv?classroom_id=1')->assertForbidden();
        $this->postJson("/api/behavior/records/$rec/void", ['version' => 1, 'reason' => 'ไม่ใช่ห้องฉัน'])->assertForbidden();
        $this->getJson('/api/behavior/report')->assertForbidden();
        $this->getJson('/api/behavior/classrooms/2/summary')->assertOk();
        $this->getJson('/api/behavior/students/9/timeline')->assertOk();
        $csv = $this->get('/api/behavior/export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('DEMO-09', $csv);
        $this->assertStringNotContainsString('DEMO-01,', $csv);

        // a teacher without any classroom or an unrelated role sees nothing
        DB::table('users')->where('id', 9)->update(['teacher_id' => null, 'classroom_id' => null]);
        $this->actingAs(User::find(9))->getJson('/api/behavior/classrooms/2/summary')->assertForbidden();
        DB::table('users')->where('id', 9)->update(['role' => 'editor']);
        $this->actingAs(User::find(9))->getJson('/api/behavior/rules')->assertForbidden();
    }

    public function test_report_and_csv_export(): void
    {
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $this->postJson('/api/behavior/records', ['student_ids' => [1, 2], 'rule_id' => $this->ruleId('help-school'), 'occurred_on' => $this->today()])->assertCreated();
        $this->postJson('/api/behavior/records', ['student_ids' => [9], 'rule_id' => $this->ruleId('uniform'), 'occurred_on' => $this->today(), 'note' => '=HYPERLINK("x")'])->assertCreated();
        $void = $this->postJson('/api/behavior/records', ['student_ids' => [3], 'rule_id' => $this->ruleId('fight'), 'occurred_on' => $this->today()])->json('data.0.id');
        $this->postJson("/api/behavior/records/$void/void", ['version' => 1, 'reason' => 'ผิดพลาด'])->assertOk();

        $rep = $this->getJson('/api/behavior/report')->assertOk()->json();
        $this->assertSame(['records' => 3, 'points' => 7, 'students' => 3], $rep['totals']);
        $cats = collect($rep['by_category'])->keyBy('category');
        $this->assertSame([2, 10], [$cats['good']['records'], $cats['good']['points']]);
        $this->assertSame([1, -3], [$cats['improve']['records'], $cats['improve']['points']]);
        $this->assertCount(2, $rep['by_classroom']);
        $this->assertSame('ช่วยงานโรงเรียน', $rep['by_rule'][0]['label']);

        $res = $this->get('/api/behavior/export.csv')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ช่วยงานโรงเรียน', $csv);
        $this->assertStringContainsString('ยกเลิก', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv); // formula neutralised
        $this->assertCount(5, array_filter(explode("\n", trim($csv)))); // header + 4 rows
        $one = $this->get('/api/behavior/export.csv?classroom_id=2')->streamedContent();
        $this->assertStringContainsString('DEMO-09', $one);
        $this->assertStringNotContainsString('DEMO-01', $one);
    }

    public function test_read_model_shape_and_guardian_filter(): void
    {
        $this->as('teacher');
        $this->getJson('/api/behavior/rules');
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('help-friend'), 'occurred_on' => $this->today()])->assertCreated();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('disturb'), 'occurred_on' => $this->today(), 'guardian_visible' => false, 'note' => 'ภายใน'])->assertCreated();

        $all = (new BehaviorReadModel())->forStudent(1);
        $this->assertSame(['student_id', 'term_id', 'records', 'totals'], array_keys($all));
        $this->assertSame(1, $all['term_id']);
        $this->assertSame(['id', 'occurred_on', 'points', 'category', 'rule_id', 'label', 'note', 'status', 'voided_reason', 'guardian_visible', 'guardian_ack_at', 'guardian_ack_by', 'guardian_ack_note', 'recorded_by_name', 'version'], array_keys($all['records'][0]));
        $this->assertSame(['good_points' => 5, 'improve_points' => -5, 'net_points' => 0, 'records' => 2, 'voided' => 0], $all['totals']);
        $this->assertSame('ช่วยเหลือเพื่อน', collect($all['records'])->firstWhere('points', 5)['label']);

        $g = (new BehaviorReadModel())->forStudent(1, 1, true);
        $this->assertCount(1, $g['records']);
        $this->assertSame(['good_points' => 5, 'improve_points' => 0, 'net_points' => 5, 'records' => 1, 'voided' => 0], $g['totals']);
        $this->assertSame([], (new BehaviorReadModel())->forStudent(2)['records']);

        // a voided guardian-visible record must not reach the guardian view, nor its reason
        $wrong = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('fight'), 'occurred_on' => $this->today(), 'note' => 'เหตุการณ์ของเด็กอีกคน'])->json('data.0.id');
        $this->postJson("/api/behavior/records/$wrong/void", ['version' => 1, 'reason' => 'บันทึกผิดคน'])->assertOk();
        $g = (new BehaviorReadModel())->forStudent(1, 1, true);
        $this->assertNotContains($wrong, array_column($g['records'], 'id'));
        $this->assertSame(0, $g['totals']['voided']);
        $this->assertStringNotContainsString('บันทึกผิดคน', json_encode($g, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('เหตุการณ์ของเด็กอีกคน', json_encode($g, JSON_UNESCAPED_UNICODE));
        $staff = (new BehaviorReadModel())->forStudent(1, 1);
        $this->assertSame(1, $staff['totals']['voided']);
        $this->assertArrayNotHasKey('can_void', $staff['records'][0]);
    }

    public function test_guardian_acknowledgement_is_versioned_and_audited(): void
    {
        $this->as('teacher');
        $this->getJson('/api/behavior/rules');
        $id = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('help-school'), 'occurred_on' => $this->today()])->json('data.0.id');
        $hidden = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today(), 'guardian_visible' => false])->json('data.0.id');
        $voided = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->json('data.0.id');
        $this->postJson("/api/behavior/records/$voided/void", ['version' => 1, 'reason' => 'บันทึกผิด'])->assertOk();

        $ack = new BehaviorAcknowledgement();
        foreach ([$hidden, $voided, 99999] as $bad) {
            try { $ack->acknowledge($bad, 1, 'guardian:1', null); $this->fail('expected 404'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        }
        $this->expectVersionConflict(fn () => $ack->acknowledge($id, 9, 'guardian:1', null));

        $res = $ack->acknowledge($id, 1, 'guardian:1', '  รับทราบครับ  ');
        $this->assertSame(2, $res['version']);
        $this->assertSame('guardian:1', $res['guardian_ack_by']);
        $this->assertSame('รับทราบครับ', $res['guardian_ack_note']);
        $this->assertNotNull(DB::table('bhv_records')->where('id', $id)->value('guardian_ack_at'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'behavior.record.acknowledged')->where('entity_id', $id)->count());
        try { $ack->acknowledge($id, 2, 'guardian:1', null); $this->fail('expected 422'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->assertSame('รับทราบครับ', (new BehaviorReadModel())->forStudent(1, 1, true)['records'][0]['guardian_ack_note']);
    }

    private function expectVersionConflict(callable $fn): void
    {
        try { $fn(); $this->fail('expected VersionConflict'); }
        catch (\App\Support\Saan\VersionConflict $e) { $this->assertTrue(true); }
    }

    public function test_history_survives_promotion_and_rule_rename(): void
    {
        DB::table('academic_terms')->insert(['id' => 2, 'school_id' => 1, 'year' => 2570, 'term' => 1,
            'starts_on' => Carbon::now('Asia/Bangkok')->subDays(30)->toDateString(), 'ends_on' => Carbon::now('Asia/Bangkok')->subDays(10)->toDateString()]);
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $rule = $this->ruleId('help-school');
        $old = Carbon::now('Asia/Bangkok')->subDays(20)->toDateString();
        $rec = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $rule, 'occurred_on' => $old, 'note' => 'a\\"b'])->assertCreated();
        $this->assertSame(2, $rec->json('term.id'));
        $this->assertSame(1, (int) DB::table('bhv_records')->value('classroom_id'));

        // promotion: student 1 moves to classroom 2
        DB::table('students')->where('id', 1)->update(['classroom_id' => 2]);
        $past = $this->getJson('/api/behavior/classrooms/1/summary?term_id=2')->assertOk()->json();
        $this->assertSame(5, collect($past['students'])->firstWhere('id', 1)['net_points']);
        $this->assertSame(1, collect($past['students'])->firstWhere('id', 1)['records']);
        $new = $this->getJson('/api/behavior/classrooms/2/summary?term_id=2')->assertOk()->json();
        $this->assertSame(0, collect($new['students'])->firstWhere('id', 1)['net_points']);
        $rep = $this->getJson('/api/behavior/report?term_id=2')->assertOk()->json();
        $this->assertSame([1], array_column($rep['by_classroom'], 'classroom_id'));

        // the teacher of the old room may still void, the new room's teacher may not
        $this->getJson('/api/behavior/export.csv?term_id=2&classroom_id=1')->assertOk();
        $csv = $this->get('/api/behavior/export.csv?term_id=2&classroom_id=1')->streamedContent();
        $this->assertStringContainsString('"a\\""b"', $csv);
        $this->assertStringNotContainsString('DEMO-09', $this->get('/api/behavior/export.csv?term_id=2&classroom_id=2')->streamedContent());

        // renaming the rule keeps the wording recorded at the time
        $r = DB::table('bhv_rules')->where('id', $rule)->first();
        $this->putJson("/api/behavior/rules/$rule", ['version' => $r->version, 'label' => 'ชื่อใหม่ของกติกา'])->assertOk();
        $tl = $this->getJson('/api/behavior/students/1/timeline?term_id=2')->assertOk()->json();
        $this->assertSame('ช่วยงานโรงเรียน', $tl['records'][0]['label']);
        $this->assertSame('ช่วยงานโรงเรียน', $this->getJson('/api/behavior/report?term_id=2')->json('by_rule.0.label'));
        $this->assertStringContainsString('ช่วยงานโรงเรียน', $this->get('/api/behavior/export.csv?term_id=2')->streamedContent());
        $this->assertStringNotContainsString('ชื่อใหม่ของกติกา', json_encode($tl, JSON_UNESCAPED_UNICODE));
    }

    public function test_can_void_hint_and_context_today(): void
    {
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->assertCreated();
        $this->as('teacher');
        $ctx = $this->getJson('/api/behavior/context')->json();
        $this->assertSame($this->today(), $ctx['today']);
        $mine = $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->json('data.0.id');
        $tl = $this->getJson('/api/behavior/students/1/timeline')->json('records');
        $by = collect($tl)->keyBy('id');
        $this->assertTrue($by[$mine]['can_void']);
        $this->assertFalse($by->except($mine)->first()['can_void']);
    }

    public function test_large_batch_checks_scope_once(): void
    {
        $this->as('teacher');
        $this->getJson('/api/behavior/rules');
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->postJson('/api/behavior/records', ['student_ids' => [1, 2, 3, 4, 5, 6, 7, 8], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->assertCreated();
        $scope = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "lessons"') || str_contains($q['query'], 'from `lessons`'))->count();
        $this->assertLessThanOrEqual(2, $scope);
    }

    public function test_other_school_rows_are_invisible(): void
    {
        $this->as('admin');
        $this->getJson('/api/behavior/rules');
        DB::table('students')->insert(['id' => 500, 'school_id' => 2, 'classroom_id' => 1, 'code' => 'OTHER-500', 'name' => 'นักเรียนโรงเรียนอื่น (สมมติ)']);
        $ruleKey = ['school_id' => 2, 'code' => 'other', 'code_key' => 'other', 'label' => 'กติกาโรงเรียนอื่น', 'category' => 'good', 'points' => 9, 'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        $otherRule = DB::table('bhv_rules')->insertGetId($ruleKey);
        $otherRec = DB::table('bhv_records')->insertGetId(['school_id' => 2, 'term_id' => 1, 'student_id' => 1, 'rule_id' => $otherRule, 'occurred_on' => $this->today(),
            'points' => 9, 'note' => 'ลับของโรงเรียนอื่น', 'recorded_by' => 1, 'status' => 'active', 'guardian_visible' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/behavior/records', ['student_ids' => [500], 'rule_id' => $this->ruleId('late'), 'occurred_on' => $this->today()])->assertUnprocessable();
        $this->postJson('/api/behavior/records', ['student_ids' => [1], 'rule_id' => $otherRule, 'occurred_on' => $this->today()])->assertUnprocessable();
        $this->postJson("/api/behavior/records/$otherRec/void", ['version' => 1, 'reason' => 'ลองยกเลิก'])->assertNotFound();
        $this->assertSame('active', DB::table('bhv_records')->where('id', $otherRec)->value('status'));
        $this->assertNotContains('กติกาโรงเรียนอื่น', array_column($this->getJson('/api/behavior/rules?all=1')->json('data'), 'label'));

        $rm = (new BehaviorReadModel())->forStudent(1, 1);
        $this->assertSame([], $rm['records']);
        $this->assertSame(0, $rm['totals']['net_points']);
        $this->assertSame(0, $this->getJson('/api/behavior/report')->json('totals.records'));
        $this->assertStringNotContainsString('ลับของโรงเรียนอื่น', $this->get('/api/behavior/export.csv')->streamedContent());
        $this->assertSame(0, $this->getJson('/api/behavior/students/1/timeline')->json('totals.records'));
        $this->expectNotFoundAck($otherRec);
    }

    private function expectNotFoundAck(int $id): void
    {
        try { (new BehaviorAcknowledgement())->acknowledge($id, 1, 'guardian:1', null); $this->fail('expected 404'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
    }

    public function test_module_is_listed_and_work_menu_is_served(): void
    {
        $this->assertContains('behavior', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->as('teacher')->get('/work')->assertOk()->assertSee('พฤติกรรมนักเรียน')->assertSee('assets/modules/behavior/behavior.js', false);
        $this->assertFileExists(public_path('assets/modules/behavior/behavior.js'));
        $this->assertFileExists(public_path('assets/modules/behavior/behavior.css'));
    }

    public function test_csv_export_is_audited(): void
    {
        $this->as('admin');
        $this->get('/api/behavior/export.csv')->assertOk();
        $row = DB::table('audit_events')->where('action', 'behavior.exported')->first();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('rows', json_decode($row->detail, true));
    }
}