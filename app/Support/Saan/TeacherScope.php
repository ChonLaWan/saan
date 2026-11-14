<?php

namespace App\Support\Saan;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which classrooms a signed-in staff account may act on. One rule for every module:
 * - admin / principal: every classroom of the school (classroomIds() returns null = no restriction)
 * - teacher: homeroom classroom (users.classroom_id or classrooms.homeroom_teacher_id = users.teacher_id)
 *   plus classrooms the teacher teaches in the given term (lessons.teacher_id = users.teacher_id, soft-deleted lessons ignored), default current term
 * - any other role: nothing
 */
class TeacherScope
{
    public const ALL_ROLES = ['admin', 'principal'];

    /** @return int[]|null null means all classrooms of the school */
    public static function classroomIds(?object $user = null, ?int $termId = null): ?array
    {
        $user ??= auth()->user();
        if (!$user) return [];
        if (in_array($user->role, self::ALL_ROLES, true)) return null;
        if ($user->role !== 'teacher') return [];
        $school = (int) config('school.school_id');
        $ids = [];
        if ($user->classroom_id) $ids[] = (int) $user->classroom_id;
        if ($user->teacher_id) {
            $ids = array_merge($ids, DB::table('classrooms')->where('school_id', $school)
                ->where('homeroom_teacher_id', $user->teacher_id)->pluck('id')->map(fn ($v) => (int) $v)->all());
            $termId ??= TermScope::currentId();
            if ($termId && Schema::hasTable('lessons')) {
                $ids = array_merge($ids, DB::table('lessons')->where('school_id', $school)->where('term_id', $termId)
                    ->when(Schema::hasColumn('lessons', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->where('teacher_id', $user->teacher_id)->distinct()->pluck('classroom_id')->map(fn ($v) => (int) $v)->all());
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) return [];
        return DB::table('classrooms')->where('school_id', $school)->whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Like classroomIds() but for historical data: a past term belongs to the teachers who taught it, so today's homeroom
     * assignment is NOT added (it would expose the previous cohort). The current term (or null) behaves like classroomIds().
     * @return int[]|null
     */
    public static function classroomIdsForTerm(?object $user = null, ?int $termId = null): ?array
    {
        $user ??= auth()->user();
        if (!$user) return [];
        $current = TermScope::currentId();
        if (!$termId || !$current || $termId === (int) $current) return self::classroomIds($user, $termId);
        if (in_array($user->role, self::ALL_ROLES, true)) return null;
        if ($user->role !== 'teacher' || !$user->teacher_id || !Schema::hasTable('lessons')) return [];
        $school = (int) config('school.school_id');
        $ids = DB::table('lessons')->where('school_id', $school)->where('term_id', $termId)
            ->when(Schema::hasColumn('lessons', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->where('teacher_id', $user->teacher_id)->distinct()->pluck('classroom_id')->map(fn ($v) => (int) $v)->all();
        if (!$ids) return [];
        return DB::table('classrooms')->where('school_id', $school)->whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Homeroom classrooms only (registry/health/guardian data that only the class teacher should see). @return int[]|null */
    public static function homeroomIds(?object $user = null): ?array
    {
        $user ??= auth()->user();
        if (!$user) return [];
        if (in_array($user->role, self::ALL_ROLES, true)) return null;
        if ($user->role !== 'teacher') return [];
        $school = (int) config('school.school_id');
        $ids = $user->classroom_id ? [(int) $user->classroom_id] : [];
        if ($user->teacher_id) $ids = array_merge($ids, DB::table('classrooms')->where('school_id', $school)
            ->where('homeroom_teacher_id', $user->teacher_id)->pluck('id')->map(fn ($v) => (int) $v)->all());
        return array_values(array_unique($ids));
    }

    /**
     * Per-student endpoints: classroom_id survives a status change (graduated, transferred) and classrooms persist across years, so a classroom
     * check alone lets next year's teacher read a former pupil. Teachers see only students who are still studying; admin/principal see everyone.
     */
    public static function canSeeStudent(?object $student, ?object $user = null): bool
    {
        if (!$student) return false;
        $user ??= auth()->user();
        if (!$user) return false;
        if (in_array($user->role, self::ALL_ROLES, true)) return true;
        return ($student->status ?? 'studying') === 'studying';
    }

    public static function canClassroom(int $classroomId, ?object $user = null, ?int $termId = null): bool
    {
        $exists = DB::table('classrooms')->where('school_id', (int) config('school.school_id'))->where('id', $classroomId)->exists();
        if (!$exists) return false;
        $ids = self::classroomIds($user, $termId);
        return $ids === null || in_array($classroomId, $ids, true);
    }

    /** Apply to a query on a table with a classroom column. */
    public static function apply($query, string $column = 'classroom_id', ?object $user = null, ?int $termId = null)
    {
        $ids = self::classroomIds($user, $termId);
        return $ids === null ? $query : $query->whereIn($column, $ids ?: [0]);
    }
}
