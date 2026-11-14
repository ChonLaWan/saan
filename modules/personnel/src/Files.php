<?php

namespace Saan\Modules\Personnel;

use App\Support\Saan\Mutation;
use App\Support\StorageQuota;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio files. Bytes live on the private local disk under personnel/<school>/<teacher>/<folder>/<uuid>.<ext>,
 * never under public/. A teacher only ever reaches own rows; admin/principal reach every teacher.
 * Missing or foreign rows are 404 so the existence of other teachers' files is not revealed.
 */
class Files
{
    public const YEAR_MIN = 2540, YEAR_MAX = 2700;
    public const REVIEW = ['pending', 'approved', 'revise'];
    public const TYPES = ['pdf', 'docx', 'xlsx', 'pptx', 'jpg', 'png', 'mp4'];
    private const MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'mp4' => 'video/mp4',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    private const MP4_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'dash', 'M4V ', 'MSNV', 'f4v '];
    /** The zip writer uses 32-bit sizes (no zip64). */
    public const ZIP_MAX_BYTES = 3900000000, ZIP_MAX_FILES = 60000;

    // ---------------- identity ----------------

    /** The teacher record of the signed-in account, or null (admin without a linked teacher). */
    public static function ownTeacherId(): ?int
    {
        $id = auth()->user()?->teacher_id;
        if (!$id) return null;
        return DB::table('teachers')->where('school_id', Support::school())->where('id', $id)->exists() ? (int) $id : null;
    }

    /** teacher_id the request may read: teachers are pinned to themselves, reviewers pick one. */
    public static function scopeTeacher(?int $requested): ?int
    {
        if (Support::isReviewer()) return $requested;
        $own = self::ownTeacherId();
        abort_if($own === null, 403, 'บัญชีนี้ยังไม่ได้ผูกกับทะเบียนบุคลากร');
        abort_if($requested !== null && $requested !== $own, 404, 'ไม่พบข้อมูล');
        return $own;
    }

    // ---------------- type sniffing ----------------

    /** @return string|null one of TYPES, decided from the bytes only (never from the client file name or type) */
    public static function sniff(string $path): ?string
    {
        $fh = @fopen($path, 'rb'); if (!$fh) return null;
        $head = (string) fread($fh, 16); fclose($fh);
        if (str_starts_with($head, '%PDF-')) return 'pdf';
        if (str_starts_with($head, "\xFF\xD8\xFF")) return 'jpg';
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) return 'png';
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') return in_array(substr($head, 8, 4), self::MP4_BRANDS, true) ? 'mp4' : null;
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) return null;
            try {
                if ($zip->locateName('[Content_Types].xml') === false) return null;
                foreach (['word/document.xml' => 'docx', 'xl/workbook.xml' => 'xlsx', 'ppt/presentation.xml' => 'pptx'] as $entry => $type) if ($zip->locateName($entry) !== false) return $type;
            } finally { $zip->close(); }
        }
        return null;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);
        if (mb_strlen($name) > 200) { $ext = pathinfo($name, PATHINFO_EXTENSION); $name = mb_substr($name, 0, 190).($ext ? '.'.mb_substr($ext, 0, 8) : ''); }
        return $name !== '' ? $name : 'file';
    }

    // ---------------- usage ----------------

    public static function usedBytes(int $teacherId, ?int $folderId = null): int
    {
        $q = DB::table('per_files')->where('school_id', Support::school())->where('teacher_id', $teacherId)->where('status', 'active');
        if ($folderId) $q->where('folder_id', $folderId);
        return (int) $q->sum('size');
    }

    /** Bytes of every stored portfolio file of the school (active + archived). The school-wide total is StorageQuota::usedBytes(). */
    public static function schoolBytes(): int
    {
        return (int) DB::table('per_files')->where('school_id', Support::school())->whereIn('status', ['active', 'archived'])->sum('size');
    }

    public static function usage(int $teacherId): array
    {
        $quota = Support::teacherQuotaMb() * 1048576;
        $rows = DB::table('per_files')->where('school_id', Support::school())->where('teacher_id', $teacherId)->where('status', 'active')
            ->selectRaw('folder_id, count(*) as n, sum(size) as bytes')->groupBy('folder_id')->get()->keyBy('folder_id');
        $total = 0; $folders = [];
        foreach (Folders::all() as $f) {
            $r = $rows[$f['id']] ?? null; $bytes = (int) ($r->bytes ?? 0); $total += $bytes;
            $fq = ($f['quota_mb_per_teacher'] ?? 0) * 1048576;
            $folders[] = ['folder_id' => $f['id'], 'files' => (int) ($r->n ?? 0), 'used_bytes' => $bytes, 'quota_bytes' => $fq, 'percent' => $fq ? round($bytes / $fq * 100, 1) : null];
        }
        return ['teacher_id' => $teacherId, 'used_bytes' => $total, 'quota_bytes' => $quota, 'percent' => $quota ? round($total / $quota * 100, 1) : null, 'folders' => $folders];
    }

    public static function limits(): array
    {
        $srv = Support::serverLimitBytes();
        return ['types' => self::TYPES, 'server_max_bytes' => $srv === PHP_INT_MAX ? null : $srv, 'year_min' => self::YEAR_MIN, 'year_max' => self::YEAR_MAX];
    }

    // ---------------- list / present ----------------

    public static function present(object $f, array $names = []): array
    {
        return [
            'id' => (int) $f->id, 'teacher_id' => (int) $f->teacher_id, 'folder_id' => (int) $f->folder_id, 'year' => (int) $f->fiscal_or_school_year,
            'original_name' => $f->original_name, 'mime' => $f->mime, 'size' => (int) $f->size, 'status' => $f->status,
            'review_status' => $f->review_status, 'review_note' => $f->review_note, 'reviewed_at' => $f->reviewed_at,
            'reviewed_by' => $f->reviewed_by ? ($names[(int) $f->reviewed_by] ?? null) : null,
            'archived_at' => $f->archived_at, 'created_at' => $f->created_at, 'version' => (int) $f->version,
        ]; // path and sha256 stay server-side
    }

    public static function list(array $f): array
    {
        $teacher = self::scopeTeacher(isset($f['teacher_id']) ? (int) $f['teacher_id'] : null);
        $q = DB::table('per_files')->where('school_id', Support::school());
        if ($teacher !== null) $q->where('teacher_id', $teacher);
        if (!empty($f['folder_id'])) $q->where('folder_id', (int) $f['folder_id']);
        if (!empty($f['year'])) $q->where('fiscal_or_school_year', (int) $f['year']);
        if (!empty($f['review_status'])) $q->where('review_status', $f['review_status']);
        $status = $f['status'] ?? null;
        if ($status === 'deleted') abort_unless(Support::isReviewer(), 404, 'ไม่พบข้อมูล');
        $status ? $q->where('status', $status) : $q->whereIn('status', ['active', 'archived']);
        $total = (clone $q)->count();
        $per = min(100, max(1, (int) ($f['per_page'] ?? 50))); $page = max(1, (int) ($f['page'] ?? 1));
        $rows = $q->orderByDesc('fiscal_or_school_year')->orderByDesc('id')->forPage($page, $per)->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('reviewed_by')->filter()->unique()->all() ?: [0])->pluck('name', 'id')->all();
        return ['data' => $rows->map(fn ($r) => self::present($r, $names))->all(), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per]];
    }

    public static function find(int $id): object
    {
        $row = DB::table('per_files')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบไฟล์');
        if (!Support::isReviewer()) abort_unless(self::ownTeacherId() === (int) $row->teacher_id, 404, 'ไม่พบไฟล์');
        return $row;
    }

    // ---------------- upload ----------------

    public static function upload(Request $r): array
    {
        $teacher = self::ownTeacherId();
        abort_if($teacher === null, 403, 'บัญชีนี้ยังไม่ได้ผูกกับทะเบียนบุคลากร จึงอัปโหลดเข้าแฟ้มสะสมงานไม่ได้');
        $file = $r->file('file');
        $srv = Support::serverLimitBytes();
        if (!$file instanceof UploadedFile) abort(422, 'ไม่ได้รับไฟล์ อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้'.($srv === PHP_INT_MAX ? '' : ' ('.Support::mb($srv).' MB ต่อครั้ง)').' กรุณาบีบอัดหรือแบ่งไฟล์เป็นหลายส่วน');
        if (!$file->isValid()) abort(422, in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้'.($srv === PHP_INT_MAX ? '' : ' ('.Support::mb($srv).' MB ต่อครั้ง)').' กรุณาบีบอัดหรือแบ่งไฟล์เป็นหลายส่วนแล้วอัปโหลดทีละส่วน'
            : 'อัปโหลดไฟล์ไม่สำเร็จ ลองใหม่อีกครั้ง');
        $d = $r->validate(['folder_id' => 'required|integer|min:1', 'year' => 'required|integer|between:'.self::YEAR_MIN.','.self::YEAR_MAX]);
        $folder = Folders::find((int) $d['folder_id']);
        $size = (int) $file->getSize();
        abort_if($size < 1, 422, 'ไฟล์ว่างเปล่า');
        $max = (int) $folder->max_file_mb * 1048576;
        abort_if($size > $max, 422, 'ไฟล์ใหญ่เกิน '.$folder->max_file_mb.' MB สำหรับแฟ้ม "'.$folder->label.'" (ไฟล์นี้ '.Support::mb($size).' MB) กรุณาบีบอัดไฟล์ (เช่น ลดความละเอียดวิดีโอหรือรูป) หรือแบ่งเป็นหลายไฟล์แล้วอัปโหลดทีละส่วน');
        $path = $file->getRealPath();
        $type = self::sniff($path);
        abort_if($type === null, 422, 'รองรับเฉพาะไฟล์ '.implode(', ', array_map('strtoupper', self::TYPES)).' (ตรวจจากเนื้อไฟล์จริง)');
        $sha = hash_file('sha256', $path);
        $dup = DB::table('per_files')->where('school_id', Support::school())->where('teacher_id', $teacher)->where('dedupe_key', $sha)->where('status', '!=', 'deleted')->first();
        if ($dup) abort(409, 'ไฟล์นี้อยู่ในแฟ้มของคุณแล้ว ('.$dup->original_name.')');

        $stored = 'personnel/'.Support::school().'/'.$teacher.'/'.(int) $folder->id.'/'.bin2hex(random_bytes(16)).'.'.$type;
        $stream = fopen($path, 'rb');
        try { abort_unless(Support::disk()->put($stored, $stream), 500, 'บันทึกไฟล์ไม่สำเร็จ'); } finally { if (is_resource($stream)) fclose($stream); }
        try {
            // quota checks and the insert are one critical section per teacher (two tabs in the March PA peak cannot both pass)
            $row = Cache::lock('per-quota:'.Support::school().':'.$teacher, 60)->block(10, function () use ($folder, $teacher, $size, $stored, $type, $sha, $d, $file) {
                if ($folder->quota_mb_per_teacher !== null && self::usedBytes($teacher, (int) $folder->id) + $size > (int) $folder->quota_mb_per_teacher * 1048576)
                    abort(422, 'พื้นที่ของแฟ้ม "'.$folder->label.'" เต็ม (ใช้ได้ '.$folder->quota_mb_per_teacher.' MB ต่อคน) ลบไฟล์ที่ไม่ใช้แล้วก่อน');
                $quota = Support::teacherQuotaMb();
                if ($quota > 0 && self::usedBytes($teacher) + $size > $quota * 1048576)
                    abort(422, 'พื้นที่แฟ้มสะสมงานของคุณเต็ม (ใช้ได้ '.$quota.' MB) ลบไฟล์ที่ไม่ใช้แล้ว หรือให้ผู้ดูแลเพิ่มโควตา');
                // school-wide total (media + every module's storage_tables); the file is on disk but not yet in per_files, so only $size is new
                StorageQuota::assertRoom($size);
                return Support::unique(fn () => Mutation::insert('per_files', [
                    'teacher_id' => $teacher, 'folder_id' => (int) $folder->id, 'fiscal_or_school_year' => (int) $d['year'],
                    'original_name' => self::cleanName($file->getClientOriginalName()), 'path' => $stored, 'mime' => self::MIME[$type], 'size' => $size,
                    'sha256' => $sha, 'dedupe_key' => $sha, 'status' => 'active', 'review_status' => 'pending', 'uploaded_by' => auth()->id(),
                ], 'personnel.file.uploaded', ['folder_id' => (int) $folder->id, 'year' => (int) $d['year'], 'size' => $size]), 'ไฟล์นี้อยู่ในแฟ้มของคุณแล้ว');
            });
        } catch (\Throwable $e) {
            Support::disk()->delete($stored);
            throw $e;
        }
        return self::present($row);
    }

    // ---------------- download / delete / review ----------------

    public static function download(int $id)
    {
        $f = self::find($id);
        abort_if($f->status === 'deleted', 404, 'ไฟล์ถูกลบแล้ว');
        abort_unless(Support::disk()->exists($f->path), 404, 'ไม่พบไฟล์ในที่เก็บ');
        if (self::ownTeacherId() !== (int) $f->teacher_id) Mutation::audit('personnel.file.downloaded', 'per_files', (int) $f->id, ['teacher_id' => (int) $f->teacher_id, 'folder_id' => (int) $f->folder_id]);
        $ext = pathinfo($f->path, PATHINFO_EXTENSION);
        return Support::disk()->download($f->path, self::cleanName($f->original_name) ?: ('file-'.$f->id.'.'.$ext), ['Content-Type' => $f->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    public static function delete(int $id, int $version): void
    {
        $f = self::find($id);
        abort_if($f->status === 'deleted', 404, 'ไฟล์ถูกลบแล้ว');
        abort_if($f->review_status === 'approved' && !Support::isReviewer(), 422, 'ไฟล์ที่ตรวจรับแล้วลบเองไม่ได้ ติดต่อผู้ตรวจ');
        Support::guard(fn () => Mutation::update('per_files', $id, $version, ['status' => 'deleted', 'dedupe_key' => null], 'personnel.file.deleted', ['teacher_id' => (int) $f->teacher_id, 'size' => (int) $f->size]), ['path', 'sha256', 'dedupe_key']);
        Support::disk()->delete($f->path); // the row stays (audit, review history); the bytes are released
    }

    public static function review(int $id, int $version, string $status, ?string $note): array
    {
        abort_unless(Support::isReviewer(), 403, 'ไม่มีสิทธิ์ตรวจแฟ้ม');
        $f = self::find($id);
        abort_if($f->status === 'deleted', 404, 'ไฟล์ถูกลบแล้ว');
        abort_if(self::ownTeacherId() === (int) $f->teacher_id, 403, 'ไม่สามารถตรวจแฟ้มของตนเอง');
        $note = $note === null ? null : trim($note);
        abort_if($status === 'revise' && ($note === null || $note === ''), 422, 'กรุณาระบุข้อความที่ต้องแก้ไข');
        $pending = $status === 'pending';
        $row = Support::guard(fn () => Mutation::update('per_files', $id, $version, [
            'review_status' => $status, 'review_note' => $note === '' ? null : $note,
            'reviewed_by' => $pending ? null : auth()->id(), 'reviewed_at' => $pending ? null : now(),
        ], 'personnel.file.reviewed', ['teacher_id' => (int) $f->teacher_id, 'review_status' => $status]), ['path', 'sha256', 'dedupe_key']);
        $names = DB::table('users')->where('id', $row->reviewed_by ?? 0)->pluck('name', 'id')->all();
        return self::present($row, $names);
    }

    // ---------------- zip of a teacher's year ----------------

    public static function zipResponse(int $teacherId, int $year, ?int $folderId)
    {
        if (!Support::isReviewer()) abort_unless(self::ownTeacherId() === $teacherId, 404, 'ไม่พบข้อมูล');
        $teacher = Support::teacher($teacherId);
        $q = DB::table('per_files')->where('school_id', Support::school())->where('teacher_id', $teacherId)->where('fiscal_or_school_year', $year)->whereIn('status', ['active', 'archived']);
        if ($folderId) $q->where('folder_id', $folderId);
        $files = $q->orderBy('folder_id')->orderBy('id')->get();
        abort_if($files->isEmpty(), 404, 'ไม่มีไฟล์ในปีนี้');
        abort_if($files->count() > self::ZIP_MAX_FILES || $files->sum('size') > self::ZIP_MAX_BYTES, 422, 'ไฟล์รวมใหญ่เกินกว่าจะรวมเป็น zip เดียว กรุณาเลือกทีละแฟ้ม');
        $labels = DB::table('per_folders')->where('school_id', Support::school())->pluck('label', 'id')->all();
        Mutation::audit('personnel.zip.downloaded', 'teachers', $teacherId, ['year' => $year, 'files' => $files->count(), 'folder_id' => $folderId]);
        $disk = Support::disk();
        $name = 'portfolio-'.$teacherId.'-'.$year.'.zip';
        return response()->streamDownload(function () use ($files, $labels, $disk) {
            $zip = new ZipWriter(function (string $chunk) { echo $chunk; if (ob_get_level() === 0) flush(); });
            $used = [];
            foreach ($files as $f) {
                $stream = $disk->readStream($f->path);
                if (!is_resource($stream)) continue; // missing bytes: skip, the rest is still delivered
                $entry = self::entryName(($labels[$f->folder_id] ?? 'folder-'.$f->folder_id), $f->original_name, (int) $f->id, $used);
                $zip->addStream($entry, $stream, strtotime((string) $f->created_at) ?: time());
                fclose($stream);
            }
            $zip->finish();
        }, $name, ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private static function entryName(string $folder, string $name, int $id, array &$used): string
    {
        $safe = fn (string $s) => trim((string) preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '_', $s), ". \t") ?: 'x';
        $base = $safe($folder).'/'.$safe(self::cleanName($name));
        if (isset($used[mb_strtolower($base)])) {
            $ext = pathinfo($base, PATHINFO_EXTENSION);
            $base = ($ext !== '' ? substr($base, 0, -strlen($ext) - 1) : $base).'-'.$id.($ext !== '' ? '.'.$ext : '');
        }
        $used[mb_strtolower($base)] = true;
        return $base;
    }
}
