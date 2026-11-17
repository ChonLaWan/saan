<?php

namespace App\Support\Saan\Publish;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Change hints for the publisher (internal edition, only while a push URL is configured, from the .env or from pairing).
 *
 * Every write (insert/update/delete) to a table the public side depends on marks the affected streams "changed" in pub_outbox (a row
 * without payload). That covers publish, unpublish, delete and expiry of posts, site settings and themes, ITA status, notices
 * (publish/archive/expiry), surveys (open/close), certificates (issue/void), personnel directory, learning links and the observance
 * settings, wherever in the code the write happens. The marker is only a hint: the publisher rebuilds the projection of a marked
 * stream and sends the difference against pub_sent_rows, so a missed hint is repaired by the hourly full reconcile and the
 * time-based streams are re-evaluated every minute.
 */
final class Outbox
{
    /** Source table => the streams whose projection can change when that table is written (a declared table also affects itself). */
    public const SOURCES = [
        'posts' => ['posts', 'cms_media', 'pub_popular_posts'],
        'cms_post_views' => ['pub_popular_posts'], // F5a: counts arriving through the inbox change the aggregate that the popular-news block reads
        'cms_media' => ['cms_media'],
        'cms_site_settings' => ['cms_site_settings', 'cms_media'],
        'cms_themes' => ['cms_themes', 'cms_media'],
        'saan_settings' => ['saan_settings', Wire::MODULES],
        'ita_years' => ['ita_years', 'ita_topics', 'ita_items', 'cms_media'],
        'ita_topics' => ['ita_topics', 'ita_items', 'cms_media'],
        'ita_items' => ['ita_items', 'cms_media'],
        'notice_lists' => ['notice_lists', 'pub_notice_rows'],
        'notice_rows' => ['pub_notice_rows'],
        'srv_surveys' => ['srv_surveys', 'pub_certificates'],
        'srv_responses' => ['pub_certificates'],
        'cmp_cases' => ['pub_complaint_status'],
        'cmp_reports' => ['pub_complaint_reports'],
        'teachers' => ['teachers', 'per_profiles'],
        'per_profiles' => ['per_profiles', 'teachers'],
        'lrn_links' => ['lrn_links'],
        'lrn_access' => ['lrn_access', 'lrn_links', 'pub_learning_rooms'],
        'classrooms' => ['pub_learning_rooms'],
        'academic_terms' => ['academic_terms', 'lrn_links'],
        'social_feeds' => ['social_feeds'],
        'cms_categories' => ['cms_categories'],
        // F4 group hubs: a hub change can show or hide its categories and documents (and the media files of those documents)
        'cms_hubs' => ['cms_hubs', 'cms_hub_categories', 'cms_hub_documents', 'cms_media', 'cms_categories'], // cms_categories: the news category of a draft page is not projected
        'cms_hub_categories' => ['cms_hub_categories', 'cms_hub_documents', 'cms_media'],
        'cms_hub_documents' => ['cms_hub_documents', 'cms_media'],
    ];

    /**     /** The application instance the listeners were registered on (a new instance, as in tests, needs its own). */
    private static ?object $watching = null;
    /** @var array<string,int> stream => time of the last marker check (avoids a query per statement in bulk writes outside a transaction) */
    private static array $seen = [];
    /** @var null|array{0:bool,1:int} last answer of pushConfigured() and when it was asked */
    private static ?array $cfg = null;

