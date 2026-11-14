<?php

namespace Saan\Modules\WebCms;

use App\Support\Saan\Edition;
use App\Support\Saan\Inbox;
use App\Support\Saan\NetKey;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Download counting for hub documents (F4), designed to hold no personal data:
 *  - the database keeps ONE aggregate row per document per Bangkok day (cms_hub_downloads.n): no address, no browser string, no session;
 *  - inflation is limited by a per-network, per-document, per-day ceiling kept in the CACHE only, under a keyed hash of the network (never the address);
 *    past the ceiling the visitor still gets the file, it is just not counted again;
 *  - the redirect target of an external link is the link stored on that document, nothing taken from the request.
 *
 * Split editions: the public edition counts, and Downloads::flush() sends the cumulative per-day totals that changed to the internal edition as
 * ONE sealed inbox envelope of kind hub_downloads (aggregate only). The internal handler stores max(existing, received), so a repeated or
 * re-ordered envelope is harmless. Combined editions count straight into the same table.
 */
final class Downloads
{
    public const KIND = 'hub_downloads';
    public const BATCH = 500;
    private const MAX_TOTAL = 100000000;

    public static function today(): string
    {
        return Carbon::now('Asia/Bangkok')->toDateString();
    }

    public static function perNetPerDay(): int
    {
        return max(1, (int) config('saan.hubs.count_per_net_day', 3));
    }

    /** True when this visitor's network has not used up today's count for this document (and records the hit). Cache only; fails open (counts) if the cache is down. */
    public static function allow(string $ip, int $docId): bool
    {
        $key = 'hubdl:'.self::today().':'.$docId.':'.substr(hash_hmac('sha256', NetKey::rate($ip), (string) config('app.key')), 0, 32);
        try {
            Cache::add($key, 0, 90000);
            return (int) Cache::increment($key) <= self::perNetPerDay();
        } catch (\Throwable) {
            return true;
        }
    }

    /** Adds one to today's aggregate of the document. */
    public static function hit(int $docId): void
    {
        $school = Hubs::school();
        $day = self::today();
        DB::table('cms_hub_downloads')->insertOrIgnore(['school_id' => $school, 'document_id' => $docId, 'day' => $day, 'n' => 0, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cms_hub_downloads')->where('document_id', $docId)->where('day', $day)->increment('n', 1, ['updated_at' => now()]);
    }

    /** A request that should count: a plain GET for the whole file (not HEAD, not a later byte range of a viewer that reads in pieces). */
    private static function countable(Request $r): bool
    {
        if (!$r->isMethod('GET') || PostViews::automated($r)) return false; // crawlers and link previews still get the file, they just are not counted as downloads
        $range = (string) $r->headers->get('Range', '');
        return $range === '' || str_starts_with($range, 'bytes=0-');
    }

    /** Streams the file or redirects to the stored https link, counting first. $doc comes from Hubs::downloadable(). */
    public static function serve(Request $r, object $doc)
    {
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
        $count = function () use ($r, $doc): void {
            if (self::countable($r) && self::allow((string) $r->ip(), (int) $doc->id)) self::hit((int) $doc->id);
        };
        if ($doc->media_id !== null) {
            $m = DB::table('cms_media')->where('id', $doc->media_id)->where('school_id', Hubs::school())->first();
            abort_unless($m && HubFiles::allowedMime((string) $m->mime), 404);
            $disk = Storage::disk('local');
            abort_unless($disk->exists($m->path), 404);
            $count();
            $ext = pathinfo((string) $m->path, PATHINFO_EXTENSION);
            $name = trim((string) $doc->file_name) !== '' ? (string) $doc->file_name : 'document.'.$ext;
            $inline = $m->mime === 'application/pdf' || str_starts_with((string) $m->mime, 'image/');
            return response()->download($disk->path($m->path), $name, $headers + ['Content-Type' => $m->mime], $inline ? 'inline' : 'attachment');
        }
        $url = (string) $doc->external_url;
        abort_unless(HubFiles::validLink($url), 404);
        $count();
        return redirect()->away($url, 302, $headers);
    }

    // ---------------------------------------------------------------- reading counts (internal admin)

    /** @param list<int> $ids @return array<int,array{total:int,last30:int}> */
    public static function totals(array $ids): array
    {
        $out = [];
        if (!$ids) return $out;
        $since = Carbon::now('Asia/Bangkok')->subDays(29)->toDateString();
        $rows = DB::table('cms_hub_downloads')->where('school_id', Hubs::school())->whereIn('document_id', $ids)
            ->selectRaw('document_id, sum(n) as total, sum(case when day >= ? then n else 0 end) as last30', [$since])->groupBy('document_id')->get();
        foreach ($rows as $r) $out[(int) $r->document_id] = ['total' => (int) $r->total, 'last30' => (int) $r->last30];
        return $out;
    }

    // ---------------------------------------------------------------- public edition -> internal edition (aggregate only)

    /**
     * Public edition: seals one envelope per batch of changed (document, day) rows holding the CUMULATIVE count of that day, and remembers what was sent.
     * Other editions have nothing to send. A refused or failed send leaves everything for the next run. Returns the number of rows sent.
     */
    public static function flush(int $maxBatches = 4): int
    {
        if (!Edition::isPublic()) return 0;
        $sent = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $rows = DB::table('cms_hub_downloads')->where('school_id', Hubs::school())->whereColumn('n', '>', 'sent_n')->orderBy('id')->limit(self::BATCH)->get(['id', 'document_id', 'day', 'n']);
            if ($rows->isEmpty()) break;
            $payload = ['rows' => $rows->map(fn ($r) => ['d' => (int) $r->document_id, 'day' => (string) $r->day, 'n' => (int) $r->n])->all()];
            Inbox::submit(self::KIND, $payload); // throws InboxRejected when not paired / inbox full: the caller logs it, the rows stay unsent
            foreach ($rows as $r) DB::table('cms_hub_downloads')->where('id', $r->id)->where('n', '>=', $r->n)->update(['sent_n' => $r->n]);
            $sent += $rows->count();
        }
        return $sent;
    }

    /**
     * Public edition housekeeping: fully sent rows older than 400 days, and fully sent rows of past days whose document is gone (withdrawn documents are deleted
     * from the public database, but their counts have no foreign key so a republished document continues its day's total; unsent ones wait for flush()).
     */
    public static function prune(): int
    {
        $old = DB::table('cms_hub_downloads')->where('day', '<', Carbon::now('Asia/Bangkok')->subDays(400)->toDateString())->whereColumn('sent_n', '>=', 'n')->delete();
        // never today's row: a document withdrawn and republished the same day must continue the same cumulative total
        $orphan = DB::table('cms_hub_downloads')->where('day', '<', self::today())->whereColumn('sent_n', '>=', 'n')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('cms_hub_documents')->whereColumn('cms_hub_documents.id', 'cms_hub_downloads.document_id'))->delete();
        return $old + $orphan;
    }

