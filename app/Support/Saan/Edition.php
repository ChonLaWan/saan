<?php

namespace App\Support\Saan;

use InvalidArgumentException;

/**
 * Installation role of this Saan copy (config('saan.edition'), env SAAN_EDITION).
 *
 *  combined  one install serves everything (demo/dev and installs that were never split) - the default, identical to the old behaviour
 *  internal  staff back office, guardian portal, API gateway and the inbox puller; no public site pages
 *  public    read-only public site plus the public form endpoints; no login, no staff routes, no API, no guardian portal
 *
 * Surfaces: site (public pages), staff (login, CMS, /work, staff APIs), guardian (/guardian*), student (/student*), api (/oauth*, /api/v1*), inbox (encrypted inbox, S5).
 */
final class Edition
{
    public const COMBINED = 'combined';
    public const INTERNAL = 'internal';
    public const PUBLIC = 'public';
    public const ALL = [self::COMBINED, self::INTERNAL, self::PUBLIC];
    public const SURFACES = ['site', 'staff', 'guardian', 'student', 'api', 'inbox'];

    private const SERVES = [
        self::COMBINED => ['site', 'staff', 'guardian', 'student', 'api', 'inbox'],
        self::INTERNAL => ['staff', 'guardian', 'student', 'api', 'inbox'],
        self::PUBLIC => ['site', 'inbox'],
    ];

    /**
     * Public edition = allowlist (deny by default): the first path segment must be one of these, everything else answers 404 (EditionGate),
     * even if a route were ever registered by mistake. A new public surface has to be added here on purpose, together with its routes/public.php.
     */
    public const PUBLIC_ALLOWED = ['portal', 'media', 'learn', 'sitemap.xml', 'up', '_saan'];
    /** Guest-safe JSON endpoints under /api that stay open in the public edition (lowercase, no leading slash). */
    /** Also the one exact path both editions answer outside the shared segments: the token-guarded finish step of an update (Updater contract 1). */
    public const PUBLIC_API_ALLOW = ['api/personnel/directory', '_saan/update-finish'];
    /** First segments that the public and internal editions both register by design (front door, files behind sign-in on the internal side, health check). */
    public const SHARED_SEGMENTS = ['', 'media', 'portal', 'up'];

    /** Validates an edition name. Empty means the default. Unknown values throw: a typo must never widen what an install serves. */
    public static function normalize(mixed $value): string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') return self::COMBINED;
        if (!in_array($v, self::ALL, true)) throw new InvalidArgumentException('SAAN_EDITION ไม่ถูกต้อง: ใช้ combined, internal หรือ public');
        return $v;
    }

    public static function current(): string
    {
        return self::normalize(config('saan.edition', self::COMBINED));
    }

    public static function is(string $edition): bool
    {
        return self::current() === $edition;
    }

    public static function isPublic(): bool
    {
        return self::current() === self::PUBLIC;
    }

    public static function isInternal(): bool
    {
        return self::current() === self::INTERNAL;
    }

    public static function isCombined(): bool
    {
        return self::current() === self::COMBINED;
    }

    public static function serves(string $surface): bool
    {
        return self::servesIn(self::current(), $surface);
    }

    public static function servesIn(string $edition, string $surface): bool
    {
        if (!in_array($surface, self::SURFACES, true)) throw new InvalidArgumentException('surface ไม่รู้จัก: '.$surface);
        return in_array($surface, self::SERVES[self::normalize($edition)], true);
    }

    /** Suffix that keeps cookies and cache keys of the two editions apart on the same host. Combined keeps the historical names. */
    public static function suffix(?string $edition = null): string
    {
        $e = self::normalize($edition ?? config('saan.edition', self::COMBINED));
        return $e === self::COMBINED ? '' : '_'.$e;
    }

    /**
     * True when the session cookie of this install can and should be a __Host- cookie (Secure, Path=/, no Domain, so no sibling subdomain such as
     * www.school.ac.th can set a cookie of the same name over it and lock staff out). Internal edition on https only: the prefix needs Secure,
     * which would break plain-http development.
     */
    public static function hostCookie(string $edition, string $appUrl, string $path, string $domain): bool
    {
        return strtolower(trim($edition)) === self::INTERNAL && str_starts_with(strtolower(trim($appUrl)), 'https://') && $path === '/' && trim($domain) === '';
    }

    /** Link target of the public site's "ผู้ดูแลระบบ" footer link: a clean absolute http(s) URL, or null (link hidden). */
    public static function adminUrl(): ?string
    {
        $u = trim((string) config('saan.internal_url', ''));
        if ($u === '' || !preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~%/-]*)?$#D', $u)) return null;
        return rtrim($u, '/');
    }

    /** Public site's "view site" target for the internal edition (SAAN_PUBLIC_URL): a clean absolute http(s) URL, or null. */
    public static function publicUrl(): ?string
    {
        $u = trim((string) config('saan.public_url', ''));
        if ($u === '' || !preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~%/-]*)?$#D', $u)) return null;
        return rtrim($u, '/');
    }

    /** First path segment (lowercase, decoded, slashes collapsed) of a request path; '' for the front door. */
    public static function firstSegment(string $path): string
    {
        return explode('/', self::normalizePath($path), 2)[0];
    }

    private static function normalizePath(string $path): string
    {
        $p = strtolower(rawurldecode($path));
        return trim((string) preg_replace('~/+~', '/', $p), '/');
    }

    /** True when $path (as the request sees it) is not part of the public site: the public edition must answer 404 for it. */
    public static function blockedInPublic(string $path): bool
    {
        $p = self::normalizePath($path);
        if ($p === '') return false;
        if (in_array($p, self::PUBLIC_API_ALLOW, true)) return false;
        return !in_array(explode('/', $p, 2)[0], self::PUBLIC_ALLOWED, true);
    }
}
