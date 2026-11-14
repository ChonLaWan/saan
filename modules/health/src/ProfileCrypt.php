<?php

namespace Saan\Modules\Health;

use Illuminate\Support\Facades\Crypt;

/**
 * Field encryption of the health profile free text (PDPA section 26 data). Stored value = 'enc1:' + Crypt::encryptString(text).
 * Values without the prefix are legacy plaintext and are still read as they are (the one-off migration encrypts them).
 * blood_group stays plaintext.
 */
final class ProfileCrypt
{
    public const FIELDS = ['allergies', 'chronic_conditions', 'medications', 'emergency_note'];
    private const PREFIX = 'enc1:';

    public static function encrypt(?string $text): ?string
    {
        if ($text === null || $text === '') return $text;
        // always encrypt user input: a client-sent 'enc1:' value must never be stored as-is (it would be decrypted on read = decryption oracle)
        return self::PREFIX.Crypt::encryptString($text);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX)) return $stored;
        try {
            return Crypt::decryptString(substr($stored, strlen(self::PREFIX)));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Encrypt the free-text fields of a write set. @param array<string,mixed> $values */
    public static function encryptValues(array $values): array
    {
        foreach (self::FIELDS as $f) if (array_key_exists($f, $values)) $values[$f] = self::encrypt($values[$f]);
        return $values;
    }
}
