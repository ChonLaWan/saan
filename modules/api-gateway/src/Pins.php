<?php

namespace Saan\Modules\ApiGateway;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/**
 * Per-student PINs for the learning-app sign-in. Birth date is never used.
 * - pin_hash = bcrypt(HMAC-SHA256(pin|student_id, APP_KEY)): a copy of the table alone cannot be brute-forced offline.
 * - Attempts are reserved atomically BEFORE the PIN is checked (UPDATE ... failed_count + 1 WHERE failed_count < MAX),
 *   so parallel guesses cannot exceed MAX attempts. MAX wrong tries lock the PIN for LOCK_MINUTES; the homeroom teacher can reset it.
 * - Unknown code, no PIN, locked and wrong PIN are indistinguishable to the caller.
 */
class Pins
{
    /** Wrong tries (from all networks together) before the PIN locks. A single network is capped lower (MAX_PER_NETWORK), so one IP alone cannot lock a student out. */
    public const MAX_FAILED = 12;
    public const MAX_PER_NETWORK = 5;
    public const NETWORK_WINDOW = 1800;
    public const LOCK_MINUTES = 30;
    /** Escalating lock: 30 min, 60, 120, 240, 480, 960, then 1440 (24 h) at most. Every lock expires on its own; a teacher reset or a correct sign-in clears the count. */
    public const MAX_LOCK_MINUTES = 1440;

    public static function lockMinutes(int $lockCount): int
    {
        return min(self::MAX_LOCK_MINUTES, self::LOCK_MINUTES * (2 ** max(0, $lockCount - 1)));
    }

    /** Kept for the roster API: locks no longer become permanent (an attacker could otherwise force teacher resets school-wide). */
    public static function needsReset(object $row): bool
    {
        return false;
    }

    private static function lock(object $row, CarbonImmutable $now): void
    {
        $next = (int) ($row->lock_count ?? 0) + 1;
        $set = ['locked_until' => $now->addMinutes(self::lockMinutes($next))];
        if (property_exists($row, 'lock_count')) $set['lock_count'] = DB::raw('lock_count + 1');
        DB::table('api_student_pins')->where('id', $row->id)->whereNull('locked_until')->update($set);
    }

    public static function normalise(string $pin): string
    {
        return trim($pin);
    }

    public static function generate(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (preg_match('/^(\d)\1{5}$/', $pin) || str_contains('0123456789876543210', $pin)); // no 111111 / 123456 / 654321
        return $pin;
    }

    public static function digest(string $pin, int $studentId, ?string $key = null): string
    {
        return hash_hmac('sha256', $pin.'|'.$studentId, $key ?? \App\Support\Saan\HashKey::secret());
    }

    /** True when the PIN matches the stored hash under the current key or (APP_KEY rotation) a previous one. */
    public static function matches(string $pin, int $studentId, string $stored): bool
    {
        foreach (\App\Support\Saan\HashKey::candidates() as $k) {
            if (Hash::check(self::digest($pin, $studentId, $k), $stored)) return true;
        }
        return false;
    }

    /**
     * The work of a wrong secret (one bcrypt per key candidate) for every branch that refuses WITHOUT counting (unknown code, network cap, locked, lost reservation).
     * Never self::matches() there: it stops at the first matching candidate, so a right PIN would answer faster than a wrong one and the lock or cap would stop
     * nothing. Hash::make costs what Hash::check costs; no static dummy hash (PHP-FPM would rebuild it per request).
     */
    private static function burn(): void
    {
        foreach (\App\Support\Saan\HashKey::candidates() as $_) Hash::make('saan-dummy-pin');
    }

    public static function hash(string $pin, int $studentId): string
    {
        return Hash::make(self::digest($pin, $studentId));
    }

