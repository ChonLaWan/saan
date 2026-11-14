<?php

namespace Saan\Modules\Complaints;

use App\Support\Saan\Turnstile as SharedTurnstile;

/**
 * Thin alias of the shared App\Support\Saan\Turnstile (the implementation moved there so other modules can use it).
 * Kept so existing code and tests that reference the complaints class keep working.
 */
final class Turnstile
{
    public const VERIFY_URL = SharedTurnstile::VERIFY_URL;

    public static function siteKey(): string { return SharedTurnstile::siteKey(); }

    public static function enabled(): bool { return SharedTurnstile::enabled(); }

    public static function verify(mixed $token): bool { return SharedTurnstile::verify($token); }
}
