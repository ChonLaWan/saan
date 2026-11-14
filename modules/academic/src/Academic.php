<?php

namespace Saan\Modules\Academic;

use App\Domain\PasswordLinks;
use App\Domain\SchoolAccounts;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Base data of a school: terms, bell periods, classrooms, teachers, calendar days (GAP-OPS-001).
 * Every query is scoped by school_id = config('school.school_id'); every write goes through Mutation (version + audit).
 * Business-rule failures are 422 ValidationException; stale versions are VersionConflict (409).
 */
class Academic
{
    public const PERIOD_KINDS = ['class', 'break', 'homeroom'];
    public const DAY_KINDS = ['holiday', 'event', 'exam', 'no_class'];
    public const PREFIXES = ['นางสาว', 'น.ส.', 'นาย', 'นาง', 'ว่าที่ร.ต.', 'ว่าที่ ร.ต.', 'ผศ.ดร.', 'ผศ.', 'รศ.', 'ศ.', 'ดร.', 'อาจารย์', 'ครู', 'Mr.', 'Mrs.', 'Miss', 'Ms.'];
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/D';

    public function school(): int
    {
        return (int) config('school.school_id');
    }

    private function q(string $table)
    {
        return DB::table($table)->where('school_id', $this->school());
    }

    /** Case/space/Unicode-insensitive key for uniqueness. Never rely on DB collation (MySQL and MariaDB defaults differ). */
    public static function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_strtolower((string) preg_replace('/\s+/u', '', $v));
    }

    /** Teacher identity key: first + last name only (prefix excluded, so a change of title is not a new person). */
    public static function teacherKey(?string $first, ?string $last): string
    {
        return mb_substr(self::key(trim((string) $first.' '.(string) $last)), 0, 200);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** Run a write; a unique-index race becomes a clear 422 instead of a 500. */
    private function guard(\Closure $fn, string $field, string $message): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            // only a unique-key collision is a "duplicate"; a foreign-key or other constraint failure is a real error
            $m = strtolower($e->getMessage());
            $driverCode = (int) ($e->errorInfo[1] ?? 0); // MySQL/MariaDB 1062 = duplicate entry, 1452 = foreign key
            if ($driverCode === 1062 || str_contains($m, 'unique constraint failed') || str_contains($m, 'duplicate entry')) $this->fail($field, $message);
            throw $e;
        }
    }

    // ---------------------------------------------------------------- terms

    public function termRow(object $r, ?int $currentId): array
    {
        return ['id' => (int) $r->id, 'year' => (int) $r->year, 'term' => (int) $r->term, 'label' => $r->label, 'starts_on' => (string) $r->starts_on,
            'ends_on' => (string) $r->ends_on, 'version' => (int) $r->version, 'is_current' => $currentId !== null && (int) $r->id === $currentId];
    }

    public function terms(): array
    {
        $current = TermScope::currentId();
        return $this->q('academic_terms')->orderByDesc('year')->orderByDesc('term')->get()->map(fn ($r) => $this->termRow($r, $current))->all();
    }

    private function checkTerm(array $d, ?int $ignoreId): void
    {
        $year = (int) ($d['year'] ?? 0);
        $term = (int) ($d['term'] ?? 0);
        if ($year < 2500 || $year > 2700) $this->fail('year', 'ปีการศึกษาต้องเป็น พ.ศ. ระหว่าง 2500 ถึง 2700');
        if ($term < 1 || $term > 3) $this->fail('term', 'ภาคเรียนต้องเป็น 1 ถึง 3');
        foreach (['starts_on', 'ends_on'] as $f) {
            $ok = is_string($d[$f] ?? null) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $d[$f], $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
            if (!$ok) $this->fail($f, 'วันที่ไม่ถูกต้อง (รูปแบบ ปปปป-ดด-วว)');
        }
        if ($d['starts_on'] >= $d['ends_on']) $this->fail('ends_on', 'วันสิ้นสุดต้องอยู่หลังวันเริ่มภาคเรียน');
        // the Buddhist-era year and the calendar dates must agree (a term of 2569 starts in 2026, or in Jan-Mar 2027 for a late start)
        $ce = (int) substr($d['starts_on'], 0, 4);
        if ($ce !== $year - 543 && $ce !== $year - 542) $this->fail('starts_on', 'วันเริ่มภาคเรียนไม่ตรงกับปีการศึกษา '.$year);
        $overlap = $this->q('academic_terms')->where('starts_on', '<=', $d['ends_on'])->where('ends_on', '>=', $d['starts_on'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->first();
        if ($overlap) $this->fail('starts_on', 'ช่วงวันที่ซ้อนกับภาคเรียน '.$overlap->year.'/'.$overlap->term.' ('.$overlap->starts_on.' ถึง '.$overlap->ends_on.')');
        $dup = $this->q('academic_terms')->where('year', $year)->where('term', $term)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($dup) $this->fail('term', 'มีภาคเรียน '.$year.'/'.$term.' อยู่แล้ว');
    }

    public function createTerm(array $d): array
    {
        $this->checkTerm($d, null);
        return DB::transaction(function () use ($d) {
            $first = !$this->q('academic_terms')->exists();
            $row = $this->guard(fn () => Mutation::insert('academic_terms', [
                'year' => (int) $d['year'], 'term' => (int) $d['term'], 'label' => $this->clean($d['label'] ?? null),
                'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'],
            ], 'academic.term.created'), 'term', 'มีภาคเรียนนี้อยู่แล้ว');
            if ($first) Settings::put('academic.current_term_id', (int) $row->id);
            return $this->termRow($row, TermScope::currentId());
        });
    }

    public function updateTerm(int $id, int $version, array $d): array
    {
        $this->q('academic_terms')->where('id', $id)->first() ?? abort(404);
        $this->checkTerm($d, $id);
        $row = $this->guard(fn () => Mutation::update('academic_terms', $id, $version, [
            'year' => (int) $d['year'], 'term' => (int) $d['term'], 'label' => $this->clean($d['label'] ?? null),
            'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'],
        ], 'academic.term.updated'), 'term', 'มีภาคเรียนนี้อยู่แล้ว');
        return $this->termRow($row, TermScope::currentId());
    }

    public function deleteTerm(int $id, int $version): void
    {
        $this->q('academic_terms')->where('id', $id)->first() ?? abort(404);
        // core (/api/state, SchoolOperations) still reads config('school.term_id') directly; deleting that row would 404 the operations pages
        if ($id === (int) config('school.term_id')) $this->fail('term', 'ลบไม่ได้ ภาคเรียนนี้ระบบงานโรงเรียนใช้อยู่ ให้แก้ไขวันที่แทน');
        // every table that keys rows by term: deleting the term would leave them unreachable (their queries join academic_terms)
        $guarded = ['lessons' => 'ตารางสอน', 'grade_entries' => 'ผลการเรียน', 'asm_courses' => 'รายวิชาและคะแนน', 'asm_attributes' => 'ผลคุณลักษณะ/สมรรถนะ',
            'exm_exams' => 'ข้อสอบ', 'rpt_remarks' => 'หมายเหตุ ปพ.6', 'bhv_records' => 'บันทึกพฤติกรรม', 'hlth_measurements' => 'ข้อมูลสุขภาพ', 'fin_invoices' => 'ใบแจ้งหนี้'];
        $checks = [];
        foreach ($guarded as $table => $label) $checks[] = [$table, 'term_id', $label];
        // tables other modules declare in module.json ("term_tables"), so the list cannot fall behind new modules
        foreach (app(\App\Support\Modules\ModuleRegistry::class)->declaredTables('term_tables') as $d) $checks[] = [$d['table'], $d['column'], $d['label']];
        DB::transaction(function () use ($id, $version, $checks) {
            // lock the term row first, then check: a club or project created while this runs cannot slip in after the check
            $this->q('academic_terms')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            foreach ($checks as [$table, $column, $label]) {
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) continue;
                $q = DB::table($table)->where($column, $id);
                if (Schema::hasColumn($table, 'school_id')) $q->where('school_id', $this->school());
                if ($q->exists()) $this->fail('term', 'ลบไม่ได้ ภาคเรียนนี้มี'.$label.'อยู่');
            }
            Mutation::delete('academic_terms', $id, $version, 'academic.term.deleted');
            $this->q('acad_calendar_days')->where('term_id', $id)->update(['term_id' => null]);
            if ((int) Settings::get('academic.current_term_id', 0) === $id) Settings::put('academic.current_term_id', null);
        });
    }

    /** Version of the saan_settings row that holds the current term; null while the school has never set one. */
    public function currentTermSettingVersion(): ?int
    {
        $v = DB::table('saan_settings')->where('school_id', $this->school())->where('key', 'academic.current_term_id')->value('version');
        return $v === null ? null : (int) $v;
    }

    /**
     * Make a term the current one. When $expectVersion is given (the controller passes it whenever the client sent
     * expected_version, null meaning "no setting yet") a different stored version is a 409 instead of a silent overwrite.
     */
    public function setCurrentTerm(int $id, bool $check = false, ?int $expectVersion = null): array
    {
        $this->q('academic_terms')->where('id', $id)->first() ?? abort(404);
        DB::transaction(function () use ($id, $check, $expectVersion) {
            $have = $this->currentTermSettingVersion();
            if ($check && $have !== $expectVersion) {
                throw new VersionConflict(['current_term_id' => TermScope::currentId(), 'version' => $have]);
            }
            Settings::put('academic.current_term_id', $id);
            Mutation::audit('academic.term.current', 'academic_terms', $id);
        });
        return $this->termRow($this->q('academic_terms')->where('id', $id)->first(), $id) + ['setting_version' => $this->currentTermSettingVersion()];
    }

    // -------------------------------------------------------------- periods

    private function periodToken(iterable $rows): string
    {
        $parts = [];
        foreach ($rows as $r) $parts[] = $r->id.':'.$r->version;
        return sha1(implode(',', $parts));
    }

    public function periods(): array
    {
        $rows = $this->q('bell_periods')->orderBy('starts_at')->orderBy('number')->get();
        return ['data' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'number' => (int) $r->number, 'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at,
            'kind' => $r->kind ?: 'class', 'version' => (int) $r->version])->all(), 'token' => $this->periodToken($rows)];
    }

    /**
     * Replace the whole bell schedule. Class periods are numbered 1..n in order; break/homeroom rows get 101.. so the
     * numbers lessons use stay meaningful. Rows are matched by number and updated in place so lesson foreign keys survive.
     */
    public function replacePeriods(array $rows, string $token): array
    {
        if (count($rows) < 1 || count($rows) > 24) $this->fail('periods', 'ต้องมีคาบ 1 ถึง 24 รายการ');
        $target = [];
        $c = 0;
        $b = 0;
        $prevEnd = null;
        foreach (array_values($rows) as $i => $r) {
            $n = $i + 1;
            $kind = (string) ($r['kind'] ?? 'class');
            $s = (string) ($r['starts_at'] ?? '');
            $e = (string) ($r['ends_at'] ?? '');
            if (!in_array($kind, self::PERIOD_KINDS, true)) $this->fail('periods', "แถวที่ $n: ชนิดคาบไม่ถูกต้อง");
            if (!preg_match(self::TIME, $s) || !preg_match(self::TIME, $e)) $this->fail('periods', "แถวที่ $n: เวลาต้องเป็นรูปแบบ HH:MM");
            if ($s >= $e) $this->fail('periods', "แถวที่ $n: เวลาเริ่มต้องก่อนเวลาสิ้นสุด");
            if ($prevEnd !== null && $s < $prevEnd) $this->fail('periods', "แถวที่ $n: เวลาซ้อนทับหรือเรียงไม่ต่อจากแถวก่อนหน้า");
            $prevEnd = $e;
            $number = $kind === 'class' ? ++$c : 100 + ++$b;
            $target[$number] = ['starts_at' => $s, 'ends_at' => $e, 'kind' => $kind];
        }
        if ($c < 1) $this->fail('periods', 'ต้องมีอย่างน้อยหนึ่งคาบเรียน');

        return DB::transaction(function () use ($target, $token) {
            $existing = $this->q('bell_periods')->orderBy('starts_at')->orderBy('number')->lockForUpdate()->get();
            if (!hash_equals($this->periodToken($existing), $token)) throw new VersionConflict($this->periods());
            $byNumber = $existing->keyBy('number');
            foreach ($existing as $old) {
                if (isset($target[(int) $old->number])) continue;
                if ($this->q('lessons')->where('period_id', $old->id)->exists()) $this->fail('periods', 'คาบที่ '.$old->number.' ('.$old->starts_at.'-'.$old->ends_at.') มีตารางสอนใช้อยู่ ลบหรือเปลี่ยนชนิดไม่ได้');
                Mutation::delete('bell_periods', (int) $old->id, (int) $old->version, 'academic.period.deleted');
            }
            foreach ($target as $number => $v) {
                $old = $byNumber->get($number);
                if (!$old) { Mutation::insert('bell_periods', ['number' => $number] + $v, 'academic.period.created'); continue; }
                if ($old->starts_at === $v['starts_at'] && $old->ends_at === $v['ends_at'] && ($old->kind ?: 'class') === $v['kind']) continue;
                if (($old->kind ?: 'class') !== $v['kind'] && $this->q('lessons')->where('period_id', $old->id)->exists()) $this->fail('periods', 'คาบที่ '.$old->number.' มีตารางสอนใช้อยู่ เปลี่ยนชนิดไม่ได้');
                Mutation::update('bell_periods', (int) $old->id, (int) $old->version, $v, 'academic.period.updated');
            }
            Mutation::audit('academic.periods.replaced', 'bell_periods', null, ['count' => count($target)]);
            return $this->periods();
        });
    }

    // ----------------------------------------------------------- classrooms

    /** Give legacy/demo rows (inserted without name_key) a key so duplicate checks can rely on the column. */
    private function ensureKeys(): void
    {
        // legacy classrooms ("ป.4/1") also get grade_level/section so they sort and group like new ones
        foreach ($this->q('classrooms')->whereNull('grade_level')->get(['id', 'name']) as $r) {
            if (preg_match('~^\s*(\S+?)\s*/\s*(\d{1,3})\s*$~u', (string) $r->name, $m) && mb_strlen($m[1]) <= 20) {
                $this->q('classrooms')->where('id', $r->id)->update(['grade_level' => $m[1], 'section' => (int) $m[2]]);
            }
        }
        foreach (['classrooms' => 110, 'teachers' => 200] as $table => $len) {
            if (!$this->q($table)->whereNull('name_key')->exists()) continue;
            $taken = $this->q($table)->whereNotNull('name_key')->pluck('name_key')->flip()->all();
            foreach ($this->q($table)->whereNull('name_key')->orderBy('id')->get(['id', 'name']) as $r) {
                if ($table === 'teachers') {
                    // same key as new rows: first + last name only, so "นายสมชาย ใจดี" and "สมชาย ใจดี" are the same person
                    $p = $this->parseTeacherLine((string) $r->name);
                    $k = isset($p['error']) ? mb_substr(self::key((string) $r->name), 0, $len) : self::teacherKey($p['first_name'], $p['last_name']);
                } else {
                    $k = mb_substr(self::key((string) $r->name), 0, $len);
                }
                if (isset($taken[$k])) $k = mb_substr($k, 0, $len - 10).'~'.$r->id;
                $taken[$k] = true;
                $this->q($table)->where('id', $r->id)->update(['name_key' => $k]);
            }
        }
    }

    public function classroomRow(object $r): array
    {
        return ['id' => (int) $r->id, 'name' => $r->name, 'grade_level' => $r->grade_level, 'section' => $r->section === null ? null : (int) $r->section,
            'homeroom_teacher_id' => $r->homeroom_teacher_id === null ? null : (int) $r->homeroom_teacher_id, 'homeroom_teacher_name' => $r->homeroom_teacher_name ?? null,
            'is_active' => (bool) $r->is_active, 'version' => (int) $r->version, 'student_count' => (int) ($r->student_count ?? 0)];
    }

    private function classroomQuery()
    {
        return DB::table('classrooms')->where('classrooms.school_id', $this->school())
            ->leftJoin('teachers', fn ($j) => $j->on('teachers.id', '=', 'classrooms.homeroom_teacher_id')->on('teachers.school_id', '=', 'classrooms.school_id'))
            ->select('classrooms.*', 'teachers.name as homeroom_teacher_name',
                DB::raw('(select count(*) from students s where s.classroom_id = classrooms.id and s.school_id = classrooms.school_id) as student_count'));
    }

    public function classrooms(): array
    {
        $this->ensureKeys();
        // school order, not code-point order: kindergarten (อ) -> primary (ป) -> secondary (ม) -> anything else; then level number, section, name
        $rank = ['อ' => 0, 'ป' => 1, 'ม' => 2];
        $rows = $this->classroomQuery()->get()->all();
        usort($rows, function ($a, $b) use ($rank) {
            $k = function ($r) use ($rank) {
                $g = (string) $r->grade_level;
                return [$g === '' ? 4 : ($rank[mb_substr($g, 0, 1)] ?? 3), (int) preg_replace('/\D+/', '', $g), $r->section === null ? PHP_INT_MAX : (int) $r->section, (string) $r->name];
            };
            return $k($a) <=> $k($b);
        });
        return array_map(fn ($r) => $this->classroomRow($r), $rows);
    }

    private function oneClassroom(int $id): array
    {
        return $this->classroomRow($this->classroomQuery()->where('classrooms.id', $id)->first() ?? abort(404));
    }

    private function gradeLevel(mixed $v): ?string
    {
        $v = $this->clean($v);
        if ($v === null) return null;
        if (mb_strlen($v) > 20 || !preg_match('/^[\p{L}\p{M}\p{N}.\- ]+$/u', $v)) $this->fail('grade_level', 'ระดับชั้นต้องยาวไม่เกิน 20 ตัวอักษร (ตัวอักษร ตัวเลข จุด)');
        return preg_replace('/\s+/u', '', $v);
    }

    /** $current is the room's existing homeroom teacher: keeping an unchanged (even deactivated) teacher is always allowed. */
    private function homeroomTeacher(mixed $id, ?int $current = null): ?int
    {
        if ($id === null || $id === '') return null;
        if ($current !== null && (int) $id === $current && $this->q('teachers')->where('id', $current)->exists()) return $current;
        $ok = $this->q('teachers')->where('id', (int) $id)->where('is_active', true)->exists();
        if (!$ok) $this->fail('homeroom_teacher_id', 'ไม่พบครูที่เลือก หรือครูถูกปิดใช้งาน');
        return (int) $id;
    }

    private function classroomValues(array $d): array
    {
        $grade = $this->gradeLevel($d['grade_level'] ?? null);
        $section = isset($d['section']) && $d['section'] !== '' ? (int) $d['section'] : null;
        if ($section !== null && ($section < 1 || $section > 99)) $this->fail('section', 'ห้องที่ต้องเป็น 1 ถึง 99');
        $name = $this->clean($d['name'] ?? null);
        if ($name === null && $grade !== null) $name = $grade.($section !== null ? '/'.$section : '');
        if ($name === null) $this->fail('name', 'กรุณากรอกชื่อห้องเรียนหรือระดับชั้น');
        $name = preg_replace('/\s+/u', ' ', $name);
        if (mb_strlen($name) > 60) $this->fail('name', 'ชื่อห้องเรียนยาวเกิน 60 ตัวอักษร');
        return ['name' => $name, 'grade_level' => $grade, 'section' => $section, 'name_key' => self::key($name)];
    }

    private function assertClassroomFree(string $key, ?int $ignoreId): void
    {
        if ($this->q('classrooms')->where('name_key', $key)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $this->fail('name', 'มีห้องเรียนชื่อนี้อยู่แล้ว (ไม่สนตัวพิมพ์เล็กใหญ่และช่องว่าง)');
        }
    }

    public function createClassroom(array $d): array
    {
        $this->ensureKeys();
        $v = $this->classroomValues($d);
        $v['homeroom_teacher_id'] = $this->homeroomTeacher($d['homeroom_teacher_id'] ?? null);
        $this->assertClassroomFree($v['name_key'], null);
        $row = $this->guard(fn () => Mutation::insert('classrooms', $v + ['is_active' => true], 'academic.classroom.created'), 'name', 'มีห้องเรียนชื่อนี้อยู่แล้ว');
        return $this->oneClassroom((int) $row->id);
    }

    public function updateClassroom(int $id, int $version, array $d): array
    {
        $this->ensureKeys();
        $old = $this->q('classrooms')->where('id', $id)->first() ?? abort(404);
        $v = $this->classroomValues($d);
        $oldTeacher = $old->homeroom_teacher_id === null ? null : (int) $old->homeroom_teacher_id;
        // a PUT that does not mention the homeroom teacher leaves it alone
        $touchHomeroom = array_key_exists('homeroom_teacher_id', $d);
        if ($touchHomeroom) $v['homeroom_teacher_id'] = $this->homeroomTeacher($d['homeroom_teacher_id'], $oldTeacher);
        if (array_key_exists('is_active', $d)) $v['is_active'] = (bool) $d['is_active'];
        $this->assertClassroomFree($v['name_key'], $id);
        DB::transaction(function () use ($id, $version, $v, $touchHomeroom, $oldTeacher) {
            $this->guard(fn () => Mutation::update('classrooms', $id, $version, $v, 'academic.classroom.updated'), 'name', 'มีห้องเรียนชื่อนี้อยู่แล้ว');
            if ($touchHomeroom) $this->syncHomeroomAccounts([$id], array_filter([$oldTeacher, $v['homeroom_teacher_id']]));
        });
        return $this->oneClassroom($id);
    }

    /**
     * Core homeroom-only access reads users.classroom_id, not classrooms.homeroom_teacher_id, so both must move together.
     * Runs in the caller's transaction after the classroom rows changed. Teacher accounts only; SchoolAccounts::save is
     * admin-only while principals may also reassign homerooms, hence the direct, audited update.
     */
    private function syncHomeroomAccounts(array $roomIds, array $teacherIds): void
    {
        $users = fn () => DB::table('users')->where('school_id', $this->school())->where('role', 'teacher');
        $set = function (int $uid, ?int $room) {
            // sessions are revoked only when the account loses a room it had; gaining a room keeps the teacher signed in
            $old = DB::table('users')->where('id', $uid)->value('classroom_id');
            $changes = ['classroom_id' => $room, 'version' => DB::raw('version + 1'), 'updated_at' => now()];
            if ($old !== null && (int) $old !== $room) $changes['auth_version'] = DB::raw('auth_version + 1');
            DB::table('users')->where('id', $uid)->update($changes);
            Mutation::audit('academic.homeroom.account_synced', 'users', $uid, ['classroom_id' => $room]);
        };
        // accounts attached to a room whose homeroom teacher is someone else (or nobody) lose that room
        foreach (array_unique($roomIds) as $room) {
            $hr = $this->q('classrooms')->where('id', $room)->value('homeroom_teacher_id');
            $stale = $users()->where('classroom_id', $room)->when($hr !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('teacher_id')->orWhere('teacher_id', '!=', (int) $hr)))->pluck('id');
            foreach ($stale as $uid) $set((int) $uid, null);
        }
        // accounts of teachers that now lead a room get that room unless they already point at one they lead
        foreach (array_unique(array_map('intval', $teacherIds)) as $tid) {
            $rooms = $this->q('classrooms')->where('homeroom_teacher_id', $tid)->where('is_active', true)->orderBy('name')->pluck('id')->map(fn ($x) => (int) $x)->all();
            if (!$rooms) continue;
            foreach ($users()->where('teacher_id', $tid)->get(['id', 'classroom_id']) as $u) {
                if ($u->classroom_id !== null && in_array((int) $u->classroom_id, $rooms, true)) continue;
                $set((int) $u->id, $rooms[0]);
            }
        }
    }

    public function deleteClassroom(int $id, int $version): void
    {
        $this->q('classrooms')->where('id', $id)->first() ?? abort(404);
        // module tables (assessment, attendance, behavior) only exist when their migrations ran
        foreach (['students' => 'นักเรียน', 'lessons' => 'ตารางสอน', 'users' => 'บัญชีผู้ใช้ที่ผูกห้อง', 'asm_courses' => 'รายวิชาและคะแนน', 'att_class_days' => 'การเช็กชื่อ', 'bhv_records' => 'บันทึกพฤติกรรม'] as $table => $label) {
            if (Schema::hasTable($table) && $this->q($table)->where('classroom_id', $id)->exists()) $this->fail('classroom', 'ลบไม่ได้ ห้องเรียนนี้มี'.$label.'อยู่ ให้ปิดใช้งานแทน');
        }
        Mutation::delete('classrooms', $id, $version, 'academic.classroom.deleted');
    }

    /** "ป.1-ป.6, ม.1-ม.3, อ.1" -> ['ป.1', ... ]. Returns [] when nothing parses. */
    public function expandGrades(string $pattern): array
    {
        $out = [];
        foreach (preg_split('/[,\n;]+/u', $pattern) as $tok) {
            $tok = preg_replace('/\s+/u', '', $tok);
            if ($tok === '') continue;
            if (preg_match('/^(\D+?)(\d{1,2})[-\x{2013}](\D*?)(\d{1,2})$/u', $tok, $m) && ($m[3] === '' || $m[3] === $m[1]) && (int) $m[2] <= (int) $m[4] && (int) $m[4] - (int) $m[2] <= 12) {
                for ($i = (int) $m[2]; $i <= (int) $m[4]; $i++) $out[] = $m[1].$i;
            } else {
                $out[] = $tok;
            }
        }
        return array_values(array_unique($out));
    }

    public function bulkClassrooms(array $grades, int $sections): array
    {
        $this->ensureKeys();
        if ($sections < 1 || $sections > 20) $this->fail('sections', 'จำนวนห้องต่อระดับต้องเป็น 1 ถึง 20');
        $grades = array_values(array_unique(array_map(fn ($g) => (string) $this->gradeLevel($g), $grades)));
        $grades = array_values(array_filter($grades, fn ($g) => $g !== ''));
        if (!$grades) $this->fail('grades', 'กรุณาระบุระดับชั้นอย่างน้อยหนึ่งระดับ');
        if (count($grades) * $sections > 80) $this->fail('grades', 'สร้างได้ครั้งละไม่เกิน 80 ห้อง');
        return DB::transaction(function () use ($grades, $sections) {
            $created = [];
            $skipped = [];
            foreach ($grades as $g) {
                for ($s = 1; $s <= $sections; $s++) {
                    $name = $g.'/'.$s;
                    $key = self::key($name);
                    if ($this->q('classrooms')->where('name_key', $key)->exists()) { $skipped[] = $name; continue; }
                    $row = $this->guard(fn () => Mutation::insert('classrooms', ['name' => $name, 'grade_level' => $g, 'section' => $s, 'name_key' => $key, 'homeroom_teacher_id' => null, 'is_active' => true], 'academic.classroom.created'), 'name', 'มีห้องเรียนชื่อนี้อยู่แล้ว');
                    $created[] = (int) $row->id;
                }
            }
            return ['created' => count($created), 'skipped' => $skipped, 'classrooms' => $this->classrooms()];
        });
    }

    /** Assign homeroom teachers for many classrooms in one all-or-nothing save; any stale version aborts with 409. */
    public function setHomerooms(array $assign): array
    {
        if (!$assign) $this->fail('assignments', 'ไม่มีรายการให้บันทึก');
        DB::transaction(function () use ($assign) {
            $rooms = [];
            $teachers = [];
            foreach ($assign as $a) {
                $id = (int) $a['classroom_id'];
                $old = $this->q('classrooms')->where('id', $id)->first() ?? abort(404);
                $oldTeacher = $old->homeroom_teacher_id === null ? null : (int) $old->homeroom_teacher_id;
                $new = $this->homeroomTeacher($a['teacher_id'] ?? null, $oldTeacher);
                Mutation::update('classrooms', $id, (int) $a['version'], ['homeroom_teacher_id' => $new], 'academic.homeroom.set');
                $rooms[] = $id;
                array_push($teachers, ...array_filter([$oldTeacher, $new]));
            }
            $this->syncHomeroomAccounts($rooms, $teachers);
        });
        return $this->classrooms();
    }

    // ------------------------------------------------------------- teachers

    public function teacherRow(object $r): array
    {
        return ['id' => (int) $r->id, 'name' => $r->name, 'prefix' => $r->prefix, 'first_name' => $r->first_name, 'last_name' => $r->last_name,
            'subject_group' => $r->subject_group ?? ($r->subject !== '' ? $r->subject : null), 'is_active' => (bool) $r->is_active, 'version' => (int) $r->version,
            'has_account' => $r->user_id !== null || (int) ($r->account_count ?? 0) > 0];
    }

    private function teacherQuery()
    {
        return DB::table('teachers')->where('teachers.school_id', $this->school())
            ->select('teachers.*', DB::raw('(select count(*) from users u where u.teacher_id = teachers.id and u.school_id = teachers.school_id) as account_count'));
    }

    public function teachers(): array
    {
        $this->ensureKeys();
        return $this->teacherQuery()->orderBy('teachers.name')->get()->map(fn ($r) => $this->teacherRow($r))->all();
    }

    private function oneTeacher(int $id): array
    {
        return $this->teacherRow($this->teacherQuery()->where('teachers.id', $id)->first() ?? abort(404));
    }

    private function teacherValues(array $d): array
    {
        $prefix = $this->clean($d['prefix'] ?? null);
        $first = $this->clean($d['first_name'] ?? null);
        $last = $this->clean($d['last_name'] ?? null);
        $group = $this->clean($d['subject_group'] ?? null);
        if ($first === null) $this->fail('first_name', 'กรุณากรอกชื่อครู');
        foreach ([['prefix', $prefix, 30], ['first_name', $first, 70], ['last_name', $last, 80], ['subject_group', $group, 120]] as [$f, $v, $max]) {
            if ($v !== null && mb_strlen($v) > $max) $this->fail($f, "ยาวเกิน $max ตัวอักษร");
        }
        $name = trim(preg_replace('/\s+/u', ' ', $prefix.$first.($last !== null ? ' '.$last : '')));
        return ['name' => $name, 'prefix' => $prefix, 'first_name' => $first, 'last_name' => $last, 'subject_group' => $group,
            'subject' => $group ?? '', 'name_key' => self::teacherKey($first, $last)];
    }

    private function assertTeacherFree(string $key, ?int $ignoreId): void
    {
        if ($this->q('teachers')->where('name_key', $key)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $this->fail('first_name', 'มีครูชื่อนี้อยู่แล้ว');
        }
    }

    public function createTeacher(array $d): array
    {
        $this->ensureKeys();
        $v = $this->teacherValues($d);
        $this->assertTeacherFree($v['name_key'], null);
        $row = $this->guard(fn () => Mutation::insert('teachers', $v + ['is_active' => true], 'academic.teacher.created'), 'first_name', 'มีครูชื่อนี้อยู่แล้ว');
        return $this->oneTeacher((int) $row->id);
    }

    public function updateTeacher(int $id, int $version, array $d): array
    {
        $this->ensureKeys();
        $this->q('teachers')->where('id', $id)->first() ?? abort(404);
        $v = $this->teacherValues($d);
        if (array_key_exists('is_active', $d)) $v['is_active'] = (bool) $d['is_active'];
        $this->assertTeacherFree($v['name_key'], $id);
        $this->guard(fn () => Mutation::update('teachers', $id, $version, $v, 'academic.teacher.updated'), 'first_name', 'มีครูชื่อนี้อยู่แล้ว');
        return $this->oneTeacher($id);
    }

    public function deleteTeacher(int $id, int $version): void
    {
        $this->q('teachers')->where('id', $id)->first() ?? abort(404);
        $used = ['lessons' => 'ตารางสอน', 'duties' => 'เวร', 'leave_requests' => 'ใบลา', 'asm_courses' => 'รายวิชาและคะแนน'];
        foreach ($used as $table => $label) {
            if (Schema::hasTable($table) && $this->q($table)->where('teacher_id', $id)->exists()) $this->fail('teacher', 'ลบไม่ได้ ครูคนนี้มี'.$label.'อยู่ ให้ปิดใช้งานแทน');
        }
        if ($this->q('classrooms')->where('homeroom_teacher_id', $id)->exists()) $this->fail('teacher', 'ลบไม่ได้ ครูคนนี้เป็นครูประจำชั้นอยู่');
        if ($this->q('users')->where('teacher_id', $id)->exists()) $this->fail('teacher', 'ลบไม่ได้ ครูคนนี้มีบัญชีผู้ใช้แล้ว ให้ปิดใช้งานแทน');
        Mutation::delete('teachers', $id, $version, 'academic.teacher.deleted');
    }

    /** One line: "คำนำหน้า ชื่อ นามสกุล, กลุ่มสาระ". Returns the fields, or ['error' => reason]. */
    public function parseTeacherLine(string $line): array
    {
        // the subject group follows the first comma or tab (a paste from a spreadsheet is tab separated)
        [$left, $group] = array_pad(preg_split('/[,\t]/u', $line, 2), 2, '');
        $left = trim((string) preg_replace('/\s+/u', ' ', $left));
        if ($left === '') return ['error' => 'ไม่มีชื่อ'];
        // a prefix is only a token from the known list (longest first, so "นางสาว" beats "นาง"); it may be glued to the name
        $prefix = null;
        $list = self::PREFIXES;
        usort($list, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($list as $p) {
            if (mb_stripos($left, $p) === 0) { $prefix = $p; $left = trim(mb_substr($left, mb_strlen($p))); break; }
        }
        $tokens = preg_split('/\s+/u', $left, -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) return ['error' => 'ไม่มีชื่อ'];
        $first = array_shift($tokens);
        return ['prefix' => $prefix, 'first_name' => $first, 'last_name' => $tokens ? implode(' ', $tokens) : null, 'subject_group' => trim($group) !== '' ? trim($group) : null];
    }

    public function bulkTeachers(string $text, bool $dryRun): array
    {
        $this->ensureKeys();
        $lines = preg_split('/\R/u', $text);
        $entries = [];
        foreach ($lines as $i => $raw) if (trim($raw) !== '') $entries[] = [$i + 1, trim($raw)];
        if (!$entries) $this->fail('text', 'กรุณาวางรายชื่อครูอย่างน้อยหนึ่งบรรทัด');
        if (count($entries) > 300) $this->fail('text', 'วางได้ครั้งละไม่เกิน 300 บรรทัด');
        $run = function () use ($entries, $dryRun) {
            $taken = $this->q('teachers')->pluck('name_key')->flip()->all();
            $created = [];
            $skipped = [];
            foreach ($entries as [$no, $raw]) {
                $p = $this->parseTeacherLine($raw);
                if (isset($p['error'])) { $skipped[] = ['line' => $no, 'text' => $raw, 'reason' => $p['error']]; continue; }
                try {
                    $v = $this->teacherValues($p);
                } catch (ValidationException $e) {
                    $skipped[] = ['line' => $no, 'text' => $raw, 'reason' => collect($e->errors())->flatten()->first()];
                    continue;
                }
                if (isset($taken[$v['name_key']])) { $skipped[] = ['line' => $no, 'text' => $raw, 'reason' => 'ชื่อซ้ำ']; continue; }
                $taken[$v['name_key']] = true;
                if (!$dryRun) {
                    try {
                        $this->guard(fn () => Mutation::insert('teachers', $v + ['is_active' => true], 'academic.teacher.created'), 'first_name', 'ชื่อซ้ำ');
                    } catch (ValidationException $e) {
                        $skipped[] = ['line' => $no, 'text' => $raw, 'reason' => 'ชื่อซ้ำ'];
                        continue;
                    }
                }
                $created[] = ['line' => $no, 'name' => $v['name'], 'subject_group' => $v['subject_group']];
            }
            return ['dry_run' => $dryRun, 'created' => $created, 'skipped' => $skipped];
        };
        return $dryRun ? $run() : DB::transaction($run);
    }

    /**
     * Create a login for a teacher through the existing account path (SchoolAccounts + PasswordLinks invite).
     * The password is random and never shown; the teacher sets their own through the e-mailed link.
     */
    public function createTeacherAccount(int $teacherId, string $email): array
    {
        $teacher = $this->q('teachers')->where('id', $teacherId)->first() ?? abort(404);
        if (!$teacher->is_active) $this->fail('teacher', 'ครูคนนี้ถูกปิดใช้งาน');
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) $this->fail('email', 'กรุณาตรวจสอบรูปแบบอีเมล');
        if ($teacher->user_id !== null || $this->q('users')->where('teacher_id', $teacherId)->exists()) $this->fail('teacher', 'ครูคนนี้มีบัญชีแล้ว');
        if (DB::table('users')->where('email', $email)->exists()) $this->fail('email', 'อีเมลนี้มีบัญชีแล้ว');
        $classroom = $this->q('classrooms')->where('homeroom_teacher_id', $teacherId)->where('is_active', true)->orderBy('name')->value('id');

        $user = DB::transaction(function () use ($teacher, $email, $classroom) {
            $user = app(SchoolAccounts::class)->save([
                'name' => $teacher->name, 'email' => $email, 'role' => 'teacher', 'is_active' => true,
                'teacher_id' => (int) $teacher->id, 'classroom_id' => $classroom ? (int) $classroom : null, 'password' => Str::password(32),
            ]);
            Mutation::update('teachers', (int) $teacher->id, (int) $teacher->version, ['user_id' => $user->id], 'academic.teacher.account_linked', ['user_id' => $user->id]);
            return $user;
        });
        $sent = true;
        try {
            app(PasswordLinks::class)->send($user, 'invite');
        } catch (\Throwable $e) {
            report($e);
            $sent = false;
        }
        return ['teacher' => $this->oneTeacher($teacherId), 'user_id' => (int) $user->id, 'email' => $user->email, 'invite_sent' => $sent];
    }

    // ------------------------------------------------------------- calendar

    public function dayRow(object $r): array
    {
        return ['id' => (int) $r->id, 'date' => (string) $r->date, 'kind' => $r->kind, 'title' => $r->title,
            'term_id' => $r->term_id === null ? null : (int) $r->term_id, 'version' => (int) $r->version];
    }

    public function days(?string $from, ?string $to, ?int $termId): array
    {
        return $this->q('acad_calendar_days')->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->when($termId, fn ($q) => $q->where('term_id', $termId))->orderBy('date')->orderBy('title')->get()->map(fn ($r) => $this->dayRow($r))->all();
    }

    private function dayValues(array $d, ?int $ignoreId): array
    {
        $date = (string) ($d['date'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) $this->fail('date', 'วันที่ไม่ถูกต้อง');
        $kind = (string) ($d['kind'] ?? '');
        if (!in_array($kind, self::DAY_KINDS, true)) $this->fail('kind', 'ชนิดวันไม่ถูกต้อง');
        $title = $this->clean($d['title'] ?? null);
        if ($title === null) $this->fail('title', 'กรุณากรอกชื่อวัน');
        if (mb_strlen($title) > 160) $this->fail('title', 'ชื่อยาวเกิน 160 ตัวอักษร');
        $term = isset($d['term_id']) && $d['term_id'] !== '' ? (int) $d['term_id'] : null;
        if ($term !== null && !$this->q('academic_terms')->where('id', $term)->exists()) $this->fail('term_id', 'ไม่พบภาคเรียนที่เลือก');
        // duplicate check on the normalised key, never on the raw title (DB collations differ)
        $titleKey = mb_substr(self::key($title), 0, 160);
        $dup = $this->q('acad_calendar_days')->where('date', $date)->where('title_key', $titleKey)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($dup) $this->fail('title', 'มีรายการชื่อนี้ในวันที่เดียวกันแล้ว');
        return ['date' => $date, 'kind' => $kind, 'title' => $title, 'title_key' => $titleKey, 'term_id' => $term];
    }

    public function createDay(array $d): array
    {
        $v = $this->dayValues($d, null);
        $row = $this->guard(fn () => Mutation::insert('acad_calendar_days', $v, 'academic.day.created'), 'title', 'มีรายการชื่อนี้ในวันที่เดียวกันแล้ว');
        return $this->dayRow($row);
    }

    public function updateDay(int $id, int $version, array $d): array
    {
        $this->q('acad_calendar_days')->where('id', $id)->first() ?? abort(404);
        $v = $this->dayValues($d, $id);
        $row = $this->guard(fn () => Mutation::update('acad_calendar_days', $id, $version, $v, 'academic.day.updated'), 'title', 'มีรายการชื่อนี้ในวันที่เดียวกันแล้ว');
        return $this->dayRow($row);
    }

    public function deleteDay(int $id, int $version): void
    {
        $this->q('acad_calendar_days')->where('id', $id)->first() ?? abort(404);
        Mutation::delete('acad_calendar_days', $id, $version, 'academic.day.deleted');
    }

    // ---------------------------------------------------------------- setup

    /** Wizard checklist. "next" is the first required step that is not done, else "summary". */
    public function setup(): array
    {
        $this->ensureKeys();
        $terms = $this->q('academic_terms')->count();
        $current = TermScope::current();
        $periods = $this->q('bell_periods')->count();
        $classPeriods = $this->q('bell_periods')->where(fn ($q) => $q->where('kind', 'class')->orWhereNull('kind'))->count();
        $rooms = $this->q('classrooms')->where('is_active', true)->count();
        $noHomeroom = $this->q('classrooms')->where('is_active', true)->whereNull('homeroom_teacher_id')->count();
        $teachers = $this->q('teachers')->where('is_active', true)->count();
        $withAccount = $this->q('teachers')->where('is_active', true)->where(fn ($q) => $q->whereNotNull('user_id')
            ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('users')->whereColumn('users.teacher_id', 'teachers.id')->whereColumn('users.school_id', 'teachers.school_id')))->count();
        $steps = [
            ['key' => 'term', 'label' => 'ปีการศึกษา/ภาคเรียน', 'done' => $terms > 0 && $current !== null, 'optional' => false, 'count' => $terms],
            ['key' => 'periods', 'label' => 'คาบเวลา', 'done' => $classPeriods > 0, 'optional' => false, 'count' => $periods],
            ['key' => 'classrooms', 'label' => 'ห้องเรียน', 'done' => $rooms > 0, 'optional' => false, 'count' => $rooms],
            ['key' => 'teachers', 'label' => 'ครู', 'done' => $teachers > 0, 'optional' => false, 'count' => $teachers],
            ['key' => 'homerooms', 'label' => 'ครูประจำชั้น', 'done' => $rooms > 0 && $noHomeroom === 0, 'optional' => false, 'count' => $rooms - $noHomeroom],
            ['key' => 'accounts', 'label' => 'บัญชีครู (ไม่บังคับ)', 'done' => $withAccount > 0, 'optional' => true, 'count' => $withAccount],
        ];
        $next = 'summary';
        foreach ($steps as $s) if (!$s['optional'] && !$s['done']) { $next = $s['key']; break; }
        return [
            'steps' => $steps, 'next' => $next, 'complete' => $next === 'summary',
            'term' => ['count' => $terms, 'current' => $current ? $this->termRow($current, (int) $current->id) : null],
            'periods' => ['count' => $periods, 'class_count' => $classPeriods],
            'classrooms' => ['count' => $rooms, 'without_homeroom' => $noHomeroom],
            'teachers' => ['count' => $teachers],
            'homerooms' => ['assigned' => $rooms - $noHomeroom, 'total' => $rooms, 'missing' => $noHomeroom],
            'accounts' => ['teachers_with_account' => $withAccount, 'teachers_total' => $teachers],
        ];
    }

    private function clean(mixed $v): ?string
    {
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
