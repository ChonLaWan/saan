<?php

namespace Saan\Modules\Connectors;

/**
 * Header presets for legacy exports. Headers are compared after Presets::headerKey (lower case, spaces and _-.() removed).
 *
 * generic_th: CSV with Thai headers, e.g. เลขประจำตัวนักเรียน, ชื่อ, นามสกุล, ชั้น, ห้อง (plus the usual grade and attendance columns).
 *
 * dmc_like (ASSUMED layout, fictional, not a real vendor specification). A school-side export that looks like a ministry data-centre dump:
 *   STUDENT_ID  PRENAME  FIRSTNAME  LASTNAME  SEX (1 = male, 2 = female)  BIRTHDATE (YYYY-MM-DD or YYYYMMDD, either calendar)
 *   CLASS_LEVEL (e.g. ป.1 or ม.2)  CLASS_ROOM (e.g. 1)  CLASS_NO (roll number)
 * grade rows:      STUDENT_ID SUBJECT_CODE SUBJECT_NAME TERM SCORE GRADE CREDIT
 * attendance rows: STUDENT_ID TERM DAYS_PRESENT DAYS_ABSENT DAYS_LEAVE DAYS_TOTAL
 * Anything not recognised is kept untouched under "extra" (archives) or ignored (registry mapping).
 */
class Presets
{
    /** canonical fields that a mapping may target */
    public const FIELDS = ['code', 'prefix', 'first_name', 'last_name', 'full_name', 'level', 'room', 'classroom', 'roll_number', 'sex', 'birth_date',
        'subject_code', 'subject_name', 'term', 'year', 'score', 'grade', 'credit', 'days_present', 'days_absent', 'days_leave', 'days_total'];

    private const COMMON = [
        'code' => ['เลขประจำตัวนักเรียน', 'เลขประจำตัว', 'รหัสนักเรียน', 'รหัสประจำตัว', 'รหัส', 'code', 'student_code', 'student_id', 'std_id', 'stdid'],
        'prefix' => ['คำนำหน้า', 'คำนำหน้าชื่อ', 'prefix', 'prename', 'title'],
        'first_name' => ['ชื่อ', 'ชื่อจริง', 'first_name', 'firstname', 'fname'],
        'last_name' => ['นามสกุล', 'last_name', 'lastname', 'lname', 'surname'],
        'full_name' => ['ชื่อ-สกุล', 'ชื่อนามสกุล', 'ชื่อ-นามสกุล', 'full_name', 'fullname', 'name'],
        'level' => ['ชั้น', 'ระดับชั้น', 'class_level', 'level', 'grade_level'],
        'room' => ['ห้อง', 'room'],
        'classroom' => ['ชั้นห้อง', 'ห้องเรียน'],
        'roll_number' => ['เลขที่', 'class_no', 'roll_number', 'number', 'no'],
        'sex' => ['เพศ', 'sex', 'gender'],
        'birth_date' => ['วันเกิด', 'วันเดือนปีเกิด', 'birth_date', 'birthdate', 'birthday', 'dob'],
        'subject_code' => ['รหัสวิชา', 'subject_code'],
        'subject_name' => ['ชื่อวิชา', 'วิชา', 'subject_name', 'subject'],
        'term' => ['ภาคเรียน', 'เทอม', 'term', 'semester'],
        'year' => ['ปีการศึกษา', 'year', 'academic_year'],
        'score' => ['คะแนน', 'คะแนนรวม', 'score'],
        'grade' => ['เกรด', 'ผลการเรียน', 'grade', 'grade_result'],
        'credit' => ['หน่วยกิต', 'credit'],
        'days_present' => ['วันมา', 'มาเรียน', 'days_present'],
        'days_absent' => ['วันขาด', 'ขาดเรียน', 'days_absent'],
        'days_leave' => ['วันลา', 'days_leave'],
        'days_total' => ['วันเรียน', 'เวลาเรียน', 'days_total'],
    ];

