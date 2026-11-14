<?php

namespace Saan\Modules\WebCms;

use App\Support\Saan\Edition;
use App\Support\Saan\Inbox;
use App\Support\Saan\NetKey;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * News view counter (F5a), designed to hold no personal data:
 *  - the database keeps ONE aggregate row per post per Bangkok day (cms_post_views.n): no address, no browser string, no cookie id, no session;
 *  - one visitor counts once per post per day: the check is a keyed hash of (network, browser string) under a SALT that is made fresh each day and lives
 *    only in the cache (TTL 25 hours, never written to the database or a log); the hash is the key of a cache entry that expires with the day;
 *  - obvious non-readers are ignored: HEAD and other methods, prefetch/prerender headers, requests that are not a document navigation, empty or robot-like
 *    browser strings, signed-in staff; and a post that is not published (or is deleted, or belongs to another school) is never counted.
 *
 * Split editions: the public edition counts, and flush() sends the cumulative per-day totals that changed to the internal edition as sealed inbox envelopes
 * of kind post_views (post id, day, count; nothing about a visitor). The internal handler stores max(existing, received), so a resent or re-ordered envelope never
 * counts twice. The internal edition publishes the aggregate back as pub_popular_posts (projectedRows) for the "ข่าวยอดนิยม" block. Combined counts straight
 * into the same table.
 */
final class PostViews
{
    public const KIND = 'post_views';
    public const BATCH = 500;
    /** Day of the single folded row that keeps the total of days older than the retention window. */
    public const FOLDED = '1970-01-01';
    /** Largest count one envelope row may carry (per post per day); a real school page never comes near it, and 90 such days still fit the unsigned int columns. */
    private const MAX_TOTAL = 1000000;
    /** Ceiling of the unsigned int columns (n, views_7, views_30, views_total); sums are clamped to it so a hostile or huge count can never fail a write. */
    public const UINT_MAX = 4294967295;
    /** Rows one projection may hold: the most the receiving side accepts in one batch. The rows with reads in the last 30 days always come first. */
    private const PROJECT_MAX = 20000;
    private const SALT_TTL = 90000;
    private const BOT = '/bot|crawl|spider|slurp|scrap|fetch|monitor|uptime|pingdom|lighthouse|headless|phantom|preview|facebookexternalhit|embedly|whatsapp|telegram|curl|wget|python|libwww|go-http|http-?client|axios|postman|ahrefs|semrush|bingpreview|petalbot/i';

    public static function today(): string
    {
        return Carbon::now('Asia/Bangkok')->toDateString();
    }

    /** Days kept per day before they are folded (internal) or dropped once sent (public). 35 at least, so the 30 day window is always whole. */
    public static function retentionDays(): int
    {
        return max(35, min(3650, (int) config('saan.views.retention_days', 90)));
    }

    public static function cutoff(): string
    {
        return Carbon::now('Asia/Bangkok')->subDays(self::retentionDays())->toDateString();
    }

    // ---------------------------------------------------------------- counting

    /** A prefetch / prerender / preview request, an empty user agent or a known robot or link-preview agent: never a person (shared with the document download counter). */
    public static function automated(Request $r): bool
    {
        foreach (['Purpose', 'X-Purpose', 'X-Moz', 'Sec-Purpose'] as $h) {
            if (preg_match('/prefetch|prerender|preview/i', (string) $r->headers->get($h, ''))) return true;
        }
        $ua = trim((string) $r->userAgent());
        return $ua === '' || preg_match(self::BOT, $ua) === 1;
    }

    /** A request that may be a person reading the page (not a robot, a link preview or a prefetch). */
    public static function countable(Request $r): bool
    {
        if (!$r->isMethod('GET')) return false;
        if (self::automated($r)) return false;
        $dest = (string) $r->headers->get('Sec-Fetch-Dest', '');
        if ($dest !== '' && strtolower($dest) !== 'document') return false;
        return true;
    }

