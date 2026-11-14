<?php

namespace Saan\Modules\LeaveDuty;

use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M24 duty roster and swaps (GAP-OPS-005). Duty status: assigned | cancelled. Teacher identity is users.teacher_id.
 * Swap: requested (teacher A) -> accepted (teacher B) -> approved (principal/admin, duty is reassigned to B) ; declined / rejected / cancelled close it.
 * One open swap per duty is enforced by the unique open_duty_id column (409 on a race).
 * replaced_by_teacher_id on a cancelled duty names the cover teacher the approver chose; a swap reassigns the duty row in place.
 */
class Duty
{
    use Common;

    public const KINDS = ['morning', 'lunch', 'evening', 'overnight', 'event', 'other'];
    public const SWAP_OPEN = ['requested', 'accepted'];
    public const SWAP_LABELS = ['requested' => 'รอเพื่อนครูตอบรับ', 'accepted' => 'รอผู้อนุมัติ', 'declined' => 'เพื่อนครูปฏิเสธ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ', 'cancelled' => 'ยกเลิก'];
    /** action => [allowed states, next state, actor rule, reason rule] */
    public const SWAP_ACTIONS = [
        'accept' => [['requested'], 'accepted', 'to', null],
        'decline' => [['requested'], 'declined', 'to', null],
        'cancel' => [['requested', 'accepted'], 'cancelled', 'from', null],
        'approve' => [['accepted'], 'approved', 'approver', null],
        'reject' => [['accepted'], 'rejected', 'approver', 'required'],
    ];

    public function __construct(private Leave $leave)
    {
    }

    // ------------------------------------------------------------------ roster

