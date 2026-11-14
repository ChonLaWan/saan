<?php

namespace App\Support\Saan;

use App\Support\Saan\Publish\Keys;

/**
 * Secret behind keyed hashes that must survive an APP_KEY rotation (identifier dedupe, student PINs, certificate links,
 * survey respondent keys, IP hashes). SAAN_HASH_KEY is a dedicated secret that never rotates and travels with the backup.
 * When it is not set the app key is used (the previous behaviour), and lookups also try every APP_PREVIOUS_KEYS entry.
 */
final class HashKey
{
    private static function raw(string $k): string
    {
        return str_starts_with($k, 'base64:') ? (string) base64_decode(substr($k, 7), true) : $k;
    }

    /** Key used to create new hashes. */
    public static function secret(): string
    {
        $dedicated = trim((string) config('saan.hash_key', ''));
        return $dedicated !== '' ? $dedicated : (string) config('app.key');
    }

    /**
     * Keys to try when VERIFYING or looking up an older hash: the current one first, then the app key and previous app keys.
     * They are always appended, so setting SAAN_HASH_KEY later on a live system does not orphan hashes made with the app key.
     * @return string[]
     */
    public static function candidates(): array
    {
        $out = [self::secret(), (string) config('app.key')];
        foreach ((array) config('app.previous_keys', []) as $k) $out[] = trim((string) $k);
        return array_values(array_unique(array_filter($out, fn ($k) => $k !== '')));
    }

    /**
     * Dedicated key for the unlock codes that must also work on the public edition (learning rooms, protected notices).
     * Derived one way from the master key (the master cannot be recovered from it), or set explicitly with SAAN_PUBLIC_UNLOCK_KEY.
     * ONLY this derived value may be copied to the public edition (as SAAN_PUBLIC_UNLOCK_KEY); never SAAN_HASH_KEY or APP_KEY.
     */
    public static function publicUnlock(): string
    {
        $explicit = trim((string) config('saan.public_unlock_key', ''));
        if ($explicit !== '') return $explicit;
        // S6: the key file written by pairing (public edition) or by a rotation (internal edition) wins over the derived value
        try {
            $file = Edition::isPublic() ? Keys::peer('unlock.key') : Keys::unlockKey();
            if ($file !== null) return $file;
        } catch (\Throwable) { /* unreadable key directory: fall back to the derived key */ }
        return self::deriveUnlock(self::secret());
    }

    private static function deriveUnlock(string $master): string
    {
        return hash_hmac('sha256', 'saan-public-unlock-v1', $master);
    }

    /**
     * The unlock key in force followed by the unlock keys retired by rotations (internal: unlock.old.*, public: what pairing sent).
     * For values hashed ONLY with publicUnlock() whose plain value is not kept, such as complaint tracking codes.
     * @return list<string>
     */
    public static function publicUnlockHistory(): array
    {
        $out = [self::publicUnlock()];
        try { foreach (Edition::isPublic() ? Keys::peerRetiredUnlockKeys() : Keys::retiredUnlockKeys() as $k) $out[] = $k; } catch (\Throwable) {}
        return array_values(array_unique($out));
    }

    /**
     * Keys to try when verifying an unlock code or token: the dedicated key first, then the same derivation from every master candidate
     * (an APP_KEY rotation or a later SAAN_HASH_KEY keeps issued codes valid), then (internal side) the older master-keyed hashes.
     */
    public static function unlockCandidates(): array
    {
        $out = [self::publicUnlock()];
        try { foreach (Edition::isPublic() ? Keys::peerRetiredUnlockKeys() : Keys::retiredUnlockKeys() as $k) $out[] = $k; } catch (\Throwable) {}
        foreach (self::candidates() as $k) $out[] = self::deriveUnlock($k);
        return array_values(array_unique(array_merge($out, self::candidates())));
    }

    /** Decoded form (base64: prefix removed) for HMAC derivations that expect raw bytes. */
    public static function bytes(string $key): string
    {
        return self::raw($key);
    }
}
