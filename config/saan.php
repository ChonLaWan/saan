<?php

// Platform identity of Saan. School identity (name, logo, favicon) stays in the CMS settings of each school.
$versionFile = dirname(__DIR__).'/VERSION';

return [
    // Dedicated secret for keyed hashes that must survive an APP_KEY rotation (empty = fall back to APP_KEY and APP_PREVIOUS_KEYS).
    'hash_key' => env('SAAN_HASH_KEY', ''),
    // Key of the unlock-code hashes (learning rooms, protected notices) that are exported to the public edition. Empty = derived from the master key
    // (App\Support\Saan\HashKey::publicUnlock). On the public edition set it to the internal derived value; never copy SAAN_HASH_KEY or APP_KEY there.
    'public_unlock_key' => env('SAAN_PUBLIC_UNLOCK_KEY', ''),
    // Installation role (S1): 'combined' = one install serves everything (demo/dev and installs that were never split, the default),
    // 'internal' = staff/back office/guardian/API only (no public site), 'public' = read-only public site and public form endpoints only.
    // An unknown value is rejected at boot (App\Support\Saan\Edition) instead of silently falling back to a wider role.
    'edition' => env('SAAN_EDITION', 'combined'),
    // S7: random 6 character id of this install (lowercase base32). Prefixes of the session cookie, cache/redis keys, database names and backup file names derive from it; empty on installs made before S7.
    'install_id' => env('SAAN_INSTALL_ID', ''),
    // Where the public site's "ผู้ดูแลระบบ" footer link points (the internal edition's URL, e.g. https://app.school.ac.th). Empty = no link.
    'internal_url' => env('SAAN_INTERNAL_URL', ''),
    // Internal edition: the public site's URL for 'view site' links (the internal edition serves no /portal pages). Empty = use this host.
    'public_url' => env('SAAN_PUBLIC_URL', ''),
    // S3/S4 push channel (internal -> public). public_push_url: the public edition's endpoint, e.g. https://www.school.ac.th/_saan/push (internal edition only; empty = no push).
    // keys_dir: Ed25519 key files (push.secret + push.pub on the internal side, peer-push.pub on the public side). receive_disk: where the public side stores pushed files.
    'public_push_url' => env('SAAN_PUBLIC_PUSH_URL', ''),
    'keys_dir' => env('SAAN_KEYS_DIR', ''),
    'push' => [
        'max_body_kb' => (int) env('SAAN_PUSH_MAX_BODY_KB', 8192),
        'max_batch_kb' => (int) env('SAAN_PUSH_MAX_BATCH_KB', 900),
        'chunk_kb' => (int) env('SAAN_PUSH_CHUNK_KB', 512),
        'receive_disk' => 'local',
    ],
    // S5 encrypted inbox (public edition seals what public forms receive; the internal edition pulls it). inbox_connection: database connection of the
    // inbox tables when it is not the default one (tests); empty = default. Limits apply to what one submission may carry (sizes in KB).
    'inbox_connection' => null,
    'inbox' => [
        'max_payload_kb' => (int) env('SAAN_INBOX_MAX_PAYLOAD_KB', 256),
        'max_file_kb' => (int) env('SAAN_INBOX_MAX_FILE_KB', 8192),
        'max_total_kb' => (int) env('SAAN_INBOX_MAX_TOTAL_KB', 20480),
        'max_waiting' => (int) env('SAAN_INBOX_MAX_WAITING', 5000),
        // the contact form may fill only this many of those places (it is open to anybody), so surveys and other kinds keep working while it is flooded
        // messages that reach cms_messages through the pull, per minute and per day (a breached public side cannot flood the inbox of the school beyond this)
        'contact_per_minute' => (int) env('SAAN_INBOX_CONTACT_PER_MINUTE', 120),
        'contact_per_day' => (int) env('SAAN_INBOX_CONTACT_PER_DAY', 3000),
        'max_waiting_contact' => (int) env('SAAN_INBOX_MAX_WAITING_CONTACT', 1000),
        // complaints (F1): same idea as the contact form. Share of the waiting places, and what the internal side accepts per minute / per day / in attachment megabytes per day
        // whatever the envelopes claim (a breached public side cannot flood the case list or fill the disk beyond this; above it envelopes simply wait)
        'max_waiting_complaint' => (int) env('SAAN_INBOX_MAX_WAITING_COMPLAINT', 1000),
        'complaint_per_minute' => (int) env('SAAN_INBOX_COMPLAINT_PER_MINUTE', 30),
        'complaint_per_day' => (int) env('SAAN_INBOX_COMPLAINT_PER_DAY', 500),
        'complaint_files_mb_per_day' => (int) env('SAAN_INBOX_COMPLAINT_FILES_MB_PER_DAY', 500),
    ],
    // Cloudflare Turnstile for the public complaint form ONLY (the project has no other captcha). Off while either value is empty. The secret lives in the
    // .env of the edition that serves the form (the public one) and is never projected or stored in the database.
    'complaints' => [
        'turnstile_site_key' => env('SAAN_TURNSTILE_SITE_KEY', ''),
        'turnstile_secret' => env('SAAN_TURNSTILE_SECRET', ''),
        // attachments of complaints may use this much of the school's storage (0 = a quarter of the school quota), and one network (/48 or
        // one IPv4 address) may upload this many megabytes per hour through the public form
        'files_quota_mb' => (int) env('SAAN_COMPLAINT_FILES_QUOTA_MB', 0),
        'net_mb_per_hour' => (int) env('SAAN_COMPLAINT_NET_MB_PER_HOUR', 60),
    ],
    // Same Turnstile keys, read by App\Support\Saan\Turnstile (shared by the complaint form and full-name notice lists). The complaints keys above stay as fallback.
    'turnstile' => [
        'site_key' => env('SAAN_TURNSTILE_SITE_KEY', ''),
        'secret' => env('SAAN_TURNSTILE_SECRET', ''),
    ],
    // web-notices anti-harvest limits for the full-name / search-only delivery.
    // per_net (/64, IPv4 as is) and per_wide (/48) count EVERY request, passed or not. per_list counts only requests that passed Turnstile or a token, so junk
    // requests cannot close a notice for every parent. Thai mobile carriers put many phones behind one IPv4 (CGNAT) and a school NAT does the same: one visit
    // costs 2 hits (challenge + rows/search), so per_net 60 serves about 30 visits per address per window; raise per_net/per_wide for a results day.
    // `search_session` = searches per browser session in the window.
    'notices' => [
        'window' => 600,
        'per_net' => 60,
        'per_wide' => 240,
        'per_list' => 6000,
        'search_session' => 15,
        'token_ttl' => 120,
    ],
    // web-cms F5a news view counter: per-day rows older than retention_days (at least 35) are folded into one total per post (internal/combined) or dropped once sent (public).
    'views' => [
        'retention_days' => (int) env('SAAN_VIEWS_RETENTION_DAYS', 90),
        // readers one network (IPv4, IPv6 /64) can add to one post per day, whatever browser strings it sends; kept in the cache under a keyed hash
        'count_per_net_day' => (int) env('SAAN_VIEWS_COUNT_PER_NET_DAY', 5),
        // cache store for the daily salt and reader markers; empty = the default store. A file store never sweeps expired files (the salts of past days are deleted by hand
        // so old markers cannot be tied to a reader); prefer a store that removes expired entries (redis, apcu).
        'cache_store' => env('SAAN_VIEWS_CACHE_STORE'),
    ],
    // web-cms F4 group hubs. count_per_net_day = how many times one network (IPv4, IPv6 /64) can raise the download counter of one document per day
    // (the file is still served past it). Kept in the cache under a keyed hash, never stored with the count.
    'hubs' => [
        'count_per_net_day' => (int) env('SAAN_HUB_COUNT_PER_NET_DAY', 3),
    ],
    'name' => 'สาน',
    'name_latin' => 'Saan',
    'display' => 'สาน (Saan)',
    'tagline' => 'ระบบเว็บไซต์และงานโรงเรียนไทย',
    'version' => is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0-unknown',
    // REL-02: packages must be signed by this Ed25519 key (base64). Only the public half ships with the app.
    'release_public_key' => env('SAAN_RELEASE_PUBLIC_KEY', 'd7EHJtN/ILHftKoGiRKdiXxQXJDzzOcUSE9NXjfRBl0='),
    // REL-02 finish step (migrate + health check after the file swap) runs on the NEW release in a fresh PHP: auto = a PHP CLI child, else a request
    // to this site, else refuse a package that replaces vendor/. process | http force one way; inline = same process (tests, last resort, no isolation).
    'update_finish' => env('SAAN_UPDATE_FINISH', 'auto'),
    'update_finish_timeout' => (int) env('SAAN_UPDATE_FINISH_TIMEOUT', 1800),
    // Path of the PHP CLI binary when the web server's PHP cannot find it by itself (web updater on hosts where PHP_BINDIR has no php).
    'php_cli' => env('SAAN_PHP_CLI', ''),
    // Optional, off by default (mode on /admin/updates). Signed with the same Ed25519 key as packages: feed.json + feed.sig (version 2 also lists zip/bytes/sha256 per package under base/, which App\Domain\SelfUpdate downloads from the same host). No telemetry; only the mode auto_patch installs by itself, and only patch releases.
    'update_feed' => env('SAAN_UPDATE_FEED', 'https://saan.linuxthai.org/releases/feed.json'),
    // Reverse proxies whose X-Forwarded-* headers are believed: comma list of IPs/CIDRs, or "*" only when the origin is reachable solely through the proxy.
    // Empty (default) = trust nobody. Behind Cloudflare, list its ranges (https://www.cloudflare.com/ips/).
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
    // Scheduled full backup (internal and combined editions only; routes/console.php). daily_at is HH:MM server time, empty string = off. The files are
    // storage/app/private/backups/auto-YYYYMMDD.zip (school:archive format); only the newest `keep` auto-*.zip files are kept (pre-update-*.zip belong to the updater).
    'backup' => [
        'daily_at' => env('SAAN_BACKUP_DAILY_AT', '02:30'),
        'keep' => (int) env('SAAN_BACKUP_KEEP', 7),
    ],
    'storage' => [
        // G9: total size of the media library per school (MB, 0 = unlimited) and the largest single upload (MB).
        'quota_mb' => (int) env('SAAN_STORAGE_QUOTA_MB', 4096),
        'max_upload_mb' => (int) env('SAAN_MAX_UPLOAD_MB', 10),
    ],
    'modules' => [
        // comma-separated module ids; experimental modules stay off in production unless listed here
        'enable' => env('SAAN_ENABLE_MODULES', ''),
        'disable' => env('SAAN_DISABLE_MODULES', ''),
    ],
    'assets' => [
        'logo_light' => 'assets/saan/saan-logo-light.png',
        'logo_dark' => 'assets/saan/saan-logo-dark.png',
        'mark_light' => 'assets/saan/saan-mark-light.png',
        'mark_dark' => 'assets/saan/saan-mark-dark.png',
        'favicon_svg' => 'assets/saan/favicon.svg',
        'favicon_png' => 'assets/saan/favicon-32.png',
        'apple_touch' => 'assets/saan/apple-touch-icon.png',
    ],
];
