<?php

namespace Saan\Modules\Registry;

use Illuminate\Support\Facades\Crypt;

/** Identity-number rules: validation, normalisation, keyed hash and masking. Full values never leave this class unencrypted except via reveal(). */
class Identity
{
    public const KINDS = ['thai_id', 'g_code', 'passport', 'other', 'pending'];

    public static function normalise(string $kind, ?string $value): ?string
    {
        if ($kind === 'pending') return null;
        $v = trim((string) $value);
        if ($kind === 'thai_id') return preg_replace('/[\s-]+/u', '', $v);
        if ($kind === 'other') return mb_strtoupper(preg_replace('/\s+/u', ' ', $v));
        return mb_strtoupper(preg_replace('/[\s-]+/u', '', $v));
    }

    /** 13 digits and mod-11 check digit: (11 - sum(d[i] * (13 - i), i = 0..11) mod 11) mod 10. */
    public static function thaiIdValid(string $digits): bool
    {
        if (!preg_match('/^\d{13}$/D', $digits)) return false;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int) $digits[$i] * (13 - $i);
        return (11 - $sum % 11) % 10 === (int) $digits[12];
    }

    /** G-code: letter G followed by 12 digits (13 characters). The system never generates a G-code. */
    public static function gCodeValid(string $v): bool { return (bool) preg_match('/^G\d{12}$/D', $v); }

    /** Returns a Thai error message, or null when the normalised value is acceptable for the kind. */
    public static function error(string $kind, ?string $normalised): ?string
    {
        if (!in_array($kind, self::KINDS, true)) return 'ชนิดเลขประจำตัวไม่ถูกต้อง';
        if ($kind === 'pending') return null;
        if ($normalised === null || $normalised === '') return 'กรุณากรอกเลขประจำตัว';
        return match ($kind) {
            'thai_id' => !preg_match('/^\d{13}$/D', $normalised) ? 'เลขประจำตัวประชาชนต้องมี 13 หลัก'
                : (self::thaiIdValid($normalised) ? null : 'เลขประจำตัวประชาชนไม่ถูกต้อง (ตัวเลขตรวจสอบไม่ตรง)'),
            'g_code' => self::gCodeValid($normalised) ? null : 'เลข G ต้องขึ้นต้นด้วย G ตามด้วยตัวเลข 12 หลัก',
            'passport' => preg_match('/^[A-Z0-9]{5,20}$/D', $normalised) ? null : 'เลขหนังสือเดินทางต้องเป็นตัวอักษรหรือตัวเลข 5-20 ตัว',
            default => mb_strlen($normalised) >= 3 && mb_strlen($normalised) <= 40 ? null : 'เลขประจำตัวต้องยาว 3-40 ตัวอักษร',
        };
    }

    /** HMAC-SHA256 with a key derived from the app key, so a leaked table cannot be brute-forced without the key. */
    public static function hash(string $normalised, ?string $key = null): string
    {
        $key = \App\Support\Saan\HashKey::bytes($key ?? \App\Support\Saan\HashKey::secret());
        return hash_hmac('sha256', $normalised, hash('sha256', 'saan.registry.identifier|'.$key, true));
    }

    /** Hashes of one value under the current key and every key a lookup must still honour (rotation-safe dedupe). @return string[] */
    public static function hashes(string $normalised): array
    {
        return array_values(array_unique(array_map(fn ($k) => self::hash($normalised, $k), \App\Support\Saan\HashKey::candidates())));
    }

    /** Issuing country/authority code of a passport (upper case, 2-10 letters); ignored for other kinds. */
    public static function issuer(string $kind, ?string $issuer): ?string
    {
        if ($kind !== 'passport') return null;
        $v = mb_strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $issuer));
        return $v === '' ? null : mb_substr($v, 0, 10);
    }

    /** Passport numbers repeat across countries, so the issuer is part of what is hashed. */
    public static function hashInput(string $kind, string $normalised, ?string $issuer): string
    {
        return $kind === 'passport' && $issuer ? $issuer.'|'.$normalised : $normalised;
    }

    public static function last4(?string $normalised): ?string
    {
        return $normalised === null || $normalised === '' ? null : mb_substr($normalised, -4);
    }

    public static function encrypt(?string $value): ?string { return $value === null || $value === '' ? null : Crypt::encryptString($value); }

    public static function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') return null;
        try { return Crypt::decryptString($cipher); } catch (\Throwable) { return null; }
    }
}
