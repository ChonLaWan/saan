<?php

namespace Saan\Modules\LeaveDuty;

use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use App\Support\StorageQuota;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * M14 leave requests. One state machine (ACTIONS) for every transition: version check, actor rule, reason, audit, mail.
 * States: draft -> submitted -> approved | rejected ; submitted -> withdrawn (owner) ; approved -> cancel_requested -> cancelled | approved ;
 * rejected -> (resubmit) new row linked by resubmitted_from. Legacy prototype status 'pending' is read as 'submitted'.
 * Working days exclude Saturday/Sunday and acad_calendar_days of kind 'holiday' (when that table exists).
 * A leave may not cross the fiscal-year boundary (1 Oct); the teacher files two requests so quota is counted in the right year.
 */
class Leave
{
    use Common;

    public const DEFAULT_TYPES = [
        ['sick', 'ลาป่วย', null, 3.0, 'working'],
        ['personal', 'ลากิจส่วนตัว', 45.0, null, 'working'],
        ['vacation', 'ลาพักผ่อน', 10.0, null, 'working'],
        ['maternity', 'ลาคลอดบุตร', 90.0, null, 'calendar'],
        ['ordination', 'ลาอุปสมบท/ประกอบพิธีฮัจย์', 120.0, null, 'calendar'],
        ['paternity', 'ลาไปช่วยเหลือภริยาที่คลอดบุตร', 15.0, null, 'working'],
    ];
    /** working = weekdays minus school holidays; calendar = every day of the range (maternity, ordination/hajj count continuous days) */
    public const COUNT_MODES = ['working', 'calendar'];
    public const PENDING = ['submitted', 'pending'];
    public const HELD = ['approved', 'cancel_requested'];
    public const OPEN = ['submitted', 'pending', 'approved', 'cancel_requested'];
    public const LABELS = [
        'draft' => 'ร่าง', 'submitted' => 'รออนุมัติ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ',
        'withdrawn' => 'ถอนคำขอแล้ว', 'cancel_requested' => 'ขอยกเลิก (รออนุมัติ)', 'cancelled' => 'ยกเลิกแล้ว',
    ];
    /** action => [allowed current states, next state, actor rule, reason rule] */
    public const ACTIONS = [
        'submit' => [['draft'], 'submitted', 'owner', null],
        'withdraw' => [['submitted'], 'withdrawn', 'owner', null],
        'approve' => [['submitted'], 'approved', 'approver', null],
        'reject' => [['submitted'], 'rejected', 'approver', 'required'],
        'request_cancel' => [['approved'], 'cancel_requested', 'owner', 'required'],
        'approve_cancel' => [['cancel_requested'], 'cancelled', 'approver', null],
        'reject_cancel' => [['cancel_requested'], 'approved', 'approver', 'required'],
    ];
    private const MIME_EXT = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public static function maxKb(): int
    {
        return max(1, (int) config('saan.storage.max_upload_mb', 10)) * 1024;
    }

    public static function status(object|array $row): string
    {
        $s = (string) (is_array($row) ? $row['status'] : $row->status);
        return $s === 'pending' ? 'submitted' : $s;
    }

    // ------------------------------------------------------------------ leave types

