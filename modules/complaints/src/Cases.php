<?php

namespace Saan\Modules\Complaints;

use App\Support\Saan\Mutation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Staff side of the cases. Every read goes through Access (404 for a case the user may not see) and every write goes through Mutation
 * (optimistic version, VersionConflict 409, audit_events). The audit detail holds ids and codes only: no complaint text, no names, no accused ids.
 */
final class Cases
{
    public const PER_PAGE = 50;
    /** The one audit_events action of this module: it names no category, no accused and no kind of step (the case story is in cmp_actions, behind Access). */
    public const AUDIT = 'complaints.case_updated';
    public const ACTIONS = [
        'received' => 'รับเรื่อง', 'assigned' => 'มอบหมาย', 'unassigned' => 'ยกเลิกการมอบหมาย', 'due_changed' => 'เปลี่ยนกำหนดเวลา', 'accused_changed' => 'แก้รายชื่อผู้ถูกร้อง',
        'replied' => 'ตอบผู้ร้อง', 'closed' => 'ปิดเรื่อง', 'reopened' => 'เปิดเรื่องใหม่', 'note' => 'บันทึกภายใน', 'file_opened' => 'เปิดไฟล์แนบ', 'viewed' => 'เปิดดูเรื่อง', 'code_reissued' => 'ออกรหัสติดตามใหม่',
    ];

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    public static function disk()
    {
        return Storage::disk('local');
    }

    public static function labelOf(string $category): string
    {
        return Complaints::CATEGORIES[$category] ?? $category;
    }

    // ---------------------------------------------------------------- reads

    public static function filtered(object $u, array $f): Builder
    {
        $q = Access::cases($u);
        if (!empty($f['category']) && isset(Complaints::CATEGORIES[$f['category']])) $q->where('cmp_cases.category', $f['category']);
        if (!empty($f['status']) && isset(Complaints::STATUSES[$f['status']])) $q->where('cmp_cases.status', $f['status']);
        if (!empty($f['overdue'])) $q->where('cmp_cases.status', '!=', 'closed')->whereNull('cmp_cases.public_reply_at')->where('cmp_cases.due_on', '<', Complaints::today());
        $s = trim((string) ($f['q'] ?? ''));
        if ($s !== '') {
            $like = '%'.addcslashes(mb_substr($s, 0, 100), '%_\\').'%';
            $q->where(fn ($w) => $w->where('cmp_cases.receipt_no', 'like', $like)->orWhere('cmp_cases.subject', 'like', $like));
        }
        return $q;
    }

    public static function summary(object $c, array $names = []): array
    {
        return [
            'id' => (int) $c->id, 'receipt_no' => $c->receipt_no, 'category' => $c->category, 'category_label' => self::labelOf($c->category),
            'anonymous' => (bool) $c->anonymous, 'status' => $c->status, 'status_label' => Complaints::STATUSES[$c->status] ?? $c->status, 'subject' => $c->subject,
            'received_at' => $c->received_at, 'due_on' => substr((string) $c->due_on, 0, 10), 'overdue' => Complaints::isOverdue($c), 'closed_at' => $c->closed_at,
            'assignee' => $c->assignee_id ? ['id' => (int) $c->assignee_id, 'name' => $names[(int) $c->assignee_id] ?? ('บัญชี #'.$c->assignee_id)] : null,
        ];
    }

