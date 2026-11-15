<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Projects\Support;
use Tests\TestCase;

class ProjectsModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
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

    private function principal(): User { return $this->user('principal'); }
    private function teacher(int $teacherId): User { return $this->user('teacher', ['teacher_id' => $teacherId]); }

    private function plan(int $year = 2570): array
    {
        return $this->postJson('/api/projects/plans', ['fiscal_year_be' => $year, 'title' => 'แผนปฏิบัติการ '.$year])->assertCreated()->json();
    }

    private function body(int $planId, array $extra = []): array
    {
        return array_merge(['plan_id' => $planId, 'code' => 'P-01', 'title' => 'โครงการส่งเสริมการอ่าน', 'objective' => 'นักเรียนอ่านออกเขียนได้', 'target' => 'ร้อยละ 90',
            'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31', 'budget_satang' => 100000, 'budget_source' => 'อุดหนุนรายหัว', 'owner_teacher_id' => 1], $extra);
    }

    /** Principal creates plan + project owned by teacher 1; returns [planId, projectId]. */
    private function seedProject(array $extra = [], int $year = 2570): array
    {
        $this->as($this->principal());
        $plan = $this->plan($year);
        $p = $this->postJson('/api/projects/projects', $this->body($plan['id'], $extra))->assertCreated()->json();
        return [$plan['id'], $p['id']];
    }

    private function moveTo(int $id, string $to)
    {
        $v = DB::table('prj_projects')->where('id', $id)->value('version');
        return $this->postJson('/api/projects/projects/'.$id.'/status', ['to' => $to, 'version' => $v]);
    }

    private function startProject(int $id): void
    {
        $this->as($this->principal());
        $this->moveTo($id, 'approved')->assertOk();
        $this->moveTo($id, 'in_progress')->assertOk();
    }

    private function act(int $projectId, array $extra = [])
    {
        return $this->postJson('/api/projects/projects/'.$projectId.'/activities', array_merge(['title' => 'กิจกรรมวันรักการอ่าน', 'budget_satang' => 30000], $extra));
    }

    // ---- budget arithmetic -------------------------------------------------------------------------------------

    public function test_money_helpers_use_integer_satang(): void
    {
        $this->assertSame('1,234.56', Support::fmt(123456));
        $this->assertSame('1234.56', Support::plain(123456));
        $this->assertSame('0.05', Support::plain(5));
        $this->assertSame('-0.50', Support::plain(-50));
        $this->assertSame('p 1', Support::key("  P   1 "));
        $this->assertSame(2570, Support::fiscalYear('2026-10-01'));
        $this->assertSame(2569, Support::fiscalYear('2026-09-30'));
    }

    public function test_activity_budgets_cannot_exceed_project_budget(): void
    {
        [, $pid] = $this->seedProject();
        $this->act($pid, ['budget_satang' => 60000])->assertCreated();
        $this->act($pid, ['budget_satang' => 40000, 'title' => 'อีกกิจกรรม'])->assertCreated();
        $this->act($pid, ['budget_satang' => 1, 'title' => 'เกินหนึ่งสตางค์'])->assertStatus(422);
        $row = $this->getJson('/api/projects/projects/'.$pid)->assertOk()->json();
        $this->assertSame(100000, $row['project']['allocated_satang']);
        $this->assertSame(0, $row['project']['spent_satang']);
        $this->assertCount(2, $row['activities']);
        // lowering one activity frees budget again
        $a = $row['activities'][0];
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'budget_satang' => 59999])->assertOk();
        $this->act($pid, ['budget_satang' => 1, 'title' => 'พอดี'])->assertCreated();
        // project budget cannot drop below what is allocated
        $p = $row['project'];
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $p['version'], 'budget_satang' => 99999])->assertStatus(422);
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $p['version'], 'budget_satang' => 150000])->assertOk()->assertJsonPath('budget_satang', 150000);
    }

    public function test_invalid_money_is_rejected(): void
    {
        [$plan] = $this->seedProject();
        $this->postJson('/api/projects/projects', $this->body($plan, ['code' => 'P-02', 'budget_satang' => -1]))->assertStatus(422);
        $this->postJson('/api/projects/projects', $this->body($plan, ['code' => 'P-02', 'budget_satang' => 12.5]))->assertStatus(422);
        $this->postJson('/api/projects/projects', $this->body($plan, ['code' => 'P-02', 'budget_source' => 'ไม่มีในรายการ']))->assertStatus(422);
        $this->postJson('/api/projects/projects', $this->body($plan, ['code' => 'P-02', 'ends_on' => '2026-10-31']))->assertStatus(422);
    }

    public function test_an_activity_with_spending_or_a_ledger_link_cannot_be_deleted(): void
    {
        [, $pid] = $this->seedProject();
        $this->startProject($pid);
        $this->as($this->teacher(1));
        $a = $this->act($pid, ['budget_satang' => 30000])->assertCreated()->json();
        $a = $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 10000])->assertOk()->json();
        $this->deleteJson('/api/projects/activities/'.$a['id'], ['version' => $a['version']])->assertStatus(422);
        $this->assertSame(1, DB::table('prj_activities')->where('id', $a['id'])->count());
        $b = $this->act($pid, ['budget_satang' => 1000, 'title' => 'ยังไม่ใช้จ่าย'])->assertCreated()->json();
        $this->deleteJson('/api/projects/activities/'.$b['id'], ['version' => $b['version']])->assertOk();
        // a ledger link alone also blocks (the link is accounting evidence)
        $c = $this->act($pid, ['budget_satang' => 1000, 'title' => 'มีลิงก์'])->assertCreated()->json();
        DB::table('prj_activities')->where('id', $c['id'])->update(['fin_ledger_id' => 99]);
        $this->deleteJson('/api/projects/activities/'.$c['id'], ['version' => $c['version']])->assertStatus(422);
    }

    public function test_a_manager_who_owns_the_project_cannot_approve_it_unless_they_are_the_only_approver(): void
    {
        $me = $this->user('principal', ['teacher_id' => 1]); // owner of the project below as well
        $this->as($me);
        $plan = $this->plan();
        $pid = $this->postJson('/api/projects/projects', $this->body($plan['id']))->assertCreated()->json('id');
        $this->moveTo($pid, 'approved')->assertForbidden();
        $this->assertSame('proposed', DB::table('prj_projects')->where('id', $pid)->value('status'));
        // another active admin exists in the seed; when none is left the sole approver may self-approve, with a dedicated audit event
        DB::table('users')->where('id', '!=', $me->id)->whereIn('role', ['admin', 'principal'])->update(['is_active' => false]);
        $this->as($me->fresh());
        $this->moveTo($pid, 'approved')->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'projects.project.self_approved')->count());
        // a manager who is not the owner approves normally (no self-approval audit)
        $pid2 = $this->postJson('/api/projects/projects', $this->body($plan['id'], ['code' => 'P-02', 'owner_teacher_id' => 2]))->assertCreated()->json('id');
        $this->moveTo($pid2, 'approved')->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'projects.project.self_approved')->count());
    }
    // ---- over budget -------------------------------------------------------------------------------------------

    public function test_overspending_needs_a_reason_and_is_audited(): void
    {
        [, $pid] = $this->seedProject();
        $this->startProject($pid);
        $a = $this->act($pid, ['budget_satang' => 30000])->assertCreated()->json();
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 30000])->assertOk()->assertJsonPath('over_budget', false);
        $a = $this->getJson('/api/projects/projects/'.$pid)->json('activities.0');
        // spent above the activity budget without a reason
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 30001])->assertStatus(422)->assertJsonValidationErrors('over_budget_reason');
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 30001, 'over_budget_reason' => '   '])->assertStatus(422);
        $ok = $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 35000, 'over_budget_reason' => 'ราคาวัสดุสูงกว่าที่ประมาณ'])->assertOk()->assertJsonPath('over_budget', true)->json();
        $this->assertSame('ราคาวัสดุสูงกว่าที่ประมาณ', $ok['over_budget_reason']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'projects.activity.over_budget')->where('entity_id', $a['id'])->count());
        // going back under budget clears the stored reason
        $back = $this->putJson('/api/projects/activities/'.$ok['id'], ['version' => $ok['version'], 'spent_satang' => 10000])->assertOk()->json();
        $this->assertNull($back['over_budget_reason']);
        // the project total counts across activities: 70000 budget left, spend all of project budget then 1 more elsewhere
        $b = $this->act($pid, ['title' => 'กิจกรรมที่สอง', 'budget_satang' => 70000])->assertCreated()->json();
        $this->putJson('/api/projects/activities/'.$b['id'], ['version' => $b['version'], 'spent_satang' => 70000])->assertOk();
        $c = $this->getJson('/api/projects/projects/'.$pid)->json('activities.0');
        $this->putJson('/api/projects/activities/'.$c['id'], ['version' => $c['version'], 'spent_satang' => 30001])->assertStatus(422);
        $this->putJson('/api/projects/activities/'.$c['id'], ['version' => $c['version'], 'spent_satang' => 30001, 'over_budget_reason' => 'เกินงบรวม'])->assertOk();
        $sum = $this->getJson('/api/projects/projects/'.$pid)->json('project');
        $this->assertSame(100001, $sum['spent_satang']);
        $this->assertTrue($sum['over_budget']);
        $this->assertSame(-1, $sum['remaining_satang']);
    }

    public function test_cannot_record_spending_before_the_project_starts(): void
    {
        [, $pid] = $this->seedProject();
        $this->act($pid, ['spent_satang' => 100])->assertStatus(422);
        $this->act($pid, ['spent_satang' => 0])->assertCreated();
    }

    // ---- approval flow -----------------------------------------------------------------------------------------

    public function test_approval_flow_and_permissions(): void
    {
        $owner = $this->teacher(1);
        $this->as($this->principal());
        $plan = $this->plan();
        $this->as($owner);
        $p = $this->postJson('/api/projects/projects', $this->body($plan['id'], ['owner_teacher_id' => 1]))->assertCreated()->assertJsonPath('status', 'proposed')->json();
        $id = $p['id'];
        $this->moveTo($id, 'approved')->assertForbidden();      // owner cannot approve
        $this->moveTo($id, 'in_progress')->assertForbidden();    // cannot skip approval
        $this->as($this->teacher(2));
        $this->moveTo($id, 'cancelled')->assertForbidden();      // another teacher cannot touch it
        $this->as($this->principal());
        $this->moveTo($id, 'approved')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertNotNull(DB::table('prj_projects')->where('id', $id)->value('approved_at'));
        $this->moveTo($id, 'done')->assertForbidden();           // approved -> done is not a transition
        $this->as($owner);
        $this->moveTo($id, 'in_progress')->assertOk();
        $this->moveTo($id, 'done')->assertOk()->assertJsonPath('status', 'done');
        $this->moveTo($id, 'in_progress')->assertForbidden();    // finished is final
        $this->assertSame(1, DB::table('audit_events')->where('action', 'projects.project.approved')->count());
        // principal can send an approved project back
        $this->as($this->principal());
        $q = $this->postJson('/api/projects/projects', $this->body($plan['id'], ['code' => 'P-02']))->assertCreated()->json();
        $this->moveTo($q['id'], 'approved')->assertOk();
        $this->moveTo($q['id'], 'proposed')->assertOk();
        $this->assertNull(DB::table('prj_projects')->where('id', $q['id'])->value('approved_at'));
    }

    public function test_teacher_can_only_propose_for_self_and_needs_teacher_record(): void
    {
        $this->as($this->principal());
        $plan = $this->plan();
        $this->as($this->teacher(1));
        $this->postJson('/api/projects/projects', $this->body($plan['id'], ['owner_teacher_id' => 2]))->assertForbidden();
        $this->as($this->user('teacher'));
        $this->postJson('/api/projects/projects', $this->body($plan['id'], ['code' => 'P-09']))->assertForbidden();
        $this->as($this->principal());
        $this->postJson('/api/projects/projects', $this->body($plan['id'], ['code' => 'P-10', 'owner_teacher_id' => null]))->assertStatus(422);
        $this->postJson('/api/projects/projects', $this->body($plan['id'], ['code' => 'P-10', 'owner_teacher_id' => 999]))->assertStatus(422);
        $this->as($this->user('editor'));
        $this->getJson('/api/projects/projects')->assertForbidden();
    }

    // ---- owner rights ------------------------------------------------------------------------------------------

    public function test_owner_edit_rights_follow_status(): void
    {
        [, $pid] = $this->seedProject();
        $owner = $this->teacher(1); $other = $this->teacher(2);
        $get = fn () => $this->getJson('/api/projects/projects/'.$pid)->json('project');
        $this->as($other);
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'title' => 'แก้โดยคนอื่น'])->assertForbidden();
        $this->act($pid)->assertForbidden();
        $this->as($owner);
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'title' => 'ชื่อใหม่', 'budget_satang' => 120000])->assertOk()->assertJsonPath('budget_satang', 120000);
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'owner_teacher_id' => 2])->assertForbidden();
        $this->as($this->principal());
        $this->moveTo($pid, 'approved')->assertOk();
        $this->as($owner);
        // approved: the owner has nothing to edit until the project starts
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'title' => 'ห้ามแก้'])->assertForbidden();
        $this->act($pid)->assertForbidden();
        $this->moveTo($pid, 'in_progress')->assertOk();
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'title' => 'แก้ระหว่างดำเนินการ'])->assertOk();
        // budget is frozen for the owner once it is no longer proposed, even when the rest of the form is resent unchanged
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'budget_satang' => 500000])->assertForbidden();
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'title' => 'ชื่อเดิม', 'budget_satang' => 120000, 'budget_source' => 'อุดหนุนรายหัว'])->assertOk();
        $this->act($pid, ['budget_satang' => 1000])->assertCreated();
        $this->as($this->principal());
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'budget_satang' => 150000, 'owner_teacher_id' => 2])->assertOk()->assertJsonPath('owner_teacher_id', 2);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'projects.project.budget_changed')->where('entity_id', $pid)->where('detail', 'like', '%150000%')->count());
        // done: owner locked out, manager still allowed
        $this->as($this->teacher(2));
        $this->moveTo($pid, 'done')->assertOk();
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'title' => 'หลังเสร็จ'])->assertForbidden();
        $this->as($this->principal());
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $get()['version'], 'title' => 'ผู้บริหารแก้ได้'])->assertOk();
    }

    public function test_delete_only_while_proposed_and_without_reports(): void
    {
        [, $pid] = $this->seedProject();
        $this->act($pid)->assertCreated();
        $this->as($this->teacher(2));
        $this->deleteJson('/api/projects/projects/'.$pid, ['version' => 1])->assertForbidden();
        $this->as($this->teacher(1));
        $this->deleteJson('/api/projects/projects/'.$pid, ['version' => 99])->assertStatus(409);
        $this->deleteJson('/api/projects/projects/'.$pid, ['version' => 1])->assertOk();
        $this->assertSame(0, DB::table('prj_activities')->where('project_id', $pid)->count());
        [, $q] = $this->seedProject(['code' => 'P-02'], 2571);
        $this->startProject($q);
        $this->as($this->teacher(1));
        $this->deleteJson('/api/projects/projects/'.$q, ['version' => DB::table('prj_projects')->where('id', $q)->value('version')])->assertStatus(422);
    }

    // ---- closed plan -------------------------------------------------------------------------------------------

    public function test_closed_plan_is_read_only(): void
    {
        [$planId, $pid] = $this->seedProject();
        $this->startProject($pid);
        $a = $this->act($pid)->assertCreated()->json();
        $plan = DB::table('prj_plans')->where('id', $planId)->first();
        $this->postJson('/api/projects/plans/'.$planId.'/status', ['to' => 'approved', 'version' => $plan->version])->assertOk();
        $plan = DB::table('prj_plans')->where('id', $planId)->first();
        $this->postJson('/api/projects/plans/'.$planId.'/status', ['to' => 'closed', 'version' => $plan->version])->assertOk()->assertJsonPath('status', 'closed');
        $proj = $this->getJson('/api/projects/projects/'.$pid)->assertOk()->json();
        $this->assertSame('closed', $proj['plan']['status']);
        $this->assertFalse($proj['perm']['project']);
        $this->assertSame([], $proj['perm']['transitions']);
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $proj['project']['version'], 'title' => 'x'])->assertStatus(422);
        $this->act($pid, ['title' => 'ใหม่'])->assertStatus(422);
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'note' => 'x'])->assertStatus(422);
        $this->deleteJson('/api/projects/activities/'.$a['id'], ['version' => $a['version']])->assertStatus(422);
        $this->moveTo($pid, 'done')->assertStatus(422);
        $this->postJson('/api/projects/projects', $this->body($planId, ['code' => 'P-77']))->assertStatus(422);
        $this->postJson('/api/projects/projects/'.$pid.'/reports', ['summary' => 'x'])->assertStatus(422);
        $this->putJson('/api/projects/plans/'.$planId, ['title' => 'ใหม่', 'version' => DB::table('prj_plans')->where('id', $planId)->value('version')])->assertStatus(422);
        $this->postJson('/api/projects/projects/'.$pid.'/reports', ['summary' => 'x'])->assertStatus(422);
        $this->deleteJson('/api/projects/plans/'.$planId, ['version' => DB::table('prj_plans')->where('id', $planId)->value('version')])->assertStatus(422);
        // owner is read-only too, and only an admin may reopen
        $this->as($this->teacher(1));
        $this->act($pid, ['title' => 'ใหม่'])->assertStatus(422);
        $this->as($this->principal());
        $v = DB::table('prj_plans')->where('id', $planId)->value('version');
        $this->postJson('/api/projects/plans/'.$planId.'/status', ['to' => 'approved', 'version' => $v])->assertForbidden();
        $this->as($this->user('admin'));
        $this->postJson('/api/projects/plans/'.$planId.'/status', ['to' => 'approved', 'version' => $v])->assertOk();
        $this->act($pid, ['title' => 'หลังเปิดแผน', 'budget_satang' => 100])->assertCreated();
    }

    public function test_plan_rules(): void
    {
        $this->as($this->teacher(1));
        $this->postJson('/api/projects/plans', ['fiscal_year_be' => 2570, 'title' => 'x'])->assertForbidden();
        $this->as($this->principal());
        $plan = $this->plan(2570);
        $this->postJson('/api/projects/plans', ['fiscal_year_be' => 2570, 'title' => 'ซ้ำ'])->assertStatus(409);
        $this->postJson('/api/projects/plans', ['fiscal_year_be' => 1999, 'title' => 'ผิดปี'])->assertStatus(422);
        $this->postJson('/api/projects/plans/'.$plan['id'].'/status', ['to' => 'closed', 'version' => $plan['version']])->assertStatus(422); // draft cannot jump to closed
        $this->postJson('/api/projects/projects', $this->body($plan['id']))->assertCreated();
        $this->deleteJson('/api/projects/plans/'.$plan['id'], ['version' => $plan['version']])->assertStatus(422); // has a project
        $empty = $this->plan(2571);
        $this->deleteJson('/api/projects/plans/'.$empty['id'], ['version' => $empty['version']])->assertOk();
    }

    // ---- uniqueness / concurrency ------------------------------------------------------------------------------

    public function test_project_code_is_unique_per_plan_regardless_of_case_and_spaces(): void
    {
        [$plan] = $this->seedProject(['code' => 'ศธ  Alpha-1']);
        $this->postJson('/api/projects/projects', $this->body($plan, ['code' => ' ศธ alpha-1 ']))->assertStatus(409);
        $other = $this->plan(2571);
        $this->postJson('/api/projects/projects', $this->body($other['id'], ['code' => 'ศธ alpha-1']))->assertCreated(); // other plan: allowed
        $b = $this->postJson('/api/projects/projects', $this->body($plan, ['code' => 'B-2']))->assertCreated()->json();
        $this->putJson('/api/projects/projects/'.$b['id'], ['version' => $b['version'], 'code' => 'ศธ ALPHA-1'])->assertStatus(409);
        // a direct duplicate insert is still stopped by the database index and never reaches the client as a 500
        try {
            Support::guard(fn () => DB::table('prj_projects')->insert(['school_id' => 1, 'plan_id' => $plan, 'code' => 'x', 'code_key' => 'ศธ alpha-1', 'title' => 't', 'objective' => 'o', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'budget_source' => 'อื่นๆ']));
            $this->fail('duplicate should have raised');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // ---- review round fixes ------------------------------------------------------------------------------------

    public function test_editing_a_within_budget_activity_does_not_demand_a_reason_or_audit(): void
    {
        [, $pid] = $this->seedProject();
        $this->startProject($pid);
        $a = $this->act($pid, ['budget_satang' => 30000])->assertCreated()->json();
        $b = $this->act($pid, ['title' => 'B', 'budget_satang' => 70000])->assertCreated()->json();
        $a = $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 30001, 'over_budget_reason' => 'วัสดุแพง'])->assertOk()->json();
        $b = $this->putJson('/api/projects/activities/'.$b['id'], ['version' => $b['version'], 'spent_satang' => 70000, 'over_budget_reason' => 'เกินงบรวม'])->assertOk()->json();
        $before = DB::table('audit_events')->where('action', 'projects.activity.over_budget')->where('entity_id', $b['id'])->count();
        $b2 = $this->putJson('/api/projects/activities/'.$b['id'], ['version' => $b['version'], 'note' => 'แก้หมายเหตุ'])->assertOk()->json();
        $this->assertSame('เกินงบรวม', $b2['over_budget_reason']);
        $this->assertSame($before, DB::table('audit_events')->where('action', 'projects.activity.over_budget')->where('entity_id', $b['id'])->count());
        // activity A: editing only the note keeps the reason and adds no audit row
        $nA = DB::table('audit_events')->where('action', 'projects.activity.over_budget')->where('entity_id', $a['id'])->count();
        $a2 = $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'note' => 'n'])->assertOk()->json();
        $this->assertSame('วัสดุแพง', $a2['over_budget_reason']);
        $this->assertSame($nA, DB::table('audit_events')->where('action', 'projects.activity.over_budget')->where('entity_id', $a['id'])->count());
    }

    public function test_cancel_rules_with_spending_and_reopening_done(): void
    {
        [, $pid] = $this->seedProject();
        $this->startProject($pid);
        $a = $this->act($pid)->assertCreated()->json();
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 100])->assertOk();
        $this->as($this->teacher(1));
        $this->moveTo($pid, 'cancelled')->assertForbidden(); // owner cannot cancel a running project
        $this->as($this->principal());
        $this->moveTo($pid, 'cancelled')->assertStatus(422); // spent money: close it instead
        $this->moveTo($pid, 'done')->assertOk();
        $this->as($this->teacher(1));
        $this->moveTo($pid, 'in_progress')->assertForbidden();
        $this->as($this->principal());
        $this->moveTo($pid, 'in_progress')->assertOk();
        // without spending a manager may still cancel
        [, $q] = $this->seedProject(['code' => 'P-09'], 2571);
        $this->startProject($q);
        $this->moveTo($q, 'cancelled')->assertOk();
    }

    public function test_budget_cannot_drop_below_spent_and_plan_list_counts_cancelled(): void
    {
        [$planId, $pid] = $this->seedProject();
        $this->startProject($pid);
        $a = $this->act($pid, ['budget_satang' => 50000])->assertCreated()->json();
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 40000])->assertOk();
        $v = $this->getJson('/api/projects/projects/'.$pid)->json('project.version');
        // below allocated (50000) is refused; the spent check is the second guard
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $v, 'budget_satang' => 30000])->assertStatus(422);
        $a = $this->getJson('/api/projects/projects/'.$pid)->json('activities.0');
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'budget_satang' => 0, 'over_budget_reason' => 'ปรับงบ'])->assertOk();
        $this->putJson('/api/projects/projects/'.$pid, ['version' => $v, 'budget_satang' => 30000])->assertStatus(422);
        [, $c] = $this->seedProject(['code' => 'C-1'], 2571);
        $this->moveTo($c, 'cancelled')->assertOk();
        $row = collect($this->getJson('/api/projects/plans')->json('data'))->firstWhere('fiscal_year_be', 2571);
        $this->assertSame(0, $row['project_count']);
        $this->assertSame(1, $row['total_count']);
    }

    public function test_report_conflict_body_hides_evidence_and_closed_plan_blocks_reports(): void
    {
        Storage::fake('local');
        [$planId, $pid] = $this->seedProject();
        $this->startProject($pid);
        $this->as($this->teacher(1));
        $pdf = UploadedFile::fake()->createWithContent('e.pdf', "%PDF-1.4\n%fictional\n");
        $r = $this->post('/api/projects/projects/'.$pid.'/reports', ['summary' => 'ok', 'evidence' => $pdf], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->putJson('/api/projects/reports/'.$r['id'], ['summary' => 'v2', 'version' => $r['version']])->assertOk();
        $res = $this->putJson('/api/projects/reports/'.$r['id'], ['summary' => 'stale', 'version' => $r['version']])->assertStatus(409);
        $this->assertArrayNotHasKey('evidence_path', $res->json('current'));
        $this->assertArrayNotHasKey('evidence_sha256', $res->json('current'));
        $this->deleteJson('/api/projects/reports/'.$r['id'], ['version' => 1])->assertStatus(409)->assertJsonMissingPath('current.evidence_path');
        $this->as($this->principal());
        foreach (['approved', 'closed'] as $to) {
            $pl = DB::table('prj_plans')->where('id', $planId)->first();
            $this->postJson('/api/projects/plans/'.$planId.'/status', ['to' => $to, 'version' => $pl->version])->assertOk();
        }
        $cur = DB::table('prj_reports')->where('id', $r['id'])->first();
        $this->putJson('/api/projects/reports/'.$r['id'], ['summary' => 'x', 'version' => $cur->version])->assertStatus(422);
        $this->deleteJson('/api/projects/reports/'.$r['id'], ['version' => $cur->version])->assertStatus(422);
    }

    public function test_inactive_teachers_are_not_offered_and_ledger_detail_is_limited(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'is_active')) {
            DB::table('teachers')->where('id', 2)->update(['is_active' => false]);
            [$planId, $pid] = $this->seedProject();
            $ids = collect($this->getJson('/api/projects/me')->json('teachers'))->pluck('id')->all();
            $this->assertNotContains(2, $ids);
            $this->postJson('/api/projects/projects', $this->body($planId, ['code' => 'Z-1', 'owner_teacher_id' => 2]))->assertStatus(422);
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('fin_ledger')) return;
        [, $pid] = $this->seedProject(['code' => 'L-1'], 2572);
        $this->startProject($pid);
        $exp = DB::table('fin_ledger')->insertGetId(['school_id' => 1, 'entry_date' => '2026-11-05', 'kind' => 'expense', 'category' => 'วัสดุ', 'amount_satang' => 25000, 'description' => 'x', 'document_no' => 'PV-9', 'created_by' => 1, 'version' => 1]);
        $a = $this->act($pid)->assertCreated()->json();
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'fin_ledger_id' => $exp])->assertOk();
        $this->as($this->teacher(2));
        $seen = $this->getJson('/api/projects/projects/'.$pid)->json('activities.0');
        $this->assertSame($exp, $seen['fin_ledger_id']);
        $this->assertNull($seen['ledger']);
    }

    public function test_stale_version_gets_409_with_current_row(): void
    {
        [, $pid] = $this->seedProject();
        $this->putJson('/api/projects/projects/'.$pid, ['version' => 1, 'title' => 'รอบแรก'])->assertOk();
        $this->putJson('/api/projects/projects/'.$pid, ['version' => 1, 'title' => 'รอบสอง'])->assertStatus(409)->assertJsonPath('current.title', 'รอบแรก');
        $a = $this->act($pid)->assertCreated()->json();
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'note' => 'a'])->assertOk();
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'note' => 'b'])->assertStatus(409);
    }

    public function test_idempotency_key_replays_without_duplicates(): void
    {
        $this->as($this->principal());
        $plan = $this->plan();
        $h = ['Idempotency-Key' => 'prj-create-1'];
        $first = $this->postJson('/api/projects/projects', $this->body($plan['id']), $h)->assertCreated()->json();
        $second = $this->postJson('/api/projects/projects', $this->body($plan['id']), $h)->assertCreated()->json();
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, DB::table('prj_projects')->count());
    }

    // ---- scoping -----------------------------------------------------------------------------------------------

    public function test_other_schools_data_is_invisible(): void
    {
        $this->as($this->principal());
        $this->plan();
        $foreignPlan = DB::table('prj_plans')->insertGetId(['school_id' => 2, 'fiscal_year_be' => 2570, 'title' => 'ของโรงเรียนอื่น', 'status' => 'draft', 'version' => 1]);
        $foreign = DB::table('prj_projects')->insertGetId(['school_id' => 2, 'plan_id' => $foreignPlan, 'code' => 'F-1', 'code_key' => 'f-1', 'title' => 'โครงการโรงเรียนอื่น', 'objective' => 'o',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'budget_satang' => 5000, 'budget_source' => 'อื่นๆ', 'status' => 'proposed', 'version' => 1]);
        $fa = DB::table('prj_activities')->insertGetId(['school_id' => 2, 'project_id' => $foreign, 'title' => 'x', 'budget_satang' => 1, 'spent_satang' => 0, 'version' => 1]);
        $fr = DB::table('prj_reports')->insertGetId(['school_id' => 2, 'project_id' => $foreign, 'summary' => 's', 'submitted_by' => 1, 'submitted_at' => now(), 'version' => 1, 'evidence_path' => 'projects/2/x.pdf']);
        $this->assertCount(1, $this->getJson('/api/projects/plans')->json('data'));
        $this->assertCount(0, $this->getJson('/api/projects/projects')->json('data'));
        $this->getJson('/api/projects/projects/'.$foreign)->assertNotFound();
        $this->getJson('/api/projects/projects/'.$foreign.'/form')->assertNotFound();
        $this->getJson('/api/projects/summary?plan_id='.$foreignPlan)->assertNotFound();
        $this->get('/api/projects/export.csv?plan_id='.$foreignPlan)->assertNotFound();
        $this->putJson('/api/projects/projects/'.$foreign, ['version' => 1, 'title' => 'x'])->assertNotFound();
        $this->postJson('/api/projects/projects/'.$foreign.'/status', ['to' => 'approved', 'version' => 1])->assertNotFound();
        $this->postJson('/api/projects/projects/'.$foreign.'/activities', ['title' => 'x', 'budget_satang' => 1])->assertNotFound();
        $this->putJson('/api/projects/activities/'.$fa, ['version' => 1, 'note' => 'x'])->assertNotFound();
        $this->deleteJson('/api/projects/activities/'.$fa, ['version' => 1])->assertNotFound();
        $this->deleteJson('/api/projects/reports/'.$fr, ['version' => 1])->assertNotFound();
        $this->getJson('/api/projects/reports/'.$fr.'/evidence')->assertNotFound();
        $this->postJson('/api/projects/projects', $this->body($foreignPlan, ['code' => 'Z']))->assertNotFound();
        $this->assertSame('proposed', DB::table('prj_projects')->where('id', $foreign)->value('status'));
    }

    public function test_guests_and_unrelated_roles_are_refused(): void
    {
        $this->getJson('/api/projects/plans')->assertUnauthorized();
        $this->as($this->user('editor'));
        $this->getJson('/api/projects/plans')->assertForbidden();
    }

    // ---- reports and evidence ----------------------------------------------------------------------------------

    public function test_report_with_private_evidence(): void
    {
        Storage::fake('local');
        [, $pid] = $this->seedProject();
        $this->as($this->teacher(1));
        $this->postJson('/api/projects/projects/'.$pid.'/reports', ['summary' => 'ยังไม่เริ่ม'])->assertForbidden(); // proposed: reports need a started project
        $this->startProject($pid);
        $this->as($this->teacher(1));
        $pdf = UploadedFile::fake()->createWithContent('หลักฐาน.pdf', "%PDF-1.4\n%fictional\n");
        $r = $this->post('/api/projects/projects/'.$pid.'/reports', ['summary' => 'จัดกิจกรรมสำเร็จ', 'outcome_pct' => 85, 'evidence' => $pdf], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertTrue($r['has_evidence']);
        $this->assertArrayNotHasKey('evidence_path', $r);
        $row = DB::table('prj_reports')->where('id', $r['id'])->first();
        $this->assertMatchesRegularExpression('~^projects/1/'.$pid.'/[0-9a-f]{32}\.pdf$~', $row->evidence_path);
        Storage::disk('local')->assertExists($row->evidence_path);
        $this->assertSame(64, strlen($row->evidence_sha256));
        $this->get('/api/projects/reports/'.$r['id'].'/evidence')->assertOk();
        // another teacher sees the report text but not the file or its name
        $this->as($this->teacher(2));
        $this->get('/api/projects/reports/'.$r['id'].'/evidence')->assertNotFound();
        $shown = $this->getJson('/api/projects/projects/'.$pid)->json('reports.0');
        $this->assertNull($shown['evidence_name']);
        $this->assertFalse($this->getJson('/api/projects/projects/'.$pid)->json('perm.evidence'));
        $this->as($this->principal());
        $this->get('/api/projects/reports/'.$r['id'].'/evidence')->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'projects.report.evidence_downloaded')->count());
        // bad type, decided from the bytes
        $this->as($this->teacher(1));
        $fake = UploadedFile::fake()->createWithContent('x.pdf', '<?php echo 1;');
        $this->post('/api/projects/projects/'.$pid.'/reports', ['summary' => 'x', 'evidence' => $fake], ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson('/api/projects/projects/'.$pid.'/reports', ['summary' => 'x', 'outcome_pct' => 101])->assertStatus(422);
        // update + delete (delete releases the bytes)
        $this->putJson('/api/projects/reports/'.$r['id'], ['summary' => 'แก้ไขสรุป', 'outcome_pct' => 90, 'version' => $r['version']])->assertOk()->assertJsonPath('outcome_pct', 90);
        $this->as($this->teacher(2));
        $this->deleteJson('/api/projects/reports/'.$r['id'], ['version' => 2])->assertForbidden();
        $this->as($this->teacher(1));
        $this->deleteJson('/api/projects/reports/'.$r['id'], ['version' => 2])->assertOk();
        Storage::disk('local')->assertMissing($row->evidence_path);
    }

    public function test_evidence_download_name_takes_its_extension_from_the_detected_type(): void
    {
        Storage::fake('local');
        [, $pid] = $this->seedProject();
        $this->startProject($pid);
        $this->as($this->teacher(1));
        $pdf = UploadedFile::fake()->createWithContent('x.pdf.html', "%PDF-1.4\n%fictional\n");
        $r = $this->post('/api/projects/projects/'.$pid.'/reports', ['summary' => 'ok', 'evidence' => $pdf], ['Accept' => 'application/json'])->assertCreated()->json();
        $dl = $this->get('/api/projects/reports/'.$r['id'].'/evidence')->assertOk();
        $disp = (string) $dl->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression('/filename="?x_pdf\.pdf"?(;|$)/', $disp);
        $this->assertStringNotContainsString('.html', $disp);
        // rows stored before this fix still download with a clean name
        DB::table('prj_reports')->where('id', $r['id'])->update(['evidence_name' => 'a"b;c.html']);
        $this->assertMatchesRegularExpression('/filename="?a_b_c\.pdf"?(;|$)/', (string) $this->get('/api/projects/reports/'.$r['id'].'/evidence')->assertOk()->headers->get('Content-Disposition'));
    }
    // ---- finance link (read only) ------------------------------------------------------------------------------

    public function test_activity_can_link_an_expense_ledger_entry_read_only(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('fin_ledger')) $this->markTestSkipped('finance module tables not present');
        [, $pid] = $this->seedProject();
        $this->startProject($pid);
        $exp = DB::table('fin_ledger')->insertGetId(['school_id' => 1, 'entry_date' => '2026-11-05', 'kind' => 'expense', 'category' => 'วัสดุ', 'amount_satang' => 25000, 'description' => 'ซื้อหนังสือ', 'document_no' => 'PV-1', 'created_by' => 1, 'version' => 1]);
        $inc = DB::table('fin_ledger')->insertGetId(['school_id' => 1, 'entry_date' => '2026-11-05', 'kind' => 'income', 'category' => 'x', 'amount_satang' => 1, 'description' => 'รับ', 'created_by' => 1, 'version' => 1]);
        $other = DB::table('fin_ledger')->insertGetId(['school_id' => 2, 'entry_date' => '2026-11-05', 'kind' => 'expense', 'category' => 'x', 'amount_satang' => 1, 'description' => 'อื่น', 'created_by' => 1, 'version' => 1]);
        $a = $this->act($pid)->assertCreated()->json();
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'fin_ledger_id' => $inc])->assertStatus(422);
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'fin_ledger_id' => $other])->assertStatus(422);
        $ok = $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'fin_ledger_id' => $exp])->assertOk()->json();
        $this->assertSame(25000, $ok['ledger']['amount_satang']);
        $this->assertSame(25000, DB::table('fin_ledger')->where('id', $exp)->value('amount_satang'));
        $this->assertSame(1, DB::table('fin_ledger')->where('school_id', 1)->where('id', $exp)->value('version'), 'finance rows are never written');
        $this->as($this->teacher(1));
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $ok['version'], 'fin_ledger_id' => null])->assertForbidden();
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $ok['version'], 'note' => 'ครูแก้หมายเหตุ'])->assertOk()->assertJsonPath('fin_ledger_id', $exp);
    }

    public function test_ledger_field_label_matches_the_server_behaviour(): void
    {
        // the server accepts and stores the number from an executive (test above), so the field must not be labelled read-only
        $js = file_get_contents(public_path('assets/modules/projects/projects.js'));
        $this->assertStringNotContainsString('สมุดเงินสด (อ่านอย่างเดียว)', $js);
        $this->assertStringContainsString('เลขรายการรายจ่ายในสมุดเงินสด (ผู้บริหารเชื่อมหรือยกเลิกได้ ระบบไม่แก้สมุดเงินสด)', $js);
    }

    // ---- summary / csv / print ---------------------------------------------------------------------------------

    public function test_summary_per_plan_and_budget_source_and_csv(): void
    {
        [$plan, $p1] = $this->seedProject(['budget_satang' => 100000]);
        $p2 = $this->postJson('/api/projects/projects', $this->body($plan, ['code' => 'P-02', 'title' => 'โครงการสอง', 'budget_satang' => 50000, 'budget_source' => 'เรียนฟรี 15 ปี']))->assertCreated()->json('id');
        $p3 = $this->postJson('/api/projects/projects', $this->body($plan, ['code' => '=P-03', 'title' => '=SUM(A1)', 'budget_satang' => 70000, 'budget_source' => 'เรียนฟรี 15 ปี']))->assertCreated()->json('id');
        $p4 = $this->postJson('/api/projects/projects', $this->body($plan, ['code' => 'P-04', 'budget_satang' => 999999, 'budget_source' => 'อื่นๆ']))->assertCreated()->json('id');
        $this->startProject($p1);
        $this->act($p1, ['budget_satang' => 100000])->assertCreated();
        $a = $this->getJson('/api/projects/projects/'.$p1)->json('activities.0');
        $this->putJson('/api/projects/activities/'.$a['id'], ['version' => $a['version'], 'spent_satang' => 40000])->assertOk();
        $this->moveTo($p2, 'approved')->assertOk();
        $this->moveTo($p4, 'cancelled')->assertOk(); // cancelled projects are left out of every money total
        $s = $this->getJson('/api/projects/summary?plan_id='.$plan)->assertOk()->json();
        $this->assertSame(3, $s['totals']['count']);
        $this->assertSame(220000, $s['totals']['budget_satang']);
        $this->assertSame(40000, $s['totals']['spent_satang']);
        $this->assertSame(180000, $s['totals']['remaining_satang']);
        $this->assertSame(150000, $s['totals']['approved_budget_satang']);
        $src = collect($s['by_source'])->keyBy('source');
        $this->assertSame(100000, $src['อุดหนุนรายหัว']['budget_satang']);
        $this->assertSame(40000, $src['อุดหนุนรายหัว']['spent_satang']);
        $this->assertSame(120000, $src['เรียนฟรี 15 ปี']['budget_satang']);
        $this->assertSame(50000, $src['เรียนฟรี 15 ปี']['approved_budget_satang']);
        $this->assertSame(0, $src['อื่นๆ']['budget_satang']);
        $this->assertSame(0, $src['รายได้สถานศึกษา']['count']);
        $this->assertSame(1, $s['by_status']['cancelled']);
        $this->assertSame($s['totals']['budget_satang'], array_sum(array_column($s['by_source'], 'budget_satang')));
        // default plan = latest
        $this->assertSame($plan, $this->getJson('/api/projects/summary')->json('plan.id'));
        // teachers may read summary and export
        $this->as($this->teacher(2));
        $this->getJson('/api/projects/summary?plan_id='.$plan)->assertOk();
        $csv = $this->get('/api/projects/export.csv?plan_id='.$plan)->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $text = $csv->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $this->assertStringContainsString('1000.00', $text);
        $this->assertStringContainsString('400.00', $text);
        $this->assertStringContainsString("'=SUM(A1)", $text, 'formula cells are neutralised');
        $this->assertStringNotContainsString(',=SUM(A1)', $text);
    }

    public function test_print_form_is_a4_thai_and_escapes_values(): void
    {
        [, $pid] = $this->seedProject(['title' => 'โครงการ <script>alert(1)</script>', 'objective' => 'เพื่อ "ทดสอบ" & ตรวจ']);
        $this->act($pid, ['title' => 'กิจกรรม <b>หนา</b>', 'budget_satang' => 12345])->assertCreated();
        $this->as($this->teacher(3)); // any staff may print
        $res = $this->get('/api/projects/projects/'.$pid.'/form')->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('size:A4', $html);
        $this->assertStringContainsString('lang="th"', $html);
        $this->assertStringContainsString('แบบเสนอโครงการตามแผนปฏิบัติการ ปีงบประมาณ 2570', $html);
        $this->assertStringContainsString('1,000.00 บาท', $html);
        $this->assertStringContainsString('123.45', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<b>หนา</b>', $html);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(' onclick=', $html);
    }

    public function test_me_and_list_filters(): void
    {
        [$plan] = $this->seedProject();
        $this->postJson('/api/projects/projects', $this->body($plan, ['code' => 'P-02', 'title' => 'โครงการกีฬา', 'owner_teacher_id' => 2]))->assertCreated();
        $this->as($this->teacher(2));
        $me = $this->getJson('/api/projects/me')->assertOk()->json();
        $this->assertSame(2, $me['teacher_id']);
        $this->assertFalse($me['is_manager']);
        $this->assertSame(Support::SOURCES, $me['sources']);
        $this->assertCount(2, $this->getJson('/api/projects/projects?plan_id='.$plan)->json('data'));
        $this->assertCount(1, $this->getJson('/api/projects/projects?mine=1')->json('data'));
        $this->assertCount(1, $this->getJson('/api/projects/projects?q='.rawurlencode('กีฬา'))->json('data'));
        $this->assertCount(0, $this->getJson('/api/projects/projects?q='.rawurlencode('%'))->json('data'));
        $this->assertCount(2, $this->getJson('/api/projects/projects?status=proposed')->json('data'));
    }

    public function test_module_manifest_declares_work_menu_and_private_storage(): void
    {
        $m = json_decode(file_get_contents(base_path('modules/projects/module.json')), true);
        $this->assertSame('projects', $m['work_menu'][0]['key']);
        $this->assertSame(['teacher', 'admin', 'principal'], $m['work_menu'][0]['roles']);
        $this->assertContains('projects', $m['private_storage']);
        foreach ($m['work_assets'] as $asset) $this->assertFileExists(public_path($asset));
        $this->as($this->teacher(1));
        $page = $this->get('/work')->assertOk()->getContent();
        $this->assertStringContainsString('assets/modules/projects/projects.js', $page);
        $this->assertStringContainsString('#projects', $page);
        $js = file_get_contents(public_path('assets/modules/projects/projects.js'));
        $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|input)\s*=/i', $js);
    }
}
