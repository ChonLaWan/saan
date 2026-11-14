<?php

namespace App\Support\Saan;

use Illuminate\Support\Facades\Http;

/**
 * Optional Cloudflare Turnstile shared by the public complaint form and the full-name notice lists.
 * Off until both the site key and the secret are set (SAAN_TURNSTILE_SITE_KEY / SAAN_TURNSTILE_SECRET). Verified on the server;
 * the visitor's address is NOT sent (no remoteip). Fails closed: a missing token, an unreachable verifier or an unexpected answer all refuse.
 *
 * verify() keeps the old complaint-form behaviour (passes while Turnstile is off). strict() is for features that are not allowed to work
 * without the anti-bot step: it refuses when Turnstile is not configured.
 */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function siteKey(): string
    {
        $k = trim((string) config('saan.turnstile.site_key', ''));
        return $k !== '' ? $k : trim((string) config('saan.complaints.turnstile_site_key', ''));
    }

    private static function secret(): string
    {
        $s = trim((string) config('saan.turnstile.secret', ''));
        return $s !== '' ? $s : trim((string) config('saan.complaints.turnstile_secret', ''));
    }

    public static function enabled(): bool
    {
        return self::siteKey() !== '' && self::secret() !== '';
    }

    public static function verify(mixed $token): bool
    {
        if (!self::enabled()) return true;
        return self::check($token);
    }

    /** Like verify(), but never passes while Turnstile is not configured. */
    public static function strict(mixed $token): bool
    {
        return self::enabled() && self::check($token);
    }

    private static function check(mixed $token): bool
    {
        if (!is_string($token) || $token === '' || strlen($token) > 2048) return false;
        try {
            $r = Http::asForm()->timeout(5)->post(self::VERIFY_URL, ['secret' => self::secret(), 'response' => $token]);
            return $r->successful() && $r->json('success') === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
