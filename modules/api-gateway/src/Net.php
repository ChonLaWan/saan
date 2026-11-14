<?php

namespace Saan\Modules\ApiGateway;

use Illuminate\Database\QueryException;

/** Small network and database helpers shared by the controllers. */
class Net
{
    /** True when $ip is in the allowlist (exact IP or CIDR, v4 and v6). An empty list means no restriction. */
    public static function ipAllowed(?array $list, string $ip): bool
    {
        if (!$list) return true;
        $addr = @inet_pton($ip);
        if ($addr === false) return false;
        foreach ($list as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') continue;
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $bin = @inet_pton($net);
            if ($bin === false || strlen($bin) !== strlen($addr)) continue;
            $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
            if ($bits < 0 || $bits > strlen($bin) * 8) continue;
            $bytes = intdiv($bits, 8);
            if ($bytes > 0 && substr($addr, 0, $bytes) !== substr($bin, 0, $bytes)) continue;
            $rest = $bits % 8;
            if ($rest === 0) return true;
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($addr[$bytes]) & $mask) === (ord($bin[$bytes]) & $mask)) return true;
        }
        return false;
    }

    /** Validation helper: a literal IP or a CIDR block. */
    public static function validIpOrCidr(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', trim($value), 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
        if ($bits === null) return true;
        $max = str_contains($ip, ':') ? 128 : 32;
        return ctype_digit($bits) && (int) $bits <= $max;
    }

    /** SQLSTATE 23000 (MySQL/MariaDB), 23505 (PostgreSQL) or constraint code 19. */
    public static function isUniqueViolation(\Throwable $e): bool
    {
        if (!$e instanceof QueryException) return false;
        return \App\Support\Saan\DbError::isUnique($e);

    }

    public static function key(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
