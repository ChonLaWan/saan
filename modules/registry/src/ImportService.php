<?php

namespace Saan\Modules\Registry;

use App\Support\Saan\Mutation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSV import in three steps: preview (nothing written except the batch), commit (one transaction), rollback.
 * Rollback restores 'before' snapshots and deletes created rows, but only for rows nobody edited since the commit.
 * Edit detection compares the current row with the 'after' snapshot stored at commit (version and timestamps ignored).
 */
class ImportService
{
    public const KINDS = ['students', 'guardians', 'student_guardians'];
    public const MAX_ROWS = 3000;
    private const PREFIXES = ['นางสาว', 'เด็กชาย', 'เด็กหญิง', 'นาย', 'นาง', 'ด.ช.', 'ด.ญ.', 'น.ส.', 'Mr.', 'Mrs.', 'Miss', 'Ms.'];

    /** canonical field => accepted header spellings (compared after Csv::headerKey) */
    private const HEADERS = [
        'students' => [
            'code' => ['code', 'student_code', 'รหัสนักเรียน', 'รหัส'], 'ref' => ['id', 'external_id', 'รหัสอ้างอิง'],
            'prefix' => ['prefix', 'คำนำหน้า'], 'first_name' => ['first_name', 'ชื่อ', 'ชื่อจริง'], 'last_name' => ['last_name', 'นามสกุล'],
            'display_name' => ['display_name', 'name', 'ชื่อ-สกุล', 'ชื่อนามสกุล'], 'nickname' => ['nickname', 'ชื่อเล่น'], 'sex' => ['sex', 'เพศ'],
            'birth_date' => ['birth_date', 'วันเกิด'], 'birth_year_be' => ['birth_year_be', 'ปีเกิด', 'ปีเกิด (พ.ศ.)'],
            'nationality_status' => ['nationality_status', 'สถานะสัญชาติ', 'สัญชาติ'], 'preferred_language' => ['language', 'preferred_language', 'ภาษา'],
            'needs_interpreter' => ['needs_interpreter', 'ต้องการล่าม'], 'status' => ['state', 'status', 'สถานะ'],
            'admitted_on' => ['admitted_on', 'วันที่เข้าเรียน'], 'admission_type' => ['admission_type', 'ประเภทการรับเข้า'],
            'previous_school' => ['previous_school_name', 'previous_school', 'โรงเรียนเดิม'], 'classroom' => ['classroom', 'ห้องเรียน', 'ชั้น'],
            'roll_number' => ['roll_number', 'เลขที่'], 'pending_reason' => ['identity_pending_reason', 'เหตุผลรอเอกสาร'],
        ],
        'guardians' => [
            'ref' => ['id', 'guardian_id', 'รหัสผู้ปกครอง'], 'prefix' => ['prefix', 'คำนำหน้า'], 'first_name' => ['first_name', 'ชื่อ'], 'last_name' => ['last_name', 'นามสกุล'],
            'display_name' => ['display_name', 'name', 'ชื่อ-สกุล', 'ชื่อนามสกุล'], 'phone' => ['phone', 'โทรศัพท์', 'เบอร์โทร'], 'email' => ['email', 'อีเมล'],
            'line_id' => ['line_id', 'ไลน์'], 'occupation' => ['occupation', 'อาชีพ'],
        ],
        'student_guardians' => [
            'student' => ['student_id', 'student_code', 'รหัสนักเรียน'], 'guardian' => ['guardian_id', 'รหัสผู้ปกครอง'],
            'relationship' => ['relationship', 'ความสัมพันธ์'], 'relationship_th' => ['relationship_th'],
            'is_emergency_contact' => ['is_emergency_contact', 'ติดต่อฉุกเฉิน'], 'can_pick_up' => ['can_pick_up', 'รับนักเรียนได้'],
            'pickup_note' => ['pickup_note', 'หมายเหตุการรับนักเรียน'], 'contact_priority' => ['contact_priority', 'ลำดับติดต่อ'],
        ],
    ];

    private const NATIONALITY = ['thai' => 'thai', 'ไทย' => 'thai', 'foreign' => 'foreign', 'ต่างชาติ' => 'foreign', 'ต่างด้าว' => 'foreign', 'stateless' => 'stateless',
        'ไร้สัญชาติ' => 'stateless', 'undetermined' => 'undetermined', 'ยังไม่ระบุ' => 'undetermined', 'รอตรวจสอบ' => 'undetermined'];
    private const STATUS = ['studying' => 'studying', 'ศึกษาอยู่' => 'studying', 'กำลังศึกษา' => 'studying', 'transferred_out' => 'transferred_out', 'ย้ายออก' => 'transferred_out',
        'graduated' => 'graduated', 'จบการศึกษา' => 'graduated', 'left' => 'left', 'ลาออก' => 'left', 'ออก' => 'left'];
    private const ADMISSION = ['regular' => 'regular', 'ปกติ' => 'regular', 'transfer_in' => 'transfer_in', 'ย้ายเข้า' => 'transfer_in', 're_enroll' => 're_enroll', 'กลับเข้าเรียน' => 're_enroll', 'other' => 'other', 'อื่นๆ' => 'other'];
    private const RELATIONSHIP = ['mother' => 'mother', 'มารดา' => 'mother', 'แม่' => 'mother', 'father' => 'father', 'บิดา' => 'father', 'พ่อ' => 'father',
        'grandmother' => 'grandmother', 'ยาย' => 'grandmother', 'ย่า' => 'grandmother', 'grandfather' => 'grandfather', 'ตา' => 'grandfather', 'ปู่' => 'grandfather',
        'aunt' => 'aunt', 'น้า' => 'aunt', 'ป้า' => 'aunt', 'uncle' => 'uncle', 'ลุง' => 'uncle', 'sibling' => 'sibling', 'พี่' => 'sibling', 'guardian' => 'guardian',
        'ผู้ปกครอง' => 'guardian', 'other' => 'other', 'อื่นๆ' => 'other'];

