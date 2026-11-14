<?php
namespace SchoolAtelier\Installer;

/**
 * S7 install id and every name derived from it. Runs before Laravel exists (used by the web installer), so it depends on nothing.
 * The id is 6 random characters of lowercase base32 (a-z, 2-7), generated once per install and kept in storage/installed.json and .env (SAAN_INSTALL_ID).
 * Two installs on one machine therefore never share a database, a database user, a session cookie, a cache/redis/queue/lock prefix or a backup name.
 */
final class Identity
{
    public const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';
    public const EDITIONS = ['combined', 'internal', 'public'];

    /** @param list<string> $taken ids that must not be reused (sibling installs, existing database names) */
    public static function generate(array $taken = []): string
    {
        do {
            $id = '';
            for ($i = 0; $i < 6; $i++) $id .= self::ALPHABET[random_int(0, 31)];
        } while (in_array($id, $taken, true));
        return $id;
    }

    public static function valid(string $id): bool
    {
        return preg_match('/^[a-z2-7]{6}$/D', $id) === 1;
    }

    /**
     * @return array{id:string,edition:string,db_internal:string,db_public:string,user_internal:string,user_public:string,table_prefix:string,
     *   session_cookie_env:string,session_cookie:string,cache_prefix:string,backup_prefix:string}
     * session_cookie_env is what goes into SESSION_COOKIE (config/session.php appends the edition suffix); session_cookie is the cookie name the browser sees.
     * Database names stay below 64 and user names below 32 characters (MySQL limits): 'saan_' + 6 + '_int' = 15, 'saan_' + 6 + '_i' = 13.
     */
    public static function derive(string $id, string $edition): array
    {
        if (!self::valid($id)) throw new \InvalidArgumentException('install id ไม่ถูกต้อง');
        if (!in_array($edition, self::EDITIONS, true)) throw new \InvalidArgumentException('edition ไม่ถูกต้อง');
        return [
            'id' => $id,
            'edition' => $edition,
            'db_internal' => 'saan_'.$id.'_int',
            'db_public' => 'saan_'.$id.'_pub',
            'user_internal' => 'saan_'.$id.'_i',
            'user_public' => 'saan_'.$id.'_p',
            // off by default (DB_PREFIX stays empty): only for a shared database that cannot be split, see docs/split-advanced.th.md
            'table_prefix' => 'saan_'.$id.'_',
            'session_cookie_env' => 'saan_'.$id,
            'session_cookie' => $edition === 'combined' ? 'saan_'.$id : 'saan_'.$id.'_'.$edition,
            'cache_prefix' => 'saan_'.$id.'_'.$edition.'_',
            'backup_prefix' => 'saan-'.$id.'-'.$edition,
        ];
    }

    /** Environment values every edition gets from the id (cache, redis, queue and lock keys share the cache prefix). */
    public static function env(string $id, string $edition): array
    {
        $d = self::derive($id, $edition);
        return ['SAAN_EDITION' => $edition, 'SAAN_INSTALL_ID' => $id, 'SESSION_COOKIE' => $d['session_cookie_env'], 'CACHE_PREFIX' => $d['cache_prefix'], 'REDIS_PREFIX' => $d['cache_prefix'], 'DB_PREFIX' => ''];
    }

    /** Ids already in use by an install at $root (storage/installed.json) or empty. */
    public static function idAt(string $root): ?string
    {
        $f = $root.'/storage/installed.json';
        $j = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        return is_array($j) && is_string($j['install_id'] ?? null) && self::valid($j['install_id']) ? $j['install_id'] : null;
    }
}
