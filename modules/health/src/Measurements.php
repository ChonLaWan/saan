<?php

namespace Saan\Modules\Health;

use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Class-grid measurements. Query budget (kept constant per class, never per row):
 * read = term(2) + previous term(1) + students incl. homeroom check(1) + measurements(1) = 5;
 * save = term(2) + previous term(1) + students(1) + existing(1) + insert(1) + update(1) + audit(1) = 8 (+ the idempotency cache).
 * Students are filtered/sorted in PHP so no schema probing query is needed on the hot path.
 */
class Measurements
{
    public const ROUNDS = [1, 2, 3, 4];
    public const WEIGHT_MIN = 8.0, WEIGHT_MAX = 150.0, HEIGHT_MIN = 70.0, HEIGHT_MAX = 210.0, MAX_ROWS = 60;
    // warnings only (never errors): compared with the previous round of the same student
    public const WARN_WEIGHT_DROP_KG = 2.0, WARN_WEIGHT_JUMP_KG = 5.0, WARN_HEIGHT_DROP_CM = 0.5, WARN_HEIGHT_JUMP_CM = 8.0;
    public const WARN_BMI_LOW = 10.0, WARN_BMI_HIGH = 40.0;

    private ?object $termRow = null;
    private ?int $prevTerm = null; // learned from the rows the load query returns (no extra query)

    public function termId(): int
    {
        if ($this->termRow === null) {
            $row = TermScope::current(); // 2 queries, done once per request
            if ($row === null) abort(422, 'ยังไม่ได้ตั้งภาคเรียน');
            $this->termRow = $row;
        }
        return (int) $this->termRow->id;
    }

