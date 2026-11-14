<?php

namespace Saan\Modules\StudentPortal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Who the signed-in student of a request is. The session only holds {student_id, auth_version, until}; everything else is recomputed from the
 * database on every request, so a reset, a changed secret (auth_version), a student who left school or a removed secret ends the session at once.
 * Session key 'sp.session' lives next to (never instead of) a staff login: a student is not a users row.
 */
final class Access
{
    public const SESSION = 'sp.session';
    public const SESSION_HOURS = 4;

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    /** @return array{student_id:int,must_change:bool}|null */
    public static function resolve(Request $request): ?array
    {
        $s = $request->hasSession() ? $request->session()->get(self::SESSION) : null;
        if (!is_array($s) || (int) ($s['until'] ?? 0) < time() || empty($s['student_id'])) return null;
        $row = DB::table('api_student_pins')->where('school_id', self::school())->where('student_id', (int) $s['student_id'])->first(['auth_version', 'must_change']);
        if (!$row || (int) $row->auth_version !== (int) ($s['auth_version'] ?? -1)) return null;
        $ok = DB::table('students')->where('school_id', self::school())->where('id', (int) $s['student_id'])->where('status', 'studying')->exists();
        return $ok ? ['student_id' => (int) $s['student_id'], 'must_change' => (bool) $row->must_change] : null;
    }

    public static function start(Request $request, int $studentId, int $authVersion): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, ['student_id' => $studentId, 'auth_version' => $authVersion, 'until' => time() + self::SESSION_HOURS * 3600]);
    }

    public static function ipHash(?Request $request = null): string
    {
        return \Saan\Modules\GuardianPortal\Access::ipHash($request);
    }

    /** Audit of a student's own action (the detail never carries a secret). */
    public static function event(string $action, ?int $studentId, array $detail = [], ?Request $request = null): void
    {
        DB::table('stu_events')->insert(['school_id' => self::school(), 'student_id' => $studentId, 'action' => $action,
            'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, 'ip_hash' => self::ipHash($request), 'created_at' => now()]);
    }
}