    private static function names(): array
    {
        return DB::table('users')->where('school_id', Complaints::school())->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    public static function list(object $u, array $f): array
    {
        $page = max(1, (int) ($f['page'] ?? 1));
        $q = self::filtered($u, $f);
        $total = (int) (clone $q)->count();
        $rows = $q->orderByRaw("case when cmp_cases.status = 'closed' then 1 else 0 end")->orderBy('cmp_cases.due_on')->orderBy('cmp_cases.id')
            ->forPage($page, self::PER_PAGE)->get(['cmp_cases.id', 'cmp_cases.receipt_no', 'cmp_cases.category', 'cmp_cases.anonymous', 'cmp_cases.status', 'cmp_cases.subject', 'cmp_cases.received_at',
                'cmp_cases.due_on', 'cmp_cases.closed_at', 'cmp_cases.assignee_id', 'cmp_cases.public_reply_at']);
        $names = self::names();
        return ['data' => $rows->map(fn ($c) => self::summary($c, $names))->all(), 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE];
    }

    /** Coded fields only (no subject, no contact): a list export must never carry the words of a complaint. */
    public static function exportCsv(object $u, array $f): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['เลขรับเรื่อง', 'ประเภท', 'สถานะ', 'วันที่รับเรื่อง', 'กำหนดตอบ', 'เกินกำหนด', 'วันที่ปิดเรื่อง'], ',', '"', '\\', "\r\n");
        $rows = 0;
        foreach (self::filtered($u, $f)->orderBy('cmp_cases.id')->limit(5000)->get() as $c) {
            $rows++;
            fputcsv($fh, [$c->receipt_no, self::labelOf($c->category), Complaints::STATUSES[$c->status] ?? $c->status, substr((string) $c->received_at, 0, 10), substr((string) $c->due_on, 0, 10),
                Complaints::isOverdue($c) ? 'ใช่' : 'ไม่', $c->closed_at ? substr((string) $c->closed_at, 0, 10) : ''], ',', '"', '\\', "\r\n");
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        Mutation::audit('complaints.exported', 'cmp_cases', null, ['rows' => $rows]);
        fclose($fh);
        return "\xEF\xBB\xBF".$csv;
    }