    /**
     * Internal edition handler of kind hub_downloads. The payload is untrusted (the public side may be breached): shape, ranges and document ids are
     * checked, unknown documents and out-of-range days are ignored, and a value is stored as max(existing, received), which makes it idempotent.
     * @return array{status:string,rows:int}
     */
    public static function receive(array $payload): array
    {
        $rows = $payload['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1000) throw new Inbox\InboxRejected('รูปแบบยอดดาวน์โหลดไม่ถูกต้อง', 422, 'invalid');
        $school = Hubs::school();
        $from = Carbon::now('Asia/Bangkok')->subDays(400)->toDateString();
        $to = Carbon::now('Asia/Bangkok')->addDay()->toDateString();
        // the whole shape is checked first (a real calendar day included), so a bad row refuses the envelope before anything is written
        foreach ($rows as $r) {
            if (!is_array($r) || !is_int($r['d'] ?? null) || !is_string($r['day'] ?? null) || !is_int($r['n'] ?? null) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $r['day'], $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) throw new Inbox\InboxRejected('รูปแบบยอดดาวน์โหลดไม่ถูกต้อง', 422, 'invalid');
        }
        $known = [];
        $applied = 0;
        DB::transaction(function () use ($rows, $school, $from, $to, &$known, &$applied) {
            foreach ($rows as $r) {
                if ($r['n'] < 1 || $r['n'] > self::MAX_TOTAL || $r['day'] < $from || $r['day'] > $to) continue;
                $known[$r['d']] ??= DB::table('cms_hub_documents')->where('school_id', $school)->where('id', $r['d'])->exists();
                if (!$known[$r['d']]) continue;
                DB::table('cms_hub_downloads')->insertOrIgnore(['school_id' => $school, 'document_id' => $r['d'], 'day' => $r['day'], 'n' => 0, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('cms_hub_downloads')->where('document_id', $r['d'])->where('day', $r['day'])->where('n', '<', $r['n'])->update(['n' => $r['n'], 'updated_at' => now()]);
                $applied++;
            }
        });
        return ['status' => 'ok', 'rows' => $applied];
    }
}
