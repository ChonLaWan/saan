<?php

namespace Saan\Modules\Maintenance;

use App\Models\User;
use App\Support\Saan\DbError;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\VersionConflict;
use App\Support\StorageQuota;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Maintenance\Notifications\MntNotification;

/**
 * M21 maintenance. Rules kept here so the controller stays thin:
 * - any signed-in staff may report; a technician is admin, principal or a user listed in Settings maintenance.technician_user_ids.
 *   Reporters see only their own requests; technicians see all.
 * - request state machine (FLOW) with a log row per change, optimistic version, reporter notified on done/rejected.
 * - photo: private local disk maintenance/<school>/requests/<random>.<ext>, MIME sniffed, size limited, quota-checked, served only to
 *   the reporter and technicians.
 * - asset code and stock code uniqueness is a normalised *_key column (never DB collation), checked in code first, unique index as backstop.
 * - stock moves change qty_on_hand under a row lock with a guarded atomic UPDATE; the balance can never go negative.
 */
class Maintenance
{
    public const STATUS = ['new' => 'รอรับเรื่อง', 'accepted' => 'รับเรื่องแล้ว', 'in_progress' => 'กำลังซ่อม', 'waiting_parts' => 'รออะไหล่',
        'done' => 'ซ่อมเสร็จ', 'rejected' => 'ไม่ดำเนินการ', 'cancelled' => 'ยกเลิก'];
    public const OPEN = ['new', 'accepted', 'in_progress', 'waiting_parts'];
    public const FLOW = [
        'new' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['in_progress', 'waiting_parts', 'done', 'rejected', 'cancelled'],
        'in_progress' => ['waiting_parts', 'done', 'rejected'],
        'waiting_parts' => ['in_progress', 'done', 'rejected'],
    ];
    public const URGENCY = ['normal' => 'ปกติ', 'urgent' => 'ด่วน'];
    public const ASSET_STATUS = ['in_use' => 'ใช้งาน', 'repair' => 'ซ่อม', 'disposed' => 'จำหน่ายแล้ว'];
    public const MOVE_KINDS = ['in' => 'รับเข้า', 'out' => 'เบิกออก', 'adjust' => 'ปรับยอด'];
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAX_PHOTO_MB = 8;
    public const MAX_SATANG = 99999999999;
    public const MAX_QTY = 1000000000;

    public static function school(): int { return (int) config('school.school_id'); }
    public static function today(): string { return now('Asia/Bangkok')->toDateString(); }
    private function disk() { return Storage::disk('local'); }
    private function me(): object { return auth()->user() ?? abort(401); }