    /** True the first time this visitor reads this post today. The salt and the entry live in the cache only; if the cache is unusable nothing is counted. */
    public static function firstToday(Request $r, int $postId): bool
    {
        $day = self::today();
        try {
            $c = self::cache();
            $saltKey = 'postviews:salt:'.$day;
            // a new day's salt: the salts of the days before are deleted at once, so a marker left on a disk (a file cache never sweeps expired files) can no longer be tied to a reader
            if ($c->add($saltKey, bin2hex(random_bytes(16)), self::SALT_TTL)) self::forgetSalts();
            $salt = (string) $c->get($saltKey, '');
            if ($salt === '') return false;
            // one network can raise one post only a few times a day, whatever browser strings it sends; past that nothing is stored for it
            $netKey = 'postviews:net:'.$day.':'.$postId.':'.substr(hash_hmac('sha256', NetKey::rate((string) $r->ip()), $salt), 0, 32);
            $c->add($netKey, 0, self::SALT_TTL);
            if ((int) $c->get($netKey, 0) >= self::perNet()) return false;
            $who = hash_hmac('sha256', NetKey::rate((string) $r->ip())."\n".strtolower(trim((string) $r->userAgent())), $salt);
            if (!$c->add('postviews:seen:'.$day.':'.$postId.':'.substr($who, 0, 32), 1, self::SALT_TTL)) return false;
            $c->increment($netKey);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Readers one network can add to one post per day (saan.views.count_per_net_day, default 5). */
    public static function perNet(): int
    {
        return max(1, (int) config('saan.views.count_per_net_day', 5));
    }

    /** The cache that holds the salt and the markers: saan.views.cache_store (a store that really removes expired entries, such as redis) or the default store. */
    private static function cache(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('saan.views.cache_store');
        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    /** Deletes the salts of the days before today (a week back by default); run on every new salt and by saan:post-views-prune. */
    public static function forgetSalts(int $days = 7): void
    {
        try {
            $c = self::cache();
            for ($i = 1; $i <= max(1, $days); $i++) $c->forget('postviews:salt:'.Carbon::now('Asia/Bangkok')->subDays($i)->toDateString());
        } catch (\Throwable) {
        }
    }

    /** Adds one to today's aggregate of the post. */
    public static function hit(int $postId): void
    {
        $day = self::today();
        DB::table('cms_post_views')->insertOrIgnore(['school_id' => Hubs::school(), 'post_id' => $postId, 'day' => $day, 'n' => 0, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cms_post_views')->where('post_id', $postId)->where('day', $day)->increment('n', 1, ['updated_at' => now()]);
    }

    /** Called when a published post page is served. Never throws: counting must not break reading. */
    public static function record(Request $r, object $post): bool
    {
        try {
            if (($post->content_type ?? '') !== 'article') return false; // only news articles are ranked and projected, so only they are counted (pages, jobs and events would show a count in one edition and not in the other)
            if (($post->status ?? '') !== 'published' || !empty($post->deleted_at) || (int) ($post->school_id ?? 0) !== Hubs::school()) return false;
            if (!self::countable($r) || auth()->check() || !self::firstToday($r, (int) $post->id)) return false;
            self::hit((int) $post->id);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------- reading

    /**
     * Ids of the most read published news posts of this school in the last $days days, with their counts: [id => views]. Unpublished, deleted and other-school
     * posts never appear. The public edition reads the projected aggregate (pub_popular_posts); the others read the counts themselves.
     * @return array<int,int>
     */
    public static function popular(int $days, int $limit): array
    {
        $days = $days === 30 ? 30 : 7;
        $limit = max(1, min(20, $limit));
        $school = Hubs::school();
        $q = DB::table('posts as p')->where('p.school_id', $school)->where('p.status', 'published')->whereNull('p.deleted_at')->where('p.content_type', 'article');
        if (Edition::isPublic()) {
            if (!Schema::hasTable('pub_popular_posts')) return [];
            $col = $days === 30 ? 'v.views_30' : 'v.views_7';
            $rows = $q->join('pub_popular_posts as v', 'v.post_id', '=', 'p.id')->where($col, '>', 0)->orderByDesc($col)->orderByDesc('p.published_at')->orderByDesc('p.id')->limit($limit)->get(['p.id', DB::raw("$col as views")]);
        } else {
            $since = Carbon::now('Asia/Bangkok')->subDays($days - 1)->toDateString();
            $rows = $q->join('cms_post_views as v', 'v.post_id', '=', 'p.id')->where('v.school_id', $school)->where('v.day', '>=', $since)->groupBy('p.id', 'p.published_at')
                ->havingRaw('sum(v.n) > 0')->orderByRaw('sum(v.n) desc')->orderByDesc('p.published_at')->orderByDesc('p.id')->limit($limit)->get(['p.id', DB::raw('sum(v.n) as views')]);
        }
        $out = [];
        foreach ($rows as $r) $out[(int) $r->id] = (int) $r->views;
        return $out;
    }

    /** All-time views of one post (including folded days). The public edition knows the last published aggregate. */
    public static function total(int $postId): int
    {
        if (Edition::isPublic()) return Schema::hasTable('pub_popular_posts') ? (int) DB::table('pub_popular_posts')->where('post_id', $postId)->value('views_total') : 0;
        return (int) DB::table('cms_post_views')->where('school_id', Hubs::school())->where('post_id', $postId)->sum('n');
    }

    // ---------------------------------------------------------------- internal edition: the aggregate that is published

    /**
     * Rows of pub_popular_posts (derived projection "popular_posts"): published news posts of this school that have views, with the 7 and 30 day sums and the
     * total. Re-evaluated on every scheduler tick because the windows move with the clock. Counts only: no day, no visitor, no cache entry.
     * @return list<array{post_id:int,views_7:int,views_30:int,views_total:int}>
     */
    public static function projectedRows(): array
    {
        $school = Hubs::school();
        $s7 = Carbon::now('Asia/Bangkok')->subDays(6)->toDateString();
        $s30 = Carbon::now('Asia/Bangkok')->subDays(29)->toDateString();
        $rows = DB::table('cms_post_views as v')->join('posts as p', 'p.id', '=', 'v.post_id')->where('v.school_id', $school)->where('p.school_id', $school)
            ->where('p.status', 'published')->whereNull('p.deleted_at')->where('p.content_type', 'article')->groupBy('v.post_id')
            ->selectRaw('v.post_id as post_id, sum(v.n) as total, sum(case when v.day >= ? then v.n else 0 end) as v30, sum(case when v.day >= ? then v.n else 0 end) as v7', [$s30, $s7])
            // the posts read in the last 30 days first (the blocks use only those), then the rest by all-time total (the optional count on a post page), up to what one batch holds
            ->havingRaw('sum(v.n) > 0')->orderByRaw('sum(case when v.day >= ? then v.n else 0 end) desc', [$s30])->orderByRaw('sum(v.n) desc')->orderBy('v.post_id')->limit(max(1, (int) config('saan.views.project_max', self::PROJECT_MAX)))->get();
        $out = [];
        $cap = fn ($n): int => (int) min((int) $n, self::UINT_MAX);
        foreach ($rows as $r) $out[] = ['post_id' => (int) $r->post_id, 'views_7' => $cap($r->v7), 'views_30' => $cap($r->v30), 'views_total' => $cap($r->total)];
        return $out;
    }

    // ---------------------------------------------------------------- public edition -> internal edition (aggregate only)

    /**
     * Public edition: seals one envelope per batch of changed (post, day) rows holding the CUMULATIVE count of that day, and remembers what was sent.
     * Other editions have nothing to send. A refused or failed send leaves everything for the next run. Returns the number of rows sent.
     */
    public static function flush(int $maxBatches = 4): int
    {
        if (!Edition::isPublic()) return 0;
        $sent = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $rows = DB::table('cms_post_views')->where('school_id', Hubs::school())->whereColumn('n', '>', 'sent_n')->orderBy('id')->limit(self::BATCH)->get(['id', 'post_id', 'day', 'n']);
            if ($rows->isEmpty()) break;
            $payload = ['rows' => $rows->map(fn ($r) => ['p' => (int) $r->post_id, 'day' => (string) $r->day, 'n' => (int) $r->n])->all()];
            Inbox::submit(self::KIND, $payload); // throws InboxRejected when not paired / inbox full: the caller logs it, the rows stay unsent
            foreach ($rows as $r) DB::table('cms_post_views')->where('id', $r->id)->where('n', '>=', $r->n)->update(['sent_n' => $r->n]);
            $sent += $rows->count();
        }
        return $sent;
    }

    /**
     * Retention. Internal/combined: per-day rows older than the window are folded into the one row of day FOLDED (the total stays right, the days go).
     * Public: fully sent rows older than the window are deleted, and so are fully sent rows of past days whose post is gone from this database
     * (never today's row: a post withdrawn and republished the same day continues the same cumulative total). Returns the number of rows removed.
     */
    public static function prune(): int
    {
        self::forgetSalts(30); // old salts must not outlive their day, even on a site that nobody visited since
        $cutoff = self::cutoff();
        if (Edition::isPublic()) {
            $old = DB::table('cms_post_views')->where('day', '<', $cutoff)->whereColumn('sent_n', '>=', 'n')->delete();
            $orphan = DB::table('cms_post_views')->where('day', '<', self::today())->whereColumn('sent_n', '>=', 'n')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('posts')->whereColumn('posts.id', 'cms_post_views.post_id'))->delete();
            return $old + $orphan;
        }
        return DB::transaction(function () use ($cutoff): int {
            $school = Hubs::school();
            $old = DB::table('cms_post_views')->where('school_id', $school)->where('day', '<', $cutoff)->where('day', '!=', self::FOLDED)->selectRaw('post_id, sum(n) as n')->groupBy('post_id')->get();
            foreach ($old as $r) {
                DB::table('cms_post_views')->insertOrIgnore(['school_id' => $school, 'post_id' => $r->post_id, 'day' => self::FOLDED, 'n' => 0, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $have = (int) DB::table('cms_post_views')->where('post_id', $r->post_id)->where('day', self::FOLDED)->value('n');
                DB::table('cms_post_views')->where('post_id', $r->post_id)->where('day', self::FOLDED)->update(['n' => (int) min($have + (int) $r->n, self::UINT_MAX), 'updated_at' => now()]);
            }
            return DB::table('cms_post_views')->where('school_id', $school)->where('day', '<', $cutoff)->where('day', '!=', self::FOLDED)->delete();
        });
    }

    /**
     * Internal edition handler of kind post_views. The payload is untrusted (the public side may be breached): shape, ranges and post ids are checked, unknown
     * posts and days outside the retention window are ignored (a folded day can never be added again), and a value is stored as max(existing, received),
     * which makes a resent or re-ordered envelope harmless.
     * @return array{status:string,rows:int}
     */
    public static function receive(array $payload): array
    {
        $rows = $payload['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1000) throw new Inbox\InboxRejected('รูปแบบยอดอ่านข่าวไม่ถูกต้อง', 422, 'invalid');
        $school = Hubs::school();
        $from = self::cutoff();
        $to = Carbon::now('Asia/Bangkok')->addDay()->toDateString();
        // the whole shape first (a real calendar day included): a bad row refuses the envelope before anything is written
        foreach ($rows as $r) {
            if (!is_array($r) || !is_int($r['p'] ?? null) || !is_string($r['day'] ?? null) || !is_int($r['n'] ?? null) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $r['day'], $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) throw new Inbox\InboxRejected('รูปแบบยอดอ่านข่าวไม่ถูกต้อง', 422, 'invalid');
        }
        $known = [];
        $applied = 0;
        DB::transaction(function () use ($rows, $school, $from, $to, &$known, &$applied) {
            foreach ($rows as $r) {
                if ($r['n'] < 1 || $r['n'] > self::MAX_TOTAL || $r['day'] < $from || $r['day'] > $to) continue;
                $known[$r['p']] ??= DB::table('posts')->where('school_id', $school)->where('id', $r['p'])->whereNull('deleted_at')->exists();
                if (!$known[$r['p']]) continue;
                DB::table('cms_post_views')->insertOrIgnore(['school_id' => $school, 'post_id' => $r['p'], 'day' => $r['day'], 'n' => 0, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('cms_post_views')->where('post_id', $r['p'])->where('day', $r['day'])->where('n', '<', $r['n'])->update(['n' => $r['n'], 'updated_at' => now()]);
                $applied++;
            }
        });
        return ['status' => 'ok', 'rows' => $applied];
    }
}