    /** Per preset header names, merged over COMMON (the preset wins). Ambiguous names live here: CLASS_ROOM is a room number in dmc_like, "classroom" is a full class such as ป.4/1 in generic_th. */
    public const PRESETS = [
        'generic_th' => [
            'headers' => ['classroom' => ['ชั้นห้อง', 'ห้องเรียน', 'classroom', 'class_room'], 'room' => ['ห้อง', 'room']],
            'label' => 'CSV ทั่วไป หัวตารางภาษาไทย',
            'columns' => 'เลขประจำตัวนักเรียน, คำนำหน้า, ชื่อ, นามสกุล, ชั้น, ห้อง, เลขที่, เพศ, วันเกิด (ข้อมูลผลการเรียนใช้ รหัสวิชา, ชื่อวิชา, ภาคเรียน, คะแนน, เกรด)',
        ],
        'dmc_like' => [
            'headers' => ['room' => ['class_room', 'classroom', 'ห้อง', 'room']],
            'label' => 'รูปแบบคล้าย DMC (โครงสมมติ)',
            'columns' => 'STUDENT_ID, PRENAME, FIRSTNAME, LASTNAME, SEX (1 ชาย, 2 หญิง), BIRTHDATE, CLASS_LEVEL, CLASS_ROOM, CLASS_NO (ผลการเรียน: SUBJECT_CODE, SUBJECT_NAME, TERM, SCORE, GRADE)',
        ],
    ];

    public static function headerKey(string $h): string
    {
        return preg_replace('/[\s_\-.()\x{FEFF}]+/u', '', mb_strtolower(trim($h)));
    }

    public static function keys(): array { return array_keys(self::PRESETS); }

    public static function listing(): array
    {
        $out = [];
        foreach (self::PRESETS as $k => $p) $out[] = ['key' => $k, 'label' => $p['label'], 'columns' => $p['columns']];
        return $out;
    }

    /** @return array<string,string> headerKey => canonical field */
    public static function lookup(string $preset): array
    {
        $map = [];
        foreach ((self::PRESETS[$preset]['headers'] ?? []) as $field => $names) foreach ($names as $n) $map[self::headerKey($n)] ??= $field;
        foreach (self::COMMON as $field => $names) foreach ($names as $n) $map[self::headerKey($n)] ??= $field;
        return $map;
    }

    /**
     * Column index => canonical field. $custom (original header => field or '' / 'ignore') wins over the preset.
     * @return array{0: array<int,string>, 1: array<int,string>} map and the original headers that stay unmapped
     */
    public static function mapHeaders(array $headers, string $preset, array $custom = []): array
    {
        $lookup = self::lookup($preset);
        $customKeys = [];
        foreach ($custom as $h => $f) $customKeys[self::headerKey((string) $h)] = (string) $f;
        $map = []; $unmapped = []; $used = []; $done = [];
        // pass 1: columns with a custom target claim their field first
        foreach ($headers as $i => $h) {
            $k = self::headerKey((string) $h);
            if (!array_key_exists($k, $customKeys)) continue;
            $done[$i] = true;
            $f = $customKeys[$k];
            if ($f === 'ignore' || $f === '') $f = null;
            if ($f !== null && !in_array($f, self::FIELDS, true)) abort(422, 'ฟิลด์ปลายทางไม่ถูกต้อง: '.$f);
            if ($f === null || isset($used[$f])) continue;
            $used[$f] = true;
            $map[$i] = $f;
        }
        // pass 2: the rest by preset
        foreach ($headers as $i => $h) {
            if (isset($done[$i])) continue;
            $f = $lookup[self::headerKey((string) $h)] ?? null;
            if ($f === null || isset($used[$f])) continue;
            $used[$f] = true;
            $map[$i] = $f;
        }
        foreach ($headers as $i => $h) if (!isset($map[$i]) && trim((string) $h) !== '') $unmapped[$i] = (string) $h;
        ksort($map);
        return [$map, $unmapped];
    }

    public static function normalizeKey(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    public static function sex(string $v): ?string
    {
        return ['m' => 'M', 'male' => 'M', 'ชาย' => 'M', '1' => 'M', 'f' => 'F', 'female' => 'F', 'หญิง' => 'F', '2' => 'F'][mb_strtolower(trim($v))] ?? null;
    }

    /** "ป.1" + "2" => "ป.1/2"; a level that already has a room, or a ready classroom value, is kept. */
    public static function classroom(array $r): string
    {
        $c = trim((string) ($r['classroom'] ?? ''));
        if ($c !== '') return $c;
        $level = trim((string) ($r['level'] ?? '')); $room = trim((string) ($r['room'] ?? ''));
        if ($level === '') return str_contains($room, '/') ? $room : '';
        return ($room === '' || str_contains($level, '/')) ? $level : $level.'/'.$room;
    }
}
