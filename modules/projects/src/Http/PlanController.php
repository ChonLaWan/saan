<?php

namespace Saan\Modules\Projects\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Saan\Modules\Projects\Support;

/** Plans (one per fiscal year), session info, summaries and CSV export. */
class PlanController
{
    private const NEXT = ['draft' => ['approved'], 'approved' => ['closed'], 'closed' => ['approved']];

    public function me(Request $r)
    {
        $u = $r->user();
        return response()->json([
            'role' => $u->role, 'is_manager' => Support::isManager($u), 'is_admin' => $u->role === 'admin', 'teacher_id' => Support::teacherId($u),
            'today' => Support::today(), 'fiscal_year_be' => Support::fiscalYear(Support::today()),
            'teachers' => DB::table('teachers')->where('school_id', Support::school())->when(Schema::hasColumn('teachers', 'is_active'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'sources' => Support::SOURCES, 'finance_link' => Support::isManager($u) && Schema::hasTable('fin_ledger'),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function presentPlan(object $p, array $extra = []): array
    {
        return ['id' => (int) $p->id, 'fiscal_year_be' => (int) $p->fiscal_year_be, 'title' => $p->title, 'status' => $p->status, 'version' => (int) $p->version] + $extra;
    }

    public function plans()
    {
        $school = Support::school();
        $rows = DB::table('prj_plans')->where('school_id', $school)->orderByDesc('fiscal_year_be')->get();
        $agg = DB::table('prj_projects')->where('school_id', $school)->groupBy('plan_id')
            ->selectRaw("plan_id, count(*) as total, sum(case when status <> 'cancelled' then 1 else 0 end) as n, coalesce(sum(case when status <> 'cancelled' then budget_satang else 0 end), 0) as budget")->get()->keyBy('plan_id');
        return response()->json(['data' => $rows->map(fn ($p) => $this->presentPlan($p, ['project_count' => (int) ($agg[$p->id]->n ?? 0), 'total_count' => (int) ($agg[$p->id]->total ?? 0), 'budget_satang' => (int) ($agg[$p->id]->budget ?? 0)]))->all()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $r)
    {
        $d = $r->validate(['fiscal_year_be' => 'required|integer|between:2500,2700', 'title' => 'required|string|max:200']);
        return Idempotency::run($r, function () use ($d) {
            $row = Support::tx(function () use ($d) {
                if (DB::table('prj_plans')->where('school_id', Support::school())->where('fiscal_year_be', $d['fiscal_year_be'])->exists()) abort(409, 'มีแผนปฏิบัติการของปีงบประมาณ '.$d['fiscal_year_be'].' อยู่แล้ว');
                return Mutation::insert('prj_plans', ['fiscal_year_be' => $d['fiscal_year_be'], 'title' => trim($d['title']), 'status' => 'draft'], 'projects.plan.created', ['fiscal_year_be' => $d['fiscal_year_be']]);
            });
            return response()->json($this->presentPlan($row), 201);
        });
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'version' => 'required|integer|min:1']);
        $row = Support::tx(function () use ($id, $d) {
            $p = Support::planRow($id);
            abort_if($p->status === 'closed', 422, 'แผนปฏิบัติการปีนี้ปิดแล้ว ข้อมูลเป็นแบบอ่านอย่างเดียว');
            return Mutation::update('prj_plans', $id, (int) $d['version'], ['title' => trim($d['title'])], 'projects.plan.updated');
        });
        return response()->json($this->presentPlan($row));
    }

    public function status(Request $r, int $id)
    {
        $d = $r->validate(['to' => ['required', Rule::in(Support::PLAN_STATUS)], 'version' => 'required|integer|min:1']);
        $row = Support::tx(function () use ($id, $d, $r) {
            $p = Support::planRow($id);
            abort_unless(in_array($d['to'], self::NEXT[$p->status] ?? [], true), 422, 'เปลี่ยนสถานะแผนจาก '.$p->status.' เป็น '.$d['to'].' ไม่ได้');
            if ($p->status === 'closed') abort_unless($r->user()->role === 'admin', 403, 'เปิดแผนที่ปิดแล้วได้เฉพาะผู้ดูแลระบบ');
            return Mutation::update('prj_plans', $id, (int) $d['version'], ['status' => $d['to']], 'projects.plan.'.($p->status === 'closed' ? 'reopened' : $d['to']), ['from' => $p->status, 'to' => $d['to']]);
        });
        return response()->json($this->presentPlan($row));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        Support::tx(function () use ($id, $d) {
            $p = Support::planRow($id, true);
            abort_unless($p->status === 'draft', 422, 'ลบได้เฉพาะแผนที่ยังเป็นร่าง');
            abort_if(DB::table('prj_projects')->where('school_id', Support::school())->where('plan_id', $id)->exists(), 422, 'แผนนี้มีโครงการแล้ว ลบไม่ได้');
            Mutation::delete('prj_plans', $id, (int) $d['version'], 'projects.plan.deleted');
        });
        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- summary / csv

    private function resolvePlan(?int $planId): ?object
    {
        $q = DB::table('prj_plans')->where('school_id', Support::school());
        if ($planId) return $q->where('id', $planId)->first() ?? abort(404, 'ไม่พบแผนปฏิบัติการ');
        return $q->orderByDesc('fiscal_year_be')->first();
    }

    private function planProjects(object $plan): array
    {
        return Support::projects()->where('p.plan_id', $plan->id)->orderBy('p.code_key')->limit(5000)->get()->map(fn ($p) => Support::presentProject($p))->all();
    }

    /** Cancelled projects are listed in by_status but excluded from every money total. */
    public function summary(Request $r)
    {
        $d = $r->validate(['plan_id' => 'nullable|integer|min:1']);
        $plan = $this->resolvePlan($d['plan_id'] ?? null);
        if (!$plan) return response()->json(['plan' => null, 'totals' => null, 'by_source' => [], 'by_status' => []])->header('Cache-Control', 'no-store, private');
        $rows = $this->planProjects($plan);
        $active = array_values(array_filter($rows, fn ($p) => $p['status'] !== 'cancelled'));
        $approvedStates = ['approved', 'in_progress', 'done'];
        $agg = function (array $list) use ($approvedStates) {
            $budget = array_sum(array_column($list, 'budget_satang')); $spent = array_sum(array_column($list, 'spent_satang'));
            return ['count' => count($list), 'budget_satang' => $budget, 'allocated_satang' => array_sum(array_column($list, 'allocated_satang')), 'spent_satang' => $spent, 'remaining_satang' => $budget - $spent,
                'approved_budget_satang' => array_sum(array_column(array_filter($list, fn ($p) => in_array($p['status'], $approvedStates, true)), 'budget_satang')),
                'over_budget_count' => count(array_filter($list, fn ($p) => $p['over_budget']))];
        };
        $bySource = [];
        foreach (Support::SOURCES as $s) $bySource[] = ['source' => $s] + $agg(array_values(array_filter($active, fn ($p) => $p['budget_source'] === $s)));
        $byStatus = array_fill_keys(Support::PROJECT_STATUS, 0);
        foreach ($rows as $p) $byStatus[$p['status']]++;
        return response()->json(['plan' => $this->presentPlan($plan), 'totals' => $agg($active), 'by_source' => $bySource, 'by_status' => $byStatus])->header('Cache-Control', 'no-store, private');
    }

    public function exportCsv(Request $r)
    {
        $d = $r->validate(['plan_id' => 'nullable|integer|min:1']);
        $plan = $this->resolvePlan($d['plan_id'] ?? null);
        abort_unless($plan, 404, 'ยังไม่มีแผนปฏิบัติการ');
        $labels = ['proposed' => 'เสนอโครงการ', 'approved' => 'อนุมัติแล้ว', 'in_progress' => 'กำลังดำเนินการ', 'done' => 'เสร็จสิ้น', 'cancelled' => 'ยกเลิก'];
        $rows = array_map(fn ($p) => [$p['code'], $p['title'], $p['owner_name'], $p['department'], $p['strategy'], $p['budget_source'], $labels[$p['status']] ?? $p['status'], $p['starts_on'], $p['ends_on'],
            Support::plain($p['budget_satang']), Support::plain($p['allocated_satang']), Support::plain($p['spent_satang']), Support::plain($p['remaining_satang']), $p['over_budget'] ? 'เกินงบ' : ''], $this->planProjects($plan));
        return Support::csvResponse('projects-'.$plan->fiscal_year_be.'.csv', ['รหัส', 'โครงการ', 'ผู้รับผิดชอบ', 'กลุ่มงาน', 'ยุทธศาสตร์/มาตรฐาน', 'แหล่งงบ', 'สถานะ', 'เริ่ม', 'สิ้นสุด', 'งบอนุมัติ (บาท)', 'งบแยกกิจกรรม (บาท)', 'ใช้จ่ายแล้ว (บาท)', 'คงเหลือ (บาท)', 'หมายเหตุ'], $rows);
    }
}
