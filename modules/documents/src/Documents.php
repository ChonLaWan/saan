<?php

namespace Saan\Modules\Documents;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\StorageQuota;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * M20 document registry. Rules kept here so controllers stay thin:
 * - registry numbers: one counter per (school, kind, Buddhist year) book, claimed with a compare-and-set on doc_books.next_no
 *   inside the same transaction as the document insert (row lock on MariaDB/MySQL, retry loop everywhere); unique (book_id, reg_no)
 *   is the backstop. Nothing deletes a document, cancel keeps its number.
 * - confidential documents: visible only to admin/principal and routed recipients. Clerks (Settings documents.clerk_user_ids)
 *   cannot register or edit confidential documents, so they never hold a document they could not read afterwards.
 * - files: private local disk documents/<school>/<doc>/<random>.<ext>, MIME sniffed, quota-checked, downloads audited.
 */
class Documents
{
    public const KINDS = [
        'incoming' => 'ทะเบียนหนังสือรับ', 'outgoing' => 'ทะเบียนหนังสือส่ง', 'order' => 'ทะเบียนคำสั่ง',
        'announcement' => 'ทะเบียนประกาศ', 'memo' => 'ทะเบียนบันทึกข้อความ',
    ];
    public const URGENCY = ['normal' => 'ปกติ', 'urgent' => 'ด่วน', 'very_urgent' => 'ด่วนมาก', 'most_urgent' => 'ด่วนที่สุด'];
    public const SECRECY = ['normal' => 'ปกติ', 'confidential' => 'ลับ'];
    public const STATUS = ['registered' => 'ลงทะเบียนแล้ว', 'routed' => 'ส่งต่อแล้ว', 'done' => 'เสร็จสิ้น', 'cancelled' => 'ยกเลิก'];
    public const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAX_FILES = 20;
    private const MONTHS = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

    /** Test seam: called once between reading next_no and claiming it, to simulate a concurrent registrar. */
    public static ?\Closure $afterRead = null;

    public static function school(): int { return (int) config('school.school_id'); }
    public static function today(): string { return now('Asia/Bangkok')->toDateString(); }
    private function disk() { return Storage::disk('local'); }
    private function me(): object { return auth()->user() ?? abort(401); }

    public static function thaiDate(?string $iso, bool $short = true): string
    {
        if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return '';
        $month = $short ? self::abbr((int) $m[2]) : self::MONTHS[(int) $m[2]];
        return (int) $m[3].' '.$month.' '.((int) $m[1] + 543);
    }

