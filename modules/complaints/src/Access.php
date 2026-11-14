<?php

namespace Saan\Modules\Complaints;

use App\Support\Saan\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may see and work a case.
 *
 *  - officers (setting complaints.officer_user_ids; while the list is empty: admin and principal) work every category EXCEPT corruption
 *  - executives (setting complaints.executive_user_ids) work corruption cases ONLY; nobody else sees them, an admin or an officer included
 *  - an account named as accused in a case never sees it, finds it in no search, list or export, and cannot be assigned to it, whatever role it holds
 *
 * One query filter (scope) is used by every read, so a case that a user may not see answers 404 exactly like a case that does not exist.
 */
final class Access
{
    public const STAFF_ROLES = ['admin', 'principal', 'teacher', 'editor'];

    private static function ids(string $key): array
    {
        $v = Settings::get($key, []);
        return is_array($v) ? array_values(array_unique(array_filter(array_map('intval', $v), fn ($x) => $x > 0))) : [];
    }

    public static function officerIds(): array
    {
        return self::ids('complaints.officer_user_ids');
    }

    public static function executiveIds(): array
    {
        return self::ids('complaints.executive_user_ids');
    }

    public static function isOfficer(object $u): bool
    {
        $ids = self::officerIds();
        return $ids ? in_array((int) $u->id, $ids, true) : in_array($u->role, ['admin', 'principal'], true);
    }

    public static function isExecutive(object $u): bool
    {
        return in_array((int) $u->id, self::executiveIds(), true);
    }

    /** Roles of the module at all: may open the staff page. */
    public static function hasAny(object $u): bool
    {
        return self::isOfficer($u) || self::isExecutive($u);
    }

    /** Could this account work a case of this category (before the accused rule)? */
    public static function worksCategory(object $u, string $category): bool
    {
        return $category === 'corruption' ? self::isExecutive($u) : self::isOfficer($u);
    }

    public static function isAccused(int $userId, int $caseId): bool
    {
        return DB::table('cmp_accused')->where('school_id', Complaints::school())->where('case_id', $caseId)->where('user_id', $userId)->exists();
    }

    /** Restricts a query on cmp_cases (unaliased) to the cases this user may see. */
    public static function scope(Builder $q, object $u): Builder
    {
        $q->where('cmp_cases.school_id', Complaints::school());
        $general = self::isOfficer($u);
        $corruption = self::isExecutive($u);
        if (!$general && !$corruption) return $q->whereRaw('1 = 0');
        $q->where(function ($w) use ($general, $corruption) {
            if ($general) $w->where('cmp_cases.category', '!=', 'corruption');
            if ($corruption) $w->orWhere('cmp_cases.category', 'corruption');
        });
        return $q->whereNotIn('cmp_cases.id', DB::table('cmp_accused')->where('school_id', Complaints::school())->where('user_id', (int) $u->id)->select('case_id'));
    }

    public static function cases(object $u): Builder
    {
        return self::scope(DB::table('cmp_cases'), $u);
    }

    /** The case when this user may see it, else 404 (the same answer as for a case that does not exist). */
    public static function find(object $u, int $id): object
    {
        return self::cases($u)->where('cmp_cases.id', $id)->first() ?? abort(404, 'ไม่พบเรื่องนี้');
    }

    /** Active staff accounts of this school that may be named as the assignee (works the category, not accused) or as accused (any staff account). */
    public static function staffUsers(): \Illuminate\Support\Collection
    {
        return DB::table('users')->where('school_id', Complaints::school())->where('is_active', 1)->whereIn('role', self::STAFF_ROLES)->orderBy('name')->get(['id', 'name', 'role']);
    }

    /** @return list<array{id:int,name:string}> accounts that may be assigned to this case */
    public static function assignable(object $case): array
    {
        $accused = DB::table('cmp_accused')->where('school_id', Complaints::school())->where('case_id', $case->id)->pluck('user_id')->map('intval')->all();
        $out = [];
        foreach (self::staffUsers() as $u) {
            if (in_array((int) $u->id, $accused, true) || !self::worksCategory($u, (string) $case->category)) continue;
            $out[] = ['id' => (int) $u->id, 'name' => (string) $u->name];
        }
        return $out;
    }
}