    /** Student row (studying, this school) by code, case/space-insensitive. */
    public static function studentByCode(string $code): ?object
    {
        $key = Net::key($code);
        if ($key === '') return null;
        $q = DB::table('students')->where('school_id', Gateway::school());
        $q->where(function ($w) use ($key, $code) {
            if (Schema::hasColumn('students', 'code_key')) $w->where('code_key', $key)->orWhere('code', trim($code));
            else $w->where('code', trim($code));
            $w->orWhereRaw('LOWER(code) = ?', [$key]);
        });
        $rows = $q->get(['id', 'code', 'status'])->all();
        if (count($rows) !== 1) return null; // ambiguous or missing
        $s = $rows[0];
        return ($s->status ?? 'studying') === 'studying' ? $s : null;
    }

    /** True only for the right PIN of a studying student whose PIN is not locked. */
    /**
     * @param string|null $networkKey rate key of the caller's network (NetKey::rate). When given, one network may make at most
     *        MAX_PER_NETWORK wrong tries per student per NETWORK_WINDOW; beyond that the attempt is refused WITHOUT touching the
     *        student's counters, so a single address cannot lock a student (or a whole class) out of the app.
     * @param bool $anySecret the student portal (F8) accepts a short password as well as a PIN; the learning-app sign-in keeps the strict 6 digits.
     */
    public static function attempt(string $code, string $pin, ?string $networkKey = null, bool $anySecret = false): ?object
    {
        $pin = self::normalise($pin);
        $student = self::studentByCode($code);
        $row = $student ? DB::table('api_student_pins')->where('school_id', Gateway::school())->where('student_id', $student->id)->first() : null;
        if (!$student || !$row) {
            // the same bcrypt count as a wrong secret of a known student (one check per key candidate); Hash::make costs what Hash::check costs.
            // No static dummy hash: under PHP-FPM it would be rebuilt on every request and double the work of this branch.
            self::burn();
            return null;
        }
        $now = CarbonImmutable::now();
        $netKey = $networkKey === null ? null : 'pin-net:'.$student->id.':'.sha1($networkKey.'|'.$row->pin_hash); // pin_hash changes on every (re)issue, so a teacher reset also frees every network
        if ($netKey !== null && RateLimiter::tooManyAttempts($netKey, self::MAX_PER_NETWORK)) { self::burn(); return null; } // same bcrypt count as a wrong secret (one check per key candidate)

        if ($row->locked_until !== null) {
            if (strtotime((string) $row->locked_until) > $now->getTimestamp()) { self::burn(); return null; }
            // lock expired: one fresh window, compare-and-set so parallel requests reset it once (lock_count is kept: the next lock is longer)
            DB::table('api_student_pins')->where('id', $row->id)->where('locked_until', $row->locked_until)->update(['locked_until' => null, 'failed_count' => 0]);
        }

        $reserved = DB::table('api_student_pins')->where('id', $row->id)->whereNull('locked_until')
            ->where('failed_count', '<', self::MAX_FAILED)->update(['failed_count' => DB::raw('failed_count + 1')]);
        if ($reserved === 0) {
            self::lock($row, $now);
            self::burn(); // lost the reservation race: still the same work as every other branch
            return null;
        }

        if (($anySecret ? (strlen($pin) >= 1 && strlen($pin) <= 200) : preg_match('/^\d{6}$/D', $pin) === 1) && self::matches($pin, (int) $student->id, $row->pin_hash)) {
            $ok = ['failed_count' => 0, 'locked_until' => null];
            if (property_exists($row, 'lock_count')) $ok['lock_count'] = 0;
            DB::table('api_student_pins')->where('id', $row->id)->update($ok);
            if ($netKey !== null) RateLimiter::clear($netKey);
            return $student;
        }
        if ($netKey !== null) RateLimiter::hit($netKey, self::NETWORK_WINDOW);
        $count = (int) DB::table('api_student_pins')->where('id', $row->id)->value('failed_count');
        if ($count >= self::MAX_FAILED) self::lock($row, $now);
        return null;
    }
}
