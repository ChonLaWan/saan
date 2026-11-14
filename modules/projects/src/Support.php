<?php

namespace Saan\Modules\Projects;

use App\Support\Saan\DbError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Shared helpers of the projects module: ids, Bangkok today, money text, access rules, row loaders and CSV.
 * Access rules (one place, used by every write and by the permission flags sent to the browser):
 *  - admin / principal ("managers") approve projects and manage plans; they may edit any project of an open plan unless it is cancelled.
 *  - a teacher owns a project when users.teacher_id = prj_projects.owner_teacher_id; the owner edits it only while it is proposed or in_progress,
 *    and may change the budget only while it is still proposed.
 *  - a closed plan is read-only for everybody (budget lines, activities, reports, status).
 */
final class Support
{
    public const SOURCES = ['อุดหนุนรายหัว', 'เรียนฟรี 15 ปี', 'รายได้สถานศึกษา', 'อื่นๆ'];
    public const PLAN_STATUS = ['draft', 'approved', 'closed'];
    public const PROJECT_STATUS = ['proposed', 'approved', 'in_progress', 'done', 'cancelled'];
    public const MAX_SATANG = 100000000000;

    public static function school(): int { return (int) config('school.school_id'); }

    public static function today(): string { return now('Asia/Bangkok')->toDateString(); }

    /** Thai fiscal year (1 Oct - 30 Sep) in the Buddhist era. */
    public static function fiscalYear(string $date): int
    {
        [$y, $m] = array_map('intval', explode('-', substr($date, 0, 7)));
        return $y + 543 + ($m >= 10 ? 1 : 0);
    }

