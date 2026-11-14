<?php

namespace Saan\Modules\Projects\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Saan\Modules\Projects\Support;

/**
 * Activities of a project. Budget arithmetic, all in integer satang:
 *  - the activity budgets of a project add up to at most the project budget (hard limit);
 *  - an activity may not be spent when the project has not started (proposed/approved);
 *  - spent above the activity budget, or a project total spent above the project budget, needs over_budget_reason
 *    (stored on the activity and written to the audit log as projects.activity.over_budget).
 */
class ActivityController
{
    public static function listFor(int $projectId, bool $full = true): array
    {
        $rows = DB::table('prj_activities')->where('school_id', Support::school())->where('project_id', $projectId)->orderBy('planned_on')->orderBy('id')->get();
        $ledger = [];
        $ids = $rows->pluck('fin_ledger_id')->filter()->unique()->all();
        if ($full && $ids && Schema::hasTable('fin_ledger')) {
            $ledger = DB::table('fin_ledger')->where('school_id', Support::school())->whereIn('id', $ids)->get(['id', 'entry_date', 'amount_satang', 'document_no', 'category'])->keyBy('id')->all();
        }
        return $rows->map(function ($a) use ($ledger) {
            $l = $a->fin_ledger_id ? ($ledger[$a->fin_ledger_id] ?? null) : null;
            return ['id' => (int) $a->id, 'project_id' => (int) $a->project_id, 'title' => $a->title, 'planned_on' => $a->planned_on, 'done_on' => $a->done_on,
                'budget_satang' => (int) $a->budget_satang, 'spent_satang' => (int) $a->spent_satang, 'note' => $a->note, 'over_budget_reason' => $a->over_budget_reason,
                'over_budget' => (int) $a->spent_satang > (int) $a->budget_satang, 'fin_ledger_id' => $a->fin_ledger_id === null ? null : (int) $a->fin_ledger_id,
                'ledger' => $l ? ['entry_date' => $l->entry_date, 'amount_satang' => (int) $l->amount_satang, 'document_no' => $l->document_no, 'category' => $l->category] : null,
                'version' => (int) $a->version];
        })->all();
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes|required';
        return ['title' => $req.'|string|max:200', 'planned_on' => 'nullable|date_format:Y-m-d', 'done_on' => 'nullable|date_format:Y-m-d|before_or_equal:'.Support::today(),
            'budget_satang' => $req.'|integer|min:0|max:'.Support::MAX_SATANG, 'spent_satang' => 'nullable|integer|min:0|max:'.Support::MAX_SATANG, 'note' => 'nullable|string|max:500',
            'over_budget_reason' => 'nullable|string|max:500', 'fin_ledger_id' => 'nullable|integer|min:1'];
    }

    /** Validates the budget rules inside the locked transaction; returns the reason to store (null when not over budget). */
    private function checkBudget(object $project, ?object $cur, int $budget, int $spent, ?string $reason): ?string
    {
        $o = DB::table('prj_activities')->where('school_id', Support::school())->where('project_id', $project->id)->when($cur, fn ($q) => $q->where('id', '!=', (int) $cur->id))
            ->selectRaw('coalesce(sum(budget_satang),0) as b, coalesce(sum(spent_satang),0) as s')->first();
        $projectBudget = (int) $project->budget_satang;
        if ((int) $o->b + $budget > $projectBudget) abort(422, 'งบกิจกรรมรวมเกินงบโครงการ (เหลือแบ่งได้ '.Support::fmt(max(0, $projectBudget - (int) $o->b)).' บาท)');
        if ($spent > 0 && !in_array($project->status, ['in_progress', 'done'], true)) abort(422, 'บันทึกการใช้จ่ายได้เมื่อโครงการเริ่มดำเนินการแล้ว');
        $prev = (int) ($cur->spent_satang ?? 0);
        $overProject = (int) $o->s + $spent > $projectBudget && $spent > $prev;
        $over = $spent > $budget || $overProject;
        if (!$over) {
            // keep an earlier reason while still over budget; never wipe it silently
            $stillOver = (int) $o->s + $spent > $projectBudget;
            return $cur && trim((string) $cur->over_budget_reason) !== '' && $stillOver ? $cur->over_budget_reason : null;
        }
        $reason = $reason === null ? '' : trim($reason);
        if ($reason === '') throw ValidationException::withMessages(['over_budget_reason' => 'ใช้จ่ายเกินงบ ต้องระบุเหตุผลที่เกินงบ']);
        return $reason;
    }

    private function checkLedger(?int $id, ?int $current): ?int
    {
        if ($id === $current) return $current;
        abort_unless(Support::isManager(), 403, 'เชื่อมหรือยกเลิกการเชื่อมรายการสมุดเงินสดได้เฉพาะผู้บริหาร');
        if ($id === null) return null;
        abort_unless(Schema::hasTable('fin_ledger'), 422, 'ยังไม่ได้เปิดใช้โมดูลการเงิน จึงเชื่อมรายการสมุดเงินสดไม่ได้');
        $e = DB::table('fin_ledger')->where('school_id', Support::school())->where('id', $id)->first(['id', 'kind']);
        abort_unless($e && $e->kind === 'expense', 422, 'ไม่พบรายการรายจ่ายในสมุดเงินสด');
        return $id;
    }