    public function teachers(): array
    {
        return $this->q('teachers')->when(Schema::hasColumn('teachers', 'is_active'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all();
    }

    private function teacherOk(int $id): void
    {
        $ok = $this->q('teachers')->where('id', $id)->when(Schema::hasColumn('teachers', 'is_active'), fn ($q) => $q->where('is_active', true))->exists();
        if (!$ok) $this->invalid('teacher_id', 'ไม่พบครูคนนี้ หรือถูกปิดใช้งานแล้ว');
    }

    /**     /** True when the duty runs past midnight (kind overnight with an end time at or before the start time). */
    public static function wraps(?string $kind, string $start, string $end): bool
    {
        return $kind === 'overnight' && self::mins($end) <= self::mins($start);
    }

    /** Minute interval [from, to) of a duty relative to 00:00 of $anchor (a Y-m-d); a wrapping duty ends on the next day. */
    private static function span(string $date, string $start, string $end, ?string $kind, string $anchor): array
    {
        $off = (int) self::parseDate($anchor)->diffInDays(self::parseDate($date), false) * 1440;
        return [$off + self::mins($start), $off + self::mins($end) + (self::wraps($kind, $start, $end) ? 1440 : 0)];
    }

    /** True when an approved leave takes the teacher away during the duty; a wrapping duty is checked as [start,24:00) on date and [00:00,end) on date+1. */
    private function leaveClash(object $l, string $date, string $start, string $end, ?string $kind, ?array $holidays = null): bool
    {
        if (self::wraps($kind, $start, $end)) {
            $next = self::parseDate($date)->addDay()->toDateString();
            return $this->leave->covers($l, $date, $start, '24:00', $holidays) || $this->leave->covers($l, $next, '00:00', $end, $holidays);
        }
        return $this->leave->covers($l, $date, $start, $end, $holidays);
    }

    /** Reason a teacher cannot take the slot, or null. Used by roster writes and swaps. */
    public function unavailable(int $teacherId, string $date, string $start, string $end, ?int $exceptDutyId = null, ?string $kind = null): ?string
    {
        $last = self::wraps($kind, $start, $end) ? self::parseDate($date)->addDay()->toDateString() : $date;
        $leaves = $this->q('leave_requests')->where('teacher_id', $teacherId)->whereIn('status', Leave::HELD)->where('starts_on', '<=', $last)->where('ends_on', '>=', $date)->get();
        foreach ($leaves as $l) if ($this->leaveClash($l, $date, $start, $end, $kind)) return 'ครูมีใบลาที่อนุมัติแล้วในวันนั้น';
        $prev = self::parseDate($date)->subDay()->toDateString();
        $others = $this->q('duties')->where('teacher_id', $teacherId)->whereBetween('date', [$prev, self::parseDate($date)->addDay()->toDateString()])->where('status', 'assigned')
            ->when($exceptDutyId, fn ($q) => $q->where('id', '!=', $exceptDutyId))->get();
        [$a, $b] = self::span($date, $start, $end, $kind, $date);
        foreach ($others as $o) {
            [$c, $e] = self::span(substr((string) $o->date, 0, 10), $o->starts_at, $o->ends_at, $o->kind ?? null, $date);
            if ($a < $e && $b > $c) return 'ครูมีเวรทับช่วงเวลานี้';
        }
        return null;
    }

private function row(int $id): object
    {
        return $this->q('duties')->where('id', $id)->first() ?? abort(404);
    }

    private function swapRow(int $id): object
    {
        return $this->q('ld_duty_swaps')->where('id', $id)->first() ?? abort(404);
    }

    private function dutyCan(object $d, ?object $user, bool $openSwap): array
    {
        $out = [];
        if ($d->status !== 'assigned') return $out;
        if (Leave::isApprover($user)) array_push($out, 'edit', 'cancel', 'delete');
        $mine = $this->teacherOf($user);
        if ($mine !== null && (int) $d->teacher_id === $mine && substr((string) $d->date, 0, 10) >= self::today() && !$openSwap) $out[] = 'swap';
        return $out;
    }

    public function list(?object $user, array $f): array
    {
        $from = $f['from'] ?? self::today();
        $to = $f['to'] ?? self::parseDate($from)->addDays(13)->toDateString();
        if ($to < $from) $this->invalid('to', 'วันสิ้นสุดต้องไม่ก่อนวันเริ่ม');
        if (self::parseDate($from)->diffInDays(self::parseDate($to)) > 120) $this->invalid('to', 'ดูตารางเวรได้ครั้งละไม่เกิน 120 วัน');
        $rows = $this->q('duties')->whereBetween('date', [$from, $to])->when(!empty($f['teacher_id']), fn ($q) => $q->where('teacher_id', (int) $f['teacher_id']))
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')->limit(1000)->get();
        $names = $this->teacherNames($rows->pluck('teacher_id')->merge($rows->pluck('replaced_by_teacher_id'))->all());
        $leaves = $rows->isEmpty() ? collect() : $this->q('leave_requests')->whereIn('teacher_id', $rows->pluck('teacher_id')->unique())->whereIn('status', Leave::HELD)
            ->where('starts_on', '<=', self::parseDate($to)->addDay()->toDateString())->where('ends_on', '>=', $from)->get()->groupBy('teacher_id');
        $swaps = $rows->isEmpty() ? collect() : $this->q('ld_duty_swaps')->whereIn('duty_id', $rows->pluck('id'))->whereIn('status', self::SWAP_OPEN)->get()->keyBy('duty_id');
        $mine = $this->teacherOf($user);
        $approver = Leave::isApprover($user);
        $holidays = $this->leave->holidaySet($from, self::parseDate($to)->addDay()->toDateString()); // one query for the whole range, not one per duty
        $data = $rows->map(function ($d) use ($names, $leaves, $swaps, $user, $mine, $approver, $holidays) {
            $date = substr((string) $d->date, 0, 10);
            $conflict = null;
            if ($d->status === 'assigned') {
                foreach ($leaves->get($d->teacher_id, collect()) as $l) {
                    if ($this->leaveClash($l, $date, $d->starts_at, $d->ends_at, $d->kind ?? null, $holidays)) {
                        // colleagues only learn that the teacher is away; the leave number and its state are for the owner and approvers
                        $conflict = $approver || ($mine !== null && (int) $d->teacher_id === $mine)
                            ? ['on_leave' => true, 'leave_id' => (int) $l->id, 'status' => $l->status, 'half_day' => $l->half_day] : ['on_leave' => true];
                        break;
                    }
                }
            }
            $swap = $swaps->get($d->id);
            return ['id' => (int) $d->id, 'version' => (int) $d->version, 'teacher_id' => (int) $d->teacher_id, 'teacher_name' => $names[$d->teacher_id] ?? null,
                'date' => $date, 'starts_at' => $d->starts_at, 'ends_at' => $d->ends_at, 'location' => $d->location, 'kind' => $d->kind ?? 'other',
                'status' => $d->status, 'cancelled_reason' => $d->cancelled_reason, 'replaced_by_teacher_id' => $d->replaced_by_teacher_id === null ? null : (int) $d->replaced_by_teacher_id,
                'replaced_by_name' => $names[$d->replaced_by_teacher_id] ?? null,
                'leave_conflict' => $conflict, 'mine' => $mine !== null && (int) $d->teacher_id === $mine,
                'open_swap' => $swap ? ['id' => (int) $swap->id, 'status' => $swap->status, 'to_teacher_id' => (int) $swap->to_teacher_id] : null,
                'can' => $this->dutyCan($d, $user, $swap !== null)];
        })->all();
        return ['data' => $data, 'from' => $from, 'to' => $to, 'kinds' => self::KINDS];
    }

    private function dutyValues(array $d): array
    {
        if (self::mins($d['ends_at']) === self::mins($d['starts_at']) || (self::mins($d['ends_at']) < self::mins($d['starts_at']) && ($d['kind'] ?? 'other') !== 'overnight')) $this->invalid('ends_at', 'เวลาสิ้นสุดต้องหลังเวลาเริ่ม (ข้ามเที่ยงคืนได้เฉพาะเวรกลางคืน)');
        $this->teacherOk((int) $d['teacher_id']);
        return ['teacher_id' => (int) $d['teacher_id'], 'date' => $d['date'], 'starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'],
            'location' => trim((string) $d['location']), 'kind' => $d['kind'] ?? 'other'];
    }

    public function create(array $d): array
    {
        $v = $this->dutyValues($d);
        $row = DB::transaction(function () use ($v) {
            $this->lockTeacher($v['teacher_id']);
            if ($why = $this->unavailable($v['teacher_id'], $v['date'], $v['starts_at'], $v['ends_at'], null, $v['kind'])) $this->invalid('teacher_id', $why);
            return Mutation::insert('duties', $v + ['status' => 'assigned'], 'duty.create', ['teacher_id' => $v['teacher_id'], 'date' => $v['date']]);
        });
        Notify::send(Notify::teacherUsers($v['teacher_id']), 'duty.assigned', 'คุณได้รับมอบหมายเวร', ['คุณได้รับมอบหมายเวร '.$this->describe($row)], 'duty');
        return ['id' => (int) $row->id, 'version' => (int) $row->version];
    }

    public function update(int $id, array $d): array
    {
        $row = $this->row($id);
        $this->assertVersion($row, (int) $d['version']);
        if ($row->status !== 'assigned') abort(409, 'แก้ไขได้เฉพาะเวรที่ยังมอบหมายอยู่');
        $v = $this->dutyValues($d);
        $changed = (int) $row->teacher_id !== $v['teacher_id'] || substr((string) $row->date, 0, 10) !== $v['date'] || $row->starts_at !== $v['starts_at'] || $row->ends_at !== $v['ends_at'];
        if ($changed && $this->q('ld_duty_swaps')->where('duty_id', $id)->whereIn('status', self::SWAP_OPEN)->exists()) abort(409, 'เวรนี้มีคำขอแลกเวรค้างอยู่ ให้ยกเลิกคำขอก่อนแก้วัน เวลา หรือครู');
        $new = DB::transaction(function () use ($id, $d, $v, $changed) {
            if ($changed) {
                $this->lockTeacher($v['teacher_id']);
                if ($why = $this->unavailable($v['teacher_id'], $v['date'], $v['starts_at'], $v['ends_at'], $id, $v['kind'])) $this->invalid('teacher_id', $why);
            }
            return Mutation::update('duties', $id, (int) $d['version'], $v, 'duty.update', ['teacher_id' => $v['teacher_id'], 'date' => $v['date']]);
        });
        if ($changed) Notify::send(Notify::teacherUsers($v['teacher_id'], (int) $row->teacher_id), 'duty.updated', 'เวรมีการเปลี่ยนแปลง', ['เวรของคุณถูกแก้ไข ตอนนี้คือ '.$this->describe($new)], 'duty');
        return ['id' => (int) $new->id, 'version' => (int) $new->version];
    }

    public function destroy(int $id, int $version): void
    {
        $row = $this->row($id);
        $this->assertVersion($row, $version);
        if ($this->q('ld_duty_swaps')->where('duty_id', $id)->exists()) abort(409, 'เวรนี้มีประวัติแลกเวร ลบไม่ได้ ให้ใช้การยกเลิกเวรแทน');
        Mutation::delete('duties', $id, $version, 'duty.delete');
    }

    public function cancel(int $id, int $version, ?string $reason, ?int $replacedBy): array
    {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) $this->invalid('reason', 'กรุณาระบุเหตุผลที่ยกเลิกเวร (อย่างน้อย 3 ตัวอักษร)');
        $closed = [];
        $new = DB::transaction(function () use ($id, $version, $reason, $replacedBy, &$closed, &$row) {
            $row = $this->q('duties')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $this->assertVersion($row, $version);
            if ($row->status !== 'assigned') abort(409, 'เวรนี้ไม่ได้อยู่ในสถานะมอบหมาย');
            if ($replacedBy !== null) {
                $this->teacherOk($replacedBy);
                if ($replacedBy === (int) $row->teacher_id) $this->invalid('replaced_by_teacher_id', 'ครูแทนต้องไม่ใช่ครูเจ้าของเวรเดิม');
                $this->lockTeacher($replacedBy);
                if ($why = $this->unavailable($replacedBy, substr((string) $row->date, 0, 10), $row->starts_at, $row->ends_at, $id, $row->kind ?? null)) $this->invalid('replaced_by_teacher_id', 'ครูแทนรับเวรนี้ไม่ได้: '.$why);
            }
            foreach ($this->q('ld_duty_swaps')->where('duty_id', $id)->whereIn('status', self::SWAP_OPEN)->get() as $s) {
                Mutation::update('ld_duty_swaps', (int) $s->id, (int) $s->version, ['status' => 'cancelled', 'open_duty_id' => null, 'decision_note' => 'เวรถูกยกเลิก'], 'duty.swap_auto_cancel', ['duty_id' => $id]);
                $closed[] = $s;
            }
            $cancelled = Mutation::update('duties', $id, $version, ['status' => 'cancelled', 'cancelled_reason' => $reason, 'replaced_by_teacher_id' => $replacedBy], 'duty.cancel', ['reason' => $reason, 'replaced_by' => $replacedBy]);
            // the cover teacher gets a real roster row so clash checks and the leave flag apply to the new duty too
            if ($replacedBy !== null) {
                Mutation::insert('duties', ['teacher_id' => $replacedBy, 'date' => substr((string) $row->date, 0, 10), 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at,
                    'location' => $row->location, 'kind' => $row->kind ?? 'other', 'status' => 'assigned'], 'duty.substitute', ['from_duty' => $id]);
            }
            return $cancelled;
        });
        $ids = array_filter([(int) $row->teacher_id, $replacedBy]);
        foreach ($closed as $s) $ids = array_merge($ids, [(int) $s->from_teacher_id, (int) $s->to_teacher_id]);
        Notify::send(Notify::teacherUsers(...array_values(array_unique($ids))), 'duty.cancel', 'เวรถูกยกเลิก', ['เวร '.$this->describe($row).' ถูกยกเลิก', 'เหตุผล: '.$reason] + ($replacedBy ? [2 => 'ครูแทน: '.($this->teacherNames([$replacedBy])[$replacedBy] ?? '')] : []), 'duty');
        return ['id' => (int) $new->id, 'version' => (int) $new->version, 'status' => $new->status];
    }

