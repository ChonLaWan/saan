<?php

namespace Saan\Modules\GuardianPortal;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\DB;

/**
 * F8: results of a term become visible to students and guardians only after the school "announces" the term.
 * Setting student.announce.<term id> = {"at": "Y-m-d H:i:s"} in the app time zone: announced when that moment has passed
 * (now = announce immediately, a future time = scheduled). No setting = not announced, so a new term never leaks half-finished results.
 */
final class Announce
{
    public const PREFIX = 'student.announce.';
    public const SHOW_PP6 = 'portal.show_pp6';

    public static function at(int $termId): ?string
    {
        $v = Settings::get(self::PREFIX.$termId);
        $at = is_array($v) ? ($v['at'] ?? null) : null;
        return is_string($at) && strtotime($at) !== false ? $at : null;
    }

    public static function isAnnounced(int $termId): bool
    {
        $at = self::at($termId);
        return $at !== null && strtotime($at) <= now()->getTimestamp();
    }

    public static function showPp6(): bool
    {
        return Settings::get(self::SHOW_PP6, false) === true;
    }

    /** $at null clears the announcement (results of the term disappear again). */
    public static function set(int $termId, ?string $at): void
    {
        $exists = DB::table('academic_terms')->where('school_id', Access::school())->where('id', $termId)->exists();
        if (!$exists) abort(404, 'ไม่พบภาคเรียน');
        Settings::put(self::PREFIX.$termId, $at === null ? null : ['at' => $at]);
        Mutation::audit('guardian_portal.results.announce', 'saan_settings', null, ['term_id' => $termId, 'at' => $at]);
    }

    /** @return list<array{id:int,year:int,term:int,announce_at:?string,announced:bool,current:bool}> */
    public static function terms(): array
    {
        $current = \App\Support\Saan\TermScope::currentId();
        return DB::table('academic_terms')->where('school_id', Access::school())->orderByDesc('year')->orderByDesc('term')->orderByDesc('id')->get(['id', 'year', 'term'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'year' => (int) $t->year, 'term' => (int) $t->term, 'announce_at' => self::at((int) $t->id),
                'announced' => self::isAnnounced((int) $t->id), 'current' => (int) $t->id === $current])->all();
    }
}
