<?php

namespace App\Support\Saan;

/**
 * Stable key for per-client rate limiting. IPv4 is used as is; IPv6 is cut to its /64 prefix because an ISP hands one
 * subscriber a whole /64, so a per-address limit would be bypassed by rotating addresses inside it.
 */
class NetKey
{
    /** Coarser key for the same purpose: IPv4 as is, IPv6 cut to its /48 (one site or one customer of a hosting provider rotates through a whole /48). */
    public static function wide(?string $ip): string
    {
        $ip = trim((string) $ip);
        $bin = $ip === '' ? false : @inet_pton($ip);
        if ($bin === false || strlen($bin) === 4 || substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return self::rate($ip);
        return inet_ntop(substr($bin, 0, 6).str_repeat("\0", 10)).'/48';
    }

    public static function rate(?string $ip): string
    {
        $ip = trim((string) $ip);
        if ($ip === '') return 'unknown';
        $bin = @inet_pton($ip);
        if ($bin === false) return 'invalid:'.substr(sha1($ip), 0, 16);
        if (strlen($bin) === 4) return (string) inet_ntop($bin);
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is really an IPv4 client
        if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return (string) inet_ntop(substr($bin, 12));
        return inet_ntop(substr($bin, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
