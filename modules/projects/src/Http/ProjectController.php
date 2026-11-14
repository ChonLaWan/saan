<?php

namespace Saan\Modules\Projects\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Saan\Modules\Projects\Support;

/** Projects: list, detail, create/edit, status flow, delete and the printable A4 form. */
class ProjectController
{
    private const FIELDS = ['code', 'title', 'owner_teacher_id', 'department', 'strategy', 'objective', 'target', 'starts_on', 'ends_on', 'budget_satang', 'budget_source'];

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes|required';
        return [
            'code' => $req.'|string|max:40', 'title' => $req.'|string|max:200', 'owner_teacher_id' => ($creating ? 'nullable' : 'sometimes|nullable').'|integer|min:1',
            'department' => 'nullable|string|max:120', 'strategy' => 'nullable|string|max:200', 'objective' => $req.'|string|max:5000', 'target' => 'nullable|string|max:5000',
            'starts_on' => $req.'|date_format:Y-m-d', 'ends_on' => $req.'|date_format:Y-m-d', 'budget_satang' => $req.'|integer|min:0|max:'.Support::MAX_SATANG,
            'budget_source' => [$creating ? 'required' : 'sometimes', Rule::in(Support::SOURCES)],
        ];
    }

    private function assertTeacher(?int $id, ?int $currentOwner = null): void
    {
        if ($id === null) return;
        abort_unless(DB::table('teachers')->where('school_id', Support::school())->where('id', $id)
            ->when(Schema::hasColumn('teachers', 'is_active') && $id !== $currentOwner, fn ($q) => $q->where('is_active', true))->exists(), 422, 'ไม่พบครูผู้รับผิดชอบโครงการ');
    }

    private function assertCodeFree(int $planId, string $code, ?int $exceptId = null): void
    {
        $dup = DB::table('prj_projects')->where('school_id', Support::school())->where('plan_id', $planId)->where('code_key', Support::key($code))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($dup) abort(409, 'รหัสโครงการ '.trim($code).' ซ้ำกับโครงการอื่นในแผนเดียวกัน');
    }

    // ---------------------------------------------------------------- read

    public function index(Request $r)
    {
        $f = $r->validate(['plan_id' => 'nullable|integer|min:1', 'status' => ['nullable', Rule::in(Support::PROJECT_STATUS)], 'owner_teacher_id' => 'nullable|integer|min:1',
            'source' => ['nullable', Rule::in(Support::SOURCES)], 'q' => 'nullable|string|max:80', 'mine' => 'nullable|boolean']);
        $q = Support::projects();
        if (!empty($f['plan_id'])) $q->where('p.plan_id', $f['plan_id']);
        if (!empty($f['status'])) $q->where('p.status', $f['status']);
        if (!empty($f['owner_teacher_id'])) $q->where('p.owner_teacher_id', $f['owner_teacher_id']);
        if (!empty($f['source'])) $q->where('p.budget_source', $f['source']);
        if (!empty($f['mine'])) $q->where('p.owner_teacher_id', Support::teacherId() ?? 0);
        if (!empty($f['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']).'%';
            $q->where(fn ($w) => $w->where('p.title', 'like', $like)->orWhere('p.code', 'like', $like));
        }
        $rows = $q->orderBy('pl.fiscal_year_be', 'desc')->orderBy('p.code_key')->limit(1000)->get();
        return response()->json(['data' => $rows->map(fn ($p) => Support::presentProject($p))->all()])->header('Cache-Control', 'no-store, private');
    }

    private function perm(object $project, object $plan): array
    {
        $can = fn (string $k) => Support::deny($project, $plan, $k) === null;
        $manager = Support::isManager();
        return ['project' => $can('project'), 'activity' => $can('activity'), 'report' => $can('report'), 'budget' => $can('project') && ($manager || $project->status === 'proposed'),
            'owner_change' => $can('project') && $manager, 'ledger' => $can('activity') && $manager && Schema::hasTable('fin_ledger'), 'evidence' => Support::canSeeEvidence($project),
            'delete' => $can('project') && $project->status === 'proposed', 'transitions' => Support::transitions($project, $plan), 'is_owner' => Support::isOwner($project)];
    }

    public function show(int $id)
    {
        $row = Support::projects()->where('p.id', $id)->first() ?? abort(404, 'ไม่พบโครงการ');
        $plan = Support::planRow((int) $row->plan_id);
        $project = Support::presentProject($row);
        return response()->json(['project' => $project, 'plan' => ['id' => (int) $plan->id, 'fiscal_year_be' => (int) $plan->fiscal_year_be, 'title' => $plan->title, 'status' => $plan->status],
            'activities' => ActivityController::listFor($id, Support::canSeeEvidence($row)), 'reports' => ReportController::listFor($id, Support::canSeeEvidence($row)), 'perm' => $this->perm($row, $plan)])
            ->header('Cache-Control', 'no-store, private');
    }

    // ---------------------------------------------------------------- write

    public function store(Request $r)
    {
        $d = $r->validate(['plan_id' => 'required|integer|min:1'] + $this->rules(true));
        abort_if($d['ends_on'] < $d['starts_on'], 422, 'วันสิ้นสุดต้องไม่ก่อนวันเริ่ม');
        $user = $r->user();
        if (Support::isManager($user)) {
            abort_if(empty($d['owner_teacher_id']), 422, 'กรุณาเลือกครูผู้รับผิดชอบโครงการ');
            $owner = (int) $d['owner_teacher_id'];
        } else {
            $owner = Support::teacherId($user);
            abort_if($owner === null, 403, 'บัญชีนี้ยังไม่ได้ผูกกับทะเบียนครู จึงเสนอโครงการไม่ได้');
            abort_if(!empty($d['owner_teacher_id']) && (int) $d['owner_teacher_id'] !== $owner, 403, 'เสนอโครงการแทนผู้อื่นไม่ได้');
        }
        return Idempotency::run($r, function () use ($d, $owner) {
            $row = Support::tx(function () use ($d, $owner) {
                $plan = Support::planRow((int) $d['plan_id'], true);
                abort_if($plan->status === 'closed', 422, 'แผนปฏิบัติการปีนี้ปิดแล้ว เพิ่มโครงการไม่ได้');
                $this->assertTeacher($owner);
                $this->assertCodeFree((int) $plan->id, $d['code']);
                return Mutation::insert('prj_projects', [
                    'plan_id' => (int) $plan->id, 'code' => trim($d['code']), 'code_key' => Support::key($d['code']), 'title' => trim($d['title']), 'owner_teacher_id' => $owner,
                    'department' => $d['department'] ?? null, 'strategy' => $d['strategy'] ?? null, 'objective' => $d['objective'], 'target' => $d['target'] ?? null,
                    'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'], 'budget_satang' => (int) $d['budget_satang'], 'budget_source' => $d['budget_source'], 'status' => 'proposed',
                ], 'projects.project.created', ['plan_id' => (int) $plan->id, 'budget_satang' => (int) $d['budget_satang'], 'owner_teacher_id' => $owner]);
            });
            return response()->json(Support::presentProject($row), 201);
        });
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'] + $this->rules(false));
        $row = Support::tx(function () use ($id, $d) {
            [$p, $plan] = Support::context($id);
            Support::assertCan($p, $plan, 'project');
            $manager = Support::isManager();
            $changes = [];
            foreach (self::FIELDS as $k) if (array_key_exists($k, $d)) $changes[$k] = in_array($k, ['owner_teacher_id', 'budget_satang'], true) && $d[$k] !== null ? (int) $d[$k] : $d[$k];
            foreach (['title', 'objective', 'code'] as $k) if (isset($changes[$k])) $changes[$k] = $k === 'objective' ? $changes[$k] : trim($changes[$k]);
            // fields only a manager (or the owner while still proposed) may change are compared with the stored value, so a full-form PUT of unchanged values is fine
            $differs = fn ($k) => array_key_exists($k, $changes) && (string) $changes[$k] !== (string) $p->{$k};
            if (!$manager) {
                abort_if($differs('owner_teacher_id'), 403, 'ย้ายผู้รับผิดชอบโครงการได้เฉพาะผู้บริหาร');
                abort_if(($differs('budget_satang') || $differs('budget_source')) && $p->status !== 'proposed', 403, 'แก้งบประมาณหลังเสนอโครงการได้เฉพาะผู้บริหาร');
            }
            if (!empty($changes['owner_teacher_id'])) $this->assertTeacher((int) $changes['owner_teacher_id'], $p->owner_teacher_id === null ? null : (int) $p->owner_teacher_id);
            if (array_key_exists('owner_teacher_id', $changes) && $changes['owner_teacher_id'] === null) abort(422, 'ต้องมีครูผู้รับผิดชอบโครงการ');
            $start = $changes['starts_on'] ?? $p->starts_on; $end = $changes['ends_on'] ?? $p->ends_on;
            abort_if(substr((string) $end, 0, 10) < substr((string) $start, 0, 10), 422, 'วันสิ้นสุดต้องไม่ก่อนวันเริ่ม');
            if ($differs('code')) { $this->assertCodeFree((int) $p->plan_id, $changes['code'], $id); $changes['code_key'] = Support::key($changes['code']); }
            elseif (isset($changes['code'])) unset($changes['code']);
            $budgetChange = $differs('budget_satang') ? [(int) $p->budget_satang, (int) $changes['budget_satang']] : null;
            if ($budgetChange) {
                $sum = DB::table('prj_activities')->where('school_id', Support::school())->where('project_id', $id)->selectRaw('coalesce(sum(budget_satang),0) as b, coalesce(sum(spent_satang),0) as s')->first();
                abort_if($budgetChange[1] < (int) $sum->b, 422, 'งบใหม่ต่ำกว่างบที่แบ่งให้กิจกรรมแล้ว ('.Support::fmt((int) $sum->b).' บาท) กรุณาลดงบกิจกรรมก่อน');
                abort_if($budgetChange[1] < $budgetChange[0] && $budgetChange[1] < (int) $sum->s, 422, 'งบใหม่ต่ำกว่ายอดที่ใช้จ่ายไปแล้ว ('.Support::fmt((int) $sum->s).' บาท)');
            }
            $out = Mutation::update('prj_projects', $id, (int) $d['version'], $changes, 'projects.project.updated', ['fields' => array_keys($changes)]);
            if ($budgetChange) Mutation::audit('projects.project.budget_changed', 'prj_projects', $id, ['from_satang' => $budgetChange[0], 'to_satang' => $budgetChange[1], 'status' => $p->status, 'by_manager' => $manager]);
            return $out;
        });
        return response()->json(Support::presentProject(Support::projects()->where('p.id', $row->id)->first()));
    }

    public function status(Request $r, int $id)
    {
        $d = $r->validate(['to' => ['required', Rule::in(Support::PROJECT_STATUS)], 'version' => 'required|integer|min:1', 'reason' => 'nullable|string|max:300']);
        $row = Support::tx(function () use ($id, $d, $r) {
            [$p, $plan] = Support::context($id);
            abort_if($plan->status === 'closed', 422, 'แผนปฏิบัติการปีนี้ปิดแล้ว ข้อมูลเป็นแบบอ่านอย่างเดียว');
            if (!Support::isManager() && !Support::isOwner($p)) abort(403, 'เฉพาะเจ้าของโครงการหรือผู้บริหารเท่านั้นที่เปลี่ยนสถานะได้');
            abort_unless(in_array($d['to'], Support::transitions($p, $plan), true), 403, 'เปลี่ยนสถานะโครงการจาก '.$p->status.' เป็น '.$d['to'].' ไม่ได้ด้วยสิทธิ์นี้');
            abort_if($d['to'] === 'cancelled' && (int) DB::table('prj_activities')->where('school_id', Support::school())->where('project_id', $id)->sum('spent_satang') > 0, 422, 'โครงการนี้มีการใช้จ่ายแล้ว ยกเลิกไม่ได้ ให้ปิดเป็นเสร็จสิ้นแทน');
            $changes = ['status' => $d['to']];
            $self = $d['to'] === 'approved' && Support::isOwner($p);
            if ($self) Mutation::audit('projects.project.self_approved', 'prj_projects', $id, ['reason' => $d['reason'] ?? null, 'sole_approver' => true]); // transitions() already refused it when another approver exists
            if ($d['to'] === 'approved') $changes += ['approved_by' => $r->user()->id, 'approved_at' => now()];
            elseif ($p->status === 'approved' && $d['to'] === 'proposed') $changes += ['approved_by' => null, 'approved_at' => null];
            return Mutation::update('prj_projects', $id, (int) $d['version'], $changes, 'projects.project.'.$d['to'], ['from' => $p->status, 'to' => $d['to'], 'reason' => $d['reason'] ?? null]);
        });
        return response()->json(Support::presentProject(Support::projects()->where('p.id', $row->id)->first()));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        Support::tx(function () use ($id, $d) {
            [$p, $plan] = Support::context($id);
            Support::assertCan($p, $plan, 'project');
            abort_unless($p->status === 'proposed', 422, 'ลบได้เฉพาะโครงการที่ยังอยู่ในสถานะเสนอโครงการ');
            abort_if(DB::table('prj_reports')->where('school_id', Support::school())->where('project_id', $id)->exists(), 422, 'โครงการนี้มีรายงานผลแล้ว ลบไม่ได้');
            Mutation::delete('prj_projects', $id, (int) $d['version'], 'projects.project.deleted');
            DB::table('prj_activities')->where('school_id', Support::school())->where('project_id', $id)->delete();
        });
        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- print

    public function form(int $id)
    {
        $row = Support::projects()->where('p.id', $id)->first() ?? abort(404, 'ไม่พบโครงการ');
        [$school, $address] = Support::schoolInfo();
        return response()->view('projects::form', ['p' => Support::presentProject($row), 'activities' => ActivityController::listFor($id, Support::canSeeEvidence($row)), 'school' => $school, 'address' => $address,
            'approver' => $row->approved_by ? (string) DB::table('users')->where('school_id', Support::school())->where('id', $row->approved_by)->value('name') : null])
            ->header('Cache-Control', 'no-store, private');
    }
}