    /** Case/space-insensitive uniqueness key. Never rely on DB collation (MySQL and MariaDB defaults differ). */
    public static function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)));
    }

    // ---- roles ----------------------------------------------------------------------------------------------------

    public static function isManager(object $u): bool { return in_array($u->role, ['admin', 'principal'], true); }

    private static function techIds(): array
    {
        $ids = Settings::get('maintenance.technician_user_ids', []);
        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    public static function isTech(object $u): bool
    {
        return self::isManager($u) || in_array((int) $u->id, self::techIds(), true);
    }

    private function requireTech(): object
    {
        $u = $this->me();
        abort_unless(self::isTech($u), 403, 'บัญชีนี้ไม่ได้รับสิทธิ์ช่างซ่อมหรือเจ้าหน้าที่พัสดุ');
        return $u;
    }

    /** Active accounts of this school that may be assigned a job. */
    private function assignees()
    {
        $ids = self::techIds();
        return DB::table('users')->where('school_id', self::school())->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('role', ['admin', 'principal'])->orWhereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name', 'role']);
    }

    public function meta(): array
    {
        $u = $this->me();
        $tech = self::isTech($u);
        return ['user_id' => (int) $u->id, 'is_tech' => $tech, 'is_manager' => self::isManager($u), 'is_admin' => $u->role === 'admin',
            'statuses' => self::STATUS, 'flow' => self::FLOW, 'urgency' => self::URGENCY, 'asset_status' => self::ASSET_STATUS, 'move_kinds' => self::MOVE_KINDS,
            'today' => self::today(), 'year' => (int) substr(self::today(), 0, 4), 'max_photo_mb' => self::photoMax() / 1048576,
            'assignees' => $tech ? $this->assignees()->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->all() : []];
    }

    private const TECH_KEY = 'maintenance.technician_user_ids';

    private static function settingsVersion(bool $lock = false): int
    {
        $q = DB::table('saan_settings')->where('school_id', self::school())->where('key', self::TECH_KEY);
        if ($lock) $q->lockForUpdate();
        return (int) ($q->value('version') ?? 0);
    }

    public function technicians(): array
    {
        abort_unless($this->me()->role === 'admin', 403, 'เฉพาะผู้ดูแลระบบ');
        return ['technician_user_ids' => self::techIds(), 'version' => self::settingsVersion(), 'photo_retention_months' => (int) Settings::get('maintenance.photo_retention_months', 12),
            'candidates' => DB::table('users')->where('school_id', self::school())->where('is_active', true)
            ->whereNotIn('role', ['admin', 'principal'])->orderBy('name')->get(['id', 'name', 'role'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'role' => $r->role])->all()];
    }

    /** Optimistic version of the settings row: a stale admin screen gets 409 instead of silently replacing a colleague's list. */
    public function setTechnicians(array $ids, int $version, ?int $retentionMonths = null): array
    {
        abort_unless($this->me()->role === 'admin', 403, 'เฉพาะผู้ดูแลระบบ');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $valid = DB::table('users')->where('school_id', self::school())->where('is_active', true)->whereIn('id', $ids ?: [0])->pluck('id')->map(fn ($i) => (int) $i)->all();
        abort_if(count($valid) !== count($ids), 422, 'มีบัญชีที่ไม่อยู่ในโรงเรียนนี้หรือถูกปิดใช้งาน');
        DB::transaction(function () use ($ids, $version, $retentionMonths) {
            if (self::settingsVersion(true) !== $version) throw new VersionConflict($this->technicians());
            Settings::put(self::TECH_KEY, $ids);
            if ($retentionMonths !== null) Settings::put('maintenance.photo_retention_months', $retentionMonths);
            Mutation::audit('maintenance.technicians.set', 'saan_settings', null, ['count' => count($ids), 'photo_retention_months' => $retentionMonths]);
        });
        return $this->technicians();
    }

    /** Teachers of this school for the "responsible teacher" picker. */
    public function teachers(): array
    {
        $this->requireTech();
        if (!\Illuminate\Support\Facades\Schema::hasTable('teachers')) return ['data' => []];
        $q = DB::table('teachers');
        if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'school_id')) $q->where('school_id', self::school());
        return ['data' => $q->orderBy('name')->limit(1000)->get(['id', 'name'])->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all()];
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    private static function like(string $v): string { return '%'.addcslashes($v, '!%_').'%'; }

    private static function page(array $p): array
    {
        $per = min(100, max(1, (int) ($p['per_page'] ?? 25)));
        $page = max(1, (int) ($p['page'] ?? 1));
        return [$page, $per];
    }

    /** Convert a unique-index violation (race past the code check) into 409, never 500. */
    private function guard(\Closure $fn)
    {
        try { return $fn(); } catch (QueryException $e) {
            if (DbError::isUnique($e)) abort(409, 'รหัสนี้มีอยู่แล้วในระบบ');
            throw $e;
        }
    }

    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $ids ? DB::table('users')->where('school_id', self::school())->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }

    // ---- requests -------------------------------------------------------------------------------------------------

    private function find(int $id): object
    {
        return DB::table('mnt_requests')->where('school_id', self::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายการแจ้งซ่อม');
    }

    /** A non-technician only ever sees their own request; 404 so another request's existence is not disclosed. */
    private function visible(int $id): object
    {
        $u = $this->me();
        $r = $this->find($id);
        abort_unless(self::isTech($u) || (int) $r->reported_by === (int) $u->id, 404, 'ไม่พบรายการแจ้งซ่อม');
        return $r;
    }

    private function row(object $r, array $names, array $assets): array
    {
        $a = $r->asset_id ? ($assets[$r->asset_id] ?? null) : null;
        return ['id' => (int) $r->id, 'asset_id' => $r->asset_id ? (int) $r->asset_id : null, 'asset_code' => $a->asset_code ?? null, 'asset_name' => $a->name ?? null,
            'location' => $r->location, 'title' => $r->title, 'detail' => $r->detail, 'urgency' => $r->urgency, 'status' => $r->status,
            'reported_by' => (int) $r->reported_by, 'reported_by_name' => $names[$r->reported_by] ?? null,
            'assigned_to' => $r->assigned_to ? (int) $r->assigned_to : null, 'assigned_to_name' => $names[$r->assigned_to] ?? null,
            'resolution' => $r->resolution, 'cost_satang' => $r->cost_satang === null ? null : (int) $r->cost_satang,
            'has_photo' => $r->photo_path !== null, 'created_at' => (string) $r->created_at, 'closed_at' => $r->closed_at ? (string) $r->closed_at : null, 'version' => (int) $r->version];
    }

    private function rows(iterable $rs): array
    {
        $rs = collect($rs);
        $names = $this->userNames($rs->pluck('reported_by')->merge($rs->pluck('assigned_to'))->all());
        $ids = $rs->pluck('asset_id')->filter()->unique()->values()->all();
        $assets = $ids ? DB::table('mnt_assets')->where('school_id', self::school())->whereIn('id', $ids)->get(['id', 'asset_code', 'name'])->keyBy('id')->all() : [];
        return $rs->map(fn ($r) => $this->row($r, $names, $assets))->all();
    }

    public function listRequests(array $p): array
    {
        $u = $this->me();
        $scope = $p['scope'] ?? 'mine';
        if ($scope === 'all') $this->requireTech();
        $q = DB::table('mnt_requests as r')->where('r.school_id', self::school());
        if ($scope !== 'all') $q->where('r.reported_by', $u->id);
        $status = $p['status'] ?? null;
        if ($status === 'open') $q->whereIn('r.status', self::OPEN);
        elseif ($status === 'closed') $q->whereNotIn('r.status', self::OPEN);
        elseif ($status) $q->where('r.status', $status);
        if (!empty($p['urgency'])) $q->where('r.urgency', $p['urgency']);
        if (!empty($p['asset_id'])) $q->where('r.asset_id', (int) $p['asset_id']);
        if (!empty($p['assigned']) && $p['assigned'] === 'me') $q->where('r.assigned_to', $u->id);
        if (isset($p['q']) && trim((string) $p['q']) !== '') {
            $like = self::like(trim((string) $p['q']));
            $q->where(fn ($w) => $w->whereRaw("r.title LIKE ? ESCAPE '!'", [$like])->orWhereRaw("r.location LIKE ? ESCAPE '!'", [$like])
                ->orWhereIn('r.asset_id', DB::table('mnt_assets')->select('id')->where('school_id', self::school())->where(fn ($a) => $a->whereRaw("asset_code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]))));
        }
        $total = (clone $q)->count();
        [$page, $per] = self::page($p);
        if ($status === 'open') $q->orderBy('r.urgency', 'desc')->orderBy('r.id'); else $q->orderByDesc('r.id');
        $rows = $q->forPage($page, $per)->get(['r.*']);
        return ['data' => $this->rows($rows), 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    private function logs(int $id): array
    {
        $logs = DB::table('mnt_request_logs')->where('school_id', self::school())->where('request_id', $id)->orderBy('id')->get();
        $names = $this->userNames($logs->pluck('by')->all());
        return $logs->map(fn ($l) => ['id' => (int) $l->id, 'from_status' => $l->from_status, 'to_status' => $l->to_status, 'note' => $l->note,
            'by_name' => $names[$l->by] ?? null, 'at' => (string) $l->at])->all();
    }

    public function show(int $id): array
    {
        $r = $this->visible($id);
        $out = $this->rows([$r])[0];
        $out['logs'] = $this->logs($id);
        $out['allowed'] = $this->allowedFor($r);
        return $out;
    }

    /** A stale client must see 409 (reload) before any state rule says 422; the current row is returned in the safe show shape. */
    private function staleCheck(object $r, int $version): void
    {
        if ((int) $r->version !== $version) throw new VersionConflict($this->show((int) $r->id));
    }

    /** Next statuses the current user may choose (the UI shows exactly these). */
    private function allowedFor(object $r): array
    {
        $u = $this->me();
        $tech = self::isTech($u);
        $own = (int) $r->reported_by === (int) $u->id;
        return array_values(array_filter(self::FLOW[$r->status] ?? [], fn ($to) => $to === 'cancelled' ? ($tech || $own) : $tech));
    }

    private static function photoMax(): int
    {
        return min(self::MAX_PHOTO_MB, max(1, (int) config('saan.storage.max_upload_mb'))) * 1048576;
    }

    private function storePhoto(UploadedFile $file): array
    {
        abort_unless($file->isValid(), 422, 'อัปโหลดรูปไม่สำเร็จ');
        $size = (int) $file->getSize();
        abort_if($size < 1, 422, 'ไฟล์รูปว่างเปล่า');
        abort_if($size > self::photoMax(), 422, 'รูปใหญ่เกิน '.round(self::photoMax() / 1048576).' MB');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        abort_unless(isset(self::MIMES[$mime]) && @getimagesize($file->getRealPath()) !== false, 422, 'รองรับเฉพาะรูป JPG, PNG และ WebP');
        $bin = $this->cleanImage($file->getRealPath(), $mime);
        $size = strlen($bin);
        StorageQuota::assertRoom($size);
        $path = 'maintenance/'.self::school().'/requests/'.bin2hex(random_bytes(16)).'.'.self::MIMES[$mime];
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename(str_replace('\\', '/', (string) $file->getClientOriginalName())));
        $name = mb_substr(trim((string) $name), 0, 200) ?: 'photo.'.self::MIMES[$mime];
        abort_unless($this->disk()->put($path, $bin), 500, 'บันทึกรูปไม่สำเร็จ');
        return ['photo_path' => $path, 'photo_name' => $name, 'photo_mime' => $mime, 'photo_size' => $size];
    }

    public const MAX_EDGE = 2000;
    public const MAX_PIXELS = 40000000;

    /**
     * Re-encode with GD: drops every metadata block (EXIF GPS position, device model, thumbnails) and limits the long edge to MAX_EDGE px.
     * The JPEG orientation flag is applied to the pixels first so the picture is not rotated after the EXIF is gone.
     */
    /** Test hook: pretend this many bytes are left (null = ask PHP). */
    public static ?int $memoryLeftOverride = null;

    /** Bytes still available to this request, or null when memory_limit is unlimited. */
    private static function memoryLeft(): ?int
    {
        if (self::$memoryLeftOverride !== null) return self::$memoryLeftOverride;
        $limit = self::memoryLimitBytes();
        return $limit > 0 ? $limit - memory_get_usage() : null;
    }

    /** memory_limit in bytes; 0 = unlimited. */
    private static function memoryLimitBytes(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') return 0;
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }

    private function cleanImage(string $file, string $mime): string
    {
        abort_unless(function_exists('imagecreatefromstring'), 500, 'เซิร์ฟเวอร์ไม่รองรับการประมวลผลรูป');
        $info = @getimagesize($file);
        abort_if(!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS, 422, 'ขนาดรูปไม่เหมาะสม');
        // a truecolor GD bitmap is ~4 bytes/pixel (+ decoder overhead): refuse with 422 instead of dying with a fatal memory error
        $left = self::memoryLeft();
        abort_if($left !== null && $info[0] * $info[1] * 5 > $left, 422, 'รูปมีความละเอียดสูงเกินไป กรุณาลดขนาดรูปแล้วลองใหม่');
        $orientation = 1;
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) $orientation = (int) (@exif_read_data($file)['Orientation'] ?? 1);
        $img = @imagecreatefromstring((string) file_get_contents($file));
        abort_unless($img !== false, 422, 'อ่านไฟล์รูปไม่ได้');
        // scale down first, rotate the small bitmap afterwards (rotating the full-resolution bitmap doubles peak memory)
        $w = imagesx($img); $h = imagesy($img);
        if (max($w, $h) > self::MAX_EDGE) {
            $scale = self::MAX_EDGE / max($w, $h);
            $small = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            if ($small !== false) { imagedestroy($img); $img = $small; }
        }
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0 && ($rot = imagerotate($img, $angle, 0)) !== false) { imagedestroy($img); $img = $rot; }
        ob_start();
        try {
            if ($mime === 'image/png') { imagealphablending($img, false); imagesavealpha($img, true); $ok = imagepng($img, null, 6); }
            elseif ($mime === 'image/webp') { imagealphablending($img, false); imagesavealpha($img, true); $ok = function_exists('imagewebp') && imagewebp($img, null, 85); }
            else $ok = imagejpeg($img, null, 85);
        } finally { $out = (string) ob_get_clean(); imagedestroy($img); }
        abort_unless($ok && $out !== '', 422, 'ประมวลผลรูปไม่สำเร็จ');
        return $out;
    }

    public function create(array $d, ?UploadedFile $photo): array
    {
        $u = $this->me();
        $asset = null;
        if (!empty($d['asset_id'])) {
            $asset = DB::table('mnt_assets')->where('school_id', self::school())->where('id', (int) $d['asset_id'])->first() ?? abort(422, 'ไม่พบครุภัณฑ์ที่เลือก');
            abort_if($asset->status === 'disposed', 422, 'ครุภัณฑ์นี้จำหน่ายแล้ว แจ้งซ่อมไม่ได้');
        }
        $location = trim((string) ($d['location'] ?? '')) ?: ($asset->location ?? '');
        abort_if($location === '', 422, 'กรุณาระบุสถานที่');
        $file = $photo ? $this->storePhoto($photo) : [];
        try {
            $row = DB::transaction(function () use ($d, $u, $asset, $location, $file) {
                $r = Mutation::insert('mnt_requests', ['asset_id' => $asset?->id, 'location' => $location, 'title' => trim($d['title']), 'detail' => $d['detail'] ?? null,
                    'urgency' => $d['urgency'] ?? 'normal', 'reported_by' => $u->id, 'status' => 'new'] + $file, 'maintenance.request.created', ['asset_id' => $asset?->id, 'photo' => (bool) $file]);
                $this->log((int) $r->id, null, 'new', null);
                return $r;
            });
        } catch (\Throwable $e) {
            if ($file) $this->disk()->delete($file['photo_path']);
            throw $e;
        }
        return $this->show((int) $row->id);
    }

    private function log(int $requestId, ?string $from, string $to, ?string $note): void
    {
        DB::table('mnt_request_logs')->insert(['school_id' => self::school(), 'request_id' => $requestId, 'from_status' => $from, 'to_status' => $to,
            'note' => $note !== null ? mb_substr($note, 0, 1000) : null, 'by' => auth()->id(), 'at' => now()]);
    }

    public function update(int $id, int $version, array $d): array
    {
        $u = $this->me();
        $r = $this->visible($id);
        $tech = self::isTech($u);
        $this->staleCheck($r, $version);
        abort_unless(in_array($r->status, self::OPEN, true), 422, 'รายการที่ปิดแล้วแก้ไขไม่ได้');
        abort_unless($tech || $r->status === 'new', 422, 'แก้ไขได้เฉพาะรายการที่ยังรอรับเรื่อง');
        $changes = [];
        foreach (['title', 'detail', 'location', 'urgency'] as $k) if (array_key_exists($k, $d)) $changes[$k] = is_string($d[$k]) ? trim($d[$k]) : $d[$k];
        if (isset($changes['title']) && $changes['title'] === '') abort(422, 'กรุณาระบุเรื่อง');
        if (isset($changes['location']) && $changes['location'] === '') abort(422, 'กรุณาระบุสถานที่');
        if ($changes) Mutation::update('mnt_requests', $id, $version, $changes, 'maintenance.request.updated', ['fields' => array_keys($changes)]);
        return $this->show($id);
    }

    public function transition(int $id, int $version, string $to, array $in): array
    {
        $u = $this->me();
        $r = $this->visible($id);
        $tech = self::isTech($u);
        $own = (int) $r->reported_by === (int) $u->id;
        abort_unless($to === 'cancelled' ? ($tech || $own) : $tech, 403, 'ไม่มีสิทธิ์เปลี่ยนสถานะนี้');
        $this->staleCheck($r, $version);
        abort_unless(in_array($to, self::FLOW[$r->status] ?? [], true), 422, 'เปลี่ยนสถานะจาก "'.(self::STATUS[$r->status] ?? $r->status).'" เป็น "'.(self::STATUS[$to] ?? $to).'" ไม่ได้');
        $note = trim((string) ($in['note'] ?? ''));
        $resolution = trim((string) ($in['resolution'] ?? ''));
        if ($to === 'rejected') abort_if(mb_strlen($note) < 2, 422, 'กรุณาระบุเหตุผลที่ไม่ดำเนินการ');
        if ($to === 'done') abort_if(mb_strlen($resolution) < 2, 422, 'กรุณาสรุปผลการซ่อม');
        $changes = ['status' => $to];
        if ($tech && !$r->assigned_to && in_array($to, ['accepted', 'in_progress', 'waiting_parts', 'done'], true)) $changes['assigned_to'] = $u->id;
        if ($to === 'done') { $changes['resolution'] = $resolution; if (array_key_exists('cost_satang', $in) && $in['cost_satang'] !== null) $changes['cost_satang'] = (int) $in['cost_satang']; }
        if ($to === 'rejected') $changes['resolution'] = $note;
        if (in_array($to, ['done', 'rejected', 'cancelled'], true)) $changes['closed_at'] = now();
        DB::transaction(function () use ($id, $version, $r, $to, $changes, $note, $resolution) {
            Mutation::update('mnt_requests', $id, $version, $changes, 'maintenance.request.'.$to, ['from' => $r->status, 'to' => $to]);
            $this->log($id, $r->status, $to, $to === 'done' ? ($note !== '' ? $note : $resolution) : $note);
            $this->syncAsset($r->asset_id ? (int) $r->asset_id : null);
        });
        if (in_array($to, ['done', 'rejected'], true) && (int) $r->reported_by !== (int) $u->id) $this->notifyReporter($id, $to);
        return $this->show($id);
    }

    public function assign(int $id, int $version, ?int $userId): array
    {
        $this->requireTech();
        $r = $this->find($id);
        $this->staleCheck($r, $version);
        abort_unless(in_array($r->status, self::OPEN, true), 422, 'รายการที่ปิดแล้วมอบหมายไม่ได้');
        $name = null;
        if ($userId !== null) {
            $cand = $this->assignees()->firstWhere('id', $userId);
            abort_unless($cand, 422, 'ผู้รับผิดชอบต้องเป็นช่างซ่อมของโรงเรียนนี้');
            $name = $cand->name;
        }
        DB::transaction(function () use ($id, $version, $r, $userId, $name) {
            Mutation::update('mnt_requests', $id, $version, ['assigned_to' => $userId], 'maintenance.request.assigned', ['to' => $userId]);
            $this->log($id, $r->status, $r->status, $userId ? 'มอบหมายให้ '.$name : 'ยกเลิกการมอบหมาย');
        });
        return $this->show($id);
    }

    /**
     * Asset shows "repair" while any request on it is being worked on; back to in_use when none is. Disposed stays disposed.
     * A "repair" status set by a person (status_auto = 0, e.g. sent to an outside shop with no request) is never reset here.
     * The busy check is a locking read (latest committed rows, not the REPEATABLE READ snapshot) taken after the asset row lock.
     */
    private function syncAsset(?int $assetId): void
    {
        if (!$assetId) return;
        $a = DB::table('mnt_assets')->where('school_id', self::school())->where('id', $assetId)->lockForUpdate()->first();
        if (!$a || $a->status === 'disposed') return;
        $busy = DB::table('mnt_requests')->where('school_id', self::school())->where('asset_id', $assetId)->whereIn('status', ['accepted', 'in_progress', 'waiting_parts'])->lockForUpdate()->exists();
        $set = null;
        if ($busy && $a->status === 'in_use') $set = ['status' => 'repair', 'status_auto' => true];
        elseif (!$busy && $a->status === 'repair' && $a->status_auto) $set = ['status' => 'in_use', 'status_auto' => false];
        if (!$set) return;
        DB::table('mnt_assets')->where('id', $assetId)->where('school_id', self::school())->update($set + ['version' => DB::raw('version + 1'), 'updated_at' => now()]);
        Mutation::audit('maintenance.asset.status_sync', 'mnt_assets', $assetId, ['from' => $a->status, 'to' => $set['status']]);
    }

    private function notifyReporter(int $id, string $to): void
    {
        $r = $this->find($id);
        $reporter = User::where('school_id', self::school())->where('is_active', true)->where('id', $r->reported_by)->first();
        if (!$reporter) return;
        $lines = ['เรื่อง: '.$r->title, 'สถานที่: '.$r->location, $to === 'done' ? 'ผลการซ่อม: '.$r->resolution : 'เหตุผล: '.$r->resolution];
        try {
            Notification::send($reporter, new MntNotification($to, 'แจ้งซ่อม #'.$id.' '.($to === 'done' ? 'ซ่อมเสร็จแล้ว' : 'ไม่ดำเนินการ'), $lines, 'repairs/'.$id));
        } catch (\Throwable $e) {
            Log::warning('[WARN] maintenance notification failed: '.$e->getMessage(), ['request' => $id]); // the committed transition must not fail because mail did
        }
    }

    public function photo(int $id)
    {
        $r = $this->visible($id);
        abort_unless($r->photo_path && $this->disk()->exists($r->photo_path), 404, 'ไม่พบรูป');
        return $this->disk()->response($r->photo_path, 'request-'.$id.'.'.(self::MIMES[$r->photo_mime] ?? 'jpg'), [
            'Content-Type' => $r->photo_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox"], 'inline');
    }

    // ---- assets ---------------------------------------------------------------------------------------------------

    private function assetFind(int $id): object
    {
        return DB::table('mnt_assets')->where('school_id', self::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบครุภัณฑ์');
    }

    private function assetRow(object $a, array $teachers = []): array
    {
        return ['id' => (int) $a->id, 'asset_code' => $a->asset_code, 'name' => $a->name, 'category' => $a->category, 'location' => $a->location, 'acquired_on' => $a->acquired_on,
            'price_satang' => $a->price_satang === null ? null : (int) $a->price_satang, 'status' => $a->status, 'responsible_teacher_id' => $a->responsible_teacher_id ? (int) $a->responsible_teacher_id : null,
            'responsible_name' => $a->responsible_teacher_id ? ($teachers[$a->responsible_teacher_id] ?? null) : null, 'version' => (int) $a->version];
    }

    private function teacherNames(iterable $assets): array
    {
        $ids = collect($assets)->pluck('responsible_teacher_id')->filter()->unique()->values()->all();
        if (!$ids || !\Illuminate\Support\Facades\Schema::hasTable('teachers')) return [];
        $q = DB::table('teachers')->whereIn('id', $ids);
        if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'school_id')) $q->where('school_id', self::school());
        return $q->pluck('name', 'id')->all();
    }

    private function checkTeacher(?int $id): void
    {
        if (!$id) return;
        $q = DB::table('teachers')->where('id', $id);
        if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'school_id')) $q->where('school_id', self::school());
        abort_unless($q->exists(), 422, 'ไม่พบครูผู้รับผิดชอบที่เลือก');
    }

    public function lookupAssets(string $q): array
    {
        $this->me();
        $like = self::like(trim($q));
        return DB::table('mnt_assets')->where('school_id', self::school())->where('status', '!=', 'disposed')
            ->where(fn ($w) => $w->whereRaw("asset_code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]))
            ->orderBy('asset_code')->limit(20)->get(['id', 'asset_code', 'name', 'location'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'asset_code' => $a->asset_code, 'name' => $a->name, 'location' => $a->location])->all();
    }

    public function listAssets(array $p): array
    {
        $this->requireTech();
        $q = DB::table('mnt_assets')->where('school_id', self::school());
        if (!empty($p['status'])) $q->where('status', $p['status']);
        if (!empty($p['category'])) $q->where('category', $p['category']);
        if (isset($p['q']) && trim((string) $p['q']) !== '') {
            $like = self::like(trim((string) $p['q']));
            $q->where(fn ($w) => $w->whereRaw("asset_code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("location LIKE ? ESCAPE '!'", [$like]));
        }
        $total = (clone $q)->count();
        [$page, $per] = self::page($p);
        $rows = $q->orderBy('asset_code')->forPage($page, $per)->get();
        $teachers = $this->teacherNames($rows);
        $cats = DB::table('mnt_assets')->where('school_id', self::school())->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
        return ['data' => $rows->map(fn ($a) => $this->assetRow($a, $teachers))->all(), 'total' => $total, 'page' => $page, 'per_page' => $per, 'categories' => $cats];
    }

    public function showAsset(int $id): array
    {
        $this->requireTech();
        $a = $this->assetFind($id);
        $reqs = DB::table('mnt_requests')->where('school_id', self::school())->where('asset_id', $id)->orderByDesc('id')->limit(200)->get();
        $totals = DB::table('mnt_requests')->where('school_id', self::school())->where('asset_id', $id)->selectRaw('count(*) as n, sum(case when status = ? then coalesce(cost_satang, 0) else 0 end) as cost', ['done'])->first();
        return $this->assetRow($a, $this->teacherNames([$a])) + ['history' => $this->rows($reqs), 'history_count' => (int) $totals->n, 'history_cost_satang' => (int) $totals->cost];
    }

    private function assetFields(array $d): array
    {
        $out = [];
        foreach (['asset_code', 'name', 'category', 'location'] as $k) if (array_key_exists($k, $d)) { $v = is_string($d[$k]) ? trim($d[$k]) : $d[$k]; $out[$k] = $v === '' ? null : $v; }
        foreach (['acquired_on', 'price_satang', 'status', 'responsible_teacher_id'] as $k) if (array_key_exists($k, $d)) $out[$k] = $d[$k];
        foreach (['asset_code', 'name'] as $k) if (array_key_exists($k, $out)) abort_if($out[$k] === null, 422, $k === 'asset_code' ? 'กรุณาระบุเลขครุภัณฑ์' : 'กรุณาระบุชื่อครุภัณฑ์');
        if (isset($out['asset_code'])) $out['asset_code_key'] = self::key($out['asset_code']);
        if (isset($out['price_satang'])) abort_if((int) $out['price_satang'] > self::MAX_SATANG, 422, 'ราคาสูงเกินไป');
        $this->checkTeacher(isset($out['responsible_teacher_id']) ? (int) $out['responsible_teacher_id'] : null);
        return $out;
    }

    private function assertAssetKeyFree(string $key, ?int $exceptId): void
    {
        $q = DB::table('mnt_assets')->where('school_id', self::school())->where('asset_code_key', $key);
        if ($exceptId) $q->where('id', '!=', $exceptId);
        abort_if($q->exists(), 409, 'เลขครุภัณฑ์นี้มีอยู่แล้ว');
    }

    public function createAsset(array $d): array
    {
        $this->requireTech();
        $v = $this->assetFields($d);
        $this->assertAssetKeyFree($v['asset_code_key'], null);
        $row = $this->guard(fn () => Mutation::insert('mnt_assets', $v + ['status' => 'in_use'], 'maintenance.asset.created', ['asset_code' => $v['asset_code']]));
        return $this->assetRow($row, $this->teacherNames([$row]));
    }

    public function updateAsset(int $id, int $version, array $d): array
    {
        $this->requireTech();
        $current = $this->assetFind($id);
        $v = $this->assetFields($d);
        if (isset($v['asset_code_key'])) $this->assertAssetKeyFree($v['asset_code_key'], $id);
        if (($v['status'] ?? null) === 'disposed') abort_if(DB::table('mnt_requests')->where('school_id', self::school())->where('asset_id', $id)->whereIn('status', self::OPEN)->exists(), 422, 'ยังมีงานแจ้งซ่อมค้างอยู่ ปิดงานก่อนจำหน่าย');
        // only a status that really changed is a person's choice; resubmitting the unchanged status (editing name/location) keeps status_auto so the request sync still works
        if (isset($v['status']) && $v['status'] !== $current->status) $v['status_auto'] = false;
        else unset($v['status']);
        $row = $v ? $this->guard(fn () => Mutation::update('mnt_assets', $id, $version, $v, 'maintenance.asset.updated', ['fields' => array_keys($v)])) : $this->assetFind($id);
        return $this->assetRow($row, $this->teacherNames([$row]));
    }

    public function deleteAsset(int $id, int $version): void
    {
        $this->requireTech();
        $this->assetFind($id);
        abort_if(DB::table('mnt_requests')->where('school_id', self::school())->where('asset_id', $id)->exists(), 422, 'ครุภัณฑ์ที่มีประวัติแจ้งซ่อมลบไม่ได้ ให้เปลี่ยนสถานะเป็นจำหน่ายแล้วแทน');
        Mutation::delete('mnt_assets', $id, $version, 'maintenance.asset.deleted');
    }

    // ---- stock ----------------------------------------------------------------------------------------------------

    private function itemRow(object $i): array
    {
        return ['id' => (int) $i->id, 'code' => $i->code, 'name' => $i->name, 'unit' => $i->unit, 'qty_on_hand' => (int) $i->qty_on_hand,
            'reorder_level' => (int) $i->reorder_level, 'below_reorder' => (int) $i->reorder_level > 0 && (int) $i->qty_on_hand <= (int) $i->reorder_level, 'version' => (int) $i->version];
    }

    private function itemFind(int $id): object
    {
        return DB::table('mnt_stock_items')->where('school_id', self::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายการพัสดุ');
    }

    public function listStock(array $p): array
    {
        $this->requireTech();
        $q = DB::table('mnt_stock_items')->where('school_id', self::school());
        if (!empty($p['below'])) $q->where('reorder_level', '>', 0)->whereColumn('qty_on_hand', '<=', 'reorder_level');
        if (isset($p['q']) && trim((string) $p['q']) !== '') {
            $like = self::like(trim((string) $p['q']));
            $q->where(fn ($w) => $w->whereRaw("code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]));
        }
        $total = (clone $q)->count();
        [$page, $per] = self::page($p);
        return ['data' => $q->orderBy('code')->forPage($page, $per)->get()->map(fn ($i) => $this->itemRow($i))->all(), 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    private function assertItemKeyFree(string $key, ?int $exceptId): void
    {
        $q = DB::table('mnt_stock_items')->where('school_id', self::school())->where('code_key', $key);
        if ($exceptId) $q->where('id', '!=', $exceptId);
        abort_if($q->exists(), 409, 'รหัสพัสดุนี้มีอยู่แล้ว');
    }

    public function createItem(array $d): array
    {
        $this->requireTech();
        $code = trim($d['code']);
        $key = self::key($code);
        $this->assertItemKeyFree($key, null);
        $initial = (int) ($d['initial_qty'] ?? 0);
        $row = $this->guard(fn () => DB::transaction(function () use ($d, $code, $key, $initial) {
            $row = Mutation::insert('mnt_stock_items', ['code' => $code, 'code_key' => $key, 'name' => trim($d['name']), 'unit' => trim($d['unit']), 'qty_on_hand' => 0,
                'reorder_level' => (int) ($d['reorder_level'] ?? 0)], 'maintenance.stock.item.created', ['code' => $code]);
            if ($initial > 0) $this->applyMove((int) $row->id, 'in', $initial, 'ยอดยกมา', null);
            return $this->itemFind((int) $row->id);
        }));
        return $this->itemRow($row);
    }

    public function updateItem(int $id, int $version, array $d): array
    {
        $this->requireTech();
        $this->itemFind($id);
        $c = [];
        foreach (['code', 'name', 'unit'] as $k) if (array_key_exists($k, $d)) $c[$k] = trim((string) $d[$k]);
        if (array_key_exists('reorder_level', $d)) $c['reorder_level'] = (int) $d['reorder_level'];
        foreach (['code', 'name', 'unit'] as $k) if (array_key_exists($k, $c)) abort_if($c[$k] === '', 422, 'กรุณากรอกข้อมูลให้ครบ');
        if (isset($c['code'])) { $c['code_key'] = self::key($c['code']); $this->assertItemKeyFree($c['code_key'], $id); }
        $row = $c ? $this->guard(fn () => Mutation::update('mnt_stock_items', $id, $version, $c, 'maintenance.stock.item.updated', ['fields' => array_keys($c)])) : $this->itemFind($id);
        return $this->itemRow($row);
    }

    /** Must run inside a transaction. Row lock + guarded atomic UPDATE: concurrent withdrawals can never drive the balance below zero. */
    private function applyMove(int $itemId, string $kind, int $qty, ?string $note, ?string $ref): void
    {
        $delta = $kind === 'out' ? -abs($qty) : ($kind === 'in' ? abs($qty) : $qty);
        $item = DB::table('mnt_stock_items')->where('school_id', self::school())->where('id', $itemId)->lockForUpdate()->first() ?? abort(404, 'ไม่พบรายการพัสดุ');
        $short = 'คงเหลือ '.$item->qty_on_hand.' '.$item->unit.' ไม่พอสำหรับการเบิกหรือปรับลด '.abs($delta);
        abort_if((int) $item->qty_on_hand + $delta < 0, 422, $short);
        $over = 'ยอดคงเหลือสูงเกินกว่าที่ระบบรับได้';
        abort_if((int) $item->qty_on_hand + $delta > self::MAX_QTY, 422, $over); // INT overflow would be a 500 (SQLSTATE 22003) on strict MariaDB
        $rows = DB::table('mnt_stock_items')->where('school_id', self::school())->where('id', $itemId)->whereRaw('qty_on_hand + ? BETWEEN 0 AND '.self::MAX_QTY, [$delta])
            ->update(['qty_on_hand' => DB::raw('qty_on_hand + ('.$delta.')'), 'updated_at' => now()]);
        abort_if($rows === 0, 422, $short);
        DB::table('mnt_stock_moves')->insert(['school_id' => self::school(), 'item_id' => $itemId, 'kind' => $kind, 'qty' => $kind === 'adjust' ? $delta : abs($qty),
            'ref' => $ref, 'by' => auth()->id(), 'at' => now(), 'note' => $note]);
        Mutation::audit('maintenance.stock.'.$kind, 'mnt_stock_items', $itemId, ['qty' => $delta, 'ref' => $ref]);
    }

    public function move(int $itemId, array $d): array
    {
        $this->requireTech();
        DB::transaction(fn () => $this->applyMove($itemId, $d['kind'], (int) $d['qty'], isset($d['note']) ? trim((string) $d['note']) ?: null : null, isset($d['ref']) ? trim((string) $d['ref']) ?: null : null));
        return $this->itemRow($this->itemFind($itemId));
    }

    public function moves(int $itemId, array $p): array
    {
        $this->requireTech();
        $item = $this->itemFind($itemId);
        $q = DB::table('mnt_stock_moves')->where('school_id', self::school())->where('item_id', $itemId);
        $total = (clone $q)->count();
        [$page, $per] = self::page($p);
        $rows = $q->orderByDesc('id')->forPage($page, $per)->get();
        $names = $this->userNames($rows->pluck('by')->all());
        return ['item' => $this->itemRow($item), 'total' => $total, 'page' => $page, 'per_page' => $per,
            'data' => $rows->map(fn ($m) => ['id' => (int) $m->id, 'kind' => $m->kind, 'qty' => (int) $m->qty, 'ref' => $m->ref, 'note' => $m->note, 'by_name' => $names[$m->by] ?? null, 'at' => (string) $m->at])->all()];
    }

    // ---- reports --------------------------------------------------------------------------------------------------

    public function openByAge(): array
    {
        $this->requireTech();
        $now = now();
        $base = fn () => DB::table('mnt_requests')->where('school_id', self::school())->whereIn('status', self::OPEN);
        // exact totals and buckets come from SQL (age = whole days since created_at); only the 200 oldest rows are listed
        $cut = fn (int $days) => $now->copy()->subDays($days)->format('Y-m-d H:i:s');
        $c = $base()->selectRaw('count(*) as total, sum(case when created_at > ? then 1 else 0 end) as d3, sum(case when created_at > ? then 1 else 0 end) as d8, '
            .'sum(case when created_at > ? then 1 else 0 end) as d15, sum(case when created_at > ? then 1 else 0 end) as d31', [$cut(3), $cut(8), $cut(15), $cut(31)])->first();
        $total = (int) $c->total; $d3 = (int) $c->d3; $d8 = (int) $c->d8; $d15 = (int) $c->d15; $d31 = (int) $c->d31;
        $buckets = ['0-2' => $d3, '3-7' => $d8 - $d3, '8-14' => $d15 - $d8, '15-30' => $d31 - $d15, '31+' => $total - $d31];
        $rows = $base()->orderBy('created_at')->orderBy('id')->limit(200)->get();
        $list = [];
        foreach ($this->rows($rows) as $r) {
            $age = (int) \Illuminate\Support\Carbon::parse($r['created_at'])->diffInDays($now, true);
            $list[] = ['id' => $r['id'], 'title' => $r['title'], 'location' => $r['location'], 'asset_code' => $r['asset_code'], 'status' => $r['status'], 'urgency' => $r['urgency'], 'age_days' => $age, 'assigned_to_name' => $r['assigned_to_name']];
        }
        usort($list, fn ($a, $b) => $b['age_days'] <=> $a['age_days'] ?: $a['id'] <=> $b['id']);
        return ['buckets' => $buckets, 'total' => $total, 'data' => $list];
    }

    public function costByMonth(int $year): array
    {
        $this->requireTech();
        $rows = DB::table('mnt_requests')->where('school_id', self::school())->where('status', 'done')
            ->where('closed_at', '>=', sprintf('%04d-01-01 00:00:00', $year))->where('closed_at', '<', sprintf('%04d-01-01 00:00:00', $year + 1))->get(['closed_at', 'cost_satang']);
        $months = [];
        for ($m = 1; $m <= 12; $m++) $months[$m] = ['month' => $m, 'count' => 0, 'cost_satang' => 0];
        foreach ($rows as $r) { $m = (int) substr((string) $r->closed_at, 5, 2); $months[$m]['count']++; $months[$m]['cost_satang'] += (int) $r->cost_satang; }
        return ['year' => $year, 'months' => array_values($months), 'total_count' => $rows->count(), 'total_cost_satang' => array_sum(array_column($months, 'cost_satang'))];
    }

    public function stockBelow(): array
    {
        $this->requireTech();
        return ['data' => DB::table('mnt_stock_items')->where('school_id', self::school())->where('reorder_level', '>', 0)->whereColumn('qty_on_hand', '<=', 'reorder_level')
            ->orderBy('code')->get()->map(fn ($i) => $this->itemRow($i))->all()];
    }

    // ---- export + labels ------------------------------------------------------------------------------------------

    /** CSV cell: a text starting with = + - @ tab or CR would run as a spreadsheet formula, so it is prefixed with an apostrophe. */
    public static function cell(mixed $v): mixed
    {
        if ($v === null || is_int($v) || is_float($v)) return $v;
        $s = (string) $v;
        return preg_match('/^[=+\-@\t\r]/', $s) ? "'".$s : $s;
    }

    private static function baht(?int $satang): string { return $satang === null ? '' : intdiv($satang, 100).'.'.str_pad((string) ($satang % 100), 2, '0', STR_PAD_LEFT); }

    /** @return array{0:string,1:array,2:\Generator} filename, header row, row generator */
    public function exportRows(string $what): array
    {
        $u = $this->requireTech();
        Mutation::audit('maintenance.export', 'mnt_'.$what, null, ['by' => $u->id]);
        $school = self::school();
        $cap = 20000;
        if ($what === 'requests') {
            $gen = (function () use ($school, $cap) {
                $names = $this->userNames(DB::table('mnt_requests')->where('school_id', $school)->limit($cap)->pluck('reported_by')->all());
                foreach (DB::table('mnt_requests as r')->leftJoin('mnt_assets as a', 'a.id', '=', 'r.asset_id')->where('r.school_id', $school)->orderBy('r.id')->limit($cap)
                    ->get(['r.*', 'a.asset_code as acode']) as $r)
                    yield [$r->id, $r->created_at, $r->closed_at, self::STATUS[$r->status] ?? $r->status, self::URGENCY[$r->urgency] ?? $r->urgency, $r->acode, $r->location, $r->title,
                        $names[$r->reported_by] ?? '', $r->resolution, self::baht($r->cost_satang === null ? null : (int) $r->cost_satang)];
            })();
            return ['maintenance-requests.csv', ['เลขที่', 'แจ้งเมื่อ', 'ปิดเมื่อ', 'สถานะ', 'ความเร่งด่วน', 'เลขครุภัณฑ์', 'สถานที่', 'เรื่อง', 'ผู้แจ้ง', 'ผลการซ่อม/เหตุผล', 'ค่าใช้จ่าย (บาท)'], $gen];
        }
        if ($what === 'assets') {
            $gen = (function () use ($school, $cap) {
                foreach (DB::table('mnt_assets')->where('school_id', $school)->orderBy('asset_code')->limit($cap)->get() as $a)
                    yield [$a->asset_code, $a->name, $a->category, $a->location, $a->acquired_on, self::baht($a->price_satang === null ? null : (int) $a->price_satang), self::ASSET_STATUS[$a->status] ?? $a->status];
            })();
            return ['maintenance-assets.csv', ['เลขครุภัณฑ์', 'ชื่อ', 'หมวด', 'สถานที่', 'วันที่ได้มา', 'ราคา (บาท)', 'สถานะ'], $gen];
        }
        if ($what === 'stock') {
            $gen = (function () use ($school, $cap) {
                foreach (DB::table('mnt_stock_items')->where('school_id', $school)->orderBy('code')->limit($cap)->get() as $i)
                    yield [$i->code, $i->name, $i->unit, (int) $i->qty_on_hand, (int) $i->reorder_level, (int) $i->reorder_level > 0 && (int) $i->qty_on_hand <= (int) $i->reorder_level ? 'ถึงจุดสั่งซื้อ' : ''];
            })();
            return ['maintenance-stock.csv', ['รหัส', 'ชื่อ', 'หน่วย', 'คงเหลือ', 'จุดสั่งซื้อ', 'หมายเหตุ'], $gen];
        }
        $gen = (function () use ($school, $cap) {
            foreach (DB::table('mnt_stock_moves as m')->join('mnt_stock_items as i', 'i.id', '=', 'm.item_id')->where('m.school_id', $school)->orderBy('m.id')->limit($cap)->get(['m.*', 'i.code as icode', 'i.name as iname']) as $m)
                yield [$m->at, $m->icode, $m->iname, self::MOVE_KINDS[$m->kind] ?? $m->kind, (int) $m->qty, $m->ref, $m->note];
        })();
        return ['maintenance-stock-moves.csv', ['เมื่อ', 'รหัส', 'ชื่อ', 'ประเภท', 'จำนวน', 'อ้างอิง', 'หมายเหตุ'], $gen];
    }

    public function labelData(array $p): array
    {
        $this->requireTech();
        $q = DB::table('mnt_assets')->where('school_id', self::school())->where('status', '!=', 'disposed');
        if (!empty($p['ids'])) $q->whereIn('id', array_slice(array_map('intval', (array) $p['ids']), 0, 500));
        if (!empty($p['category'])) $q->where('category', $p['category']);
        $rows = $q->orderBy('asset_code')->limit(500)->get(['asset_code', 'name', 'location']);
        return ['school' => (string) (app(\App\Domain\CmsPublishing::class)->theme(self::school())['published']['school_name'] ?? config('saan.display', 'โรงเรียน')),
            'labels' => $rows->map(fn ($a) => ['code' => $a->asset_code, 'name' => $a->name, 'location' => (string) $a->location])->all(), 'printedOn' => self::thaiDate()];
    }

    /** e.g. "6 พ.ย. 2569" (Buddhist era, same style as the staff UI). */
    public static function thaiDate(?\Carbon\CarbonInterface $d = null): string
    {
        $d ??= now('Asia/Bangkok');
        $m = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        return $d->day.' '.$m[$d->month - 1].' '.($d->year + 543);
    }
}
