<?php

namespace Saan\Modules\Activities;

use App\Support\Saan\Settings;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared helpers for the activities module: scoping, normalisation, student lookups, settings. */
final class Common
{
    public const PASS_KEY = 'activities.pass_percent';
    public const MANAGE_ROLES = ['admin', 'principal'];

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    /** mb_strtolower + trim + collapsed whitespace: the value behind every unique *_key index. */
    public static function key(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    public static function today(): string
    {
        return now('Asia/Bangkok')->toDateString();
    }

    public static function isManager(?object $user = null): bool
    {
        $user ??= auth()->user();
        return $user && in_array($user->role, self::MANAGE_ROLES, true);
    }

    public static function passPercent(): int
    {
        $v = Settings::get(self::PASS_KEY, 80);
        return is_numeric($v) ? max(1, min(100, (int) $v)) : 80;
    }

    /** Requested term (must belong to this school) or the current term. */
    public static function termId(mixed $requested = null): int
    {
        if ($requested !== null && $requested !== '') {
            abort_unless(is_numeric($requested) && DB::table('academic_terms')->where('school_id', self::school())->where('id', (int) $requested)->exists(), 422, 'ไม่พบภาคเรียนที่ระบุ');
            return (int) $requested;
        }
        $id = TermScope::currentId();
        abort_if($id === null, 422, 'ยังไม่ได้ตั้งภาคเรียน');
        return $id;
    }

    public static function termLabel(int $termId): string
    {
        $t = DB::table('academic_terms')->where('school_id', self::school())->where('id', $termId)->first();
        return $t ? 'ปีการศึกษา '.$t->year.' ภาคเรียนที่ '.$t->term : '';
    }

    public static function schoolName(): string
    {
        try {
            $theme = app(\App\Domain\CmsPublishing::class)->theme(self::school())['published'] ?? [];
            $name = (string) ($theme['school_name'] ?? '');
        } catch (\Throwable) {
            $name = '';
        }
        return $name !== '' ? $name : (string) (config('saan.display') ?: 'โรงเรียน');
    }

    /** Local (Asia/Bangkok) input like 2026-11-20T08:00 -> application timezone Carbon, or null when unreadable. */
    public static function parseLocal(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') return null;
        try {
            return Carbon::parse(trim($value), 'Asia/Bangkok')->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function iso(?string $dbTime): ?string
    {
        if (!$dbTime) return null;
        try {
            return Carbon::parse($dbTime, config('app.timezone'))->setTimezone('Asia/Bangkok')->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function at(string $dbTime): Carbon
    {
        return Carbon::parse($dbTime, config('app.timezone'));
    }

    /** Studying students per classroom and grade level (one grouped query). */
    public static function studentCounts()
    {
        $q = DB::table('students as s')->where('s.school_id', self::school())
            ->leftJoin('classrooms as c', function ($j) { $j->on('c.id', '=', 's.classroom_id')->where('c.school_id', self::school()); });
        if (Schema::hasColumn('students', 'status')) $q->where('s.status', 'studying');
        if (!Schema::hasColumn('classrooms', 'grade_level')) return $q->selectRaw('s.classroom_id as classroom_id, NULL as grade_level, count(*) as n')->groupBy('s.classroom_id')->get();
        return $q->selectRaw('s.classroom_id as classroom_id, c.grade_level as grade_level, count(*) as n')->groupBy('s.classroom_id', 'c.grade_level')->get();
    }
    public static function levels(mixed $json): array
    {
        $v = is_array($json) ? $json : json_decode((string) $json, true);
        return is_array($v) ? array_values(array_filter(array_map('strval', $v), fn ($x) => $x !== '')) : [];
    }

    public static function normaliseLevels(mixed $levels): array
    {
        $out = [];
        foreach ((array) $levels as $l) {
            $l = trim((string) $l);
            if ($l !== '' && mb_strlen($l) <= 20) $out[mb_strtolower($l)] = $l;
        }
        return array_values($out);
    }

    public static function gradeOf(?object $classroomRow): ?string
    {
        $g = $classroomRow->grade_level ?? null;
        return $g !== null && $g !== '' ? (string) $g : null;
    }

    /** Students of this school with classroom name and grade level. Optional columns of other modules are guarded. */
    public static function studentsQuery()
    {
        $q = DB::table('students as s')->where('s.school_id', self::school())
            ->leftJoin('classrooms as c', function ($j) { $j->on('c.id', '=', 's.classroom_id')->where('c.school_id', self::school()); });
        $q->select('s.id', 's.code', 's.name', 's.classroom_id', 'c.name as classroom_name');
        $q->selectRaw(Schema::hasColumn('classrooms', 'grade_level') ? 'c.grade_level as grade_level' : 'NULL as grade_level');
        if (Schema::hasColumn('students', 'status')) $q->where('s.status', 'studying');
        return $q;
    }

    public static function levelAllowed(array $levels, ?string $grade): bool
    {
        if (!$levels) return true;
        if ($grade === null) return false;
        foreach ($levels as $l) if (mb_strtolower($l) === mb_strtolower($grade)) return true;
        return false;
    }

    /** May the signed-in user act on this student's classroom? Students without a classroom are admin/principal only. */
    public static function canStudent(object $student): bool
    {
        if (self::isManager()) return true;
        return $student->classroom_id && TeacherScope::canClassroom((int) $student->classroom_id);
    }

    public static function teacherName(?int $id): ?string
    {
        return $id ? DB::table('teachers')->where('school_id', self::school())->where('id', $id)->value('name') : null;
    }
}
