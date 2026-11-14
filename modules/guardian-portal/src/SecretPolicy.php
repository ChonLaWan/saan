<?php

namespace Saan\Modules\GuardianPortal;

use App\Support\Saan\Settings;

/**
 * F8: what a student or guardian may choose as their own secret. No e-mail is required, so the secret is deliberately light but never trivial:
 * a numeric PIN of at least 6 digits, or a short password of at least portal.password_min characters (school setting, default 6, 6 to 64).
 * Weak secrets are compensated elsewhere (lockout with growing delay, network and school rate limits, read-only own-data scope).
 */
final class SecretPolicy
{
    public const KEY = 'portal.password_min';
    public const MAX_LEN = 100;
    private const COMMON = ['123456', '1234567', '12345678', '123456789', '1234567890', '654321', '000000', '111111', '222222', '333333', '444444', '555555', '666666', '777777', '888888', '999999',
        '121212', '112233', '123123', '012345', '987654', 'password', 'passw0rd', 'qwerty', 'qwerty123', 'abc123', 'abcdef', 'abcd1234', 'iloveyou', 'student', 'school'];

    public static function min(): int
    {
        $v = Settings::get(self::KEY, 6);
        return is_numeric($v) ? max(6, min(64, (int) $v)) : 6;
    }

    /** @param string[] $forbidden values that must not be the secret or part of it (student code, username, e-mail name) @return string|null Thai message, or null when acceptable */
    public static function check(string $secret, array $forbidden = []): ?string
    {
        if ($secret !== trim($secret) || preg_match('/[\x00-\x1F\x7F]/', $secret)) return 'รหัสผ่านต้องไม่ขึ้นต้นหรือลงท้ายด้วยช่องว่าง และห้ามมีอักขระควบคุม';
        $len = mb_strlen($secret);
        if ($len > self::MAX_LEN) return 'รหัสผ่านยาวเกินไป';
        if (ctype_digit($secret)) {
            if ($len < 6) return 'รหัสตัวเลขต้องมีอย่างน้อย 6 หลัก';
        } elseif ($len < self::min()) {
            return 'รหัสผ่านต้องมีอย่างน้อย '.self::min().' ตัวอักษร (หรือใช้ตัวเลขล้วนอย่างน้อย 6 หลัก)';
        }
        $low = mb_strtolower($secret);
        if (preg_match('/^(.)\1*$/us', $secret) || in_array($low, self::COMMON, true) || str_contains('0123456789876543210', $low) || str_contains('abcdefghijklmnopqrstuvwxyz', $low)) {
            return 'รหัสผ่านนี้เดาง่ายเกินไป กรุณาเลือกใหม่';
        }
        foreach ($forbidden as $f) {
            $f = mb_strtolower(trim((string) $f));
            if ($f !== '' && mb_strlen($f) >= 4 && str_contains($low, $f)) return 'รหัสผ่านต้องไม่มีรหัสนักเรียนหรือชื่อผู้ใช้อยู่ในนั้น';
        }
        return null;
    }
}
