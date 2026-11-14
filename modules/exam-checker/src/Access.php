<?php

namespace Saan\Modules\ExamChecker;

use App\Support\Saan\TeacherScope;
use Illuminate\Support\Facades\DB;

/**
 * Who may touch which exam / sheet.
 * - admin, principal: everything in the school.
 * - teacher: exams they created and open/closed exams made by others; sheets, rosters and reports only for
 *   classrooms in their TeacherScope, for every exam (creator included). Drafts of other people are invisible (404).
 */
final class Access
{
    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    public static function manage(object $exam, ?object $user = null): bool
    {
        $user ??= auth()->user();
        if (!$user) return false;
        return in_array($user->role, TeacherScope::ALL_ROLES, true) || ((int) $exam->created_by === (int) $user->id && $user->role === 'teacher');
    }

    public static function exam(int $id): object
    {
        $exam = DB::table('exm_exams')->where('school_id', self::school())->where('id', $id)->first();
        if (!$exam || ($exam->status === 'draft' && !self::manage($exam))) abort(404);
        return $exam;
    }

    /** Classrooms whose sheets this user may see/enter for the exam. null = all. */
    public static function classroomIds(object $exam): ?array
    {
        // managing an exam (settings, key, rescore, delete) never widens the classroom scope
        return self::scopeFor($exam->term_id ? (int) $exam->term_id : null);
    }

    /** TeacherScope for a term: null = every classroom (admin, principal). */
    public static function scopeFor(?int $termId): ?array
    {
        return in_array(auth()->user()?->role, TeacherScope::ALL_ROLES, true) ? null : TeacherScope::classroomIdsForTerm(null, $termId);
    }

    public static function canClassroom(object $exam, ?int $classroomId): bool
    {
        $ids = self::classroomIds($exam);
        return $ids === null || ($classroomId !== null && in_array($classroomId, $ids, true));
    }

    public static function sheets(object $exam, ?int $classroomId = null)
    {
        $q = DB::table('exm_sheets')->where('school_id', self::school())->where('exam_id', $exam->id);
        $ids = self::classroomIds($exam);
        if ($ids !== null) $q->whereIn('classroom_id', $ids ?: [0]);
        if ($classroomId) {
            if (!self::canClassroom($exam, $classroomId)) abort(403, 'ไม่มีสิทธิ์ดูข้อมูลห้องนี้');
            $q->where('classroom_id', $classroomId);
        }
        return $q;
    }

    public static function codeKey(string $code): string
    {
        return mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $code) ?? '')), 0, 80);
    }
}