    /** Active classrooms of this school (is_active exists once the academic module is installed). */
    public static function activeClassrooms(?array $ids)
    {
        return DB::table('classrooms')->where('school_id', self::school())
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))
            ->when(Schema::hasColumn('classrooms', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->get(['id', 'name']);
    }

    /** Natural order so ม.1/2 comes before ม.1/10. */
    public static function sortClasses(array $rows): array
    {
        usort($rows, fn ($a, $b) => strnatcasecmp((string) $a->name, (string) $b->name) ?: ((int) $a->id <=> (int) $b->id));
        return $rows;
    }

    public static function bmi(?float $weight, ?float $height): ?float
    {
        if ($weight === null || $height === null || $height <= 0) return null;
        $m = $height / 100;
        return round($weight / ($m * $m), 1);
    }

    /**
     * Classroom (404 when it is not in this school) and its current students ordered by roll number then code, in ONE query:
     * the classroom row is LEFT JOINed so an empty class still proves it exists.
     * @return array{0: object, 1: array}
     */
    public function classAndStudents(int $classroomId): array
    {
        $school = self::school();
        $u = auth()->user();
        $isTeacher = $u && $u->role === 'teacher';
        if (!$u || (!$isTeacher && !in_array($u->role, TeacherScope::ALL_ROLES, true))) abort(403, 'ไม่มีสิทธิ์ใช้ข้อมูลห้องนี้');
        // homeroom check is folded into this query (no separate scope query on the hot path)
        $rows = DB::table('classrooms as c')->leftJoin('students as s', function ($j) use ($school) {
            $j->on('s.classroom_id', '=', 'c.id')->where('s.school_id', $school);
        })->where('c.school_id', $school)->where('c.id', $classroomId)
            ->when($isTeacher, fn ($q) => $q->where(fn ($w) => $w->where('c.homeroom_teacher_id', $u->teacher_id ?: 0)->orWhere('c.id', $u->classroom_id ?: 0)))
            ->get(['c.id as class_id', 'c.name as class_name', 's.*']);
        if ($rows->isEmpty()) abort($isTeacher ? 403 : 404, $isTeacher ? 'ไม่มีสิทธิ์ใช้ข้อมูลห้องนี้' : 'ไม่พบห้องเรียน');
        $classroom = (object) ['id' => (int) $rows[0]->class_id, 'name' => (string) $rows[0]->class_name];
        $out = [];
        foreach ($rows as $s) {
            if ($s->code === null) continue; // classroom without students
            if (($s->status ?? 'studying') !== 'studying') continue;
            $name = trim(((string) ($s->prefix ?? '')).((string) ($s->first_name ?? '')).' '.((string) ($s->last_name ?? '')));
            $out[] = [
                'id' => (int) $s->id, 'code' => (string) $s->code, 'name' => $name !== '' ? $name : (string) $s->name,
                'roll_number' => isset($s->roll_number) ? (int) $s->roll_number : null,
                'sex' => $s->sex ?? null, 'birth_date' => $s->birth_date ?? null,
            ];
        }
        usort($out, fn ($a, $b) => [$a['roll_number'] ?? PHP_INT_MAX, $a['code'], $a['id']] <=> [$b['roll_number'] ?? PHP_INT_MAX, $b['code'], $b['id']]);
        return [$classroom, $out];
    }

    private static function school(): int { return (int) config('school.school_id'); }

    private static function num($v): ?float { return $v === null ? null : (float) $v; }

    private static function shape(?object $m): ?array
    {
        if (!$m) return null;
        $w = self::num($m->weight_kg);
        $h = self::num($m->height_cm);
        return ['weight_kg' => $w, 'height_cm' => $h, 'bmi' => self::bmi($w, $h)];
    }

    /**
     * One query: this round (and the round before it) of the current term, plus every round of the previous term
     * (fallback for the "previous" comparison). No row locks: writes are compare-and-set on version / unique index.
     * @return array<int,array<int,array<int,object>>> [term_id][round][student_id] => row
     */
    private function load(int $term, int $round, array $studentIds): array
    {
        if (!$studentIds) return [];
        $rounds = $round > 1 ? [$round, $round - 1] : [$round];
                $g = DB::getQueryGrammar();
        [$cId, $cYear, $cTerm, $cSchool] = array_map(fn ($n) => $g->wrap($n), ['id', 'year', 'term', 'school_id']);
        $t = $this->termRow;
        // previous term resolved by a scalar subquery inside the same statement (no separate round trip)
        $prevSql = "term_id = (select $cId from ".$g->wrapTable('academic_terms')." where $cSchool = ? and ($cYear < ? or ($cYear = ? and $cTerm < ?)) order by $cYear desc, $cTerm desc limit 1)";
        $q = DB::table('hlth_measurements')->where('school_id', self::school())->whereIn('student_id', $studentIds)
            ->where(function ($w) use ($term, $rounds, $prevSql, $t) {
                $w->where(fn ($c) => $c->where('term_id', $term)->whereIn('round', $rounds))
                    ->orWhereRaw($prevSql, [self::school(), $t->year, $t->year, $t->term]);
            });
        $by = [];
        $this->prevTerm = null;
        foreach ($q->get() as $m) {
            $by[(int) $m->term_id][(int) $m->round][(int) $m->student_id] = $m;
            if ((int) $m->term_id !== $term) $this->prevTerm = (int) $m->term_id;
        }
return $by;
    }

    /** Previous value of a student: round-1 of the same term, else the latest round of the previous term. */
    private function previous(array $by, int $term, int $round, int $sid): ?array
    {
        if ($round > 1 && ($m = $by[$term][$round - 1][$sid] ?? null)) return self::shape($m) + ['term_id' => $term, 'round' => $round - 1];
        $prev = $this->prevTerm;
        if ($prev !== null && !empty($by[$prev])) {
            $rounds = array_keys($by[$prev]);
            rsort($rounds);
            foreach ($rounds as $r) if (isset($by[$prev][$r][$sid])) return self::shape($by[$prev][$r][$sid]) + ['term_id' => $prev, 'round' => $r];
        }
        return null;
    }

    public function grid(int $classroomId, int $round): array
    {
        $term = $this->termId();
        [$classroom, $students] = $this->classAndStudents($classroomId);
        $by = $this->load($term, $round, array_column($students, 'id'));
        $rows = [];
        $date = null;
        foreach ($students as $s) {
            $cur = $by[$term][$round][$s['id']] ?? null;
            $prev = $this->previous($by, $term, $round, $s['id']);
            $shape = self::shape($cur) ?? ['weight_kg' => null, 'height_cm' => null, 'bmi' => null];
            if ($cur && ($date === null || $cur->measured_on > $date)) $date = (string) $cur->measured_on;
            $rows[] = [
                'student_id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'roll_number' => $s['roll_number'],
                'version' => $cur ? (int) $cur->version : 0,
                'growth' => GrowthReference::classify($s, $shape['bmi'], $shape['weight_kg'], $shape['height_cm']),
                'previous' => $prev,
            ] + $shape;
        }
        return [
            'classroom' => ['id' => (int) $classroom->id, 'name' => (string) $classroom->name],
            'term_id' => $term, 'round' => $round, 'measured_on' => $date,
            'limits' => ['weight_kg' => [self::WEIGHT_MIN, self::WEIGHT_MAX], 'height_cm' => [self::HEIGHT_MIN, self::HEIGHT_MAX], 'max_rows' => self::MAX_ROWS],
            'summary' => self::summarise($rows),
            'rows' => $rows,
        ];
    }

    private static function summarise(array $rows): array
    {
        $full = $partial = $missing = 0;
        foreach ($rows as $r) {
            $has = ($r['weight_kg'] !== null ? 1 : 0) + ($r['height_cm'] !== null ? 1 : 0);
            if ($has === 2) $full++; elseif ($has === 1) $partial++; else $missing++;
        }
        return ['total' => count($rows), 'measured' => $full, 'partial' => $partial, 'missing' => $missing];
    }

    public function summary(int $classroomId, int $round): array
    {
        $g = $this->grid($classroomId, $round);
        $missing = array_values(array_map(fn ($r) => ['student_id' => $r['student_id'], 'name' => $r['name']],
            array_filter($g['rows'], fn ($r) => $r['weight_kg'] === null || $r['height_cm'] === null)));
        return ['classroom' => $g['classroom'], 'term_id' => $g['term_id'], 'round' => $round, 'measured_on' => $g['measured_on']] + $g['summary'] + ['missing_students' => $missing];
    }

    /** Whole-school (or homeroom-only) overview: one row per classroom, constant number of queries. */
    public function overview(int $round, ?array $classroomIds): array
    {
        $term = $this->termId();
        $school = self::school();
        $hasStatus = Schema::hasColumn('students', 'status');
        $classes = self::sortClasses(self::activeClassrooms($classroomIds)->all());
        $totals = DB::table('students')->where('school_id', $school)->whereNotNull('classroom_id')
            ->when($hasStatus, fn ($q) => $q->where('status', 'studying'))->groupBy('classroom_id')->selectRaw('classroom_id, count(*) as n')->pluck('n', 'classroom_id');
        $done = DB::table('hlth_measurements as m')->join('students as s', 's.id', '=', 'm.student_id')
            ->where('m.school_id', $school)->where('m.term_id', $term)->where('m.round', $round)
            ->when($hasStatus, fn ($q) => $q->where('s.status', 'studying'))
            ->whereNotNull('m.weight_kg')->whereNotNull('m.height_cm')->groupBy('s.classroom_id')->selectRaw('s.classroom_id as classroom_id, count(*) as n')->pluck('n', 'classroom_id');
        $out = [];
        foreach ($classes as $c) {
            $total = (int) ($totals[$c->id] ?? 0);
            $measured = min($total, (int) ($done[$c->id] ?? 0));
            $out[] = ['classroom_id' => (int) $c->id, 'name' => (string) $c->name, 'total' => $total, 'measured' => $measured, 'missing' => $total - $measured];
        }
        return ['term_id' => $term, 'round' => $round, 'classrooms' => $out];
    }

    public function csv(int $classroomId, int $round): string
    {
        $g = $this->grid($classroomId, $round);
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['ห้อง', 'ภาคเรียน(id)', 'รอบ', 'เลขที่', 'รหัสนักเรียน', 'ชื่อ', 'น้ำหนัก(กก.)', 'ส่วนสูง(ซม.)', 'BMI'], ',', '"', '');
        foreach ($g['rows'] as $r) {
            fputcsv($fh, [self::cell($g['classroom']['name']), $g['term_id'], $round, $r['roll_number'] ?? '', self::cell($r['code']), self::cell($r['name']),
                $r['weight_kg'] === null ? '' : number_format($r['weight_kg'], 2, '.', ''), $r['height_cm'] === null ? '' : number_format($r['height_cm'], 1, '.', ''),
                $r['bmi'] === null ? '' : number_format($r['bmi'], 1, '.', '')], ',', '"', '');
        }
        rewind($fh);
        $body = stream_get_contents($fh);
        fclose($fh);
        Mutation::audit('health.export', 'classrooms', $classroomId, ['round' => $round, 'term_id' => $g['term_id'], 'rows' => count($g['rows'])]);
        return "\xEF\xBB\xBF".$body;
    }

    /** Neutralise spreadsheet formula injection in text cells. */
    private static function cell(string $v): string
    {
        return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'".$v : $v;
    }

    /**
     * Save a whole class grid in one transaction.
     * rows: [{student_id, weight_kg|null, height_cm|null, version|null}], version 0/null = "I saw no row".
     * Returns ['conflicts' => [...]] (nothing written) or ['inserted','updated','unchanged','rows','warnings'].
     */
    public function save(int $classroomId, int $round, string $measuredOn, array $rows, bool $retried = false): array
    {
        $term = $this->termId();
        $students = [];
        foreach ($this->classAndStudents($classroomId)[1] as $s) $students[$s['id']] = $s;
        $errors = [];
        foreach ($rows as $i => $r) if (!isset($students[(int) $r['student_id']])) $errors["rows.$i.student_id"] = ['นักเรียนไม่ได้อยู่ในห้องนี้'];
        if ($errors) throw ValidationException::withMessages($errors);

        $ids = array_map(fn ($r) => (int) $r['student_id'], $rows);
        $actor = auth()->id();
        try {
            return DB::transaction(function () use ($term, $round, $measuredOn, $rows, $ids, $students, $classroomId, $actor) {
                $by = $this->load($term, $round, $ids);
                $now = now();
                $conflicts = $inserts = $updates = $out = $warnings = [];
                $unchanged = 0;
                foreach ($rows as $r) {
                    $sid = (int) $r['student_id'];
                    $w = isset($r['weight_kg']) ? round((float) $r['weight_kg'], 2) : null;
                    $h = isset($r['height_cm']) ? round((float) $r['height_cm'], 1) : null;
                    $ver = (int) ($r['version'] ?? 0);
                    $cur = $by[$term][$round][$sid] ?? null;
                    if (!$cur) {
                        if ($ver !== 0) { $conflicts[] = ['student_id' => $sid, 'name' => $students[$sid]['name'], 'version' => 0, 'weight_kg' => null, 'height_cm' => null]; continue; }
                        if ($w === null && $h === null) { $unchanged++; continue; }
                        $inserts[] = ['school_id' => self::school(), 'term_id' => $term, 'round' => $round, 'student_id' => $sid, 'measured_on' => $measuredOn,
                            'weight_kg' => $w, 'height_cm' => $h, 'recorded_by' => $actor, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
                        $newVersion = 1;
                    } else {
                        if ($ver !== (int) $cur->version) {
                            $conflicts[] = ['student_id' => $sid, 'name' => $students[$sid]['name'], 'version' => (int) $cur->version, 'weight_kg' => self::num($cur->weight_kg), 'height_cm' => self::num($cur->height_cm)];
                            continue;
                        }
                        if (self::same($w, self::num($cur->weight_kg)) && self::same($h, self::num($cur->height_cm))) { $unchanged++; continue; }
                        $updates[] = ['id' => (int) $cur->id, 'version' => $ver, 'weight_kg' => $w, 'height_cm' => $h];
                        $newVersion = $ver + 1;
                    }
                    $out[] = ['student_id' => $sid, 'weight_kg' => $w, 'height_cm' => $h, 'bmi' => self::bmi($w, $h), 'version' => $newVersion];
                    array_push($warnings, ...$this->warn($students[$sid], $w, $h, $this->previous($by, $term, $round, $sid)));
                }
                if ($conflicts) return ['conflicts' => $conflicts];

                foreach (array_chunk($inserts, 200) as $chunk) DB::table('hlth_measurements')->insert($chunk);
                foreach (array_chunk($updates, 100) as $chunk) $this->bulkUpdate($chunk, $term, $round, $measuredOn, $actor, $now);
                if ($inserts || $updates) {
                    Mutation::audit('health.grid.save', 'classrooms', $classroomId, [
                        'term_id' => $term, 'round' => $round, 'inserted' => count($inserts), 'updated' => count($updates),
                        'student_ids' => array_column($out, 'student_id'),
                    ]);
                }
                return ['term_id' => $term, 'inserted' => count($inserts), 'updated' => count($updates), 'unchanged' => $unchanged, 'rows' => $out, 'warnings' => $warnings];
            }, 3); // retried on deadlock / lock wait so a concurrent first save ends as a 409, not a 500
        } catch (QueryException $e) {
            if (!self::isUniqueViolation($e) && !self::isConcurrencyError($e)) throw $e;
            // lost a race: re-run once outside the failed transaction so the winner's rows come back as conflicts
            if (!$retried) return $this->save($classroomId, $round, $measuredOn, $rows, true);
            return ['conflicts' => [], 'race' => true];
        } catch (\App\Support\Saan\VersionConflict $e) {
            if (!$retried) return $this->save($classroomId, $round, $measuredOn, $rows, true);
            return ['conflicts' => [], 'race' => true];
        }
    }

    private static function same(?float $a, ?float $b): bool
    {
        return $a === null || $b === null ? $a === $b : abs($a - $b) < 0.0001;
    }

    /** One UPDATE per chunk: CASE per column, compare-and-set on version, throws (rolling back) if any row lost the race. */
    private function bulkUpdate(array $chunk, int $term, int $round, string $measuredOn, ?int $actor, $now): void
    {
        $g = DB::getQueryGrammar();
        $T = $g->wrapTable('hlth_measurements');
        [$cId, $cRound, $cVer, $cTerm, $cSchool] = array_map(fn ($n) => $g->wrap($n), ['id', 'round', 'version', 'term_id', 'school_id']);
        $cW = $g->wrap('weight_kg'); $cH = $g->wrap('height_cm'); $cOn = $g->wrap('measured_on'); $cBy = $g->wrap('recorded_by'); $cAt = $g->wrap('updated_at');
        $w = $h = $v = "CASE $cId";
        $bw = $bh = $bv = [];
        foreach ($chunk as $u) {
            $w .= ' WHEN ? THEN CAST(? AS DECIMAL(5,2))'; array_push($bw, $u['id'], $u['weight_kg']);
            $h .= ' WHEN ? THEN CAST(? AS DECIMAL(5,1))'; array_push($bh, $u['id'], $u['height_cm']);
            $v .= ' WHEN ? THEN ?'; array_push($bv, $u['id'], $u['version']);
        }
        $ids = array_column($chunk, 'id');
        $sql = "UPDATE $T SET $cW = $w END, $cH = $h END, $cOn = ?, $cBy = ?, $cAt = ?, $cVer = $cVer + 1 "
            ."WHERE $cSchool = ? AND $cTerm = ? AND $cRound = ? AND $cId IN (".implode(',', array_fill(0, count($ids), '?')).") AND $cVer = $v END";
        $n = DB::update($sql, array_merge($bw, $bh, [$measuredOn, $actor, $now, self::school(), $term, $round], $ids, $bv));
        if ($n !== count($chunk)) throw new \App\Support\Saan\VersionConflict(null, 'มีผู้อื่นแก้ไขข้อมูลพร้อมกัน กรุณาโหลดใหม่');
    }

    /** Plausibility warnings against the previous round; never block the save. */
    private function warn(array $student, ?float $w, ?float $h, ?array $prev): array
    {
        $out = [];
        $add = function (string $field, string $text, $previous, $value) use (&$out, $student) {
            $out[] = ['student_id' => $student['id'], 'name' => $student['name'], 'field' => $field, 'message' => $student['name'].' '.$text, 'previous' => $previous, 'value' => $value];
        };
        if ($prev) {
            if ($w !== null && $prev['weight_kg'] !== null) {
                $d = $w - $prev['weight_kg'];
                if ($d <= -self::WARN_WEIGHT_DROP_KG) $add('weight_kg', 'น้ำหนักลดลง '.rtrim(rtrim(number_format(-$d, 2), '0'), '.').' กก. จากรอบก่อน', $prev['weight_kg'], $w);
                elseif ($d >= self::WARN_WEIGHT_JUMP_KG) $add('weight_kg', 'น้ำหนักเพิ่มขึ้น '.rtrim(rtrim(number_format($d, 2), '0'), '.').' กก. จากรอบก่อน', $prev['weight_kg'], $w);
            }
            if ($h !== null && $prev['height_cm'] !== null) {
                $d = $h - $prev['height_cm'];
                if ($d <= -self::WARN_HEIGHT_DROP_CM) $add('height_cm', 'ส่วนสูงลดลง '.rtrim(rtrim(number_format(-$d, 1), '0'), '.').' ซม. จากรอบก่อน', $prev['height_cm'], $h);
                elseif ($d >= self::WARN_HEIGHT_JUMP_CM) $add('height_cm', 'ส่วนสูงเพิ่มขึ้น '.rtrim(rtrim(number_format($d, 1), '0'), '.').' ซม. จากรอบก่อน', $prev['height_cm'], $h);
            }
        }
        $bmi = self::bmi($w, $h);
        if ($bmi !== null && ($bmi < self::WARN_BMI_LOW || $bmi > self::WARN_BMI_HIGH)) $add('bmi', 'ค่า BMI '.number_format($bmi, 1).' ผิดปกติมาก ตรวจน้ำหนักและส่วนสูงอีกครั้ง', null, $bmi);
        return $out;
    }

    /** Deadlock (1213 / 40001) or lock wait timeout (1205): another writer got there first. */
    public static function isConcurrencyError(\Throwable $e): bool
    {
        if (!$e instanceof QueryException) return false;
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driver = (int) ($e->errorInfo[1] ?? 0);
        return $state === '40001' || in_array($driver, [1213, 1205], true);
    }

    public static function isUniqueViolation(\Throwable $e): bool
    {
        return \App\Support\Saan\DbError::isUnique($e);

    }
}

