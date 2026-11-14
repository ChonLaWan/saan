<?php

namespace Saan\Modules\Constructionism;

use App\Support\Saan\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** School-editable rubric (Settings key constructionism.rubric). Four levels per criterion, 1 (needs support) to 4 (excellent). */
final class Rubric
{
    public const KEY = 'constructionism.rubric';
    public const LEVELS = [1 => 'ต้องพัฒนา', 2 => 'พอใช้', 3 => 'ดี', 4 => 'ดีเยี่ยม'];
    private const DEFAULTS = [
        ['key' => 'inquiry', 'label' => 'การตั้งคำถามและสืบค้น'],
        ['key' => 'making', 'label' => 'การลงมือสร้างชิ้นงาน'],
        ['key' => 'teamwork', 'label' => 'การทำงานร่วมกับผู้อื่น'],
        ['key' => 'iteration', 'label' => 'การทดสอบและปรับปรุงงาน'],
        ['key' => 'sharing', 'label' => 'การนำเสนอและแบ่งปัน'],
    ];

    /** @return array<int,array{key:string,label:string,levels:string[]}> */
    public static function criteria(): array
    {
        $saved = Settings::get(self::KEY);
        $list = is_array($saved['criteria'] ?? null) && $saved['criteria'] ? $saved['criteria'] : self::DEFAULTS;
        return array_values(array_map(fn ($c) => ['key' => (string) $c['key'], 'label' => (string) $c['label'], 'levels' => array_values(array_map('strval', (array) ($c['levels'] ?? [])))], $list));
    }

    public static function isCustom(): bool
    {
        return is_array(Settings::get(self::KEY));
    }

    /** Optimistic token: Settings has no version API for callers, so writers compare this digest of the effective criteria. */
    public static function revision(): string
    {
        return sha1(json_encode(self::criteria(), JSON_UNESCAPED_UNICODE));
    }

    private static function invalid(string $field, string $m): never
    {
        throw ValidationException::withMessages([$field => [$m]]);
    }

    public static function save(array $input, string $revision): array
    {
        // check, scored-criteria guard and write share one transaction; the settings row (or the reflections read) is locked first
        return DB::transaction(function () use ($input, $revision) {
            DB::table('saan_settings')->where('school_id', (int) config('school.school_id'))->where('key', self::KEY)->lockForUpdate()->first();
            return self::saveLocked($input, $revision);
        });
    }

    private static function saveLocked(array $input, string $revision): array
    {
        if (!hash_equals(self::revision(), $revision)) abort(409, 'รูบริกถูกแก้ไขโดยผู้อื่นแล้ว กรุณาโหลดใหม่');
        $out = [];
        $keys = [];
        $labels = [];
        foreach (array_values($input) as $i => $c) {
            $key = (string) ($c['key'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/D', $key)) self::invalid('criteria.'.$i.'.key', 'รหัสเกณฑ์ต้องเป็นตัวอักษรอังกฤษพิมพ์เล็ก ตัวเลข หรือ _ (ไม่เกิน 30 ตัว)');
            $label = trim((string) preg_replace('/\s+/u', ' ', (string) ($c['label'] ?? '')));
            if ($label === '' || mb_strlen($label) > 80) self::invalid('criteria.'.$i.'.label', 'กรุณากรอกชื่อเกณฑ์ (ไม่เกิน 80 ตัวอักษร)');
            $lk = mb_strtolower($label);
            if (isset($keys[$key]) || isset($labels[$lk])) self::invalid('criteria.'.$i.'.label', 'รหัสหรือชื่อเกณฑ์ซ้ำกัน');
            $keys[$key] = $labels[$lk] = true;
            $levels = array_values((array) ($c['levels'] ?? []));
            if (count($levels) > 4) self::invalid('criteria.'.$i.'.levels', 'คำอธิบายระดับมีได้ไม่เกิน 4 ระดับ');
            foreach ($levels as $j => $l) {
                if (!is_string($l) || mb_strlen($l) > 200) self::invalid('criteria.'.$i.'.levels', 'คำอธิบายระดับต้องเป็นข้อความไม่เกิน 200 ตัวอักษร');
                $levels[$j] = trim($l);
            }
            $out[] = ['key' => $key, 'label' => $label, 'levels' => $levels];
        }
        if (count($out) < 1 || count($out) > 10) self::invalid('criteria', 'ต้องมีเกณฑ์ 1 ถึง 10 ข้อ');
        // a criterion that already has scores cannot be dropped, or those scores would silently vanish from the summaries
        $used = [];
        foreach (DB::table('con_reflections')->where('school_id', (int) config('school.school_id'))->whereNotNull('rubric')->lockForUpdate()->pluck('rubric') as $json) {
            foreach (array_keys((array) json_decode((string) $json, true)) as $k) $used[$k] = true;
        }
        foreach (self::criteria() as $old) {
            if (isset($used[$old['key']]) && !isset($keys[$old['key']])) self::invalid('criteria', 'เกณฑ์ "'.$old['label'].'" มีการให้คะแนนแล้ว ลบไม่ได้ (เปลี่ยนชื่อได้)');
        }
        Settings::put(self::KEY, ['criteria' => $out]);
        return $out;
    }

    /** Validates {criterion_key: level 1-4} against the current criteria. Unknown keys and out-of-range levels are 422. */
    public static function scores(mixed $raw, string $field = 'rubric'): ?array
    {
        if ($raw === null || $raw === [] || $raw === '') return null;
        if (!is_array($raw)) self::invalid($field, 'รูปแบบคะแนนรูบริกไม่ถูกต้อง');
        $valid = array_column(self::criteria(), 'key');
        $out = [];
        foreach ($raw as $k => $v) {
            if (!in_array((string) $k, $valid, true)) self::invalid($field, 'ไม่มีเกณฑ์ "'.$k.'" ในรูบริกของโรงเรียน');
            if ($v === null || $v === '') continue;
            if (!is_int($v) && !(is_string($v) && preg_match('/^[1-4]$/D', $v))) self::invalid($field, 'ระดับคะแนนต้องเป็นเลข 1 ถึง 4');
            $v = (int) $v;
            if ($v < 1 || $v > 4) self::invalid($field, 'ระดับคะแนนต้องเป็นเลข 1 ถึง 4');
            $out[(string) $k] = $v;
        }
        return $out ?: null;
    }
}