    /** Seeds the Thai civil-service defaults the first time a school uses leave. They are ordinary rows: admins edit or add. */
    public function ensureTypes(): void
    {
        if ($this->q('ld_leave_types')->exists()) return;
        try {
            DB::transaction(function () {
                foreach (self::DEFAULT_TYPES as [$code, $label, $quota, $attach, $mode]) {
                    DB::table('ld_leave_types')->insert(['school_id' => $this->school(), 'code' => $code, 'label' => $label, 'label_key' => self::key($label),
                        'yearly_quota_days' => $quota, 'needs_attachment_after_days' => $attach, 'count_mode' => $mode, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                Mutation::audit('leave.types_seeded', 'ld_leave_types', null, ['count' => count(self::DEFAULT_TYPES)]);
            });
        } catch (QueryException $e) {
            if (!$this->q('ld_leave_types')->exists()) throw $e; // lost the race to a concurrent first use: rows exist now
        }
    }

    private function typeRow(object $r): array
    {
        return ['id' => (int) $r->id, 'code' => $r->code, 'label' => $r->label, 'version' => (int) $r->version, 'count_mode' => $r->count_mode ?? 'working',
            'yearly_quota_days' => $r->yearly_quota_days === null ? null : (float) $r->yearly_quota_days,
            'needs_attachment_after_days' => $r->needs_attachment_after_days === null ? null : (float) $r->needs_attachment_after_days];
    }

    public function types(): array
    {
        $this->ensureTypes();
        return $this->q('ld_leave_types')->orderBy('id')->get()->map(fn ($r) => $this->typeRow($r))->all();
    }

    public function typeSave(array $d, ?int $id = null): array
    {
        $this->ensureTypes();
        $label = trim((string) preg_replace('/\s+/u', ' ', (string) $d['label']));
        $key = self::key($label);
        $dup = $this->q('ld_leave_types')->where('label_key', $key)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) $this->invalid('label', 'มีประเภทการลาชื่อนี้อยู่แล้ว');
        $values = ['label' => $label, 'label_key' => $key, 'yearly_quota_days' => $d['yearly_quota_days'] ?? null, 'needs_attachment_after_days' => $d['needs_attachment_after_days'] ?? null];
        if (!empty($d['count_mode'])) $values['count_mode'] = $d['count_mode'];
        $this->modes = null;
        $row = $this->guard(function () use ($id, $d, $values) {
            if ($id === null) {
                if ($this->q('ld_leave_types')->where('code', $d['code'])->exists()) $this->invalid('code', 'รหัสประเภทการลานี้ถูกใช้แล้ว');
                return Mutation::insert('ld_leave_types', $values + ['code' => $d['code']], 'leave.type_create', ['code' => $d['code']]);
            }
            return Mutation::update('ld_leave_types', $id, (int) $d['version'], $values, 'leave.type_update', ['label' => $values['label']]);
        });
        return $this->typeRow($row);
    }

    public function typeDelete(int $id, int $version): void
    {
        $t = $this->q('ld_leave_types')->where('id', $id)->first() ?? abort(404);
        if ($this->q('leave_requests')->where('type', $t->code)->exists()) $this->invalid('type', 'มีใบลาใช้ประเภทนี้อยู่ ลบไม่ได้ (แก้ชื่อหรือโควตาแทน)');
        Mutation::delete('ld_leave_types', $id, $version, 'leave.type_delete');
    }

    // ------------------------------------------------------------------ calculation

    /** Holiday titles keyed by Y-m-d for from..to (empty when the academic module's calendar table does not exist). */
    public function holidaySet(string $from, string $to): array
    {
        $holidays = [];
        if (Schema::hasTable('acad_calendar_days')) {
            foreach ($this->q('acad_calendar_days')->where('kind', 'holiday')->whereBetween('date', [$from, $to.' 23:59:59'])->get(['date', 'title']) as $h) $holidays[substr((string) $h->date, 0, 10)] = $h->title;
        }
        return $holidays;
    }

    private ?array $modes = null;

    /** count_mode of a leave type code (working when unknown or when the column is not there yet). */
    private function modeOf(string $code): string
    {
        if ($this->modes === null) $this->ensureTypes();
        $this->modes ??= Schema::hasTable('ld_leave_types') && Schema::hasColumn('ld_leave_types', 'count_mode') ? $this->q('ld_leave_types')->pluck('count_mode', 'code')->all() : [];
        return $this->modes[$code] ?? 'working';
    }

    /** Dates that count (and the skipped ones) for a range. 'calendar' mode counts every day; 'working' skips Saturday/Sunday and holidays. No validation here. */
    private function countDays(string $from, string $to, string $mode): array
    {
        $a = self::parseDate($from);
        $b = self::parseDate($to);
        $holidays = $mode === 'calendar' ? [] : $this->holidaySet($from, $to);
        $dates = [];
        $skipped = [];
        for ($d = $a, $i = 0; $d <= $b && $i < 400; $d = $d->addDay(), $i++) {
            $s = $d->toDateString();
            if ($mode !== 'calendar' && $d->isWeekend()) $skipped[] = ['date' => $s, 'reason' => 'weekend', 'title' => null];
            elseif (isset($holidays[$s])) $skipped[] = ['date' => $s, 'reason' => 'holiday', 'title' => $holidays[$s]];
            else $dates[] = $s;
        }
        return [$dates, $skipped];
    }

    /** @return array{days: float, dates: string[], skipped: array<int, array{date: string, reason: string, title: ?string}>} */
    public function workingDays(string $from, string $to, ?string $half, string $mode = 'working'): array
    {
        $a = self::parseDate($from);
        $b = self::parseDate($to);
        if ($b < $a) $this->invalid('ends_on', 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มลา');
        if ($a->diffInDays($b) > 365) $this->invalid('ends_on', 'ช่วงวันลายาวเกิน 1 ปี');
        if ($half !== null && !in_array($half, ['am', 'pm'], true)) $this->invalid('half_day', 'ครึ่งวันต้องเป็นช่วงเช้าหรือช่วงบ่าย');
        if ($half !== null && $from !== $to) $this->invalid('half_day', 'ลาครึ่งวันเลือกได้เฉพาะวันเดียว');
        [$dates, $skipped] = $this->countDays($from, $to, $mode);
        if (!$dates) $this->invalid('starts_on', 'ช่วงวันที่เลือกไม่มีวันทำการ (เสาร์-อาทิตย์หรือวันหยุดของโรงเรียน)');
        return ['days' => $half !== null ? 0.5 : (float) count($dates), 'dates' => $dates, 'skipped' => $skipped];
    }

    /**
     * Leave rows written by the old prototype route or a seeder carry days=0 / fiscal_year=NULL. These are derived columns (no version bump):
     * fill them from the dates so the approval queue, quota and report see the row.
     */
    public function normalizeDerived(): void
    {
        // only rows never normalized (fiscal_year NULL): a legitimately zero-day range (weekend/holiday only) keeps days = 0 once it has a fiscal year, so it is not re-selected forever
        $rows = $this->q('leave_requests')->whereNull('fiscal_year')->orderBy('id')->limit(500)->get(['id', 'type', 'starts_on', 'ends_on', 'half_day', 'days', 'fiscal_year']);
        if ($rows->isEmpty()) return;
        foreach ($rows as $r) {
            $from = substr((string) $r->starts_on, 0, 10);
            $to = substr((string) $r->ends_on, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) continue;
            $n = count($this->countDays($from, $to, $this->modeOf((string) $r->type))[0]);
            $days = $r->half_day !== null && $n > 0 ? 0.5 : (float) $n;
            DB::table('leave_requests')->where('id', $r->id)->where('school_id', $this->school())->update(['days' => $days, 'fiscal_year' => self::fiscalYear($from)]);
        }
    }

    /** True when the leave row takes the teacher away at that date (and, when given, that time window). $holidays: optional preloaded holidaySet() to avoid a query per call. */
    public function covers(object $leave, string $date, ?string $start = null, ?string $end = null, ?array $holidays = null): bool
    {
        $from = substr((string) $leave->starts_on, 0, 10);
        $to = substr((string) $leave->ends_on, 0, 10);
        if ($date < $from || $date > $to) return false;
        if ($this->modeOf((string) $leave->type) !== 'calendar') {
            if (self::parseDate($date)->isWeekend()) return false;
            if ($holidays !== null ? isset($holidays[$date]) : (Schema::hasTable('acad_calendar_days') && $this->q('acad_calendar_days')->where('kind', 'holiday')->whereDate('date', $date)->exists())) return false;
        }
        $half = $leave->half_day ?? null;
        if ($half === 'am' && $start !== null) return self::mins($start) < 720;
        if ($half === 'pm' && $end !== null) return self::mins($end) > 720;
        return true;
    }
private function overlap(int $teacherId, string $from, string $to, ?string $half, ?int $exceptId, array $statuses = self::OPEN): void
    {
        $hits = $this->q('leave_requests')->where('teacher_id', $teacherId)->whereIn('status', $statuses)
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->get();
        foreach ($hits as $h) {
            $sameDay = $half !== null && $h->half_day !== null && $from === $to && substr((string) $h->starts_on, 0, 10) === $from && substr((string) $h->ends_on, 0, 10) === $from;
            if ($sameDay && $h->half_day !== $half) continue; // morning + afternoon of the same day do not collide
            $this->invalid('starts_on', 'ช่วงวันลาทับกับใบลาของคุณที่ยื่นหรืออนุมัติแล้ว (ใบลา #'.$h->id.')');
        }
    }

    /** Validates input and returns the columns to store. Throws 422 on any rule failure. */
    private function payload(array $d, int $teacherId, ?int $exceptId): array
    {
        $this->ensureTypes();
        $type = $this->q('ld_leave_types')->where('code', $d['type'])->first() ?? $this->invalid('type', 'ไม่พบประเภทการลานี้');
        $half = ($d['half_day'] ?? null) ?: null;
        $calc = $this->workingDays($d['starts_on'], $d['ends_on'], $half, $type->count_mode ?? 'working');
        $fy = self::fiscalYear($d['starts_on']);
        if (self::fiscalYear($d['ends_on']) !== $fy) $this->invalid('ends_on', 'ใบลาข้ามปีงบประมาณ (30 ก.ย. - 1 ต.ค.) กรุณาแยกยื่นเป็นสองใบ');
        $this->overlap($teacherId, $d['starts_on'], $d['ends_on'], $half, $exceptId);
        return ['type' => $type->code, 'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'], 'half_day' => $half, 'days' => $calc['days'],
            'fiscal_year' => $fy, 'reason' => trim((string) $d['reason'])];
    }

    /** used = approved (incl. cancel pending), pending = waiting for approval; per leave type. */
    public function usage(int $teacherId, int $fiscalYear, ?int $exceptId = null): array
    {
        $this->normalizeDerived();
        $out = [];
        $rows = $this->q('leave_requests')->where('teacher_id', $teacherId)->where('fiscal_year', $fiscalYear)->whereIn('status', self::OPEN)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->get(['type', 'status', 'days']);
        foreach ($rows as $r) {
            $k = in_array($r->status, self::HELD, true) ? 'used' : 'pending';
            $out[$r->type][$k] = ($out[$r->type][$k] ?? 0.0) + (float) $r->days;
        }
        return $out;
    }

    private function checkSubmit(array $p, int $teacherId, bool $hasAttachment, ?int $id): void
    {
        $type = $this->q('ld_leave_types')->where('code', $p['type'])->first() ?? $this->invalid('type', 'ไม่พบประเภทการลานี้');
        if ($type->yearly_quota_days !== null) {
            $u = $this->usage($teacherId, (int) $p['fiscal_year'], $id)[$p['type']] ?? [];
            $used = (float) ($u['used'] ?? 0);
            $pending = (float) ($u['pending'] ?? 0);
            if ($used + $pending + $p['days'] > (float) $type->yearly_quota_days) {
                $this->invalid('days', sprintf('เกินสิทธิ์%s ปีงบประมาณ %d: ใช้แล้ว %s + รออนุมัติ %s + ครั้งนี้ %s วัน เกินโควตา %s วัน', $type->label, $p['fiscal_year'], $used, $pending, $p['days'], (float) $type->yearly_quota_days));
            }
        }
        if ($type->needs_attachment_after_days !== null && $p['days'] > (float) $type->needs_attachment_after_days && !$hasAttachment) {
            $this->invalid('attachment', sprintf('ลา%sเกิน %s วันต้องแนบไฟล์ (เช่น ใบรับรองแพทย์) ก่อนส่ง', $type->label, (float) $type->needs_attachment_after_days));
        }
    }

    public function preview(array $d, ?object $user): array
    {
        $teacher = $this->teacherOf($user);
        $half = ($d['half_day'] ?? null) ?: null;
        $this->ensureTypes();
        $type = $this->q('ld_leave_types')->where('code', $d['type'])->first() ?? $this->invalid('type', 'ไม่พบประเภทการลานี้');
        $calc = $this->workingDays($d['starts_on'], $d['ends_on'], $half, $type->count_mode ?? 'working');
        $fy = self::fiscalYear($d['starts_on']);
        if (self::fiscalYear($d['ends_on']) !== $fy) $this->invalid('ends_on', 'ใบลาข้ามปีงบประมาณ (30 ก.ย. - 1 ต.ค.) กรุณาแยกยื่นเป็นสองใบ');
        if ($teacher) $this->overlap($teacher, $d['starts_on'], $d['ends_on'], $half, isset($d['except_id']) ? (int) $d['except_id'] : null);
        $u = $teacher ? ($this->usage($teacher, $fy)[$type->code] ?? []) : [];
        $quota = $type->yearly_quota_days === null ? null : (float) $type->yearly_quota_days;
        return ['days' => $calc['days'], 'skipped' => $calc['skipped'], 'fiscal_year' => $fy, 'count_mode' => $type->count_mode ?? 'working', 'needs_attachment' => $type->needs_attachment_after_days !== null && $calc['days'] > (float) $type->needs_attachment_after_days,
            'quota' => ['quota' => $quota, 'used' => (float) ($u['used'] ?? 0), 'pending' => (float) ($u['pending'] ?? 0),
                'remaining' => $quota === null ? null : $quota - (float) ($u['used'] ?? 0) - (float) ($u['pending'] ?? 0) - $calc['days']]];
    }

    // ------------------------------------------------------------------ reading

    private function row(int $id): object
    {
        return $this->q('leave_requests')->where('id', $id)->first() ?? abort(404);
    }

    private function owns(object $row, ?object $user): bool
    {
        $t = $this->teacherOf($user);
        return $t !== null && (int) $row->teacher_id === $t;
    }

    private function actorOk(string $who, object $row, ?object $user): bool
    {
        if ($who === 'owner') return $this->owns($row, $user);
        // an approver never decides on his or her own leave
        return self::isApprover($user) && $this->teacherOf($user) !== (int) $row->teacher_id;
    }

    private function canView(object $row, ?object $user): bool
    {
        return $this->owns($row, $user) || (self::isApprover($user) && self::status($row) !== 'draft');
    }

    /** Actions the viewer may take on the row right now (drives the UI buttons; the server re-checks on every call). */
    public function can(object $row, ?object $user, ?array $children = null): array
    {
        $s = self::status($row);
        $out = [];
        foreach (self::ACTIONS as $name => [$from, , $who]) {
            if (!in_array($s, $from, true) || !$this->actorOk($who, $row, $user)) continue;
            if ($name === 'request_cancel' && substr((string) $row->starts_on, 0, 10) <= self::today()) continue;
            $out[] = $name;
        }
        if ($this->owns($row, $user)) {
            if ($s === 'draft') array_push($out, 'edit', 'delete', 'attach');
            if ($s === 'rejected' && !($children !== null ? isset($children[$row->id]) : $this->q('leave_requests')->where('resubmitted_from', $row->id)->exists())) $out[] = 'resubmit'; // $children = map already loaded by shapeMany (no per-row query)
        }
        return $out;
    }

    private function shape(object $r, array $names, array $types, array $children, ?object $user): array
    {
        return [
            'id' => (int) $r->id, 'version' => (int) $r->version, 'teacher_id' => (int) $r->teacher_id, 'teacher_name' => $names[$r->teacher_id] ?? null,
            'type' => $r->type, 'type_label' => $types[$r->type] ?? $r->type,
            'starts_on' => substr((string) $r->starts_on, 0, 10), 'ends_on' => substr((string) $r->ends_on, 0, 10), 'half_day' => $r->half_day,
            'days' => (float) $r->days, 'fiscal_year' => $r->fiscal_year === null ? null : (int) $r->fiscal_year, 'reason' => $r->reason,
            'status' => self::status($r), 'status_label' => self::LABELS[self::status($r)] ?? $r->status,
            'has_attachment' => $r->attachment_path !== null, 'attachment_name' => $r->attachment_name, 'attachment_bytes' => (int) $r->attachment_bytes,
            'decision_note' => $r->decision_note, 'cancel_reason' => $r->cancel_reason, 'decided_by' => $r->decided_by, 'decided_at' => $r->decided_at,
            'submitted_at' => $r->submitted_at, 'withdrawn_at' => $r->withdrawn_at, 'cancel_requested_at' => $r->cancel_requested_at, 'cancelled_at' => $r->cancelled_at,
            'resubmitted_from' => $r->resubmitted_from === null ? null : (int) $r->resubmitted_from, 'resubmitted_to' => $children[$r->id] ?? null,
            'can' => $this->can($r, $user, $children),
        ];
    }

    private function shapeMany($rows, ?object $user): array
    {
        $ids = $rows->pluck('id')->all();
        $names = $this->teacherNames($rows->pluck('teacher_id')->all());
        $types = $this->q('ld_leave_types')->pluck('label', 'code')->all();
        $children = $ids ? $this->q('leave_requests')->whereIn('resubmitted_from', $ids)->pluck('id', 'resubmitted_from')->all() : [];
        return $rows->map(fn ($r) => $this->shape($r, $names, $types, $children, $user))->all();
    }

    public function list(?object $user, string $scope, array $f): array
    {
        $this->ensureTypes();
        $this->normalizeDerived();
        $q = $this->q('leave_requests');
        if ($scope === 'all') {
            if (!self::isApprover($user)) abort(403, 'บัญชีนี้ไม่มีสิทธิ์ดูใบลาของผู้อื่น');
            $q->where('status', '!=', 'draft'); // another teacher's unsent draft (e.g. a medical certificate) is not the approver's to see
            if (!empty($f['teacher_id'])) $q->where('teacher_id', (int) $f['teacher_id']);
        } else {
            $t = $this->teacherOf($user);
            $q->where('teacher_id', $t ?? 0);
        }
        if (!empty($f['status'])) {
            $q->whereIn('status', $f['status'] === 'submitted' ? self::PENDING : [$f['status']]);
        }
        if (!empty($f['fiscal_year'])) $q->where('fiscal_year', (int) $f['fiscal_year']);
        $total = (clone $q)->count();
        $per = min(100, max(1, (int) ($f['per_page'] ?? 50)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $rows = $q->orderByDesc('starts_on')->orderByDesc('id')->forPage($page, $per)->get();
        return ['data' => $this->shapeMany($rows, $user), 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    public function read(int $id, ?object $user): array
    {
        $this->normalizeDerived();
        $row = $this->row($id);
        if (!$this->canView($row, $user)) abort(403, 'ไม่มีสิทธิ์ดูใบลานี้');
        $out = $this->shapeMany(collect([$row]), $user)[0];
        $out['history'] = $this->history($id);
        if (self::isApprover($user)) {
            $out['duty_conflicts'] = $this->dutyConflicts($row);
            $out['quota'] = $this->quota($user, self::fiscalYear(substr((string) $row->starts_on, 0, 10)), (int) $row->teacher_id);
        }
        return $out;
    }

    private function history(int $id): array
    {
        return DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')
            ->where('audit_events.school_id', $this->school())->where('audit_events.entity', 'leave_requests')->where('audit_events.entity_id', $id)
            ->orderBy('audit_events.id')->get(['audit_events.action', 'audit_events.detail', 'audit_events.created_at', 'users.name'])
            ->map(function ($e) {
                $d = json_decode((string) $e->detail, true) ?: [];
                return ['action' => $e->action, 'at' => $e->created_at, 'actor' => $e->name, 'reason' => $d['reason'] ?? null, 'to' => $d['to'] ?? null];
            })->all();
    }

    /** Assigned duties of the teacher that fall inside this leave. The approver sees them before deciding. */
    public function dutyConflicts(object $leave): array
    {
        $from = substr((string) $leave->starts_on, 0, 10);
        $to = substr((string) $leave->ends_on, 0, 10);
        $holidays = $this->holidaySet($from, $to);
        return $this->q('duties')->where('teacher_id', $leave->teacher_id)->where('status', 'assigned')
            ->whereBetween('date', [self::parseDate($from)->subDay()->toDateString(), $to])->orderBy('date')->orderBy('starts_at')->get()
            ->filter(function ($d) use ($leave, $holidays, $from) {
                $date = substr((string) $d->date, 0, 10);
                $wrap = Duty::wraps($d->kind ?? null, $d->starts_at, $d->ends_at);
                $next = self::parseDate($date)->addDay()->toDateString();
                if ($wrap) { // overnight duty: [start,24:00) on date and [00:00,end) on date+1
                    return $this->covers($leave, $date, $d->starts_at, '24:00', $holidays) || $this->covers($leave, $next, '00:00', $d->ends_at, $holidays);
                }
                return $date >= $from && $this->covers($leave, $date, $d->starts_at, $d->ends_at, $holidays);
            })
            ->map(fn ($d) => ['id' => (int) $d->id, 'date' => substr((string) $d->date, 0, 10), 'starts_at' => $d->starts_at, 'ends_at' => $d->ends_at, 'location' => $d->location])->values()->all();
    }

    // ------------------------------------------------------------------ quota

    public function fiscalYears(): array
    {
        $now = self::fiscalYear(self::today());
        $years = $this->q('leave_requests')->whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year')->map(fn ($v) => (int) $v)->all();
        $years[] = $now;
        rsort($years);
        return array_values(array_unique($years));
    }

    public function quota(?object $user, int $fy, ?int $teacherId = null): array
    {
        $mine = $this->teacherOf($user);
        if ($teacherId !== null && $teacherId !== $mine && !self::isApprover($user)) abort(403, 'ไม่มีสิทธิ์ดูโควตาของผู้อื่น');
        $teacherId ??= $mine ?? $this->invalid('teacher', 'บัญชีนี้ยังไม่ได้ผูกกับครู');
        $usage = $this->usage($teacherId, $fy);
        $types = array_map(function ($t) use ($usage) {
            $u = $usage[$t['code']] ?? [];
            $used = (float) ($u['used'] ?? 0);
            $pending = (float) ($u['pending'] ?? 0);
            return ['code' => $t['code'], 'label' => $t['label'], 'quota' => $t['yearly_quota_days'], 'used' => $used, 'pending' => $pending,
                'remaining' => $t['yearly_quota_days'] === null ? null : max(0.0, $t['yearly_quota_days'] - $used - $pending)];
        }, $this->types());
        return ['fiscal_year' => $fy, 'teacher_id' => $teacherId, 'types' => $types];
    }

    /** Per teacher per leave type usage for one fiscal year (approvers). */
    public function report(int $fy): array
    {
        $this->normalizeDerived();
        $types = $this->types();
        $by = [];
        foreach ($this->q('leave_requests')->where('fiscal_year', $fy)->whereIn('status', self::OPEN)->get(['teacher_id', 'type', 'status', 'days']) as $r) {
            $k = in_array($r->status, self::HELD, true) ? 'used' : 'pending';
            $by[$r->teacher_id][$r->type][$k] = ($by[$r->teacher_id][$r->type][$k] ?? 0.0) + (float) $r->days;
        }
        $teachers = $this->q('teachers')->when(Schema::hasColumn('teachers', 'is_active'), fn ($q) => $q->where(fn ($w) => $w->where('is_active', true)->orWhereIn('id', array_keys($by))))->orderBy('name')->get(['id', 'name']);
        $rows = $teachers->map(function ($t) use ($by, $types) {
            $cells = [];
            $total = 0.0;
            foreach ($types as $ty) {
                $u = $by[$t->id][$ty['code']] ?? [];
                $cells[$ty['code']] = ['used' => (float) ($u['used'] ?? 0), 'pending' => (float) ($u['pending'] ?? 0),
                    'over' => $ty['yearly_quota_days'] !== null && ((float) ($u['used'] ?? 0)) > $ty['yearly_quota_days']];
                $total += (float) ($u['used'] ?? 0);
            }
            return ['teacher_id' => (int) $t->id, 'name' => $t->name, 'by_type' => $cells, 'used_total' => $total];
        })->all();
        return ['fiscal_year' => $fy, 'types' => $types, 'rows' => $rows];
    }

    // ------------------------------------------------------------------ writes (draft)

    private function requireTeacher(?object $user): int
    {
        $t = $this->teacherOf($user) ?? $this->invalid('teacher', 'บัญชีนี้ยังไม่ได้ผูกกับครู กรุณาติดต่อผู้ดูแลระบบ');
        if (!$this->q('teachers')->where('id', $t)->exists()) $this->invalid('teacher', 'ไม่พบข้อมูลครูของบัญชีนี้');
        return $t;
    }

    public function create(array $d, ?object $user): array
    {
        $t = $this->requireTeacher($user);
        $p = $this->payload($d, $t, null);
        $row = Mutation::insert('leave_requests', $p + ['teacher_id' => $t, 'status' => 'draft'], 'leave.create', ['type' => $p['type'], 'days' => $p['days']]);
        return $this->shapeMany(collect([$row]), $user)[0];
    }

    public function update(int $id, array $d, ?object $user): array
    {
        $row = $this->row($id);
        if (!$this->owns($row, $user)) abort(403, 'แก้ได้เฉพาะใบลาของตนเอง');
        $this->assertVersion($row, (int) $d['version']);
        if (self::status($row) !== 'draft') abort(409, 'แก้ไขได้เฉพาะใบลาที่ยังเป็นร่าง');
        $p = $this->payload($d, (int) $row->teacher_id, $id);
        $new = $this->safe(fn () => Mutation::update('leave_requests', $id, (int) $d['version'], $p, 'leave.update', ['type' => $p['type'], 'days' => $p['days']]));
        return $this->shapeMany(collect([$new]), $user)[0];
    }

    public function destroy(int $id, int $version, ?object $user): void
    {
        $row = $this->row($id);
        if (!$this->owns($row, $user)) abort(403, 'ลบได้เฉพาะใบลาของตนเอง');
        $this->assertVersion($row, $version);
        if (self::status($row) !== 'draft') abort(409, 'ลบได้เฉพาะใบลาที่ยังเป็นร่าง (ใบที่ส่งแล้วให้ถอนคำขอแทน)');
        $this->safe(fn () => Mutation::delete('leave_requests', $id, $version, 'leave.delete'));
        if ($row->attachment_path) Storage::disk('local')->delete($row->attachment_path);
    }

    /** The 409 body carries the current row; the private-disk path of the attachment is never part of it. */
    private function publicRow(array $row): array
    {
        return Arr::except($row, ['attachment_path']);
    }

    private function assertVersion(object $row, int $version): void
    {
        if ((int) $row->version !== $version) throw new VersionConflict($this->publicRow((array) $row));
    }

    /** Runs a Mutation call on leave_requests and strips attachment_path from a VersionConflict it raises. */
    private function safe(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (VersionConflict $e) {
            throw new VersionConflict($e->current === null ? null : $this->publicRow($e->current));
        }
    }

    // ------------------------------------------------------------------ the state machine

    public function transition(int $id, string $action, int $version, ?string $reason, ?object $user): array
    {
        $rule = self::ACTIONS[$action] ?? abort(422, 'ไม่รู้จักการดำเนินการนี้');
        [$from, $to, $who, $reasonRule] = $rule;
        $reason = $reason === null ? null : trim($reason);
        $this->normalizeDerived(); // outside the transaction: it is a bulk backfill, not part of the state change
        $result = DB::transaction(function () use ($id, $action, $version, $reason, $user, $from, $to, $who, $reasonRule) {
            $row = $this->q('leave_requests')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if (!$this->canView($row, $user)) abort(403, 'ไม่มีสิทธิ์ใช้ใบลานี้');
            if (!$this->actorOk($who, $row, $user)) {
                abort(403, $who === 'owner' ? 'ทำได้เฉพาะเจ้าของใบลา' : ($this->owns($row, $user) ? 'ห้ามอนุมัติหรือไม่อนุมัติใบลาของตนเอง' : 'เฉพาะผู้อำนวยการหรือผู้ดูแลระบบเท่านั้น'));
            }
            // serialise this teacher's concurrent submits / approvals: overlap and quota are checked against committed rows
            if (in_array($action, ['submit', 'approve'], true)) $this->lockTeacher((int) $row->teacher_id);
            $this->assertVersion($row, $version);
            $current = self::status($row);
            if (!in_array($current, $from, true)) abort(409, 'สถานะปัจจุบัน ('.(self::LABELS[$current] ?? $current).') ไม่รองรับการดำเนินการนี้');
            if ($reasonRule === 'required' && ($reason === null || mb_strlen($reason) < 3)) $this->invalid('reason', 'กรุณาระบุเหตุผล (อย่างน้อย 3 ตัวอักษร)');
            if ($reason !== null && mb_strlen($reason) > 500) $this->invalid('reason', 'เหตุผลยาวเกิน 500 ตัวอักษร');

            $now = now();
            $changes = ['status' => $to];
            switch ($action) {
                case 'submit':
                    $p = $this->payload(['type' => $row->type, 'starts_on' => substr((string) $row->starts_on, 0, 10), 'ends_on' => substr((string) $row->ends_on, 0, 10),
                        'half_day' => $row->half_day, 'reason' => $row->reason], (int) $row->teacher_id, $id);
                    $this->checkSubmit($p, (int) $row->teacher_id, $row->attachment_path !== null, $id);
                    $changes += $p + ['submitted_at' => $now];
                    break;
                case 'withdraw':
                    $changes += ['withdrawn_at' => $now];
                    break;
                case 'approve':
                    // re-check at decision time: another leave may have been approved since this one was submitted
                    $this->overlap((int) $row->teacher_id, substr((string) $row->starts_on, 0, 10), substr((string) $row->ends_on, 0, 10), $row->half_day, $id, self::HELD);
                    $changes += ['decided_by' => $user->id, 'decided_at' => $now, 'decision_note' => $reason];
                    break;
                case 'reject':
                    $changes += ['decided_by' => $user->id, 'decided_at' => $now, 'decision_note' => $reason];
                    break;
                case 'request_cancel':
                    // cancelling a leave that already started would hand back quota for days already taken
                    if (substr((string) $row->starts_on, 0, 10) <= self::today()) abort(409, 'ใบลาที่เริ่มลาแล้วหรือสิ้นสุดแล้วยกเลิกทั้งใบไม่ได้ (ให้ผู้อนุมัติแก้ไขวันที่เหลือแทน)');
                    $changes += ['cancel_requested_at' => $now, 'cancel_reason' => $reason];
                    break;
                case 'approve_cancel':
                    $changes += ['cancelled_at' => $now] + ($reason !== null ? ['decision_note' => $reason] : []);
                    break;
                case 'reject_cancel':
                    $changes += ['cancel_requested_at' => null, 'decision_note' => $reason];
                    break;
            }
            $new = $this->safe(fn () => Mutation::update('leave_requests', $id, $version, $changes, 'leave.'.$action, ['reason' => $reason, 'from' => $current, 'to' => $to]));
            return [$row, $new];
        });
        [$row, $new] = $result;
        $this->notifyTransition($action, $row, $new, $reason, $user);
        return $this->shapeMany(collect([$new]), $user)[0];
    }

    private function thai(string $ymd): string
    {
        $d = self::parseDate(substr($ymd, 0, 10));
        return $d->day.'/'.$d->month.'/'.($d->year + 543);
    }

    private function notifyTransition(string $action, object $row, object $new, ?string $reason, ?object $user): void
    {
        $name = $this->teacherNames([$row->teacher_id])[$row->teacher_id] ?? 'ครู';
        $type = $this->q('ld_leave_types')->where('code', $new->type)->value('label') ?? $new->type;
        $when = $this->thai((string) $new->starts_on).($new->ends_on !== $new->starts_on ? ' - '.$this->thai((string) $new->ends_on) : '')
            .($new->half_day ? ($new->half_day === 'am' ? ' (ครึ่งวันเช้า)' : ' (ครึ่งวันบ่าย)') : '').' รวม '.(float) $new->days.' วันทำการ';
        // the reason for leave (may be health data) and the teacher's own cancel reason never go by e-mail: the mail says that a request exists and links to the screen
        $detail = ["{$type} {$when}", 'ดูเหตุผลและรายละเอียดได้ในระบบ'];
        $note = $reason !== null && $reason !== '' ? ['หมายเหตุ: '.$reason] : [];
        $approvers = Notify::approvers((int) $row->teacher_id);
        $owner = Notify::teacherUsers((int) $row->teacher_id);
        match ($action) {
            'submit' => Notify::send($approvers, 'leave.submit', "ใบลาใหม่รออนุมัติ: {$name}", ["{$name} ยื่นใบลา", ...$detail], 'leave-approve'),
            'withdraw' => Notify::send($approvers, 'leave.withdraw', "ถอนใบลา: {$name}", ["{$name} ถอนคำขอลา", ...$detail], 'leave-approve'),
            'request_cancel' => Notify::send($approvers, 'leave.request_cancel', "ขอยกเลิกใบลา: {$name}", ["{$name} ขอยกเลิกใบลาที่อนุมัติแล้ว", ...$detail], 'leave-approve'),
            'approve' => Notify::send($owner, 'leave.approve', 'ใบลาของคุณได้รับการอนุมัติ', ['ใบลาของคุณได้รับการอนุมัติแล้ว', ...$detail, ...$note]),
            'reject' => Notify::send($owner, 'leave.reject', 'ใบลาของคุณไม่ได้รับการอนุมัติ', ['ใบลาของคุณไม่ได้รับการอนุมัติ', ...$detail, ...$note]),
            'approve_cancel' => Notify::send($owner, 'leave.approve_cancel', 'ยกเลิกใบลาเรียบร้อย', ['คำขอยกเลิกใบลาของคุณได้รับการอนุมัติ ใบลาถูกยกเลิกแล้ว', ...$detail, ...$note]),
            'reject_cancel' => Notify::send($owner, 'leave.reject_cancel', 'คำขอยกเลิกใบลาไม่ได้รับการอนุมัติ', ['คำขอยกเลิกใบลาของคุณไม่ได้รับการอนุมัติ ใบลายังมีผลตามเดิม', ...$detail, ...$note]),
        };
    }

    /**
     * Rejected request: edit and send again. Creates a NEW DRAFT linked by resubmitted_from (the old attachment is copied);
     * the owner may replace the file via /attachment and then submits through the normal state machine (checks, audit, mail).
     */
    public function resubmit(int $id, array $d, ?object $user): array
    {
        $old = $this->row($id);
        if (!$this->owns($old, $user)) abort(403, 'ยื่นซ้ำได้เฉพาะใบลาของตนเอง');
        $this->assertVersion($old, (int) $d['version']);
        if (self::status($old) !== 'rejected') abort(409, 'ยื่นซ้ำได้เฉพาะใบลาที่ไม่ได้รับอนุมัติ');
        $copied = null;
        try {
            $new = DB::transaction(function () use ($id, $d, $old, &$copied) {
                $this->lockTeacher((int) $old->teacher_id);
                if ($this->q('leave_requests')->where('resubmitted_from', $id)->exists()) abort(409, 'ใบลานี้ถูกยื่นซ้ำไปแล้ว');
                $p = $this->payload($d, (int) $old->teacher_id, null);
                $attachment = [];
                if ($old->attachment_path && Storage::disk('local')->exists($old->attachment_path)) {
                    StorageQuota::assertRoom((int) $old->attachment_bytes); // leave_requests.attachment_bytes is a declared storage table
                    $copied = 'leave-duty/'.$this->school().'/r'.$id.'/'.Str::random(40).'.'.pathinfo($old->attachment_path, PATHINFO_EXTENSION);
                    Storage::disk('local')->copy($old->attachment_path, $copied);
                    $attachment = ['attachment_path' => $copied, 'attachment_name' => $old->attachment_name, 'attachment_bytes' => $old->attachment_bytes];
                }
                // bump the old row's version first: two concurrent resubmits of the same request cannot both pass this point
                $this->safe(fn () => Mutation::update('leave_requests', $id, (int) $d['version'], ['decision_note' => $old->decision_note], 'leave.resubmitted', ['to_status' => 'draft']));
                return Mutation::insert('leave_requests', $p + $attachment + ['teacher_id' => $old->teacher_id, 'status' => 'draft', 'resubmitted_from' => $id],
                    'leave.resubmit', ['from_id' => $id, 'days' => $p['days']]);
            });
        } catch (\Throwable $e) {
            if ($copied) Storage::disk('local')->delete($copied);
            throw $e;
        }
        return $this->shapeMany(collect([$new]), $user)[0];
    }

    // ------------------------------------------------------------------ attachment (private disk)

    public function attach(int $id, UploadedFile $file, int $version, ?object $user): array
    {
        $row = $this->row($id);
        if (!$this->owns($row, $user)) abort(403, 'แนบไฟล์ได้เฉพาะใบลาของตนเอง');
        $this->assertVersion($row, $version);
        if (self::status($row) !== 'draft') abort(409, 'แนบหรือเปลี่ยนไฟล์ได้เฉพาะใบลาที่ยังเป็นร่าง');
        $ext = self::MIME_EXT[(string) $file->getMimeType()] ?? $this->invalid('file', 'รองรับเฉพาะไฟล์ PDF, JPG และ PNG');
        $size = (int) $file->getSize();
        if ($size > self::maxKb() * 1024) $this->invalid('file', 'ไฟล์ใหญ่เกินกำหนด (สูงสุด '.(self::maxKb() / 1024).' MB)');
        // school total counts leave attachments already; a replacement frees the old file, so only the growth must fit
        StorageQuota::assertRoom(max(0, $size - (int) $row->attachment_bytes));
        $path = Storage::disk('local')->putFileAs('leave-duty/'.$this->school().'/'.$id, $file, Str::random(40).'.'.$ext);
        if ($path === false) abort(500, 'บันทึกไฟล์ไม่สำเร็จ');
        try {
            $new = $this->safe(fn () => Mutation::update('leave_requests', $id, $version, ['attachment_path' => $path, 'attachment_name' => mb_substr(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 0, 160), 'attachment_bytes' => $size],
                'leave.attach', ['bytes' => $size]));
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($row->attachment_path) Storage::disk('local')->delete($row->attachment_path);
        return $this->shapeMany(collect([$new]), $user)[0];
    }

    public function detach(int $id, int $version, ?object $user): array
    {
        $row = $this->row($id);
        if (!$this->owns($row, $user)) abort(403, 'ลบไฟล์ได้เฉพาะใบลาของตนเอง');
        $this->assertVersion($row, $version);
        if (self::status($row) !== 'draft') abort(409, 'ลบไฟล์ได้เฉพาะใบลาที่ยังเป็นร่าง');
        $new = $this->safe(fn () => Mutation::update('leave_requests', $id, $version, ['attachment_path' => null, 'attachment_name' => null, 'attachment_bytes' => 0], 'leave.detach'));
        if ($row->attachment_path) Storage::disk('local')->delete($row->attachment_path);
        return $this->shapeMany(collect([$new]), $user)[0];
    }

    /** Only the requester and approvers. Everyone else gets 403; files are never reachable by URL because the disk is private. */
    public function download(int $id, ?object $user)
    {
        $row = $this->row($id);
        if (!$this->canView($row, $user)) abort(403, 'ไม่มีสิทธิ์ดาวน์โหลดไฟล์นี้');
        if (!$row->attachment_path || !Storage::disk('local')->exists($row->attachment_path)) abort(404, 'ไม่พบไฟล์แนบ');
        Mutation::audit('leave.download', 'leave_requests', $id);
        return Storage::disk('local')->download($row->attachment_path, 'leave-'.$id.'.'.pathinfo($row->attachment_path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