    private function payload(array $d, ?object $cur, object $project): array
    {
        $budget = array_key_exists('budget_satang', $d) ? (int) $d['budget_satang'] : (int) ($cur->budget_satang ?? 0);
        $spent = array_key_exists('spent_satang', $d) ? (int) ($d['spent_satang'] ?? 0) : (int) ($cur->spent_satang ?? 0);
        $reason = $this->checkBudget($project, $cur, $budget, $spent, $d['over_budget_reason'] ?? ($cur->over_budget_reason ?? null));
        $row = ['budget_satang' => $budget, 'spent_satang' => $spent, 'over_budget_reason' => $reason];
        foreach (['title', 'planned_on', 'done_on', 'note'] as $k) if (array_key_exists($k, $d)) $row[$k] = is_string($d[$k]) && $k === 'title' ? trim($d[$k]) : $d[$k];
        if (array_key_exists('fin_ledger_id', $d)) $row['fin_ledger_id'] = $this->checkLedger($d['fin_ledger_id'] === null ? null : (int) $d['fin_ledger_id'], $cur?->fin_ledger_id === null ? null : (int) $cur->fin_ledger_id);
        return $row;
    }

    private function auditOver(object $row, array $values, ?object $cur = null): void
    {
        $changed = $cur === null || $values['spent_satang'] !== (int) $cur->spent_satang || $values['budget_satang'] !== (int) $cur->budget_satang || $values['over_budget_reason'] !== $cur->over_budget_reason;
        if ($values['over_budget_reason'] !== null && $changed) Mutation::audit('projects.activity.over_budget', 'prj_activities', (int) $row->id,
            ['budget_satang' => $values['budget_satang'], 'spent_satang' => $values['spent_satang'], 'reason' => $values['over_budget_reason']]);
    }

    public function store(Request $r, int $projectId)
    {
        $d = $r->validate($this->rules(true));
        return Idempotency::run($r, function () use ($d, $projectId) {
            $row = Support::tx(function () use ($d, $projectId) {
                [$project, $plan] = Support::context($projectId);
                Support::assertCan($project, $plan, 'activity');
                $values = $this->payload($d, null, $project);
                $row = Mutation::insert('prj_activities', array_merge(['note' => null, 'planned_on' => null, 'done_on' => null], $values, ['project_id' => $projectId]),
                    'projects.activity.created', ['project_id' => $projectId, 'budget_satang' => $values['budget_satang'], 'spent_satang' => $values['spent_satang']]);
                $this->auditOver($row, $values);
                return $row;
            });
            return response()->json(collect(self::listFor($projectId))->firstWhere('id', (int) $row->id), 201);
        });
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'] + $this->rules(false));
        $a = DB::table('prj_activities')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบกิจกรรม');
        $row = Support::tx(function () use ($d, $id, $a) {
            [$project, $plan] = Support::context((int) $a->project_id);
            Support::assertCan($project, $plan, 'activity');
            $cur = DB::table('prj_activities')->where('school_id', Support::school())->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'ไม่พบกิจกรรม');
            if ((int) $cur->version !== (int) $d['version']) Mutation::update('prj_activities', $id, (int) $d['version'], [], 'projects.activity.updated'); // raises the standard 409 with the current row
            $values = $this->payload($d, $cur, $project);
            $row = Mutation::update('prj_activities', $id, (int) $d['version'], $values, 'projects.activity.updated', ['fields' => array_keys($values)]);
            $this->auditOver($row, $values, $cur);
            return $row;
        });
        return response()->json(collect(self::listFor((int) $row->project_id))->firstWhere('id', (int) $row->id));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $a = DB::table('prj_activities')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบกิจกรรม');
        Support::tx(function () use ($d, $id, $a) {
            [$project, $plan] = Support::context((int) $a->project_id);
            Support::assertCan($project, $plan, 'activity');
            $cur = DB::table('prj_activities')->where('school_id', Support::school())->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'ไม่พบกิจกรรม');
            // spending and a finance ledger link are accounting evidence: deleting the activity would orphan them
            abort_if((int) $cur->spent_satang > 0 || $cur->fin_ledger_id !== null, 422, 'กิจกรรมนี้มีการใช้จ่ายหรือเชื่อมกับสมุดเงินสดแล้ว ลบไม่ได้ (แก้ยอดเป็น 0 และยกเลิกการเชื่อมก่อน)');
            Mutation::delete('prj_activities', $id, (int) $d['version'], 'projects.activity.deleted');
        });
        return response()->json(['ok' => true]);
    }
}
