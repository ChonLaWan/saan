<?php

namespace Saan\Modules\Personnel;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\DB;

/** Portfolio categories (per_folders). Seeded lazily per school with editable Thai defaults. */
class Folders
{
    /** code, label, retention_years, max_file_mb */
    public const DEFAULTS = [
        ['personal', 'ข้อมูลส่วนตัว', null, 20],
        ['lesson-plan', 'แผนการจัดการเรียนรู้', 5, 50],
        ['pa-agreement', 'PA ข้อตกลงในการพัฒนางาน', null, 50],
        ['pa-report', 'PA รายงานผลการพัฒนางาน', null, 100],
        ['student-work', 'ผลงานนักเรียน', 3, 100],
        ['innovation-media', 'สื่อนวัตกรรม', 5, 300],
        ['self-development', 'การพัฒนาตนเอง', null, 50],
        ['certificate', 'เกียรติบัตร', null, 20],
        ['classroom-research', 'วิจัยในชั้นเรียน', null, 100],
        ['assessment-tools', 'เครื่องมือวัดและประเมินผล', 5, 50],
        ['teaching-record', 'บันทึกหลังการสอน', 3, 50],
        ['homeroom', 'งานที่ปรึกษาและงานประจำชั้น', 3, 50],
        ['other', 'อื่น ๆ', 3, 50],
    ];

    public static function ensure(): void
    {
        $school = Support::school();
        // seed once: an admin who deliberately deleted every folder must not get the 13 defaults back silently
        if (Settings::get('personnel.folders_seeded')) return;
        if (DB::table('per_folders')->where('school_id', $school)->exists()) { Settings::put('personnel.folders_seeded', 1); return; }
        $now = now();
        foreach (self::DEFAULTS as $i => [$code, $label, $years, $max]) {
            DB::table('per_folders')->insertOrIgnore([
                'school_id' => $school, 'code' => $code, 'label' => $label, 'label_key' => Support::key($label),
                'retention_years' => $years, 'max_file_mb' => $max, 'quota_mb_per_teacher' => null, 'sort' => ($i + 1) * 10,
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        Settings::put('personnel.folders_seeded', 1);
    }

    public static function all(): array
    {
        self::ensure();
        return DB::table('per_folders')->where('school_id', Support::school())->orderBy('sort')->orderBy('id')->get()->map(fn ($f) => self::present($f))->all();
    }

    public static function find(int $id): object
    {
        self::ensure();
        return DB::table('per_folders')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบแฟ้ม');
    }

    public static function present(object $f): array
    {
        return ['id' => (int) $f->id, 'code' => $f->code, 'label' => $f->label, 'retention_years' => $f->retention_years === null ? null : (int) $f->retention_years,
            'max_file_mb' => (int) $f->max_file_mb, 'quota_mb_per_teacher' => $f->quota_mb_per_teacher === null ? null : (int) $f->quota_mb_per_teacher,
            'sort' => (int) $f->sort, 'version' => (int) $f->version];
    }

    public static function create(array $d): array
    {
        self::ensure();
        self::assertLabelFree($d['label']);
        $code = $d['code'] ?? null;
        if (!$code) $code = 'f-'.bin2hex(random_bytes(4));
        $dup = DB::table('per_folders')->where('school_id', Support::school())->where('code', $code)->exists();
        abort_if($dup, 409, 'รหัสแฟ้มนี้มีอยู่แล้ว');
        $sort = (int) DB::table('per_folders')->where('school_id', Support::school())->max('sort') + 10;
        $row = Support::unique(fn () => Mutation::insert('per_folders', [
            'code' => $code, 'label' => trim($d['label']), 'label_key' => Support::key($d['label']),
            'retention_years' => $d['retention_years'] ?? null, 'max_file_mb' => $d['max_file_mb'] ?? 20,
            'quota_mb_per_teacher' => $d['quota_mb_per_teacher'] ?? null, 'sort' => $d['sort'] ?? $sort,
        ], 'personnel.folder.created', ['code' => $code]), 'ชื่อหรือรหัสแฟ้มซ้ำกับแฟ้มที่มีอยู่');
        return self::present($row);
    }

    public static function update(int $id, int $version, array $d): array
    {
        $cur = self::find($id);
        $ch = array_intersect_key($d, array_flip(['retention_years', 'max_file_mb', 'quota_mb_per_teacher', 'sort']));
        if (isset($d['label'])) {
            self::assertLabelFree($d['label'], $id);
            $ch['label'] = trim($d['label']); $ch['label_key'] = Support::key($d['label']);
        }
        $row = Support::unique(fn () => Mutation::update('per_folders', $id, $version, $ch, 'personnel.folder.updated', ['code' => $cur->code, 'fields' => array_keys($ch)]), 'ชื่อแฟ้มซ้ำกับแฟ้มที่มีอยู่');
        return self::present($row);
    }

    public static function delete(int $id, int $version): void
    {
        $cur = self::find($id);
        abort_if(DB::table('per_files')->where('school_id', Support::school())->where('folder_id', $id)->exists(), 409, 'แฟ้มนี้มีไฟล์อยู่ ลบไม่ได้ (ปรับชื่อหรือเรียงลำดับแทน)');
        Mutation::delete('per_folders', $id, $version, 'personnel.folder.deleted');
    }

    private static function assertLabelFree(string $label, ?int $except = null): void
    {
        $q = DB::table('per_folders')->where('school_id', Support::school())->where('label_key', Support::key($label));
        if ($except) $q->where('id', '!=', $except);
        abort_if($q->exists(), 409, 'มีแฟ้มชื่อนี้อยู่แล้ว');
    }
}