    private function assertVersion(object $row, int $version): void
    {
        if ((int) $row->version !== $version) throw new VersionConflict((array) $row);
    }

    private function describe(object $d): string
    {
        $dt = self::parseDate(substr((string) $d->date, 0, 10));
        return $dt->day.'/'.$dt->month.'/'.($dt->year + 543).' '.$d->starts_at.'-'.$d->ends_at.' ที่ '.$d->location;
    }

    // ------------------------------------------------------------------ swaps

    private function swapCan(object $s, ?object $user): array
    {
        $mine = $this->teacherOf($user);
        $out = [];
        foreach (self::SWAP_ACTIONS as $name => [$from, , $who]) {
            if (!in_array($s->status, $from, true)) continue;
            $ok = match ($who) {
                'to' => $mine !== null && $mine === (int) $s->to_teacher_id,
                'from' => $mine !== null && $mine === (int) $s->from_teacher_id,
                default => Leave::isApprover($user) && $mine !== (int) $s->from_teacher_id && $mine !== (int) $s->to_teacher_id,
            };
            if ($ok) $out[] = $name;
        }
        return $out;
    }

    private function swapShapeMany($rows, ?object $user): array
    {
        $names = $this->teacherNames($rows->pluck('from_teacher_id')->merge($rows->pluck('to_teacher_id'))->all());
        $duties = $rows->isEmpty() ? collect() : $this->q('duties')->whereIn('id', $rows->pluck('duty_id'))->get()->keyBy('id');
        return $rows->map(function ($s) use ($names, $duties, $user) {
            $d = $duties->get($s->duty_id);
            return ['id' => (int) $s->id, 'version' => (int) $s->version, 'status' => $s->status, 'status_label' => self::SWAP_LABELS[$s->status] ?? $s->status,
                'duty' => $d ? ['id' => (int) $d->id, 'date' => substr((string) $d->date, 0, 10), 'starts_at' => $d->starts_at, 'ends_at' => $d->ends_at, 'location' => $d->location, 'teacher_id' => (int) $d->teacher_id, 'status' => $d->status] : null,
                'from_teacher_id' => (int) $s->from_teacher_id, 'from_name' => $names[$s->from_teacher_id] ?? null, 'to_teacher_id' => (int) $s->to_teacher_id, 'to_name' => $names[$s->to_teacher_id] ?? null,
                'note' => $s->note, 'decision_note' => $s->decision_note, 'created_at' => $s->created_at, 'can' => $this->swapCan($s, $user)];
        })->all();
    }