    public static function show(object $u, int $id): array
    {
        $c = Access::find($u, $id);
        $names = self::names();
        $contact = null;
        if (!$c->anonymous && $c->contact) {
            try { $contact = json_decode(Crypt::decryptString((string) $c->contact), true) ?: null; } catch (\Throwable) { $contact = null; }
        }
        $actions = DB::table('cmp_actions')->where('case_id', $c->id)->orderBy('id')->get()->map(fn ($a) => [
            'id' => (int) $a->id, 'action' => $a->action, 'label' => self::ACTIONS[$a->action] ?? $a->action, 'actor' => $a->actor_id ? ($names[(int) $a->actor_id] ?? ('บัญชี #'.$a->actor_id)) : 'ระบบ',
            'detail' => $a->detail ? (json_decode((string) $a->detail, true) ?: null) : null, 'created_at' => $a->created_at])->all();
        $notes = DB::table('cmp_notes')->where('case_id', $c->id)->orderBy('id')->get()->map(fn ($n) => ['id' => (int) $n->id, 'user' => $names[(int) $n->user_id] ?? ('บัญชี #'.$n->user_id), 'body' => $n->body, 'created_at' => $n->created_at])->all();
        $files = DB::table('cmp_files')->where('case_id', $c->id)->orderBy('id')->get(['id', 'name', 'mime', 'size'])->map(fn ($f) => ['id' => (int) $f->id, 'name' => $f->name, 'mime' => $f->mime, 'size' => (int) $f->size])->all();
        $accused = DB::table('cmp_accused')->where('case_id', $c->id)->pluck('user_id')->map('intval')->all();
        return ['case' => self::summary($c, $names) + [
                'detail' => $c->detail, 'contact' => $contact, 'public_reply' => $c->public_reply, 'public_reply_at' => $c->public_reply_at, 'version' => (int) $c->version,
                'sla_days' => Complaints::slaDays($c->category),
            ],
            'actions' => $actions, 'notes' => $notes, 'files' => $files,
            'accused' => array_map(fn ($x) => ['id' => $x, 'name' => $names[$x] ?? ('บัญชี #'.$x)], $accused),
            'assignable' => Access::assignable($c),
            'staff' => Access::staffUsers()->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])->all(),
        ];
    }

    /** Opening a case decrypts the complainant's contact: it leaves a trace (who, which case). One entry per user and case per hour, so reloading the page does not fill the log. */
    public static function viewed(object $u, int $id): void
    {
        if (!\Illuminate\Support\Facades\Cache::add('cmp-viewed:'.(int) $u->id.':'.$id, 1, 3600)) return;
        self::log($id, 'viewed');
    }

    // ---------------------------------------------------------------- writes

    public static function log(int $caseId, string $action, array $detail = []): void
    {
        Mutation::insert('cmp_actions', ['case_id' => $caseId, 'actor_id' => auth()->id(), 'action' => $action, 'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now()], self::AUDIT, []);
    }

    private static function change(object $c, int $version, array $changes, string $logAction, array $logDetail = []): object
    {
        return DB::transaction(function () use ($c, $version, $changes, $logAction, $logDetail) {
            $row = Mutation::update('cmp_cases', (int) $c->id, $version, $changes, self::AUDIT);
            self::log((int) $c->id, $logAction, $logDetail);
            return $row;
        });
    }

    public static function assign(object $u, int $id, int $version, ?int $assigneeId): array
    {
        $c = Access::find($u, $id);
        $changes = ['assignee_id' => $assigneeId];
        if ($assigneeId !== null) {
            $target = DB::table('users')->where('school_id', Complaints::school())->where('id', $assigneeId)->where('is_active', 1)->first();
            if (!$target || !in_array($target->role, Access::STAFF_ROLES, true)) self::fail('assignee_id', 'ไม่พบบัญชีเจ้าหน้าที่นี้');
            if (Access::isAccused((int) $target->id, (int) $c->id)) self::fail('assignee_id', 'ไม่สามารถมอบหมายเรื่องนี้ให้บัญชีนี้ได้');
            if (!Access::worksCategory($target, (string) $c->category)) self::fail('assignee_id', $c->category === 'corruption' ? 'เรื่องทุจริตมอบหมายได้เฉพาะผู้บริหารที่ได้รับสิทธิ์' : 'บัญชีนี้ไม่ได้เป็นเจ้าหน้าที่รับเรื่องร้องเรียน');
            if ($c->status === 'received') $changes += ['status' => 'in_progress', 'status_changed_at' => now()];
        }
        self::change($c, $version, $changes, $assigneeId === null ? 'unassigned' : 'assigned', $assigneeId === null ? [] : ['assignee_id' => $assigneeId]);
        return self::show($u, $id);
    }

    public static function setDue(object $u, int $id, int $version, string $dueOn): array
    {
        $c = Access::find($u, $id);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueOn) || !checkdate((int) substr($dueOn, 5, 2), (int) substr($dueOn, 8, 2), (int) substr($dueOn, 0, 4))) self::fail('due_on', 'วันที่ไม่ถูกต้อง');
        if ($dueOn < substr((string) $c->received_at, 0, 10)) self::fail('due_on', 'กำหนดเวลาต้องไม่ก่อนวันที่รับเรื่อง');
        self::change($c, $version, ['due_on' => $dueOn], 'due_changed', ['due_on' => $dueOn]);
        return self::show($u, $id);
    }

    /** Replaces the accused list. The assignee leaves the case when named; the actor cannot name themselves (they would lock themselves out by mistake). */
    public static function setAccused(object $u, int $id, int $version, array $userIds): array
    {
        $c = Access::find($u, $id);
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($x) => $x > 0)));
        if (count($ids) > 20) self::fail('user_ids', 'ระบุผู้ถูกร้องได้ไม่เกิน 20 บัญชี');
        if (in_array((int) $u->id, $ids, true)) self::fail('user_ids', 'ไม่สามารถตั้งตนเองเป็นผู้ถูกร้องได้');
        if ($ids && DB::table('users')->where('school_id', Complaints::school())->whereIn('id', $ids)->count() !== count($ids)) self::fail('user_ids', 'พบบัญชีที่ไม่มีอยู่ในระบบ');
        DB::transaction(function () use ($c, $version, $ids) {
            $changes = [];
            if ($c->assignee_id && in_array((int) $c->assignee_id, $ids, true)) $changes['assignee_id'] = null;
            Mutation::update('cmp_cases', (int) $c->id, $version, $changes, self::AUDIT);
            $have = DB::table('cmp_accused')->where('case_id', $c->id)->get(['id', 'user_id', 'version']);
            foreach ($have as $row) if (!in_array((int) $row->user_id, $ids, true)) Mutation::delete('cmp_accused', (int) $row->id, (int) $row->version, self::AUDIT);
            $kept = $have->pluck('user_id')->map('intval')->all();
            foreach ($ids as $x) if (!in_array($x, $kept, true)) Mutation::insert('cmp_accused', ['case_id' => (int) $c->id, 'user_id' => $x, 'created_at' => now()], self::AUDIT);
            self::log((int) $c->id, 'accused_changed', ['count' => count($ids)]);
        });
        return self::show($u, $id);
    }

    /**
     * New tracking code for a case (the old one stops working at once). The reason it exists: after the unlock key is rotated the codes made with the old key no longer
     * match on the public side, and a complainant who left contact details can be given a new code. The code is returned ONCE to this caller and kept nowhere (only its HMAC).
     * @return array{0:array,1:string} the case view and the formatted code
     */
    public static function reissueCode(object $u, int $id, int $version): array
    {
        $c = Access::find($u, $id);
        $code = Complaints::newTrackingCode();
        self::change($c, $version, ['track_hash' => Complaints::trackHash((string) $c->receipt_no, $code)], 'code_reissued');
        return [self::show($u, $id), Complaints::formatCode($code)];
    }

    public static function reply(object $u, int $id, int $version, string $text): array
    {
        $c = Access::find($u, $id);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > Complaints::MAX_DETAIL) self::fail('text', 'กรุณาระบุคำตอบ (ไม่เกิน '.Complaints::MAX_DETAIL.' ตัวอักษร)');
        if ($c->status === 'closed') self::fail('text', 'เรื่องนี้ปิดแล้ว เปิดเรื่องใหม่ก่อนตอบ');
        self::change($c, $version, ['public_reply' => $text, 'public_reply_at' => now(), 'status' => 'replied', 'status_changed_at' => now()], 'replied');
        return self::show($u, $id);
    }

    public static function close(object $u, int $id, int $version): array
    {
        $c = Access::find($u, $id);
        if ($c->status === 'closed') self::fail('status', 'เรื่องนี้ปิดแล้ว');
        self::change($c, $version, ['status' => 'closed', 'closed_at' => now(), 'status_changed_at' => now()], 'closed');
        return self::show($u, $id);
    }

    public static function reopen(object $u, int $id, int $version): array
    {
        $c = Access::find($u, $id);
        if ($c->status !== 'closed') self::fail('status', 'เรื่องนี้ยังไม่ได้ปิด');
        // the reply stays visible, but its time no longer stops the SLA clock: a reopened case can become overdue again until a new reply is sent
        self::change($c, $version, ['status' => 'in_progress', 'closed_at' => null, 'public_reply_at' => null, 'status_changed_at' => now()], 'reopened');
        return self::show($u, $id);
    }

    public static function addNote(object $u, int $id, string $body): array
    {
        $c = Access::find($u, $id);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > Complaints::MAX_DETAIL) self::fail('body', 'กรุณาระบุบันทึก (ไม่เกิน '.Complaints::MAX_DETAIL.' ตัวอักษร)');
        DB::transaction(function () use ($c, $u, $body) {
            Mutation::insert('cmp_notes', ['case_id' => (int) $c->id, 'user_id' => (int) $u->id, 'body' => $body, 'created_at' => now()], self::AUDIT);
            self::log((int) $c->id, 'note');
        });
        return self::show($u, $id);
    }

    /** The authorised download: the case must be visible to this user, the file must belong to it, the bytes come from private storage. */
    public static function download(object $u, int $id, int $fileId)
    {
        $c = Access::find($u, $id);
        $f = DB::table('cmp_files')->where('school_id', Complaints::school())->where('case_id', $c->id)->where('id', $fileId)->first() ?? abort(404, 'ไม่พบไฟล์แนบ');
        abort_unless(preg_match('~^complaints/\d{1,10}/[a-f0-9]{32}\.[a-z0-9]{2,5}$~D', (string) $f->path) && self::disk()->exists($f->path), 404, 'ไม่พบไฟล์แนบ');
        self::log((int) $c->id, 'file_opened', ['file_id' => (int) $f->id]);
        return self::disk()->download($f->path, preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '_', (string) $f->name) ?: 'attachment', [
            'Content-Type' => $f->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
