<?php

namespace Saan\Modules\StudentPortal;

use App\Support\Saan\Mutation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Saan\Modules\ApiGateway\Net;
use Saan\Modules\ApiGateway\Pins;
use Saan\Modules\GuardianPortal\Contacts;
use Saan\Modules\GuardianPortal\SecretPolicy;

/**
 * Writes of the student secret (api_student_pins). Same storage as the learning-app PIN: bcrypt(HMAC-SHA256(secret|student_id, key)), failed_count,
 * locked_until with the escalating lock of Pins. Nothing here ever stores or audits a secret in clear text.
 *  - staff issue/reset: random 6 digit temporary secret (must_change), every session of the student ends (auth_version + 1), learning-app tokens are revoked,
 *    the contact e-mail becomes unverified again and open reset tokens are deleted
 *  - self change / e-mail reset: the chosen secret, must_change cleared, other sessions end
 */
final class Credentials
{
    private static function revokeTokens(int $studentId): void
    {
        DB::table('api_tokens')->where('user_kind', 'student')->where('subject_id', $studentId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** Staff side. Returns the temporary secret (shown once to the caller). Needs a signed-in staff user for the audit row. */
    public static function issue(object $student): string
    {
        $secret = Pins::generate();
        try {
            DB::transaction(function () use ($student, $secret) {
                $row = DB::table('api_student_pins')->where('school_id', Access::school())->where('student_id', $student->id)->lockForUpdate()->first();
                $v = ['pin_hash' => Pins::hash($secret, (int) $student->id), 'failed_count' => 0, 'locked_until' => null, 'lock_count' => 0, 'must_change' => true, 'secret_kind' => 'pin',
                    'issued_by' => auth()->id(), 'issued_at' => now()->toDateTimeString()];
                $detail = ['student_id' => (int) $student->id]; // never the secret
                if ($row) Mutation::update('api_student_pins', (int) $row->id, (int) $row->version, $v + ['auth_version' => DB::raw('auth_version + 1')], 'student_portal.secret.reset', $detail);
                else Mutation::insert('api_student_pins', ['student_id' => (int) $student->id, 'auth_version' => 1] + $v, 'student_portal.secret.issued', $detail);
                self::revokeTokens((int) $student->id);
                Contacts::revoke('student', (int) $student->id); // the e-mail may belong to whoever held the old session: it must be verified again
            });
        } catch (\Throwable $e) {
            if (Net::isUniqueViolation($e)) abort(409, 'มีผู้ออกรหัสให้นักเรียนคนนี้พร้อมกัน กรุณาโหลดใหม่');
            throw $e;
        }
        return $secret;
    }

    public static function unlock(object $student): void
    {
        DB::transaction(function () use ($student) {
            $row = DB::table('api_student_pins')->where('school_id', Access::school())->where('student_id', $student->id)->lockForUpdate()->first();
            if (!$row) abort(404, 'นักเรียนคนนี้ยังไม่มีรหัส');
            Mutation::update('api_student_pins', (int) $row->id, (int) $row->version, ['failed_count' => 0, 'locked_until' => null, 'lock_count' => 0], 'student_portal.secret.unlocked', ['student_id' => (int) $student->id]);
        });
    }

    /** Student's own new secret (policy already checked by the caller). Returns the new auth_version for the continuing session. */
    public static function set(int $studentId, string $secret): int
    {
        return DB::transaction(function () use ($studentId, $secret) {
            $row = DB::table('api_student_pins')->where('school_id', Access::school())->where('student_id', $studentId)->lockForUpdate()->first();
            $vals = ['pin_hash' => Pins::hash($secret, $studentId), 'failed_count' => 0, 'locked_until' => null, 'lock_count' => 0, 'must_change' => false,
                'secret_kind' => ctype_digit($secret) ? 'pin' : 'password', 'changed_at' => now()->toDateTimeString(), 'updated_at' => now()];
            if ($row) DB::table('api_student_pins')->where('id', $row->id)->update($vals + ['version' => DB::raw('version + 1'), 'auth_version' => DB::raw('auth_version + 1')]);
            else DB::table('api_student_pins')->insert($vals + ['school_id' => Access::school(), 'student_id' => $studentId, 'created_at' => now(), 'version' => 1, 'auth_version' => 1]);
            self::revokeTokens($studentId);
            Contacts::resetDone('student', $studentId); // a changed password ends every reset link that is still out
            return (int) DB::table('api_student_pins')->where('school_id', Access::school())->where('student_id', $studentId)->value('auth_version');
        });
    }

    /** Policy for a student's chosen secret: the student code must not be part of it. */
    public static function check(string $secret, object $student): ?string
    {
        return SecretPolicy::check($secret, [(string) $student->code]);
    }

    /** Exactly one bcrypt per call, no static dummy hash (see Contacts::dummy). */
    public static function dummy(): void
    {
        Hash::make('saan-student-dummy');
    }
}
