<?php

namespace Saan\Modules\ExamChecker;

use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bulk save of answer sheets and whole-exam rescoring. Everything is set-based: constant query count per request. */
final class SheetService
{
    public const MAX_ROWS = 500;

    /**
     * Save sheets for an exam: 1 query for students, 1 for existing sheets, 1 exam lock, 1 insert, 1 update, 1 audit, 1 id read-back (new rows only).
     * rows: [{line?, student_code, answers, display_name?, version?}]
     * opts: overwrite (replace an existing sheet without a version), allow_unknown (managers only), source, dry_run
     * A stale version anywhere aborts the whole request with VersionConflict (409); other row problems are returned in 'errors'.
     * @return array{saved:array,errors:array,created:int,updated:int,unchanged:int}
     */
    public static function save(object $exam, array $rows, array $opts = []): array
    {
        $school = Access::school();
        $scorer = Scorer::forExam($exam);
        $n = (int) $exam->question_count;
        $choices = (int) $exam->choices_per_question;
        $allowed = Access::classroomIds($exam);          // null = all
        $allowUnknown = in_array(auth()->user()?->role, ['admin', 'principal'], true) && !empty($opts['allow_unknown']);
        $source = in_array($opts['source'] ?? 'keyed', ['keyed', 'csv', 'omr_csv'], true) ? $opts['source'] ?? 'keyed' : 'keyed';
        $errors = [];
        $clean = [];

        foreach (array_values($rows) as $idx => $r) {
            $line = $r['line'] ?? ($idx + 1);
            $code = trim((string) ($r['student_code'] ?? ''));
            $fail = function (string $m) use (&$errors, $line, $code) { $errors[] = ['row' => $line, 'student_code' => $code, 'message' => $m]; };
            if ($code === '' || mb_strlen($code) > 60) { $fail('รหัสนักเรียนว่างหรือยาวเกินไป'); continue; }
            $key = Access::codeKey($code);
            if (isset($clean[$key])) { $fail('รหัสนักเรียนซ้ำในข้อมูลที่ส่ง'); continue; }
            [$ans, $count, $e] = Scoring::parseAnswers((string) ($r['answers'] ?? ''), $choices);
            if ($e) { $fail($e); continue; }
            if ($count !== $n) { $fail('จำนวนคำตอบ '.$count.' ข้อ ไม่ตรงกับข้อสอบ '.$n.' ข้อ'); continue; }
            $clean[$key] = ['line' => $line, 'code' => $code, 'answers' => $ans, 'name' => trim((string) ($r['display_name'] ?? '')),
                'version' => isset($r['version']) && $r['version'] !== '' ? (int) $r['version'] : null];
        }
        if (!$clean) return ['saved' => [], 'errors' => $errors, 'created' => 0, 'updated' => 0, 'unchanged' => 0];

        $keys = array_keys($clean);
        $students = self::students($school, $keys, array_column($clean, 'code'));
        $existing = DB::table('exm_sheets')->where('school_id', $school)->where('exam_id', $exam->id)->whereIn('student_code_key', $keys)
            ->get(['id', 'student_code_key', 'student_code', 'display_name', 'answers', 'score', 'section_scores', 'version', 'classroom_id'])->keyBy('student_code_key');

        $insert = []; $update = []; $unchanged = []; $stale = []; $saved = [];
        $now = now();
        foreach ($clean as $key => $r) {
            $fail = function (string $m) use (&$errors, $r) { $errors[] = ['row' => $r['line'], 'student_code' => $r['code'], 'message' => $m]; };
            $stu = $students[$key] ?? null;
            if (!$stu && !$allowUnknown) { $fail('ไม่พบรหัสนักเรียนนี้ในทะเบียน'); continue; }
            if ($stu && $allowed !== null && !\App\Support\Saan\TeacherScope::canSeeStudent($stu)) { $fail('นักเรียนคนนี้ไม่ได้อยู่ในสถานะกำลังศึกษา'); continue; } // a former pupil keeps classroom_id: teachers cannot file sheets for them
            $cid = $stu && $stu->classroom_id ? (int) $stu->classroom_id : null;
            $old = $existing[$key] ?? null;
            if ($allowed !== null && (($cid === null || !in_array($cid, $allowed, true)) || ($old && $old->classroom_id && !in_array((int) $old->classroom_id, $allowed, true)))) {
                $fail('ไม่มีสิทธิ์บันทึกใบตอบของห้องนี้'); continue;
            }
            [$score, $sections] = $scorer->score($r['answers']);
            if ($old) {
                if ($r['version'] === null && empty($opts['overwrite'])) { $fail('นักเรียนคนนี้มีใบตอบแล้ว ต้องระบุ version หรือเลือกเขียนทับ'); continue; }
                $expected = $r['version'] ?? (int) $old->version;
                if ($expected !== (int) $old->version) {
                    $stale[] = ['id' => (int) $old->id, 'student_code' => $old->student_code, 'version' => (int) $old->version, 'answers' => $old->answers, 'score' => (float) $old->score];
                    continue;
                }
                if ($old->answers === $r['answers']) { $unchanged[$key] = true; $saved[$key] = self::present($old); continue; }
                $json = $sections ? json_encode($sections, JSON_UNESCAPED_UNICODE) : null;
                $update[] = ['id' => (int) $old->id, 'version' => $expected, 'key' => $key, 'answers' => $r['answers'], 'score' => $score, 'sections' => $json];
                $saved[$key] = self::present((object) ['id' => $old->id, 'student_code' => $old->student_code, 'display_name' => $old->display_name,
                    'classroom_id' => $old->classroom_id, 'answers' => $r['answers'], 'score' => $score, 'section_scores' => $json, 'version' => $expected + 1]);
            } else {
                $insert[] = [
                    'school_id' => $school, 'exam_id' => (int) $exam->id, 'student_id' => $stu ? (int) $stu->id : null,
                    'student_code' => $stu ? $stu->code : $r['code'], 'student_code_key' => $key,
                    'display_name' => mb_substr($stu ? ($stu->name ?: $r['code']) : ($r['name'] !== '' ? $r['name'] : $r['code']), 0, 200),
                    'classroom_id' => $cid, 'classroom_label' => $stu ? $stu->classroom_name : null,
                    'answers' => $r['answers'], 'score' => $score, 'section_scores' => $sections ? json_encode($sections, JSON_UNESCAPED_UNICODE) : null,
                    'source' => $source, 'recorded_by' => auth()->id(), 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        if ($stale) throw new VersionConflict(['stale' => $stale], 'ใบตอบถูกแก้ไขโดยผู้อื่นแล้ว กรุณาโหลดใหม่');

        $result = ['created' => count($insert), 'updated' => count($update), 'unchanged' => count($unchanged)];
        if (!empty($opts['dry_run'])) {
            return $result + ['saved' => [], 'errors' => $errors, 'preview' => array_merge(array_map(fn ($u) => ['student_code' => $u['key'], 'score' => $u['score']], $update),
                array_map(fn ($i) => ['student_code' => $i['student_code'], 'score' => $i['score']], $insert))];
        }

        if ($insert || $update) {
            try {
                DB::transaction(function () use ($insert, $update, $exam, $source, $now) {
                    // a key/settings change commits (and rescores) under this row lock; scoring with a stale exam row is refused
                    $v = DB::table('exm_exams')->where('school_id', Access::school())->where('id', $exam->id)->lockForUpdate()->value('version');
                    if ((int) $v !== (int) $exam->version) throw new VersionConflict(null, 'เฉลยหรือการตั้งค่าข้อสอบเพิ่งถูกแก้ไข กรุณาโหลดใหม่');
                    foreach (array_chunk($insert, 100) as $chunk) DB::table('exm_sheets')->insert($chunk);
                    if ($update) self::updateMany((int) $exam->id, $update, $source, $now);
                    Mutation::audit('exam_checker.sheets_saved', 'exm_exams', (int) $exam->id, [
                        'created' => count($insert), 'updated' => count($update), 'source' => $source,
                        'codes' => array_slice(array_merge(array_column($insert, 'student_code'), array_column($update, 'key')), 0, 50),
                    ]);
                });
            } catch (QueryException $e) {
                if (self::isUnique($e)) abort(409, 'มีผู้อื่นบันทึกใบตอบของนักเรียนคนนี้ไปพร้อมกัน กรุณาโหลดใหม่');
                throw $e;
            }
        }

        if ($insert) {
            // only the new ids are read back; everything else is already known in memory
            $ids = DB::table('exm_sheets')->where('school_id', $school)->where('exam_id', $exam->id)
                ->whereIn('student_code_key', array_column($insert, 'student_code_key'))->pluck('id', 'student_code_key');
            foreach ($insert as $i) {
                $saved[$i['student_code_key']] = self::present((object) ($i + ['id' => $ids[$i['student_code_key']] ?? 0]));
            }
        }
        return $result + ['saved' => array_values($saved), 'errors' => $errors];
    }

    public static function present(object $s): array
    {
        return ['id' => (int) $s->id, 'student_code' => $s->student_code, 'display_name' => $s->display_name ?? null,
            'classroom_id' => $s->classroom_id !== null ? (int) $s->classroom_id : null, 'answers' => $s->answers, 'score' => (float) $s->score,
            'section_scores' => json_decode((string) ($s->section_scores ?? ''), true) ?: [], 'version' => (int) $s->version];
    }

    /** Rescore every sheet of the exam in chunks (single pass). Caller wraps this in the transaction that changed the key. */
    public static function rescore(object $exam): array
    {
        $scorer = Scorer::forExam($exam);
        $total = 0; $changed = 0;
        DB::table('exm_sheets')->where('school_id', Access::school())->where('exam_id', $exam->id)
            ->select('id', 'answers', 'score', 'section_scores')->orderBy('id')
            ->chunkById(500, function ($chunk) use ($scorer, &$total, &$changed) {
                $upd = [];
                foreach ($chunk as $s) {
                    $total++;
                    [$score, $sections] = $scorer->score((string) $s->answers);
                    $old = json_decode((string) ($s->section_scores ?? ''), true) ?: [];
                    if (abs((float) $s->score - $score) > 0.004 || $old != $sections) {
                        $upd[] = [(int) $s->id, $score, $sections ? json_encode($sections, JSON_UNESCAPED_UNICODE) : null];
                    }
                }
                if ($upd) { $changed += count($upd); self::applyScores($upd); }
            }, 'id');
        return ['total' => $total, 'changed' => $changed];
    }

    private static function applyScores(array $upd): void
    {
        $n = count($upd);
        $sql = 'UPDATE exm_sheets SET score = CASE id'.str_repeat(' WHEN ? THEN ?', $n).' END, section_scores = CASE id'.str_repeat(' WHEN ? THEN ?', $n)
            .' END, updated_at = ?, version = version + 1 WHERE school_id = ? AND id IN ('.implode(',', array_fill(0, $n, '?')).')';
        $b = [];
        foreach ($upd as $u) { $b[] = $u[0]; $b[] = $u[1]; }
        foreach ($upd as $u) { $b[] = $u[0]; $b[] = $u[2]; }
        $b[] = now(); $b[] = Access::school();
        foreach ($upd as $u) $b[] = $u[0];
        DB::update($sql, $b);
    }

    /** One UPDATE for many sheets, each guarded by its own expected version. */
    private static function updateMany(int $examId, array $upd, string $source, $now): void
    {
        $n = count($upd);
        $case = fn (string $col) => $col.' = CASE id'.str_repeat(' WHEN ? THEN ?', $n).' END';
        $sql = 'UPDATE exm_sheets SET '.$case('answers').', '.$case('score').', '.$case('section_scores')
            .', source = ?, recorded_by = ?, updated_at = ?, version = version + 1 WHERE school_id = ? AND exam_id = ? AND ('
            .implode(' OR ', array_fill(0, $n, '(id = ? AND version = ?)')).')';
        $b = [];
        foreach (['answers', 'score', 'sections'] as $f) foreach ($upd as $u) { $b[] = $u['id']; $b[] = $u[$f]; }
        array_push($b, $source, auth()->id(), $now, Access::school(), $examId);
        foreach ($upd as $u) { $b[] = $u['id']; $b[] = $u['version']; }
        if (DB::update($sql, $b) !== $n) {
            $stale = DB::table('exm_sheets')->where('school_id', Access::school())->whereIn('id', array_column($upd, 'id'))
                ->get(['id', 'student_code', 'version', 'answers', 'score'])->map(fn ($s) => (array) $s)->all();
            throw new VersionConflict(['stale' => $stale], 'ใบตอบถูกแก้ไขโดยผู้อื่นแล้ว กรุณาโหลดใหม่');
        }
    }

    /** @return array<string,object> students keyed by Access::codeKey of their registry code */
    private static function students(int $school, array $keys, array $codes): array
    {
        static $hasKey = null;
        $hasKey ??= Schema::hasColumn('students', 'code_key');
        $base = fn () => DB::table('students')->leftJoin('classrooms', 'classrooms.id', '=', 'students.classroom_id')->where('students.school_id', $school);
        $cols = ['students.id', 'students.code', 'students.name', 'students.classroom_id', 'classrooms.name as classroom_name'];
        static $hasStatus = null;
        $hasStatus ??= Schema::hasColumn('students', 'status');
        if ($hasStatus) $cols[] = 'students.status';
        // the registry stores code_key = mb_strtolower(trim(code)) without collapsing inner spaces; look up both spellings
        $regKeys = array_values(array_unique(array_merge($keys, array_map(fn ($c) => mb_strtolower(trim((string) $c)), $codes))));
        $out = [];
        $q = $base()->where(function ($w) use ($keys, $regKeys, $codes, $hasKey) {
            if ($hasKey) $w->whereIn('students.code_key', $regKeys)->orWhereIn('students.code', $codes);
            else $w->whereIn('students.code', $codes);
        });
        foreach ($q->get($cols) as $s) $out[Access::codeKey((string) $s->code)] ??= $s;
        // legacy rows without code_key on a case-sensitive collation: second look only for codes still unresolved
        $missing = array_values(array_diff($keys, array_keys($out)));
        if ($missing) {
            foreach ($base()->whereIn(DB::raw('lower(trim(students.code))'), array_values(array_unique(array_merge($missing, $regKeys))))->get($cols) as $s) $out[Access::codeKey((string) $s->code)] ??= $s;
        }
        return $out;
    }

    public static function isUnique(QueryException $e): bool
    {
        return \App\Support\Saan\DbError::isUnique($e);

    }
}
