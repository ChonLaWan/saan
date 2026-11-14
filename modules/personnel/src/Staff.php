<?php

namespace Saan\Modules\Personnel;

use App\Support\Saan\Mutation;
use App\Support\StorageQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Staff profiles (HR record that feeds the public directory), photo derivative, directory output. */
class Staff
{
    public const FIELDS = ['position', 'academic_standing', 'department', 'work_group', 'start_date', 'education', 'show_in_directory', 'directory_order'];
    private const HIDE = ['photo_path'];

    private static function pub() { return Storage::disk('public'); }

    /**
     * Photos live only on the private disk and are streamed by the app route GET /portal/staff-photo/{hex}.jpg, which checks
     * directory visibility (or an admin/principal session). No public/storage symlink is needed and nothing is published by URL.
     */
    public static function photoUrl(?string $path): ?string
    {
        if (!$path || !preg_match('/^personnel\/\d+\/([0-9a-f]{32}\.jpg)$/D', $path, $m)) return null;
        return url('/portal/staff-photo/'.$m[1]);
    }

    /** Resolve a requested file name to its private path when the viewer may see it, else null. */
    public static function photoPathFor(string $file, bool $isStaff): ?string
    {
        if (!preg_match('/^[0-9a-f]{32}\.jpg$/D', $file)) return null;
        $path = 'personnel/'.Support::school().'/'.$file;
        $p = DB::table('per_profiles')->where('school_id', Support::school())->where('photo_path', $path)->first();
        if (!$p) return null;
        if (!$isStaff) {
            if (!$p->show_in_directory) return null;
            if (!Support::activeTeachers()->where('teachers.id', $p->teacher_id)->exists()) return null;
        }
        return Support::disk()->exists($path) ? $path : null;
    }

    public static function presentProfile(?object $p): ?array
    {
        if (!$p) return null;
        $edu = $p->education ? json_decode((string) $p->education, true) : null;
        return ['id' => (int) $p->id, 'teacher_id' => (int) $p->teacher_id, 'position' => $p->position, 'academic_standing' => $p->academic_standing,
            'department' => $p->department, 'work_group' => $p->work_group, 'start_date' => $p->start_date, 'education' => is_array($edu) ? $edu : [],
            'photo_url' => self::photoUrl($p->photo_path), 'show_in_directory' => (bool) $p->show_in_directory, 'directory_order' => (int) $p->directory_order,
            'version' => (int) $p->version];
    }

    public static function profile(int $teacherId): ?object
    {
        return DB::table('per_profiles')->where('school_id', Support::school())->where('teacher_id', $teacherId)->first();
    }