    private static function abbr(int $m): string
    {
        return ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][$m];
    }

    public static function monthName(int $m): string { return self::MONTHS[$m] ?? ''; }

    // ---- access -----------------------------------------------------------------------------------------------------

    public static function isManager(object $u): bool { return in_array($u->role, ['admin', 'principal'], true); }

    public static function isClerk(object $u): bool
    {
        if (self::isManager($u)) return true;
        $ids = Settings::get('documents.clerk_user_ids', []);
        return is_array($ids) && in_array((int) $u->id, array_map('intval', $ids), true);
    }

    private function requireClerk(): object
    {
        $u = $this->me();
        abort_unless(self::isClerk($u), 403, 'บัญชีนี้ไม่ได้รับสิทธิ์เจ้าหน้าที่สารบรรณ');
        return $u;
    }

    private function routed(int $docId, int $userId): ?object
    {
        return DB::table('doc_routes')->where('school_id', self::school())->where('document_id', $docId)->where('to_user_id', $userId)->first();
    }

    private function canView(object $doc, object $u): bool
    {
        if (self::isManager($u) || $this->routed((int) $doc->id, (int) $u->id)) return true;
        return self::isClerk($u) && $doc->secrecy === 'normal';
    }

    private function find(int $id): object
    {
        $doc = DB::table('doc_documents as d')->join('doc_books as b', 'b.id', '=', 'd.book_id')
            ->where('d.school_id', self::school())->where('d.id', $id)
            ->select('d.*', 'b.kind', 'b.prefix', 'b.year_be')->first() ?? abort(404, 'ไม่พบหนังสือ');
        abort_unless($this->canView($doc, $this->me()), 404, 'ไม่พบหนังสือ'); // 404, not 403: a hidden document's existence is not revealed
        return $doc;
    }

    /** A document the caller may change: confidential ones only by admin/principal, even when a clerk is also a routed recipient. */
    private function manageable(int $id, object $u): object
    {
        $doc = $this->find($id);
        abort_if($doc->secrecy === 'confidential' && !self::isManager($u), 403, 'เฉพาะผู้ดูแลหรือผู้อำนวยการจัดการหนังสือลับได้');
        return $doc;
    }

    /** Convert a unique-key collision (race) into a 409 instead of a 500. */
    public static function guard(\Closure $fn): mixed
    {
        try { return $fn(); }
        catch (QueryException $e) {
            if (\App\Support\Saan\DbError::isUnique($e))

                abort(409, 'ข้อมูลซ้ำหรือถูกบันทึกพร้อมกัน กรุณาลองใหม่');
            throw $e;
        }
    }

    // ---- meta / books -------------------------------------------------------------------------------------------------

    public function meta(): array
    {
        $u = $this->me();
        $clerk = self::isClerk($u);
        return [
            'user_id' => (int) $u->id, 'is_clerk' => $clerk, 'is_manager' => self::isManager($u), 'is_admin' => $u->role === 'admin',
            'kinds' => self::KINDS, 'urgency' => self::URGENCY, 'secrecy' => self::SECRECY, 'status' => self::STATUS,
            'year_be' => (int) substr(self::today(), 0, 4) + 543, 'today' => self::today(), 'unread' => $this->unread((int) $u->id),
            'books' => $clerk ? DB::table('doc_books')->where('school_id', self::school())->orderByDesc('year_be')->orderBy('kind')->get()->map(fn ($b) => (array) $b)->all() : [],
            'max_upload_mb' => (int) config('saan.storage.max_upload_mb'),
        ];
    }

    public function books(): array
    {
        $this->requireClerk();
        return DB::table('doc_books')->where('school_id', self::school())->orderByDesc('year_be')->orderBy('kind')->get()->map(fn ($b) => (array) $b)->all();
    }

    public function updateBook(int $id, int $version, array $d): array
    {
        abort_unless(self::isManager($this->me()), 403, 'เฉพาะผู้ดูแลหรือผู้อำนวยการ');
        DB::table('doc_books')->where('school_id', self::school())->where('id', $id)->exists() || abort(404, 'ไม่พบเล่มทะเบียน');
        $ch = [];
        if (array_key_exists('label', $d)) $ch['label'] = trim((string) $d['label']);
        if (array_key_exists('prefix', $d)) $ch['prefix'] = trim((string) $d['prefix']) ?: null;
        return (array) Mutation::update('doc_books', $id, $version, $ch, 'documents.book.updated', ['fields' => array_keys($ch)]);
    }

    private function ensureBook(string $kind, int $yearBe): object
    {
        $q = fn () => DB::table('doc_books')->where('school_id', self::school())->where('kind', $kind)->where('year_be', $yearBe)->first();
        if ($row = $q()) return $row;
        try {
            return Mutation::insert('doc_books', ['kind' => $kind, 'label' => self::KINDS[$kind].' '.$yearBe, 'prefix' => null, 'year_be' => $yearBe, 'next_no' => 1], 'documents.book.created', ['kind' => $kind, 'year_be' => $yearBe]);
        } catch (QueryException $e) {
            if ($row = $q()) return $row; // another request created the same book first
            throw $e;
        }
    }

    // ---- registry -----------------------------------------------------------------------------------------------------

    public function register(array $d): array
    {
        $u = $this->requireClerk();
        $kind = $d['kind'];
        $secrecy = $d['secrecy'] ?? 'normal';
        abort_if($secrecy === 'confidential' && !self::isManager($u), 403, 'เฉพาะผู้ดูแลหรือผู้อำนวยการลงทะเบียนหนังสือลับได้');
        if ($kind === 'incoming' && trim((string) ($d['from_org'] ?? '')) === '') $this->invalid('from_org', 'หนังสือรับต้องระบุหน่วยงานที่ส่งมา');
        if ($kind === 'outgoing' && trim((string) ($d['to_org'] ?? '')) === '') $this->invalid('to_org', 'หนังสือส่งต้องระบุหน่วยงานปลายทาง');
        $registered = $d['registered_on'] ?? self::today();
        $backdated = $registered !== self::today();
        if ($backdated && !self::isManager($u)) $this->invalid('registered_on', 'เฉพาะผู้ดูแลหรือผู้อำนวยการลงทะเบียนย้อนหลังได้');
        if ($registered > self::today() || $registered < now('Asia/Bangkok')->subYears(5)->toDateString()) $this->invalid('registered_on', 'วันที่ลงทะเบียนต้องไม่เป็นอนาคตและไม่เกิน 5 ปีย้อนหลัง');
        $yearBe = (int) substr($registered, 0, 4) + 543;
        $book = $this->ensureBook($kind, $yearBe);

        $row = self::guard(fn () => DB::transaction(function () use ($book, $d, $u, $secrecy, $registered, $kind, $backdated) {
            $claimed = 0;
            $no = 0;
            for ($try = 0; $try < 8 && $claimed !== 1; $try++) {
                $b = DB::table('doc_books')->where('id', $book->id)->where('school_id', self::school())->lockForUpdate()->first();
                $no = (int) $b->next_no;
                if (self::$afterRead) { $hook = self::$afterRead; self::$afterRead = null; $hook($b); }
                $claimed = DB::table('doc_books')->where('id', $book->id)->where('school_id', self::school())->where('next_no', $no)
                    ->update(['next_no' => $no + 1, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }
            abort_if($claimed !== 1, 409, 'เลขทะเบียนถูกใช้พร้อมกัน กรุณาลองใหม่');
            return Mutation::insert('doc_documents', [
                'book_id' => $book->id, 'reg_no' => $no, 'doc_no' => $this->str($d, 'doc_no', 80), 'doc_date' => $d['doc_date'],
                'received_on' => $d['received_on'] ?? ($kind === 'incoming' ? $registered : null), 'registered_on' => $registered,
                'from_org' => $this->str($d, 'from_org', 200), 'to_org' => $this->str($d, 'to_org', 200), 'subject' => trim($d['subject']),
                'urgency' => $d['urgency'] ?? 'normal', 'secrecy' => $secrecy, 'summary' => $this->str($d, 'summary', 5000),
                'status' => 'registered', 'created_by' => $u->id,
            ], 'documents.registered', ['kind' => $kind, 'reg_no' => $no, 'year_be' => (int) $book->year_be, 'secrecy' => $secrecy, 'backdated' => $backdated]);
        }));
        return $this->detail((int) $row->id);
    }

    private function str(array $d, string $k, int $max): ?string
    {
        $v = trim((string) ($d[$k] ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function invalid(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => [$msg]]);
    }

    public function update(int $id, int $version, array $d): array
    {
        $u = $this->requireClerk();
        $doc = $this->find($id);
        abort_if($doc->status === 'cancelled', 422, 'หนังสือที่ยกเลิกแล้วแก้ไขไม่ได้');
        $new = $d['secrecy'] ?? $doc->secrecy;
        abort_if(($doc->secrecy === 'confidential' || $new === 'confidential') && !self::isManager($u), 403, 'เฉพาะผู้ดูแลหรือผู้อำนวยการแก้ไขหนังสือลับได้');
        $ch = [];
        foreach (['doc_no' => 80, 'from_org' => 200, 'to_org' => 200, 'summary' => 5000] as $k => $max) if (array_key_exists($k, $d)) $ch[$k] = $this->str($d, $k, $max);
        if (isset($d['subject'])) $ch['subject'] = trim($d['subject']);
        foreach (['doc_date', 'urgency', 'secrecy'] as $k) if (isset($d[$k])) $ch[$k] = $d[$k];
        if (array_key_exists('received_on', $d)) $ch['received_on'] = $d['received_on'];
        if ($doc->kind === 'incoming' && array_key_exists('from_org', $ch) && $ch['from_org'] === null) $this->invalid('from_org', 'หนังสือรับต้องระบุหน่วยงานที่ส่งมา');
        if ($doc->kind === 'outgoing' && array_key_exists('to_org', $ch) && $ch['to_org'] === null) $this->invalid('to_org', 'หนังสือส่งต้องระบุหน่วยงานปลายทาง');
        Mutation::update('doc_documents', $id, $version, $ch, 'documents.updated', ['fields' => array_keys($ch)]);
        return $this->detail($id);
    }

    public function cancel(int $id, int $version, string $reason): array
    {
        $doc = $this->manageable($id, $this->requireClerk());
        abort_if($doc->status === 'cancelled', 422, 'หนังสือนี้ยกเลิกแล้ว');
        Mutation::update('doc_documents', $id, $version, ['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 300)], 'documents.cancelled', ['reg_no' => (int) $doc->reg_no]);
        return $this->detail($id);
    }

    public function setDone(int $id, int $version, bool $done): array
    {
        $doc = $this->manageable($id, $this->requireClerk());
        abort_if($doc->status === 'cancelled', 422, 'หนังสือที่ยกเลิกแล้วเปลี่ยนสถานะไม่ได้');
        $has = DB::table('doc_routes')->where('school_id', self::school())->where('document_id', $id)->exists();
        $status = $done ? 'done' : ($has ? 'routed' : 'registered');
        Mutation::update('doc_documents', $id, $version, ['status' => $status], $done ? 'documents.done' : 'documents.reopened');
        return $this->detail($id);
    }

    // ---- reading ------------------------------------------------------------------------------------------------------

    public function list(array $p): array
    {
        $u = $this->requireClerk();
        $school = self::school();
        $q = DB::table('doc_documents as d')->join('doc_books as b', 'b.id', '=', 'd.book_id')->where('d.school_id', $school);
        if (!self::isManager($u)) {
            $q->where(fn ($w) => $w->where('d.secrecy', 'normal')->orWhereIn('d.id', DB::table('doc_routes')->select('document_id')->where('school_id', $school)->where('to_user_id', $u->id)));
        }
        if (!empty($p['year_be'])) $q->where('b.year_be', (int) $p['year_be']);
        if (!empty($p['kind'])) $q->where('b.kind', $p['kind']);
        if (!empty($p['status'])) $q->where('d.status', $p['status']);
        if (!empty($p['urgency'])) $q->where('d.urgency', $p['urgency']);
        if (($term = trim((string) ($p['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($term, 0, 100)).'%';
            $q->where(function ($w) use ($like, $term) {
                foreach (['d.subject', 'd.from_org', 'd.to_org', 'd.doc_no'] as $col) $w->orWhereRaw($col." LIKE ? ESCAPE '!'", [$like]);
                if (ctype_digit($term) && strlen($term) < 9) $w->orWhere('d.reg_no', (int) $term);
            });
        }
        $total = (clone $q)->count();
        $per = min(100, max(5, (int) ($p['per_page'] ?? 25)));
        $page = max(1, (int) ($p['page'] ?? 1));
        $rows = $q->select('d.*', 'b.kind', 'b.prefix', 'b.year_be')
            ->selectRaw('(select count(*) from doc_routes r where r.document_id = d.id) as route_total')
            ->selectRaw('(select count(*) from doc_routes r where r.document_id = d.id and r.acknowledged_at is not null) as route_ack')
            ->orderByDesc('d.registered_on')->orderByDesc('d.id')->forPage($page, $per)->get()
            ->map(fn ($r) => $this->present($r) + ['route_total' => (int) $r->route_total, 'route_ack' => (int) $r->route_ack])->all();
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per,
            'years' => DB::table('doc_books')->where('school_id', $school)->orderByDesc('year_be')->distinct()->pluck('year_be')->map(fn ($y) => (int) $y)->all()];
    }

    private function present(object $d): array
    {
        return [
            'id' => (int) $d->id, 'book_id' => (int) $d->book_id, 'kind' => $d->kind, 'year_be' => (int) $d->year_be, 'reg_no' => (int) $d->reg_no,
            'reg_label' => self::regLabel($d), 'doc_no' => $d->doc_no, 'doc_date' => $d->doc_date, 'received_on' => $d->received_on,
            'registered_on' => $d->registered_on, 'from_org' => $d->from_org, 'to_org' => $d->to_org, 'subject' => $d->subject,
            'urgency' => $d->urgency, 'secrecy' => $d->secrecy, 'summary' => $d->summary, 'status' => $d->status,
            'cancel_reason' => $d->cancel_reason, 'version' => (int) $d->version,
        ];
    }

    public static function regLabel(object $d): string
    {
        return (trim((string) $d->prefix) !== '' ? $d->prefix.' ' : '').$d->reg_no.'/'.$d->year_be;
    }

    private function detail(int $id): array
    {
        $u = $this->me();
        $doc = $this->find($id);
        $out = $this->present($doc);
        $school = self::school();
        $out['created_by_name'] = DB::table('users')->where('id', $doc->created_by)->where('school_id', $school)->value('name');
        $out['files'] = DB::table('doc_files')->where('school_id', $school)->where('document_id', $id)->orderBy('id')->get()
            ->map(fn ($f) => ['id' => (int) $f->id, 'original_name' => $f->original_name, 'mime' => $f->mime, 'size' => (int) $f->size, 'version' => (int) $f->version])->all();
        $routes = DB::table('doc_routes as r')->leftJoin('users as usr', 'usr.id', '=', 'r.to_user_id')
            ->where('r.school_id', $school)->where('r.document_id', $id)->orderBy('r.id')
            ->get(['r.*', 'usr.name as user_name']);
        $clerk = self::isClerk($u) && ($doc->secrecy === 'normal' || self::isManager($u)); // a clerk who is only a recipient of a confidential document sees just their own route
        $out['routes'] = $routes->filter(fn ($r) => $clerk || (int) $r->to_user_id === (int) $u->id)->map(fn ($r) => [
            'id' => (int) $r->id, 'user_id' => (int) $r->to_user_id, 'user_name' => $r->user_name, 'note' => $r->note, 'read_at' => $r->read_at,
            'acknowledged_at' => $r->acknowledged_at, 'action_note' => $r->action_note, 'version' => (int) $r->version, 'mine' => (int) $r->to_user_id === (int) $u->id,
        ])->values()->all();
        $out['is_clerk'] = $clerk;
        return $out;
    }

    /** Opening a document as a recipient marks the route read. A concurrent change to the route is ignored: read state is best effort. */
    public function show(int $id): array
    {
        $u = $this->me();
        $this->find($id);
        $r = $this->routed($id, (int) $u->id);
        if ($r && $r->read_at === null) {
            try { Mutation::update('doc_routes', (int) $r->id, (int) $r->version, ['read_at' => now()], 'documents.route.read', ['document_id' => $id]); }
            catch (\App\Support\Saan\VersionConflict) { /* already updated elsewhere */ }
        }
        return $this->detail($id);
    }

    // ---- routing ------------------------------------------------------------------------------------------------------

    public function recipients(): array
    {
        $this->requireClerk();
        return DB::table('users')->where('school_id', self::school())->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'role' => $r->role])->all();
    }

    public function route(int $id, array $userIds, ?string $note): array
    {
        $u = $this->requireClerk();
        $this->manageable($id, $u);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $valid = DB::table('users')->where('school_id', self::school())->where('is_active', true)->whereIn('id', $userIds)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (count($valid) !== count($userIds)) $this->invalid('user_ids', 'มีผู้รับที่ไม่อยู่ในโรงเรียนหรือปิดการใช้งานแล้ว');
        self::guard(fn () => DB::transaction(function () use ($id, $userIds, $note, $u) {
            $doc = DB::table('doc_documents')->where('school_id', self::school())->where('id', $id)->lockForUpdate()->first();
            abort_if($doc->status === 'cancelled', 422, 'หนังสือที่ยกเลิกแล้วส่งต่อไม่ได้');
            $have = DB::table('doc_routes')->where('school_id', self::school())->where('document_id', $id)->pluck('to_user_id')->map(fn ($i) => (int) $i)->all();
            $added = 0;
            foreach (array_diff($userIds, $have) as $uid) {
                Mutation::insert('doc_routes', ['document_id' => $id, 'to_user_id' => $uid, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null, 'routed_by' => $u->id], 'documents.routed', ['document_id' => $id, 'to_user_id' => $uid]);
                $added++;
            }
            if ($added && $doc->status === 'registered') Mutation::update('doc_documents', $id, (int) $doc->version, ['status' => 'routed'], 'documents.status', ['status' => 'routed']);
        }));
        return $this->detail($id);
    }

    public function unroute(int $id, int $routeId, int $version): array
    {
        $this->manageable($id, $this->requireClerk());
        $r = DB::table('doc_routes')->where('school_id', self::school())->where('document_id', $id)->where('id', $routeId)->first() ?? abort(404, 'ไม่พบรายการส่งต่อ');
        abort_if($r->acknowledged_at !== null, 422, 'ผู้รับรับทราบแล้ว ถอนการส่งต่อไม่ได้');
        DB::transaction(function () use ($id, $routeId, $version) {
            $doc = DB::table('doc_documents')->where('school_id', self::school())->where('id', $id)->lockForUpdate()->first(); // same lock order as route()
            abort_if($doc->status === 'cancelled', 422, 'หนังสือที่ยกเลิกแล้วถอนการส่งต่อไม่ได้');
            Mutation::delete('doc_routes', $routeId, $version, 'documents.unrouted');
            $left = DB::table('doc_routes')->where('school_id', self::school())->where('document_id', $id)->exists();
            if (!$left && $doc->status === 'routed') Mutation::update('doc_documents', $id, (int) $doc->version, ['status' => 'registered'], 'documents.status', ['status' => 'registered']);
        });
        return $this->detail($id);
    }

    public function unread(int $userId): int
    {
        return DB::table('doc_routes as r')->join('doc_documents as d', 'd.id', '=', 'r.document_id')
            ->where('r.school_id', self::school())->where('d.school_id', self::school())->where('r.to_user_id', $userId)
            ->whereNull('r.read_at')->where('d.status', '!=', 'cancelled')->count();
    }

    public function inbox(array $p): array
    {
        $u = $this->me();
        $school = self::school();
        $q = DB::table('doc_routes as r')->join('doc_documents as d', 'd.id', '=', 'r.document_id')->join('doc_books as b', 'b.id', '=', 'd.book_id')
            ->leftJoin('users as by', 'by.id', '=', 'r.routed_by')
            ->where('r.school_id', $school)->where('d.school_id', $school)->where('r.to_user_id', $u->id);
        $state = $p['state'] ?? 'all';
        if ($state === 'unread') $q->whereNull('r.read_at')->where('d.status', '!=', 'cancelled');
        if ($state === 'pending') $q->whereNull('r.acknowledged_at')->where('d.status', '!=', 'cancelled');
        $total = (clone $q)->count();
        $per = min(100, max(5, (int) ($p['per_page'] ?? 30)));
        $page = max(1, (int) ($p['page'] ?? 1));
        $rows = $q->select('r.id as route_id', 'r.note', 'r.read_at', 'r.acknowledged_at', 'r.action_note', 'r.version as route_version', 'r.created_at as routed_at', 'by.name as routed_by_name', 'd.*', 'b.kind', 'b.prefix', 'b.year_be')
            ->orderByRaw('case when r.read_at is null then 0 else 1 end')->orderByDesc('r.id')->forPage($page, $per)->get()
            ->map(fn ($r) => [
                'route_id' => (int) $r->route_id, 'route_version' => (int) $r->route_version, 'note' => $r->note, 'read_at' => $r->read_at, 'acknowledged_at' => $r->acknowledged_at,
                'action_note' => $r->action_note, 'routed_at' => $r->routed_at, 'routed_by_name' => $r->routed_by_name,
                'document' => ['id' => (int) $r->id, 'reg_label' => self::regLabel($r), 'kind' => $r->kind, 'subject' => $r->subject, 'from_org' => $r->from_org,
                    'to_org' => $r->to_org, 'doc_no' => $r->doc_no, 'doc_date' => $r->doc_date, 'urgency' => $r->urgency, 'secrecy' => $r->secrecy, 'status' => $r->status],
            ])->all();
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'unread' => $this->unread((int) $u->id)];
    }

    public function acknowledge(int $routeId, ?string $note): array
    {
        $u = $this->me();
        $out = DB::transaction(function () use ($routeId, $note, $u) {
            $r = DB::table('doc_routes')->where('school_id', self::school())->where('id', $routeId)->where('to_user_id', $u->id)->lockForUpdate()->first() ?? abort(404, 'ไม่พบหนังสือที่ส่งถึงคุณ');
            if ($r->acknowledged_at === null) {
                abort_if(DB::table('doc_documents')->where('school_id', self::school())->where('id', $r->document_id)->value('status') === 'cancelled', 422, 'หนังสือนี้ถูกยกเลิกแล้ว รับทราบไม่ได้');
                $ch = ['acknowledged_at' => now(), 'action_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null];
                if ($r->read_at === null) $ch['read_at'] = now();
                Mutation::update('doc_routes', $routeId, (int) $r->version, $ch, 'documents.acknowledged', ['document_id' => (int) $r->document_id]);
            }
            return (int) $r->document_id;
        });
        return $this->detail($out);
    }

    // ---- clerk settings -------------------------------------------------------------------------------------------------

    public function clerks(): array
    {
        abort_unless($this->me()->role === 'admin', 403, 'เฉพาะผู้ดูแลระบบ');
        $ids = array_map('intval', (array) Settings::get('documents.clerk_user_ids', []));
        return ['clerk_user_ids' => $ids, 'version' => self::clerkSettingVersion(), 'candidates' => DB::table('users')->where('school_id', self::school())->where('is_active', true)->whereNotIn('role', ['admin', 'principal'])
            ->orderBy('name')->get(['id', 'name', 'role'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'role' => $r->role])->all()];
    }

    /** Version of the clerk list = version of its saan_settings row (0 while it was never saved). */
    private static function clerkSettingVersion(): int
    {
        return (int) DB::table('saan_settings')->where('school_id', self::school())->where('key', 'documents.clerk_user_ids')->value('version');
    }

    public function setClerks(array $ids, int $version): array
    {
        abort_unless($this->me()->role === 'admin', 403, 'เฉพาะผู้ดูแลระบบ');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $valid = DB::table('users')->where('school_id', self::school())->where('is_active', true)->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (count($valid) !== count($ids)) $this->invalid('clerk_user_ids', 'มีบัญชีที่ไม่อยู่ในโรงเรียนหรือปิดการใช้งานแล้ว');
        DB::transaction(function () use ($ids, $version) {
            // optimistic version like the sibling endpoints: two admins editing the list cannot silently overwrite each other
            $cur = DB::table('saan_settings')->where('school_id', self::school())->where('key', 'documents.clerk_user_ids')->lockForUpdate()->first();
            if ((int) ($cur->version ?? 0) !== $version) abort(409, 'รายชื่อผู้ดูแลทะเบียนถูกแก้ไขโดยผู้อื่นแล้ว กรุณาโหลดหน้าใหม่แล้วบันทึกอีกครั้ง');
            Settings::put('documents.clerk_user_ids', $ids);
            Mutation::audit('documents.clerks.updated', 'saan_settings', null, ['clerk_user_ids' => $ids]);
        });
        return $this->clerks();
    }

    // ---- files --------------------------------------------------------------------------------------------------------


    public function upload(int $id, UploadedFile $file): array
    {
        $doc = $this->manageable($id, $this->requireClerk());
        abort_if($doc->status === 'cancelled', 422, 'หนังสือที่ยกเลิกแล้วแนบไฟล์เพิ่มไม่ได้');
        $school = self::school();
        abort_if(DB::table('doc_files')->where('school_id', $school)->where('document_id', $id)->count() >= self::MAX_FILES, 422, 'แนบไฟล์ได้ไม่เกิน '.self::MAX_FILES.' ไฟล์ต่อหนังสือ');
        $max = max(1, (int) config('saan.storage.max_upload_mb')) * 1048576;
        abort_unless($file->isValid(), 422, 'อัปโหลดไฟล์ไม่สำเร็จ');
        $size = (int) $file->getSize();
        abort_if($size < 1, 422, 'ไฟล์ว่างเปล่า');
        abort_if($size > $max, 422, 'ไฟล์ใหญ่เกิน '.round($max / 1048576).' MB');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()); // sniffed from content, never from the client name or type
        abort_unless(isset(self::MIMES[$mime]), 422, 'รองรับเฉพาะไฟล์ PDF, JPG, PNG และ WebP');
        StorageQuota::assertRoom($size); // school-wide total: media library plus every module's storage_tables (doc_files is declared in module.json)
        $sha = hash_file('sha256', $file->getRealPath());
        $path = 'documents/'.$school.'/'.$id.'/'.bin2hex(random_bytes(16)).'.'.self::MIMES[$mime];
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename(str_replace('\\', '/', (string) $file->getClientOriginalName())));
        $name = mb_substr(trim((string) $name), 0, 200) ?: 'document.'.self::MIMES[$mime];
        $stream = fopen($file->getRealPath(), 'rb');
        try { abort_unless($this->disk()->put($path, $stream), 500, 'บันทึกไฟล์ไม่สำเร็จ'); } finally { if (is_resource($stream)) fclose($stream); }
        try {
            Mutation::insert('doc_files', ['document_id' => $id, 'path' => $path, 'original_name' => $name, 'mime' => $mime, 'size' => $size, 'sha256' => $sha, 'uploaded_by' => auth()->id()],
                'documents.file.uploaded', ['document_id' => $id, 'size' => $size, 'mime' => $mime]);
        } catch (\Throwable $e) {
            $this->disk()->delete($path);
            throw $e;
        }
        return $this->detail($id);
    }

    private function file(int $fileId): array
    {
        $f = DB::table('doc_files')->where('school_id', self::school())->where('id', $fileId)->first() ?? abort(404, 'ไม่พบไฟล์');
        return [$f, $this->find((int) $f->document_id)];
    }

    public function download(int $fileId)
    {
        [$f, $doc] = $this->file($fileId);
        abort_unless($this->disk()->exists($f->path), 404, 'ไม่พบไฟล์');
        Mutation::audit('documents.file.downloaded', 'doc_files', (int) $f->id, ['document_id' => (int) $doc->id, 'secrecy' => $doc->secrecy]);
        return $this->disk()->download($f->path, $this->downloadName($f),
            ['Content-Type' => $f->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** Original name (sanitised at upload) with the extension forced from the sniffed MIME type; Laravel adds the ASCII fallback beside filename*. */
    private function downloadName(object $f): string
    {
        $ext = self::MIMES[$f->mime] ?? 'bin';
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\\\\\"]+/u', '', pathinfo((string) $f->original_name, PATHINFO_FILENAME)));
        return ($base !== '' ? mb_substr($base, 0, 150) : 'document-'.$f->document_id.'-'.$f->id).'.'.$ext;
    }

    public function deleteFile(int $fileId, int $version): array
    {
        $u = $this->requireClerk();
        [$f, $doc] = $this->file($fileId);
        abort_if($doc->secrecy === 'confidential' && !self::isManager($u), 403, 'เฉพาะผู้ดูแลหรือผู้อำนวยการจัดการหนังสือลับได้');
        abort_if($doc->status === 'cancelled', 422, 'หนังสือที่ยกเลิกแล้วลบไฟล์ไม่ได้');
        Mutation::delete('doc_files', $fileId, $version, 'documents.file.deleted');
        $this->disk()->delete($f->path);
        return $this->detail((int) $doc->id);
    }

    // ---- print --------------------------------------------------------------------------------------------------------

    private static function actionText(?\Illuminate\Support\Collection $routes): string
    {
        if (!$routes || $routes->isEmpty()) return '';
        return $routes->pluck('name')->filter()->implode(', ').' (รับทราบ '.$routes->whereNotNull('acknowledged_at')->count().'/'.$routes->count().')';
    }

    public function printData(string $kind, int $yearBe, int $month): array
    {
        $u = $this->requireClerk();
        $school = self::school();
        $book = DB::table('doc_books')->where('school_id', $school)->where('kind', $kind)->where('year_be', $yearBe)->first();
        $from = sprintf('%04d-%02d-01', $yearBe - 543, $month);
        $to = date('Y-m-d', strtotime($from.' +1 month'));
        $rows = [];
        if ($book) {
            $routedIds = DB::table('doc_routes')->where('school_id', $school)->where('to_user_id', $u->id)->pluck('document_id')->map(fn ($i) => (int) $i)->all();
            $routeRows = DB::table('doc_routes as r')->leftJoin('users as usr', 'usr.id', '=', 'r.to_user_id')->where('r.school_id', $school)
                ->whereIn('r.document_id', DB::table('doc_documents')->select('id')->where('school_id', $school)->where('book_id', $book->id)->where('registered_on', '>=', $from)->where('registered_on', '<', $to))
                ->orderBy('r.id')->get(['r.document_id', 'r.acknowledged_at', 'usr.name'])->groupBy('document_id');
            $rows = DB::table('doc_documents')->where('school_id', $school)->where('book_id', $book->id)->where('registered_on', '>=', $from)->where('registered_on', '<', $to)->orderBy('reg_no')->get()->map(function ($d) use ($u, $routedIds, $book, $routeRows) {
                    $hidden = $d->secrecy === 'confidential' && !self::isManager($u) && !in_array((int) $d->id, $routedIds, true);
                    $d->prefix = $book->prefix; $d->year_be = $book->year_be;
                    return ['reg_label' => self::regLabel($d), 'registered_on' => self::thaiDate($d->registered_on), 'doc_no' => $hidden ? '' : (string) $d->doc_no,
                        'doc_date' => $hidden ? '' : self::thaiDate($d->doc_date), 'party' => $hidden ? '' : (string) ($d->from_org ?: $d->to_org), 'to' => $hidden ? '' : (string) $d->to_org,
                        // same rule as detail(): a non-manager never sees the other recipients (or their acknowledgement state) of a confidential document, even when routed to it
                        'action' => $hidden || ($d->secrecy === 'confidential' && !self::isManager($u)) ? '' : self::actionText($routeRows->get($d->id)),
                        'subject' => $hidden ? 'หนังสือลับ' : $d->subject, 'urgency' => $d->urgency === 'normal' ? '' : self::URGENCY[$d->urgency],
                        'note' => $d->status === 'cancelled' ? 'ยกเลิก'.($hidden ? '' : ($d->cancel_reason ? ': '.$d->cancel_reason : '')) : ($d->secrecy === 'confidential' ? 'ลับ' : '')];
                })->all();
        }
        return ['school' => (string) (app(\App\Domain\CmsPublishing::class)->theme(self::school())['published']['school_name'] ?? config('saan.display', 'โรงเรียน')), 'kind' => $kind, 'kindLabel' => self::KINDS[$kind], 'yearBe' => $yearBe,
            'month' => $month, 'monthName' => self::monthName($month), 'bookLabel' => $book->label ?? self::KINDS[$kind], 'rows' => $rows, 'printedOn' => self::thaiDate(self::today())];
    }
}
