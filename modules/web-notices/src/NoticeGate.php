<?php

namespace Saan\Modules\WebNotices;

use App\Support\Saan\NetKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Anti-harvest delivery of full-name and search-only lists: rate limits that count every request, and the one-time row token.
 *
 * The token is 32 random bytes (hex), kept only as a SHA-256 hash in the cache, bound to the session id and the list, valid for token_ttl
 * seconds (default 120) and single use: the claim is an atomic Cache::add on a "used" key, so two parallel requests can never both win.
 * Any failed attempt on an existing token burns it.
 */
final class NoticeGate
{
    private static function cfg(string $k, int $default): int
    {
        return max(1, (int) config('saan.notices.'.$k, $default));
    }

    public static function ttl(): int { return self::cfg('token_ttl', 120); }

    /**
     * Counts this request against the per-/64 and per-/48 budgets (always counted, every request, passed or not). True = over a limit, refuse.
     * The per-list budget is NOT counted here: see listLimited(). A request that cannot pass Turnstile must never be able to spend the budget every
     * parent shares. The network budgets are kept for everyone, so a bot is still stopped per address.
     */
    public static function limited(Request $r, object $list): bool
    {
        $window = self::cfg('window', 600);
        $limited = false;
        foreach ([['net:'.NetKey::rate($r->ip()), self::cfg('per_net', 60)], ['wide:'.NetKey::wide($r->ip()), self::cfg('per_wide', 240)]] as [$key, $max]) {
            if (RateLimiter::hit('notice-gate:'.$key, $window) > $max) $limited = true;
        }
        return $limited;
    }

    /** Per-notice ceiling, counted only for requests that already passed Turnstile (challenge) or a one-time token (rows, search). True = over. */
    public static function listLimited(object $list): bool
    {
        return RateLimiter::hit('notice-gate:list:'.(int) $list->id, self::cfg('window', 600)) > self::cfg('per_list', 6000);
    }

    /** Per-browser-session budget for searches. True = over the budget. */
    public static function searchLimited(Request $r, object $list): bool
    {
        return RateLimiter::hit('notice-gate:search:'.$r->session()->getId().'|'.(int) $list->id, self::cfg('window', 600)) > self::cfg('search_session', 15);
    }

    private static function key(string $token): string
    {
        return 'notice-token:'.hash('sha256', $token);
    }

    public static function issue(Request $r, object $list): string
    {
        $token = bin2hex(random_bytes(32));
        Cache::put(self::key($token), ['sid' => $r->session()->getId(), 'list' => (int) $list->id, 'at' => Carbon::now()->getTimestamp()], self::ttl());
        return $token;
    }

    public static function consume(Request $r, object $list, mixed $token): bool
    {
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return false;
        $key = self::key($token);
        if (!Cache::has($key)) return false;
        // atomic claim: add() succeeds for exactly one caller
        if (!Cache::add($key.':used', 1, self::ttl() + 60)) return false;
        $rec = Cache::pull($key);
        if (!is_array($rec)) return false;
        if (Carbon::now()->getTimestamp() - (int) ($rec['at'] ?? 0) > self::ttl()) return false;
        return hash_equals((string) ($rec['sid'] ?? ''), (string) $r->session()->getId()) && (int) ($rec['list'] ?? 0) === (int) $list->id;
    }
}