    /** Teachers with their HR profile, portfolio usage and pending-review count. */
    public static function list(array $f): array
    {
        $school = Support::school();
        $q = Support::activeTeachers()->leftJoin('per_profiles as p', fn ($j) => $j->on('p.teacher_id', '=', 'teachers.id')->where('p.school_id', '=', $school))
            ->select('teachers.*', 'p.id as p_id', 'p.position', 'p.department', 'p.work_group', 'p.show_in_directory', 'p.directory_order', 'p.version as p_version');
        if (!empty($f['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($f['q'])).'%';
            $q->where(fn ($w) => $w->where('teachers.name', 'like', $like)->orWhere('p.position', 'like', $like)->orWhere('p.department', 'like', $like)->orWhere('p.work_group', 'like', $like));
        }
        $total = (clone $q)->count('teachers.id');
        $per = min(100, max(1, (int) ($f['per_page'] ?? 50))); $page = max(1, (int) ($f['page'] ?? 1));
        $rows = $q->orderBy('teachers.id')->forPage($page, $per)->get();
        $ids = $rows->pluck('id')->all() ?: [0];
        $use = DB::table('per_files')->where('school_id', $school)->whereIn('teacher_id', $ids)->where('status', 'active')
            ->selectRaw("teacher_id, count(*) as n, sum(size) as bytes, sum(case when review_status = 'pending' then 1 else 0 end) as pending")->groupBy('teacher_id')->get()->keyBy('teacher_id');
        $quota = Support::teacherQuotaMb() * 1048576;
        $data = $rows->map(function ($t) use ($use, $quota) {
            $u = $use[$t->id] ?? null; $bytes = (int) ($u->bytes ?? 0);
            return ['teacher_id' => (int) $t->id, 'name' => Support::teacherName($t), 'subject' => $t->subject ?? null,
                'profile' => $t->p_id ? ['position' => $t->position, 'department' => $t->department, 'work_group' => $t->work_group, 'show_in_directory' => (bool) $t->show_in_directory,
                    'directory_order' => (int) $t->directory_order, 'version' => (int) $t->p_version] : null,
                'files' => (int) ($u->n ?? 0), 'used_bytes' => $bytes, 'quota_bytes' => $quota, 'percent' => $quota ? round($bytes / $quota * 100, 1) : null, 'pending_review' => (int) ($u->pending ?? 0)];
        })->all();
        return ['data' => $data, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per]];
    }

    public static function detail(int $teacherId): array
    {
        $t = Support::teacher($teacherId);
        return ['teacher' => ['id' => (int) $t->id, 'name' => Support::teacherName($t), 'subject' => $t->subject ?? null], 'profile' => self::presentProfile(self::profile($teacherId)), 'usage' => Files::usage($teacherId)];
    }

    /** Create the profile (no version) or update it (version required). */
    public static function save(int $teacherId, ?int $version, array $d): array
    {
        Support::teacher($teacherId);
        $ch = array_intersect_key($d, array_flip(self::FIELDS));
        foreach (['position', 'academic_standing', 'department', 'work_group'] as $k) if (array_key_exists($k, $ch)) { $ch[$k] = trim((string) $ch[$k]); if ($ch[$k] === '') $ch[$k] = null; }
        if (isset($ch['education'])) $ch['education'] = array_values($ch['education']);
        $cur = self::profile($teacherId);
        if ($cur && $cur->photo_path && array_key_exists('show_in_directory', $ch) && !$ch['show_in_directory']) self::pub()->delete($cur->photo_path); // withdrawn consent: drop any copy published by an older release
        if ($cur) {
            abort_if($version === null, 422, 'ต้องระบุ version ของข้อมูลบุคลากร');
            $row = Support::guard(fn () => Mutation::update('per_profiles', (int) $cur->id, $version, $ch, 'personnel.profile.updated', ['teacher_id' => $teacherId, 'fields' => array_keys($ch)]), self::HIDE);
        } else {
            $row = Support::unique(fn () => Mutation::insert('per_profiles', ['teacher_id' => $teacherId] + $ch + ['show_in_directory' => true, 'directory_order' => 0],
                'personnel.profile.created', ['teacher_id' => $teacherId]), 'ข้อมูลบุคลากรนี้ถูกสร้างไปแล้ว กรุณาโหลดใหม่');
        }
        return self::presentProfile($row);
    }

    // ---------------- photo ----------------

    /** Re-encode to a 480 px square JPEG (strips EXIF and any hidden payload) and publish only that derivative. */
    public static function setPhoto(int $teacherId, int $version, UploadedFile $file): array
    {
        $cur = self::profile($teacherId) ?? abort(422, 'กรุณาบันทึกข้อมูลบุคลากรก่อนแล้วจึงเพิ่มรูป');
        abort_unless($file->isValid(), 422, 'อัปโหลดรูปไม่สำเร็จ');
        $size = (int) $file->getSize();
        abort_if($size < 1 || $size > 5 * 1048576, 422, 'รูปต้องมีขนาดไม่เกิน 5 MB');
        $info = @getimagesize($file->getRealPath());
        abort_unless($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true), 422, 'รองรับเฉพาะรูป JPG, PNG หรือ WebP');
        abort_if($info[0] * $info[1] > 16000000 || $info[0] < 32 || $info[1] < 32, 422, 'ขนาดรูปไม่เหมาะสม (ด้านละ 32 พิกเซลขึ้นไป และไม่เกิน 16 ล้านพิกเซล) กรุณาย่อรูปให้ไม่เกิน 4000 x 4000 พิกเซล');
        // GD truecolor needs about 5 bytes per pixel: refuse before imagecreatefrom*() can exhaust memory_limit (a fatal, not an exception)
        $need = $info[0] * $info[1] * 5 + 480 * 480 * 5 + 8 * 1048576;
        $limit = function_exists('ini_parse_quantity') ? ini_parse_quantity((string) ini_get('memory_limit')) : 0;
        abort_if($limit > 0 && memory_get_usage() + $need > $limit, 422, 'รูปใหญ่เกินไป กรุณาย่อรูปให้ไม่เกิน 4000 x 4000 พิกเซล');
        $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()), IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()), default => @imagecreatefromwebp($file->getRealPath()) };
        abort_unless($src, 422, 'อ่านรูปไม่ได้');
        $side = min($info[0], $info[1]); $out = 480;
        $dst = imagecreatetruecolor($out, $out);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, intdiv($info[0] - $side, 2), intdiv($info[1] - $side, 2), $out, $out, $side, $side);
        ob_start(); imagejpeg($dst, null, 82); $jpeg = (string) ob_get_clean();
        unset($src, $dst);
        $path = 'personnel/'.Support::school().'/'.bin2hex(random_bytes(16)).'.jpg';
        // private disk only (covered by school:archive, private_storage "personnel"); served through an access-checked app route
        abort_unless(Support::disk()->put($path, $jpeg), 500, 'บันทึกรูปไม่สำเร็จ');
        try {
            $row = Support::guard(fn () => Mutation::update('per_profiles', (int) $cur->id, $version, ['photo_path' => $path], 'personnel.profile.photo', ['teacher_id' => $teacherId]), self::HIDE);
        } catch (\Throwable $e) { self::dropPhoto($path); throw $e; }
        if ($cur->photo_path) self::dropPhoto($cur->photo_path);
        return self::presentProfile($row);
    }

    public static function removePhoto(int $teacherId, int $version): array
    {
        $cur = self::profile($teacherId) ?? abort(404, 'ไม่พบข้อมูลบุคลากร');
        $row = Support::guard(fn () => Mutation::update('per_profiles', (int) $cur->id, $version, ['photo_path' => null], 'personnel.profile.photo_removed', ['teacher_id' => $teacherId]), self::HIDE);
        if ($cur->photo_path) self::dropPhoto($cur->photo_path);
        return self::presentProfile($row);
    }

    private static function dropPhoto(string $path): void
    {
        self::pub()->delete($path);
        Support::disk()->delete($path);
    }

    // ---------------- public directory (G3) ----------------

    /** Public-safe staff list. Only these keys ever leave the server: name, position, department, work_group, photo_url, order. */
    public static function directory(): array
    {
        $school = Support::school();
        // only teachers that have a profile row with show_in_directory: a teacher with no profile is never published
        $q = Support::activeTeachers()
            ->join('per_profiles as p', fn ($j) => $j->on('p.teacher_id', '=', 'teachers.id')->where('p.school_id', '=', $school))
            ->where('p.show_in_directory', true);
        $cols = ['teachers.name'];
        foreach (['prefix', 'first_name', 'last_name'] as $c) if (Schema::hasColumn('teachers', $c)) $cols[] = 'teachers.'.$c;
        return $q->orderByRaw('coalesce(p.directory_order, 0)')->orderBy('teachers.id')->limit(1000)
            ->select(array_merge($cols, ['p.position', 'p.department', 'p.work_group', 'p.photo_path', 'p.directory_order']))
            ->get()->map(fn ($t) => [
                'name' => Support::teacherName($t), 'position' => $t->position, 'department' => $t->department, 'work_group' => $t->work_group,
                'photo_url' => self::photoUrl($t->photo_path), 'order' => (int) ($t->directory_order ?? 0),
            ])->all();
    }

    // ---------------- summary for admins ----------------

    public static function summary(): array
    {
        $school = Support::school();
        $byFolder = DB::table('per_files')->where('school_id', $school)->whereIn('status', ['active', 'archived'])
            ->selectRaw('folder_id, status, count(*) as n, sum(size) as bytes')->groupBy('folder_id', 'status')->get();
        $labels = DB::table('per_folders')->where('school_id', $school)->pluck('label', 'id')->all();
        $folders = [];
        foreach ($byFolder as $r) { $k = (int) $r->folder_id; $folders[$k] ??= ['folder_id' => $k, 'label' => $labels[$k] ?? '-', 'active_files' => 0, 'active_bytes' => 0, 'archived_files' => 0, 'archived_bytes' => 0];
            $folders[$k][$r->status.'_files'] = (int) $r->n; $folders[$k][$r->status.'_bytes'] = (int) $r->bytes; }
        return ['school' => StorageQuota::summary($school) + ['personnel_bytes' => Files::schoolBytes()], 'teacher_quota_mb' => Support::teacherQuotaMb(),
            'pending_review' => (int) DB::table('per_files')->where('school_id', $school)->where('status', 'active')->where('review_status', 'pending')->count(),
            'folders' => array_values($folders)];
    }
}
