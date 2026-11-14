<?php

namespace Saan\Modules\Payslip;

use App\Support\StorageQuota;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Field and file encryption of payroll data (same pattern as the health ProfileCrypt: 'enc1:' + Crypt::encryptString with APP_KEY).
 * Amounts, bank account numbers, national ids and uploaded PDFs are never stored readable. A value that does not decrypt reads as null,
 * so a damaged or foreign row can never show somebody else's data.
 */
final class Vault
{
    private const PREFIX = 'enc1:';

    public static function seal(?string $text): ?string
    {
        if ($text === null || $text === '') return null;
        return self::PREFIX.Crypt::encryptString($text);
    }

    public static function open(?string $stored): ?string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX)) return null;
        try { return Crypt::decryptString(substr($stored, strlen(self::PREFIX))); } catch (\Throwable) { return null; }
    }

    public static function sealJson(array $data): string
    {
        return (string) self::seal(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function openJson(?string $stored): ?array
    {
        $plain = self::open($stored);
        if ($plain === null) return null;
        $d = json_decode($plain, true);
        return is_array($d) ? $d : null;
    }

    // ---------------------------------------------------------------- files (private disk, encrypted)

    private static function disk() { return Storage::disk('local'); }

    public static function putFile(string $bytes): string
    {
        StorageQuota::assertRoom(strlen($bytes));
        $path = 'payslip/'.Support::school().'/pdf/'.bin2hex(random_bytes(16)).'.enc';
        abort_unless(self::disk()->put($path, Crypt::encryptString($bytes)), 500, 'บันทึกไฟล์ไม่สำเร็จ');
        return $path;
    }

    private static function ours(?string $path): bool
    {
        return $path !== null && $path !== '' && str_starts_with($path, 'payslip/'.Support::school().'/') && !str_contains($path, '..');
    }

    public static function readFile(?string $path): ?string
    {
        if (!self::ours($path) || !self::disk()->exists($path)) return null;
        try { return Crypt::decryptString((string) self::disk()->get($path)); } catch (\Throwable) { return null; }
    }

    public static function forget(?string $path): void
    {
        if (self::ours($path)) self::disk()->delete($path);
    }
}