    public function __construct(private RegistryRepository $repo) {}

    private function school(): int { return $this->repo->school(); }

    // ---------- preview ----------

    public function preview(string $kind, string $filename, string $content): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'ชนิดไฟล์นำเข้าไม่ถูกต้อง');
        $csv = Csv::parse($content);
        abort_if(count($csv['rows']) === 0, 422, 'ไฟล์ไม่มีแถวข้อมูล');
        abort_if(count($csv['rows']) > self::MAX_ROWS, 422, 'นำเข้าได้ครั้งละไม่เกิน '.self::MAX_ROWS.' แถว');
        [$map, $ignored] = $this->mapHeaders($kind, $csv['headers']);
        $required = ['students' => ['code'], 'guardians' => [], 'student_guardians' => ['student', 'guardian']][$kind];
        foreach ($required as $field) abort_unless(in_array($field, $map, true), 422, 'ไม่พบคอลัมน์ที่จำเป็น: '.self::HEADERS[$kind][$field][0]);
        if ($kind === 'guardians') abort_unless(in_array('display_name', $map, true) || in_array('first_name', $map, true), 422, 'ไม่พบคอลัมน์ชื่อผู้ปกครอง (display_name หรือ first_name)');

        $ctx = ['classrooms' => $this->classroomMap(), 'seen' => []];
        $rows = []; $count = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        foreach ($csv['rows'] as $i => $cells) {
            $raw = [];
            foreach ($map as $col => $field) $raw[$field] = $cells[$col] ?? '';
            $raw['_row'] = $csv['line_numbers'][$i];
            [$data, $errors, $action, $entity] = match ($kind) {
                'students' => $this->planStudent($raw, $ctx),
                'guardians' => $this->planGuardian($raw, $ctx),
                default => $this->planLink($raw, $ctx),
            };
            if ($errors) { $action = 'skip'; $count['error']++; } else $count[$action]++;
            $rows[] = ['row_no' => $csv['line_numbers'][$i], 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'action' => $action, 'entity_id' => $entity];
        }
        $summary = ['rows' => count($rows)] + $count + ['ignored_columns' => $ignored];
        $id = DB::transaction(function () use ($kind, $filename, $summary, $rows) {
            $id = (int) DB::table('reg_import_batches')->insertGetId(['school_id' => $this->school(), 'kind' => $kind, 'filename' => mb_substr($filename, 0, 200), 'status' => 'preview',
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($rows, 200) as $chunk) DB::table('reg_import_rows')->insert(array_map(fn ($r) => $r + ['batch_id' => $id], $chunk));
            Mutation::audit('registry.import.previewed', 'reg_import_batches', $id, ['kind' => $kind, 'rows' => count($rows)]);
            return $id;
        });
        return $this->batch($id);
    }

    /** @return array{0: array<int,string>, 1: array<int,string>} column index => canonical field, and the ignored header names */
    private function mapHeaders(string $kind, array $headers): array
    {
        $lookup = [];
        foreach (self::HEADERS[$kind] as $field => $names) foreach ($names as $n) $lookup[Csv::headerKey($n)] ??= $field;
        $map = []; $ignored = [];
        foreach ($headers as $i => $h) {
            $f = $lookup[Csv::headerKey($h)] ?? null;
            if ($f === null || in_array($f, $map, true)) { if ($h !== '') $ignored[] = $h; continue; }
            $map[$i] = $f;
        }
        return [$map, $ignored];
    }

    private function classroomMap(): array
    {
        $m = [];
        foreach (DB::table('classrooms')->where('school_id', $this->school())->get(['id', 'name']) as $c) $m[$this->roomKey($c->name)] = (int) $c->id;
        return $m;
    }

    private function roomKey(string $n): string { return mb_strtolower(preg_replace('/\s+/u', '', trim($n))); }

    private function findStudentByCode(string $code): ?object
    {
        return DB::table('students')->where('school_id', $this->school())->where(fn ($w) => $w->where('code_key', RegistryRepository::codeKey($code))
            ->orWhere(fn ($x) => $x->whereNull('code_key')->whereRaw('LOWER(TRIM(code)) = ?', [RegistryRepository::codeKey($code)])))->first();
    }

    // ---------- students ----------

    private function planStudent(array $r, array &$ctx): array
    {
        $e = []; $d = [];
        $code = trim($r['code'] ?? '');
        if ($code === '') $e[] = 'ไม่มีรหัสนักเรียน';
        elseif (mb_strlen($code) > 40) $e[] = 'รหัสนักเรียนยาวเกิน 40 ตัวอักษร';
        else {
            $key = RegistryRepository::codeKey($code);
            if (isset($ctx['seen'][$key])) $e[] = 'รหัสนักเรียนซ้ำกับแถว '.$ctx['seen'][$key].' ในไฟล์';
            $ctx['seen'][$key] ??= $r['_row'] ?? 0;
            $d['code'] = $code;
        }
        $this->put($d, 'prefix', $r['prefix'] ?? '', 30, $e);
        $p = null; $first = trim($r['first_name'] ?? ''); $last = trim($r['last_name'] ?? '');
        if ($first === '' && trim($r['display_name'] ?? '') !== '') [$p, $first, $last] = $this->splitName($r['display_name']);
        if (!empty($p) && !isset($d['prefix'])) $d['prefix'] = $p;
        if ($first !== '') { $d['first_name'] = mb_substr($first, 0, 120); if ($last !== '') $d['last_name'] = mb_substr($last, 0, 120); }
        $this->put($d, 'nickname', $r['nickname'] ?? '', 60, $e);
        $this->put($d, 'preferred_language', $r['preferred_language'] ?? '', 40, $e);
        $this->put($d, 'previous_school', $r['previous_school'] ?? '', 200, $e);
        if (($v = trim($r['sex'] ?? '')) !== '') {
            $s = ['m' => 'M', 'male' => 'M', 'ชาย' => 'M', 'f' => 'F', 'female' => 'F', 'หญิง' => 'F'][mb_strtolower($v)] ?? null;
            $s ? $d['sex'] = $s : $e[] = 'เพศไม่ถูกต้อง: '.$v;
        }
        foreach (['birth_date', 'admitted_on'] as $f) {
            if (($v = trim($r[$f] ?? '')) === '') continue;
            ($iso = $this->date($v)) ? $d[$f] = $iso : $e[] = ($f === 'birth_date' ? 'วันเกิด' : 'วันที่เข้าเรียน').'ไม่ถูกต้อง: '.$v;
        }
        if (($v = trim($r['birth_year_be'] ?? '')) !== '') {
            $y = ctype_digit($v) ? (int) $v : 0;
            if ($y >= 1900 && $y <= 2300) $y += 543;
            $y >= 2400 && $y <= 2700 ? $d['birth_year_be'] = $y : $e[] = 'ปีเกิดไม่ถูกต้อง: '.$v;
        } elseif (isset($d['birth_date'])) $d['birth_year_be'] = (int) substr($d['birth_date'], 0, 4) + 543;
        $this->enum($d, $e, 'nationality_status', $r['nationality_status'] ?? '', self::NATIONALITY, 'สถานะสัญชาติ');
        $this->enum($d, $e, 'status', $r['status'] ?? '', self::STATUS, 'สถานะนักเรียน');
        $this->enum($d, $e, 'admission_type', $r['admission_type'] ?? '', self::ADMISSION, 'ประเภทการรับเข้า');
        if (($v = trim($r['needs_interpreter'] ?? '')) !== '') {
            $b = $this->bool($v);
            $b === null ? $e[] = 'ค่าต้องการล่ามไม่ถูกต้อง: '.$v : $d['needs_interpreter'] = $b;
        }
        if (($v = trim($r['roll_number'] ?? '')) !== '') ctype_digit($v) && (int) $v >= 1 && (int) $v <= 999 ? $d['roll_number'] = (int) $v : $e[] = 'เลขที่ไม่ถูกต้อง: '.$v;
        if (($v = trim($r['classroom'] ?? '')) !== '') {
            $id = $ctx['classrooms'][$this->roomKey($v)] ?? null;
            $id ? $d['classroom_id'] = $id : $e[] = 'ไม่พบห้องเรียน: '.$v;
            $d['classroom_label'] = $v;
        }
        $this->put($d, 'pending_reason', $r['pending_reason'] ?? '', 300, $e);
        if (($v = trim($r['ref'] ?? '')) !== '') mb_strlen($v) <= 60 ? $d['external_ref'] = $v : $e[] = 'รหัสอ้างอิงยาวเกิน 60 ตัวอักษร';

        $cur = isset($d['code']) ? $this->findStudentByCode($d['code']) : null;
        if (!$cur) {
            if (!isset($d['first_name'])) $e[] = 'ไม่มีชื่อนักเรียน';
            return [$d, $e, 'create', null];
        }
        $diff = [];
        foreach ($d as $f => $v) {
            if (in_array($f, ['code', 'classroom_label'], true)) continue;
            if ($f === 'pending_reason') { if (!$this->hasPending((int) $cur->id)) $diff[] = $f; continue; }
            $now = $cur->$f ?? null;
            if ($f === 'needs_interpreter') $now = (bool) $now;
            if ($f === 'birth_year_be' || $f === 'roll_number' || $f === 'classroom_id') $now = $now === null ? null : (int) $now;
            if ($now !== $v) $diff[] = $f;
        }
        $d['_diff'] = $diff;
        return [$d, $e, $diff ? 'update' : 'skip', (int) $cur->id];
    }

    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full));
        $prefix = null;
        foreach (self::PREFIXES as $p) if (mb_stripos($full, $p) === 0) { $prefix = $p; $full = trim(mb_substr($full, mb_strlen($p))); break; }
        $parts = explode(' ', $full, 2);
        return [$prefix, $parts[0], $parts[1] ?? ''];
    }

    private function put(array &$d, string $f, string $v, int $max, array &$e): void
    {
        $v = trim($v);
        if ($v === '') return;
        if (mb_strlen($v) > $max) { $e[] = 'ค่า '.$f.' ยาวเกิน '.$max.' ตัวอักษร'; return; }
        $d[$f] = $v;
    }

    private function enum(array &$d, array &$e, string $f, string $v, array $map, string $label): void
    {
        $v = trim($v);
        if ($v === '') return;
        $k = mb_strtolower($v);
        isset($map[$k]) ? $d[$f] = $map[$k] : $e[] = $label.'ไม่ถูกต้อง: '.$v;
    }

    private function bool(string $v): ?bool
    {
        return match (mb_strtolower(trim($v))) { '1', 'true', 'yes', 'y', 'ใช่', 'มี' => true, '0', 'false', 'no', 'n', 'ไม่ใช่', 'ไม่มี' => false, default => null };
    }

    /** ISO (CE), ISO with a Buddhist year (>= 2400), or d/m/Y with either calendar. Returns Y-m-d in CE, or null. */
    private function date(string $v): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $v, $m)) [$y, $mo, $da] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/D', $v, $m)) [$y, $mo, $da] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        else return null;
        if ($y >= 2400) $y -= 543;
        return $y >= 1900 && $y <= 2200 && checkdate($mo, $da, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $da) : null;
    }

    // ---------- guardians ----------

    private function planGuardian(array $r, array &$ctx): array
    {
        $e = []; $d = [];
        $p = null; $first = trim($r['first_name'] ?? ''); $last = trim($r['last_name'] ?? ''); $prefix = trim($r['prefix'] ?? ''); $display = trim($r['display_name'] ?? '');
        if ($first === '' && $display !== '') [$p, $first, $last] = $this->splitName($display);
        if ($prefix === '' && isset($p) && $p) $prefix = $p;
        if ($first === '') $e[] = 'ไม่มีชื่อผู้ปกครอง';
        else {
            $d['first_name'] = mb_substr($first, 0, 120); if ($last !== '') $d['last_name'] = mb_substr($last, 0, 120);
            if ($prefix !== '') $d['prefix'] = mb_substr($prefix, 0, 30);
            $d['display_name'] = mb_substr($display !== '' ? $display : RegistryRepository::displayName($prefix, $first, $last), 0, 240);
        }
        // the phone number is never kept in clear text in the batch rows: only ciphertext and the last 4 digits
        $plain = null; $tmp = [];
        $this->put($tmp, 'phone', $r['phone'] ?? '', 40, $e);
        if (isset($tmp['phone'])) { $plain = $tmp['phone']; $d['phone_enc'] = Identity::encrypt($plain); $d['phone_last4'] = Identity::last4(preg_replace('/\D+/', '', $plain) ?: $plain); }
        $this->put($d, 'email', $r['email'] ?? '', 190, $e);
        if (isset($d['email']) && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $e[] = 'อีเมลไม่ถูกต้อง: '.$d['email'];
        $this->put($d, 'line_id', $r['line_id'] ?? '', 80, $e);
        $this->put($d, 'occupation', $r['occupation'] ?? '', 120, $e);
        $ref = trim($r['ref'] ?? '');
        $cur = null;
        if ($ref !== '') {
            if (mb_strlen($ref) > 60) $e[] = 'รหัสอ้างอิงยาวเกิน 60 ตัวอักษร';
            else {
                $key = RegistryRepository::codeKey($ref);
                if (isset($ctx['seen'][$key])) $e[] = 'รหัสผู้ปกครองซ้ำในไฟล์';
                $ctx['seen'][$key] = true;
                $d['ref_code'] = $ref;
                $cur = DB::table('reg_guardians')->where('school_id', $this->school())->where('ref_key', $key)->first();
            }
        }
        if (!$cur) return [$d, $e, 'create', null];
        $diff = [];
        foreach ($d as $f => $v) {
            if ($f === 'ref_code' || $f === 'phone_last4') continue;
            if ($f === 'phone_enc') { if (Identity::decrypt($cur->phone_enc) !== $plain) $diff[] = 'phone'; continue; }
            $now = $cur->$f ?? null;
            if ($now !== $v) $diff[] = $f;
        }
        $d['_diff'] = $diff;
        return [$d, $e, $diff ? 'update' : 'skip', (int) $cur->id];
    }

    // ---------- links ----------

    private function planLink(array $r, array &$ctx): array
    {
        $e = []; $d = [];
        $sref = trim($r['student'] ?? ''); $gref = trim($r['guardian'] ?? '');
        $student = $sref === '' ? null : DB::table('students')->where('school_id', $this->school())->where('external_ref', $sref)->first() ?? $this->findStudentByCode($sref);
        $guardian = $gref === '' ? null : DB::table('reg_guardians')->where('school_id', $this->school())->where('ref_key', RegistryRepository::codeKey($gref))->first();
        if (!$student) $e[] = 'ไม่พบนักเรียน: '.$sref.' (นำเข้าไฟล์นักเรียนก่อน)'; else $d['student_id'] = (int) $student->id;
        if (!$guardian) $e[] = 'ไม่พบผู้ปกครอง: '.$gref.' (นำเข้าไฟล์ผู้ปกครองก่อน)'; else $d['guardian_id'] = (int) $guardian->id;
        $d['student_ref'] = $sref; $d['guardian_ref'] = $gref;
        $rel = trim($r['relationship'] ?? '') ?: trim($r['relationship_th'] ?? '');
        if ($rel !== '') { $k = self::RELATIONSHIP[mb_strtolower($rel)] ?? null; $k ? $d['relationship'] = $k : $e[] = 'ความสัมพันธ์ไม่ถูกต้อง: '.$rel; }
        foreach (['is_emergency_contact', 'can_pick_up'] as $f) {
            if (($v = trim($r[$f] ?? '')) === '') continue;
            $b = $this->bool($v);
            $b === null ? $e[] = 'ค่า '.$f.' ไม่ถูกต้อง: '.$v : $d[$f] = $b;
        }
        if (($v = trim($r['contact_priority'] ?? '')) !== '') ctype_digit($v) ? $d['is_primary'] = ((int) $v === 1) : $e[] = 'ลำดับติดต่อไม่ถูกต้อง: '.$v;
        $this->put($d, 'pickup_note', $r['pickup_note'] ?? '', 300, $e);
        if (($d['can_pick_up'] ?? true) === false && !isset($d['pickup_note'])) $d['pickup_note'] = 'ห้ามรับนักเรียนกลับบ้าน (นำเข้าจาก CSV) ติดต่อครูประจำชั้นก่อน';
        if (($d['can_pick_up'] ?? null) === true) unset($d['pickup_note']);
        if ($student && $guardian) {
            $pair = $student->id.'|'.$guardian->id;
            if (isset($ctx['seen'][$pair])) $e[] = 'คู่นักเรียนและผู้ปกครองซ้ำในไฟล์';
            $ctx['seen'][$pair] = true;
            $cur = DB::table('reg_student_guardians')->where('student_id', $student->id)->where('guardian_id', $guardian->id)->first();
            if ($cur) {
                $diff = [];
                foreach (['relationship', 'is_emergency_contact', 'can_pick_up', 'is_primary', 'pickup_note'] as $f) {
                    if (!array_key_exists($f, $d)) continue;
                    $now = $cur->$f; if (is_numeric($now) && is_bool($d[$f])) $now = (bool) $now;
                    if ($now !== $d[$f]) $diff[] = $f;
                }
                $d['_diff'] = $diff;
                return [$d, $e, $diff ? 'update' : 'skip', (int) $cur->id];
            }
        }
        return [$d, $e, 'create', null];
    }

    // ---------- read ----------

    public function batch(int $id, int $page = 1): array
    {
        $b = $this->find($id);
        $per = 100; $page = max(1, $page);
        $rows = DB::table('reg_import_rows')->where('batch_id', $id)->orderBy('row_no')->forPage($page, $per)->get(['row_no', 'data', 'errors', 'action', 'entity_id'])
            ->map(fn ($r) => ['row_no' => (int) $r->row_no, 'data' => array_diff_key(json_decode($r->data, true) ?: [], array_flip(['phone_enc'])), 'errors' => json_decode($r->errors, true) ?: [], 'action' => $r->action, 'entity_id' => $r->entity_id !== null ? (int) $r->entity_id : null])->all();
        return ['batch' => $this->presentBatch($b), 'rows' => $rows, 'page' => $page, 'per_page' => $per];
    }

    public function batches(): array
    {
        return DB::table('reg_import_batches')->where('school_id', $this->school())->orderByDesc('id')->limit(30)->get()->map(fn ($b) => $this->presentBatch($b))->all();
    }

    private function find(int $id): object
    {
        return DB::table('reg_import_batches')->where('school_id', $this->school())->where('id', $id)->first() ?? abort(404, 'ไม่พบชุดนำเข้า');
    }

    private function presentBatch(object $b): array
    {
        return ['id' => (int) $b->id, 'kind' => $b->kind, 'filename' => $b->filename, 'status' => $b->status, 'summary' => json_decode($b->summary ?? '[]', true) ?: [], 'created_at' => $b->created_at];
    }

    // ---------- commit ----------

    private function hasPending(int $studentId): bool
    {
        return DB::table('reg_identifiers')->where('school_id', $this->school())->where('person_type', 'student')->where('person_id', $studentId)->where('kind', 'pending')->exists();
    }

    public function commit(int $id): array
    {
        $b = $this->find($id);
        DB::transaction(function () use ($b, $id) {
            // atomic claim: a second request (double click, retry) finds status already changed
            $claimed = DB::table('reg_import_batches')->where('id', $id)->where('school_id', $this->school())->where('status', 'preview')->update(['status' => 'committed', 'updated_at' => now()]);
            abort_if($claimed === 0, 422, 'ชุดนำเข้านี้ถูกดำเนินการไปแล้ว');
            $done = ['create' => 0, 'update' => 0, 'skip' => 0];
            $guard = [];
            $table = $this->table($b->kind);
            foreach (DB::table('reg_import_rows')->where('batch_id', $id)->orderBy('row_no')->cursor() as $row) {
                $errors = json_decode($row->errors, true) ?: [];
                if ($errors || $row->action === 'skip') { $done['skip']++; continue; }
                $data = json_decode($row->data, true) ?: [];
                $res = match ($b->kind) {
                    'students' => $this->commitStudent($data, $guard),
                    'guardians' => $this->commitGuardian($data),
                    default => $this->commitLink($data),
                };
                $res += [3 => []];
                [$action, $entity, $before, $extra] = $res;
                $done[$action]++;
                $after = (array) DB::table($table)->where('id', $entity)->first();
                DB::table('reg_import_rows')->where('id', $row->id)->update(['action' => $action, 'entity_id' => $entity,
                    'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), 'after' => json_encode($after + $extra, JSON_UNESCAPED_UNICODE)]);
            }
            abort_if($done['create'] + $done['update'] === 0, 422, 'ไม่มีแถวที่นำเข้าได้ ตรวจข้อผิดพลาดในตัวอย่างก่อน');
            $summary = json_decode($b->summary ?? '[]', true) ?: [];
            $summary['committed'] = $done;
            DB::table('reg_import_batches')->where('id', $id)->update(['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)]);
            Mutation::audit('registry.import.committed', 'reg_import_batches', $id, ['kind' => $b->kind] + $done);
        });
        return $this->batch($id);
    }

    /** Re-resolves the target at commit time (the preview may be stale) and returns [action, entity id, before snapshot, extra after-fields]. */
    private function commitStudent(array $d, array &$guard): array
    {
        $cur = $this->findStudentByCode($d['code']);
        $cols = array_diff_key($d, array_flip(['code', 'classroom_label', '_diff', 'pending_reason']));
        $pendingReason = $d['pending_reason'] ?? null;
        if (!$cur) {
            $row = $cols + ['school_id' => $this->school(), 'code' => $d['code'], 'code_key' => RegistryRepository::codeKey($d['code']), 'version' => 1,
                'name' => RegistryRepository::fullName($d['first_name'] ?? null, $d['last_name'] ?? null), 'created_at' => now(), 'updated_at' => now()];
            $sid = (int) DB::table('students')->insertGetId($row);
            return ['create', $sid, null, $this->pendingFor($sid, $pendingReason)];
        }
        $before = (array) $cur;
        $set = $cols;
        if (isset($d['first_name'])) $set['name'] = RegistryRepository::fullName($d['first_name'], $d['last_name'] ?? $cur->last_name);
        if ($cur->code_key === null) $set += $this->repo->legacyCodeKey($cur);
        $set += ['version' => (int) $cur->version + 1, 'updated_at' => now()];
        $n = DB::table('students')->where('id', $cur->id)->where('school_id', $this->school())->where('version', $cur->version)->update($set);
        abort_if($n === 0, 409, 'ข้อมูลนักเรียนรหัส '.$d['code'].' ถูกแก้ไขระหว่างนำเข้า กรุณาสร้างตัวอย่างใหม่');
        if (array_key_exists('classroom_id', $set)) \App\Support\Saan\ClassroomMoves::record($this->school(), (int) $cur->id, $cur->classroom_id === null ? null : (int) $cur->classroom_id, $set['classroom_id'] === null ? null : (int) $set['classroom_id']);
        return ['update', (int) $cur->id, $before, $this->pendingFor((int) $cur->id, $pendingReason)];
    }

    /** Creates the "waiting for documents" identifier from the mock's identity_pending_reason, only when the student has none. */
    private function pendingFor(int $studentId, ?string $reason): array
    {
        if ($reason === null || $reason === '' || $this->hasPending($studentId)) return [];
        $pid = (int) DB::table('reg_identifiers')->insertGetId(['school_id' => $this->school(), 'person_type' => 'student', 'person_id' => $studentId, 'kind' => 'pending',
            'verification_state' => 'unverified', 'note' => mb_substr($reason, 0, 300), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return ['_pending_id' => $pid];
    }

    private function commitGuardian(array $d): array
    {
        $cur = isset($d['ref_code']) ? DB::table('reg_guardians')->where('school_id', $this->school())->where('ref_key', RegistryRepository::codeKey($d['ref_code']))->first() : null;
        $cols = array_diff_key($d, array_flip(['ref_code', 'phone_last4', '_diff']));
        if (!$cur) {
            if (isset($d['ref_code'])) $cols += ['ref_code' => $d['ref_code'], 'ref_key' => RegistryRepository::codeKey($d['ref_code'])];
            return ['create', (int) DB::table('reg_guardians')->insertGetId($cols + ['school_id' => $this->school(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]), null];
        }
        $before = (array) $cur;
        $n = DB::table('reg_guardians')->where('id', $cur->id)->where('school_id', $this->school())->where('version', $cur->version)->update($cols + ['version' => (int) $cur->version + 1, 'updated_at' => now()]);
        abort_if($n === 0, 409, 'ข้อมูลผู้ปกครอง '.($d['display_name'] ?? '').' ถูกแก้ไขระหว่างนำเข้า กรุณาสร้างตัวอย่างใหม่');
        return ['update', (int) $cur->id, $before];
    }

    private function commitLink(array $d): array
    {
        $cur = DB::table('reg_student_guardians')->where('student_id', $d['student_id'])->where('guardian_id', $d['guardian_id'])->where('school_id', $this->school())->first();
        $cols = array_intersect_key($d, array_flip(['relationship', 'is_emergency_contact', 'can_pick_up', 'is_primary', 'pickup_note']));
        if (($cols['is_primary'] ?? false) && DB::table('reg_student_guardians')->where('school_id', $this->school())->where('student_id', $d['student_id'])->where('is_primary', true)->when($cur, fn ($q) => $q->where('id', '!=', $cur->id))->exists()) $cols['is_primary'] = false;
        if (($cols['can_pick_up'] ?? null) === true) $cols['pickup_note'] = null;
        if (!$cur) {
            return ['create', (int) DB::table('reg_student_guardians')->insertGetId($cols + ['school_id' => $this->school(), 'student_id' => $d['student_id'], 'guardian_id' => $d['guardian_id'], 'version' => 1, 'created_at' => now(), 'updated_at' => now()]), null];
        }
        $before = (array) $cur;
        $n = DB::table('reg_student_guardians')->where('id', $cur->id)->where('version', $cur->version)->update($cols + ['version' => (int) $cur->version + 1, 'updated_at' => now()]);
        abort_if($n === 0, 409, 'การผูกผู้ปกครองของนักเรียนรหัส '.($d['student_ref'] ?? '').' ถูกแก้ไขระหว่างนำเข้า กรุณาสร้างตัวอย่างใหม่');
        // same rule as the screen (RegistryRepository::updateLink): switching pickup off also closes the guardian's portal access to this child
        if ((bool) $cur->can_pick_up && array_key_exists('can_pick_up', $cols) && $cols['can_pick_up'] === false) RegistryRepository::revokePortalLinks((int) $cur->student_id, (int) $cur->guardian_id, $this->school());
        return ['update', (int) $cur->id, $before];
    }

    // ---------- rollback ----------

    private function table(string $kind): string { return ['students' => 'students', 'guardians' => 'reg_guardians', 'student_guardians' => 'reg_student_guardians'][$kind]; }

    private const VOLATILE = ['version', 'updated_at', 'created_at'];

    /**
     * A row blocks rollback when its current content differs from the 'after' snapshot taken at commit (version and timestamps ignored),
     * so batches applied one after another can be rolled back in reverse order. Batches committed before the snapshot existed fall back to the version rule.
     *
     * @return array<int,array{row_no:int, entity_id:int, reason:string}>
     */
    public function blockers(object $b, bool $lock = false): array
    {
        $table = $this->table($b->kind); $out = [];
        foreach (DB::table('reg_import_rows')->where('batch_id', $b->id)->whereIn('action', ['create', 'update'])->orderBy('row_no')->cursor() as $row) {
            $q = DB::table($table)->where('id', $row->entity_id)->where('school_id', $this->school());
            $cur = ($lock ? $q->lockForUpdate() : $q)->first();
            if (!$cur) continue; // already gone: nothing to roll back
            $after = $row->after === null ? null : (json_decode($row->after, true) ?: []);
            if ($after === null) {
                $expected = $row->action === 'create' ? 1 : (int) (json_decode($row->before, true)['version'] ?? 0) + 1;
                $edited = (int) $cur->version !== $expected;
            } else {
                $edited = false;
                foreach ($after as $k => $v) {
                    if (str_starts_with((string) $k, '_') || in_array($k, self::VOLATILE, true)) continue;
                    if ((string) ($cur->$k ?? '') !== (string) ($v ?? '')) { $edited = true; break; }
                }
            }
            if ($edited) { $out[] = ['row_no' => (int) $row->row_no, 'entity_id' => (int) $row->entity_id, 'reason' => 'ข้อมูลถูกแก้ไขแล้วหลังนำเข้า']; continue; }
            if ($row->action === 'create' && $this->hasDependents($b->kind, (int) $cur->id, (int) ($after['_pending_id'] ?? 0))) $out[] = ['row_no' => (int) $row->row_no, 'entity_id' => (int) $row->entity_id, 'reason' => 'มีข้อมูลที่เกี่ยวข้องแล้ว (ผู้ปกครอง เลขประจำตัว เอกสาร การเช็กชื่อ ใบแจ้งหนี้ คะแนน สุขภาพ พฤติกรรม หรือบัญชีผู้ปกครอง)'];
        }
        return $out;
    }

    private function hasDependents(string $kind, int $id, int $ownPendingId = 0): bool
    {
        $checks = match ($kind) {
            // these tables have no foreign keys, so every student-keyed table of the other modules is listed here (skipped when the table does not exist)
            'students' => array_map(fn (string $t) => [$t, 'student_id'], [
                'reg_student_guardians', 'reg_documents', 'attendances', 'att_corrections', 'grade_entries',
                'fin_invoices', 'asm_scores', 'asm_score_revisions', 'asm_attributes', 'hlth_measurements', 'hlth_profiles', 'bhv_records',
                'gp_links', 'gp_acks', 'exm_sheets', 'rpt_remarks', 'rpt_special_results', 'api_student_pins',
            ]),
            'guardians' => array_map(fn (string $t) => [$t, 'guardian_id'], ['reg_student_guardians', 'gp_accounts', 'gp_links', 'gp_acks']),
            default => [],
        };
        // tables the other modules declare in module.json ("student_tables"), so the next module cannot be forgotten here
        if ($kind === 'students') foreach (app(\App\Support\Modules\ModuleRegistry::class)->declaredTables('student_tables') as $d) $checks[] = [$d['table'], $d['column']];
        foreach ($checks as [$t, $c]) if (Schema::hasTable($t) && DB::table($t)->where($c, $id)->exists()) return true;
        // contact e-mails of the student/guardian portal (personal data keyed by kind + subject id): removing the person would leave them without an owner
        if (($kind === 'students' || $kind === 'guardians') && Schema::hasTable('gp_contacts') && DB::table('gp_contacts')->where('kind', $kind === 'students' ? 'student' : 'guardian')->where('subject_id', $id)->exists()) return true;
        if ($kind === 'students' || $kind === 'guardians') {
            // the pending identifier this import created (still untouched) is not a dependent; it is removed together with the student
            return DB::table('reg_identifiers')->where('person_type', $kind === 'students' ? 'student' : 'guardian')->where('person_id', $id)
                ->where(fn ($w) => $w->where('id', '!=', $ownPendingId)->orWhere('version', '!=', 1))->exists();
        }
        return false;
    }

    /**
     * Retention (PDPA): staging rows hold names, birth dates, guardian contacts and pickup notes. Previews never committed lose their rows after
     * $previewDays (batch becomes 'expired'); committed batches become 'archived' (no rollback any more) and rolled-back ones lose their row
     * payloads after $keepDays. Only the batch summary stays. All schools; returns counts per step.
     * @return array{preview_batches:int,archived_batches:int,rows_wiped:int}
     */
    public static function purge(int $previewDays = 7, int $keepDays = 30, bool $dryRun = false): array
    {
        $previewCut = now()->subDays(max(1, $previewDays))->toDateTimeString();
        $keepCut = now()->subDays(max(1, $keepDays))->toDateTimeString();
        $previews = DB::table('reg_import_batches')->where('status', 'preview')->where('updated_at', '<', $previewCut)->pluck('id')->all();
        $old = DB::table('reg_import_batches')->whereIn('status', ['committed', 'rolled_back'])->where('updated_at', '<', $keepCut)->pluck('status', 'id')->all();
        $rows = 0;
        foreach (array_chunk($previews, 200) as $ids) {
            $rows += DB::table('reg_import_rows')->whereIn('batch_id', $ids)->count();
            if (!$dryRun) {
                DB::table('reg_import_rows')->whereIn('batch_id', $ids)->delete();
                DB::table('reg_import_batches')->whereIn('id', $ids)->where('status', 'preview')->update(['status' => 'expired']);
            }
        }
        foreach (array_chunk(array_keys($old), 200) as $ids) {
            $q = DB::table('reg_import_rows')->whereIn('batch_id', $ids)->where(fn ($w) => $w->whereNotNull('data')->orWhereNotNull('before')->orWhereNotNull('after')->orWhereNotNull('errors'));
            $rows += $q->count();
            if (!$dryRun) {
                $q->update(['data' => null, 'before' => null, 'after' => null, 'errors' => null]);
                DB::table('reg_import_batches')->whereIn('id', $ids)->where('status', 'committed')->update(['status' => 'archived']);
            }
        }
        return ['preview_batches' => count($previews), 'archived_batches' => count($old), 'rows_wiped' => $rows];
    }

    public function rollback(int $id): array
    {
        $b = $this->find($id);
        abort_unless($b->status === 'committed', 422, 'ย้อนกลับได้เฉพาะชุดนำเข้าที่บันทึกแล้ว');
        $table = $this->table($b->kind);
        $blocking = DB::transaction(function () use ($b, $id, $table) {
            $claimed = DB::table('reg_import_batches')->where('id', $id)->where('school_id', $this->school())->where('status', 'committed')->update(['status' => 'rolled_back', 'updated_at' => now()]);
            abort_if($claimed === 0, 422, 'ชุดนำเข้านี้ถูกย้อนกลับไปแล้ว');
            $blocking = $this->blockers($b, true);
            if ($blocking) {
                // nothing is written: undo the claim and report; 409 is raised by the controller
                DB::table('reg_import_batches')->where('id', $id)->update(['status' => 'committed']);
                return $blocking;
            }
            $n = 0;
            try {
                foreach (DB::table('reg_import_rows')->where('batch_id', $id)->whereIn('action', ['create', 'update'])->orderByDesc('row_no')->cursor() as $row) {
                    $after = $row->after === null ? [] : (json_decode($row->after, true) ?: []);
                    if (!empty($after['_pending_id'])) DB::table('reg_identifiers')->where('id', (int) $after['_pending_id'])->where('school_id', $this->school())->where('version', 1)->delete();
                    $cur = DB::table($table)->where('id', $row->entity_id)->where('school_id', $this->school())->first();
                    if (!$cur) continue;
                    if ($row->action === 'create') {
                        if ($table === 'reg_student_guardians') RegistryRepository::revokePortalLinks((int) $cur->student_id, (int) $cur->guardian_id, $this->school());
                        $gone = DB::table($table)->where('id', $row->entity_id)->where('school_id', $this->school())->where('version', $cur->version)->delete();
                        if ($gone === 0) abort(409, 'ไม่สามารถลบแถวที่นำเข้าได้ (ข้อมูลเปลี่ยนระหว่างย้อนกลับ) แถวที่ '.$row->row_no);
                    } else {
                        $before = json_decode($row->before, true) ?: [];
                        $restore = array_diff_key($before, array_flip(['id', 'school_id', 'created_at', 'version']));
                        $restore['version'] = (int) $cur->version + 1;
                        $restore['updated_at'] = now();
                        $hit = DB::table($table)->where('id', $row->entity_id)->where('school_id', $this->school())->where('version', $cur->version)->update($restore);
                        abort_if($hit === 0, 409, 'ข้อมูลถูกแก้ไขระหว่างย้อนกลับ กรุณาลองใหม่ แถวที่ '.$row->row_no);
                        if ($table === 'students' && array_key_exists('classroom_id', $restore)) \App\Support\Saan\ClassroomMoves::record($this->school(), (int) $row->entity_id, $cur->classroom_id === null ? null : (int) $cur->classroom_id, $restore['classroom_id'] === null ? null : (int) $restore['classroom_id']);
                    }
                    $n++;
                }
            } catch (QueryException) {
                abort(409, 'ย้อนกลับไม่ได้ เพราะมีข้อมูลของโมดูลอื่นอ้างถึงแถวที่นำเข้า');
            }
            Mutation::audit('registry.import.rolled_back', 'reg_import_batches', $id, ['kind' => $b->kind, 'rows' => $n]);
            return null;
        });
        if ($blocking) return ['blocking' => $blocking];
        return $this->batch($id);
    }
}