    public function swaps(?object $user, string $scope, ?string $status): array
    {
        $q = $this->q('ld_duty_swaps');
        if ($scope === 'approval' || $scope === 'all') {
            if (!Leave::isApprover($user)) abort(403, 'ไม่มีสิทธิ์ดูคำขอแลกเวรทั้งหมด');
            if ($scope === 'approval') $q->where('status', 'accepted');
        } else {
            $t = $this->teacherOf($user) ?? 0;
            $q->where(fn ($w) => $w->where('from_teacher_id', $t)->orWhere('to_teacher_id', $t));
        }
        if ($status) $q->where('status', $status);
        return ['data' => $this->swapShapeMany($q->orderByDesc('id')->limit(100)->get(), $user)];
    }

    public function swapRequest(int $dutyId, int $toTeacherId, ?string $note, ?object $user): array
    {
        $mine = $this->teacherOf($user) ?? abort(403, 'บัญชีนี้ยังไม่ได้ผูกกับครู');
        $duty = $this->row($dutyId);
        if ((int) $duty->teacher_id !== $mine) abort(403, 'ขอแลกได้เฉพาะเวรของตนเอง');
        if ($duty->status !== 'assigned') abort(409, 'เวรนี้ไม่ได้อยู่ในสถานะมอบหมาย');
        $date = substr((string) $duty->date, 0, 10);
        if ($date < self::today()) abort(409, 'เวรที่ผ่านมาแล้วขอแลกไม่ได้');
        if ($toTeacherId === $mine) $this->invalid('to_teacher_id', 'เลือกเพื่อนครูคนอื่น');
        $this->teacherOk($toTeacherId);
        if ($this->q('ld_duty_swaps')->where('duty_id', $dutyId)->whereIn('status', self::SWAP_OPEN)->exists()) abort(409, 'เวรนี้มีคำขอแลกเวรค้างอยู่แล้ว');
        if ($why = $this->unavailable($toTeacherId, $date, $duty->starts_at, $duty->ends_at, null, $duty->kind ?? null)) $this->invalid('to_teacher_id', 'เพื่อนครูคนนี้รับเวรไม่ได้: '.$why);
        $row = $this->guard(fn () => Mutation::insert('ld_duty_swaps', ['duty_id' => $dutyId, 'from_teacher_id' => $mine, 'to_teacher_id' => $toTeacherId, 'status' => 'requested',
            'open_duty_id' => $dutyId, 'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 300)], 'duty.swap_request', ['duty_id' => $dutyId, 'to' => $toTeacherId]));
        $from = $this->teacherNames([$mine])[$mine] ?? 'เพื่อนครู';
        Notify::send(Notify::teacherUsers($toTeacherId), 'swap.request', "{$from} ขอแลกเวรกับคุณ", ["{$from} ขอให้คุณรับเวร ".$this->describe($duty), ...($row->note ? ['หมายเหตุ: '.$row->note] : [])], 'duty');
        return $this->swapShapeMany(collect([$row]), $user)[0];
    }

    public function swapTransition(int $id, string $action, int $version, ?string $reason, ?object $user): array
    {
        [$from, $to, $who, $reasonRule] = self::SWAP_ACTIONS[$action] ?? abort(422, 'ไม่รู้จักการดำเนินการนี้');
        $reason = $reason === null ? null : trim($reason);
        [$swap, $new, $duty] = DB::transaction(function () use ($id, $action, $version, $reason, $user, $from, $to, $who, $reasonRule) {
            // lock order is duty -> swap everywhere (cancel() does the same), so approve and cancel cannot deadlock
            $peek = $this->q('ld_duty_swaps')->where('id', $id)->first() ?? abort(404);
            $duty = $this->q('duties')->where('id', $peek->duty_id)->lockForUpdate()->first() ?? abort(404);
            $s = $this->q('ld_duty_swaps')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $mine = $this->teacherOf($user);
            $involved = $mine !== null && in_array($mine, [(int) $s->from_teacher_id, (int) $s->to_teacher_id], true);
            if (!$involved && !Leave::isApprover($user)) abort(403, 'ไม่มีสิทธิ์ใช้คำขอแลกเวรนี้');
            $allowed = match ($who) {
                'to' => $mine === (int) $s->to_teacher_id,
                'from' => $mine === (int) $s->from_teacher_id,
                default => Leave::isApprover($user) && !$involved,
            };
            if (!$allowed) abort(403, $who === 'approver' ? 'เฉพาะผู้อำนวยการหรือผู้ดูแลระบบที่ไม่ใช่คู่แลกเวรเท่านั้น' : 'ทำได้เฉพาะ'.($who === 'to' ? 'ผู้รับเวร' : 'ผู้ขอแลกเวร'));
            $this->assertVersion($s, $version);
            if (!in_array($s->status, $from, true)) abort(409, 'สถานะปัจจุบัน ('.(self::SWAP_LABELS[$s->status] ?? $s->status).') ไม่รองรับการดำเนินการนี้');
            if ($reasonRule === 'required' && ($reason === null || mb_strlen($reason) < 3)) $this->invalid('reason', 'กรุณาระบุเหตุผล (อย่างน้อย 3 ตัวอักษร)');
            $changes = ['status' => $to];
            if (!in_array($to, self::SWAP_OPEN, true)) $changes['open_duty_id'] = null;
            if (in_array($action, ['approve', 'reject'], true)) $changes += ['decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason];
            if ($action === 'approve') {
                $date = substr((string) $duty->date, 0, 10);
                if ($duty->status !== 'assigned' || (int) $duty->teacher_id !== (int) $s->from_teacher_id) abort(409, 'เวรนี้เปลี่ยนแปลงไปแล้ว ไม่สามารถอนุมัติการแลกได้');
                if ($date < self::today()) abort(409, 'เวรที่ผ่านมาแล้วอนุมัติการแลกไม่ได้');
                $this->lockTeacher((int) $s->to_teacher_id);
                if ($why = $this->unavailable((int) $s->to_teacher_id, $date, $duty->starts_at, $duty->ends_at, (int) $duty->id, $duty->kind ?? null)) $this->invalid('to_teacher_id', 'ผู้รับเวรรับเวรนี้ไม่ได้แล้ว: '.$why);
                Mutation::update('duties', (int) $duty->id, (int) $duty->version, ['teacher_id' => (int) $s->to_teacher_id], 'duty.reassign', ['swap_id' => $id, 'from' => (int) $s->from_teacher_id, 'to' => (int) $s->to_teacher_id]);
            }
            $new = Mutation::update('ld_duty_swaps', $id, $version, $changes, 'duty.swap_'.$action, ['reason' => $reason, 'from' => $s->status, 'to' => $to]);
            return [$s, $new, $duty];
        });
        $this->notifySwap($action, $swap, $duty, $reason);
        return $this->swapShapeMany(collect([$new]), $user)[0];
    }

    private function notifySwap(string $action, object $s, object $duty, ?string $reason): void
    {
        $names = $this->teacherNames([$s->from_teacher_id, $s->to_teacher_id]);
        $from = $names[$s->from_teacher_id] ?? 'ครู';
        $to = $names[$s->to_teacher_id] ?? 'ครู';
        $what = 'เวร '.$this->describe($duty);
        $note = $reason ? ['หมายเหตุ: '.$reason] : [];
        $fromUsers = Notify::teacherUsers((int) $s->from_teacher_id);
        $toUsers = Notify::teacherUsers((int) $s->to_teacher_id);
        match ($action) {
            'accept' => [Notify::send($fromUsers, 'swap.accept', "{$to} ตอบรับแลกเวร", ["{$to} ตอบรับรับ{$what} รอผู้อนุมัติ"], 'duty'),
                Notify::send(Notify::approvers((int) $s->from_teacher_id), 'swap.accept', 'มีคำขอแลกเวรรออนุมัติ', ["{$from} และ {$to} ขอแลก{$what}"], 'duty')],
            'decline' => Notify::send($fromUsers, 'swap.decline', "{$to} ปฏิเสธการแลกเวร", ["{$to} ไม่สะดวกรับ{$what}"], 'duty'),
            'cancel' => Notify::send($toUsers, 'swap.cancel', "{$from} ยกเลิกคำขอแลกเวร", ["{$from} ยกเลิกคำขอแลก{$what}"], 'duty'),
            'approve' => Notify::send($fromUsers->merge($toUsers), 'swap.approve', 'อนุมัติการแลกเวรแล้ว', ["อนุมัติให้ {$to} รับ{$what} แทน {$from}", ...$note], 'duty'),
            'reject' => Notify::send($fromUsers->merge($toUsers), 'swap.reject', 'ไม่อนุมัติการแลกเวร', ["ไม่อนุมัติการแลก{$what} ระหว่าง {$from} และ {$to}", ...$note], 'duty'),
        };
    }
}