    /** Normalised unique key: trimmed, lower-cased, runs of whitespace collapsed. */
    public static function key(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    public static function fmt(int $satang): string
    {
        $abs = abs($satang);
        return ($satang < 0 ? '-' : '').number_format(intdiv($abs, 100), 0, '.', ',').'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function plain(int $satang): string
    {
        $abs = abs($satang);
        return ($satang < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function thaiDate(?string $iso): string
    {
        if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return '';
        $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        return (int) $m[3].' '.$months[(int) $m[2]].' '.((int) $m[1] + 543);
    }

    public static function disk() { return Storage::disk('local'); } // storage/app/private, never served by the web server

    // ---------------------------------------------------------------- database guards

    /** Unique violation or deadlock becomes HTTP 409 (never 500). */
    public static function guard(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            if (DbError::isUnique($e)) abort(409, 'ข้อมูลซ้ำกับรายการที่มีอยู่ กรุณาโหลดใหม่แล้วลองอีกครั้ง');
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            $driver = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
            if ($state === '40001' || in_array($driver, [1213, 1205], true)) abort(409, 'ระบบกำลังทำรายการอื่นอยู่ กรุณาลองใหม่');
            throw $e;
        }
    }

    public static function tx(\Closure $fn): mixed
    {
        return self::guard(fn () => DB::transaction($fn, 3));
    }

    // ---------------------------------------------------------------- rows

    public static function planRow(int $id, bool $shared = false): object
    {
        $q = DB::table('prj_plans')->where('school_id', self::school())->where('id', $id);
        if ($shared && DB::transactionLevel() > 0) $q->sharedLock();
        return $q->first() ?? abort(404, 'ไม่พบแผนปฏิบัติการ');
    }

    public static function projectRow(int $id, bool $lock = false): object
    {
        $q = DB::table('prj_projects')->where('school_id', self::school())->where('id', $id);
        if ($lock && DB::transactionLevel() > 0) $q->lockForUpdate();
        return $q->first() ?? abort(404, 'ไม่พบโครงการ');
    }

    /** Inside a transaction: plan (shared lock, so closing a plan and writing into it are serialised) then project (row lock). @return array{0:object,1:object} */
    public static function context(int $projectId): array
    {
        $plan = DB::table('prj_projects')->where('school_id', self::school())->where('id', $projectId)->value('plan_id');
        abort_if($plan === null, 404, 'ไม่พบโครงการ');
        $planRow = self::planRow((int) $plan, true);
        return [self::projectRow($projectId, true), $planRow];
    }

    /** Projects joined with plan, owner name and activity totals (satang). */
    public static function projects()
    {
        $sum = fn (string $col) => '(select coalesce(sum(a.'.$col.'), 0) from prj_activities a where a.project_id = p.id and a.school_id = p.school_id)';
        return DB::table('prj_projects as p')
            ->join('prj_plans as pl', fn ($j) => $j->on('pl.id', '=', 'p.plan_id')->on('pl.school_id', '=', 'p.school_id'))
            ->leftJoin('teachers as t', fn ($j) => $j->on('t.id', '=', 'p.owner_teacher_id')->on('t.school_id', '=', 'p.school_id'))
            ->where('p.school_id', self::school())
            ->select('p.*', 'pl.status as plan_status', 'pl.fiscal_year_be', 't.name as owner_name')
            ->selectRaw($sum('budget_satang').' as allocated_satang')
            ->selectRaw($sum('spent_satang').' as spent_satang');
    }

    public static function presentProject(object $p): array
    {
        $budget = (int) $p->budget_satang; $spent = (int) ($p->spent_satang ?? 0); $alloc = (int) ($p->allocated_satang ?? 0);
        return [
            'id' => (int) $p->id, 'plan_id' => (int) $p->plan_id, 'fiscal_year_be' => isset($p->fiscal_year_be) ? (int) $p->fiscal_year_be : null, 'plan_status' => $p->plan_status ?? null,
            'code' => $p->code, 'title' => $p->title, 'owner_teacher_id' => $p->owner_teacher_id === null ? null : (int) $p->owner_teacher_id, 'owner_name' => $p->owner_name ?? null,
            'department' => $p->department, 'strategy' => $p->strategy, 'objective' => $p->objective, 'target' => $p->target,
            'starts_on' => $p->starts_on, 'ends_on' => $p->ends_on, 'budget_satang' => $budget, 'budget_source' => $p->budget_source, 'status' => $p->status,
            'approved_at' => $p->approved_at, 'allocated_satang' => $alloc, 'spent_satang' => $spent, 'remaining_satang' => $budget - $spent,
            'over_budget' => $spent > $budget, 'version' => (int) $p->version,
        ];
    }

    // ---------------------------------------------------------------- access

    public static function isManager(?object $u = null): bool
    {
        $u ??= auth()->user();
        return $u && in_array($u->role, ['admin', 'principal'], true);
    }

    public static function teacherId(?object $u = null): ?int
    {
        $u ??= auth()->user();
        if (!$u || !$u->teacher_id) return null;
        return DB::table('teachers')->where('school_id', self::school())->where('id', $u->teacher_id)->exists() ? (int) $u->teacher_id : null;
    }

    public static function isOwner(object $project): bool
    {
        $t = self::teacherId();
        return $t !== null && $project->owner_teacher_id !== null && (int) $project->owner_teacher_id === $t;
    }

    /** Evidence files are readable by the owner and managers only (photos may show pupils). */
    public static function canSeeEvidence(object $project): bool
    {
        return self::isManager() || self::isOwner($project);
    }

    /**
     * Why the signed-in user may NOT write $kind ('project'|'activity'|'report') on this project, or null when allowed.
     * @return array{0:int,1:string}|null [http status, message]
     */
    public static function deny(object $project, object $plan, string $kind): ?array
    {
        if ($plan->status === 'closed') return [422, 'แผนปฏิบัติการปีนี้ปิดแล้ว ข้อมูลเป็นแบบอ่านอย่างเดียว'];
        if ($project->status === 'cancelled') return [422, 'โครงการถูกยกเลิกแล้ว แก้ไขไม่ได้'];
        $reportOk = in_array($project->status, ['in_progress', 'done'], true);
        if (self::isManager()) return $kind === 'report' && !$reportOk ? [422, 'ส่งรายงานผลได้เมื่อโครงการเริ่มดำเนินการแล้ว'] : null;
        if (!self::isOwner($project)) return [403, 'เฉพาะเจ้าของโครงการหรือผู้บริหารเท่านั้นที่แก้ไขได้'];
        if ($kind === 'report') return $reportOk ? null : [403, 'ส่งรายงานผลได้เมื่อโครงการเริ่มดำเนินการแล้ว'];
        return in_array($project->status, ['proposed', 'in_progress'], true) ? null : [403, 'เจ้าของโครงการแก้ไขได้เฉพาะสถานะเสนอโครงการหรือกำลังดำเนินการ'];
    }

    public static function assertCan(object $project, object $plan, string $kind): void
    {
        $d = self::deny($project, $plan, $kind);
        if ($d) abort($d[0], $d[1]);
    }

    /**
     * A manager who owns the project may not approve it while another active admin/principal exists. Only the sole approver of the school
     * (no other eligible account) may self-approve, and that is audited as such by the caller.
     */
    public static function selfApprovalBlocked(object $p): bool
    {
        if (!self::isManager() || !self::isOwner($p)) return false;
        return DB::table('users')->where('school_id', self::school())->where('is_active', true)->whereIn('role', ['admin', 'principal'])->where('id', '!=', (int) auth()->id())->exists();
    }

    /** Status values the signed-in user may move this project to. */
    public static function transitions(object $p, object $plan): array
    {
        if ($plan->status === 'closed') return [];
        $m = self::isManager(); $o = self::isOwner($p); $out = [];
        switch ($p->status) {
            case 'proposed': if ($m && !self::selfApprovalBlocked($p)) $out[] = 'approved'; if ($m || $o) $out[] = 'cancelled'; break;
            case 'approved': if ($m) { $out[] = 'proposed'; $out[] = 'cancelled'; } if ($m || $o) $out[] = 'in_progress'; break;
            case 'in_progress': if ($m || $o) $out[] = 'done'; if ($m) $out[] = 'cancelled'; break;
            case 'done': if ($m) $out[] = 'in_progress'; break;
        }
        return $out;
    }

    // ---------------------------------------------------------------- CSV / school name

    /** CSV text (UTF-8 BOM for Excel). Cells that start with a formula character are neutralised. */
    public static function csv(array $header, iterable $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        $clean = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/D', $v) ? "'".$v : $v;
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $row) fputcsv($fh, array_map($clean, $row), ',', '"', '');
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    public static function csvResponse(string $name, array $header, iterable $rows)
    {
        return response(self::csv($header, $rows), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function schoolInfo(): array
    {
        $theme = app(\App\Domain\CmsPublishing::class)->theme(self::school())['published'] ?? [];
        return [trim((string) ($theme['school_name'] ?? '')) ?: (string) config('saan.display'), trim((string) ($theme['address'] ?? ''))];
    }
}
