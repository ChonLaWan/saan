<?php
namespace SchoolAtelier\Installer;

/**
 * Do two app roots of a standard install really keep separate storage/ and bootstrap/cache? Shared by the installer and by school:doctor
 * (no dependency on Laravel). A top-level realpath comparison is not enough: a link INSIDE the public storage (storage/app/private,
 * saan-keys, framework/sessions, logs) would hand the public side the internal secrets, so links are refused and the sensitive sub-folders are
 * compared by real path too.
 */
final class Roots
{
    /** Folders whose real path must differ between the two sides (the first two are the top-level ones). */
    public const SENSITIVE = ['storage', 'bootstrap/cache', 'storage/app', 'storage/app/private', 'storage/app/private/saan-keys', 'storage/framework', 'storage/framework/sessions', 'storage/logs'];
    private const MAX_DEPTH = 6;
    private const MAX_ENTRIES = 20000;

    public static function norm(string $p): string
    {
        $p = rtrim(str_replace('\\', '/', $p), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
    }

    /** Scheme, host and effective port of a URL (lowercase, default port made explicit), or null when it has no host. */
    public static function origin(string $url): ?string
    {
        $p = parse_url(trim($url));
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) return null;
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        return $scheme.'://'.$host.':'.$port;
    }

    /** True when the two URLs are the same origin (scheme, host, port: path, case and a default port do not matter). Page script of one is then same-origin with the other. */
    public static function sameOrigin(string $a, string $b): bool
    {
        $x = self::origin($a);
        return $x !== null && $x === self::origin($b);
    }

    public static function inside(string $child, string $parent): bool
    {
        return str_starts_with(self::norm($child).'/', self::norm($parent).'/');
    }

    /** First link found below $dir (links are never followed), or null. Bounded in depth and entries so the doctor stays quick on a big storage. */
    public static function findLink(string $dir, int $depth = 0, int &$seen = 0): ?string
    {
        if ($depth > self::MAX_DEPTH || !is_dir($dir)) return null;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            if (++$seen > self::MAX_ENTRIES) return null;
            $p = $dir.'/'.$f;
            if (is_link($p)) return $p;
            if (is_dir($p) && ($hit = self::findLink($p, $depth + 1, $seen)) !== null) return $hit;
        }
        return null;
    }

    /** @return string|null Thai problem text, or null when the two roots keep separate storage and cache. */
    public static function conflict(string $internal, string $public): ?string
    {
        $int = realpath($internal);
        $pub = realpath($public);
        if ($int === false || $pub === false) return 'ไม่พบโฟลเดอร์แอปของสองฝั่ง';
        if (self::norm($int) === self::norm($pub) || self::inside($pub, $int) || self::inside($int, $pub)) return 'โฟลเดอร์หน้าบ้านต้องเป็นสำเนาแยก ไม่ซ้อนหรือเป็นที่เดียวกับระบบภายใน';
        foreach (['storage', 'bootstrap/cache'] as $d) {
            if (is_link($pub.'/'.$d)) return 'หน้าบ้านใช้ '.$d.' เป็น symlink ไม่รับ ต้องเป็นโฟลเดอร์จริงของหน้าบ้านเอง';
            if (($hit = self::findLink($pub.'/'.$d)) !== null) return 'พบ symlink ใน '.$d.' ของหน้าบ้าน ('.substr(str_replace('\\', '/', $hit), strlen(str_replace('\\', '/', $pub)) + 1).') ไม่รับ ต้องเป็นโฟลเดอร์จริงของหน้าบ้านเอง';
        }
        foreach (self::SENSITIVE as $d) {
            $a = realpath($int.'/'.$d);
            $b = realpath($pub.'/'.$d);
            if ($a === false || $b === false) {
                if (in_array($d, ['storage', 'bootstrap/cache'], true)) return 'สองฝั่งต้องไม่ใช้ '.$d.' ร่วมกัน (ไม่พบโฟลเดอร์)';
                continue;
            }
            if (self::norm($a) === self::norm($b) || self::inside($a, $b) || self::inside($b, $a)) return 'สองฝั่งต้องไม่ใช้ '.$d.' ร่วมกัน';
        }
        return null;
    }
}