    public static function watch(): void
    {
        if (self::$watching === app()) return;
        self::$watching = app();
        self::$pending = [];
        DB::listen(function (QueryExecuted $q) {
            if ($q->connectionName !== DB::getDefaultConnection()) return; // only the internal database feeds the queue
            if (!preg_match('/^\s*(?:(insert(?:\s+(?:or\s+\w+|ignore))?\s+into|replace\s+into)|update(?:\s+(?:or\s+\w+|ignore))?|delete\s+from)\s+[`"\[]?([A-Za-z0-9_]+)/i', $q->sql, $m)) return;
            $table = strtolower($m[2]);
            $streams = self::SOURCES[$table] ?? null;
            if ($streams === null) return;
            // a module switch (saan_settings) changes what every module stream may hold: they are all re-evaluated, not only the list of active modules
            if ($table === 'saan_settings') { self::$cfg = null; $streams = array_merge($streams, self::moduleStreams()); }
            $dedupe = $q->connection->transactionLevel() === 0;
            // INSERT: this event fires between the statement and insertGetId() reading the new id from the connection; writing the marker now
            // would hand the caller the id of the pub_outbox row instead. The marker waits for the next statement on this connection (or the
            // commit, or the end of the process), so it is still written inside the same transaction as the change.
            if ($m[1] !== '') { foreach ($streams as $s) self::$pending[$s] = (self::$pending[$s] ?? true) && $dedupe; return; }
            // inside a transaction the marker is written in that same transaction: it commits together with the change, and vanishes with a rollback
            try { self::touch($streams, $dedupe); } catch (\Throwable) { /* a hint must never break the write that caused it; the hourly reconcile repairs it */ }
        });
        DB::connection()->beforeExecuting(fn () => self::flush());
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionCommitting::class, function ($e) {
            if ($e->connectionName === DB::getDefaultConnection()) self::flush();
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, function ($e) {
            if ($e->connectionName === DB::getDefaultConnection()) self::$pending = []; // the rows they were for are gone (the reconcile covers a nested rollback)
        });
        app()->terminating(fn () => self::flush());
    }

    /** @var array<string,bool> markers of inserts not written yet: stream => dedupe allowed (false when the insert ran inside a transaction) */
    private static array $pending = [];
    private static bool $flushing = false;

    /** Writes the markers held back after inserts (see watch()). Safe to call any time; never throws. */
    public static function flush(): void
    {
        if (self::$flushing || !self::$pending) return;
        self::$flushing = true;
        try {
            $p = self::$pending; self::$pending = [];
            $in = array_keys(array_filter($p, fn ($d) => !$d)); $out = array_keys(array_filter($p));
            if ($in) self::touch($in, false);
            if ($out) self::touch($out, true);
        } catch (\Throwable) {
            // a hint must never break the statement that triggered the flush; the hourly reconcile repairs a missed marker
        } finally {
            self::$flushing = false;
        }
    }

    /** Marks streams as changed (one marker row per stream until the publisher consumes it). */
    /** Streams that belong to optional modules (and the module list): all of them change when a module is switched on or off. @return list<string> */
    public static function moduleStreams(): array
    {
        $out = [Wire::MODULES];
        foreach (\App\Support\Saan\Projection::declared(null, true) as $e) if ($e['source'] !== 'core') $out[] = $e['table'];
        return array_values(array_unique($out));
    }

    /**
     * True when there is somewhere to push to: the .env value, or the address saved by pairing (Settings). A wizard-paired install has only the
     * second one, so the hints must not depend on the .env alone. Asked at most every 5 seconds (a write to saan_settings asks again at once).
     */
    public static function pushConfigured(): bool
    {
        if (trim((string) config('saan.public_push_url', '')) !== '') return true;
        if (self::$cfg !== null && time() - self::$cfg[1] < 5) return self::$cfg[0];
        try { $v = Publisher::url() !== ''; } catch (\Throwable) { $v = false; }
        self::$cfg = [$v, time()];
        return $v;
    }

    public static function touch(array $streams, bool $dedupe = true): void
    {
        if (!self::pushConfigured()) return; // nothing to deliver to: no markers pile up on an install that is not paired
        $now = time();
        foreach ($streams as $s) {
            if ($dedupe && ($now - (self::$seen[$s] ?? 0)) < 2) continue;
            if (!DB::table('pub_outbox')->where('stream', $s)->whereNull('payload')->whereNull('sent_at')->exists()) {
                DB::table('pub_outbox')->insert(['stream' => $s, 'op' => 'upsert', 'created_at' => now()]);
            }
            if ($dedupe) self::$seen[$s] = $now;
        }
    }

    public static function forget(): void
    {
        self::$seen = [];
        self::$pending = [];
        self::$cfg = null;
    }

    public static function pendingMarkers(): bool
    {
        return DB::table('pub_outbox')->whereNull('payload')->whereNull('sent_at')->exists();
    }
}
