<?php

namespace App\Support\Saan\Publish;

use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Projection;
use App\Support\Saan\TermScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Turns the declared projection (Projection::declared) into the rows the public edition may hold (S3, internal side).
 * Nothing leaves that is not listed in a declaration: every column goes through its rule (keep, null, const, mask_name), tables and
 * columns that are not declared are never read into a row. Rows are keyed by the stream key column and normalised (Wire::normalize).
 * Runs on the default database connection (the internal one).
 */
class Builder
{
    private const MAX_FILE_BYTES = 67108864;
    /** @var list<string> files that could not be offered (missing on disk or too large), for the status page */
    public array $skipped = [];

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    /** Declared entries in dependency order (parents first) so that foreign keys hold when the receiver applies them in order. */
    public function entries(): array
    {
        $entries = [];
        // inactive modules are listed too: their streams stay known so that rows already on the public side are withdrawn when a module is switched off (rows() answers empty for them)
        foreach (Projection::declared(null, true) as $e) $entries[$e['table']] = $e;
        $names = array_keys($entries);
        $deps = [];
        foreach ($names as $t) {
            $deps[$t] = [];
            foreach ($this->foreignTables($t) as $parent) if ($parent !== $t && isset($entries[$parent])) $deps[$t][] = $parent;
        }
        $this->deps = $deps;
        $out = [];
        $done = [];
        $visit = function (string $t) use (&$visit, &$out, &$done, $deps, $entries): void {
            if (isset($done[$t])) return;
            $done[$t] = true;
            foreach ($deps[$t] as $d) $visit($d);
            $out[] = $entries[$t];
        };
        foreach ($names as $t) $visit($t);
        return $out;
    }

    /** @var array<string,list<string>> stream => streams it has a foreign key to (set by entries()) */
    private array $deps = [];

    /** @var array<string,list<string>> table => tables its foreign keys point at, read once per Builder */
    private array $foreignTables = [];
    /** @var array<string,list<string>> table => column names, read once per Builder */
    private array $columnNames = [];

    /**
     * entries() runs many times per batch (ancestors, descendants, entry, streams). On MySQL/MariaDB every schema lookup is an information_schema
     * query, so asking again for each call made one full batch cost over a thousand of them and the installer's first delivery ran out of time.
     * A table that does not exist yet is asked again next time (a module may create it later).
     */
    private function foreignTables(string $table): array
    {
        if (isset($this->foreignTables[$table])) return $this->foreignTables[$table];
        if (!Schema::hasTable($table)) return [];
        return $this->foreignTables[$table] = array_values(array_unique(array_column(Schema::getForeignKeys($table), 'foreign_table')));
    }

    /** Streams that $stream depends on through foreign keys, directly or not (they must be applied before it). @return list<string> */
    public function ancestors(string $stream): array
    {
        $this->entries();
        $out = [];
        $walk = function (string $s) use (&$walk, &$out): void {
            foreach ($this->deps[$s] ?? [] as $p) if (!in_array($p, $out, true)) { $out[] = $p; $walk($p); }
        };
        $walk($stream);
        return $out;
    }

    /** Streams that depend on $stream (they must be deleted from before it). @return list<string> */
    public function descendants(string $stream): array
    {
        $this->entries();
        return array_values(array_filter(array_keys($this->deps), fn ($s) => $s !== $stream && in_array($stream, $this->ancestors($s), true)));
    }

    /** All stream names in apply order (the modules list first: it is tiny and decides what the public side serves). */
    public function streams(): array
    {
        return array_merge([Wire::MODULES], array_column($this->entries(), 'table'));
    }

    public function entry(string $stream): ?array
    {
        foreach ($this->entries() as $e) if ($e['table'] === $stream) return $e;
        return null;
    }

    /** Streams whose content depends on the clock or on expiry, so the scheduler re-evaluates them every minute. */
    public function timedStreams(): array
    {
        $out = [];
        foreach ($this->entries() as $e) {
            $tokens = array_filter($e['where'], fn ($w) => is_string($w['value']) && in_array($w['value'], Projection::TOKENS, true));
            if ($tokens || in_array($e['filter'], ['learning_public_links', 'current_term'], true) || in_array($e['derived'], ['notice_masked_rows', 'learning_rooms', 'popular_posts'], true)) $out[] = $e['table'];
        }
        return $out;
    }

    // ---------------------------------------------------------------- rows

    /** @return array<string,array<string,?string>> key => normalised projected row */
    public function rows(string $stream): array
    {
        if ($stream === Wire::MODULES) return $this->moduleRows();
        $e = $this->entry($stream) ?? throw new RuntimeException('ไม่มีการประกาศ projection สำหรับ '.$stream);
        if ($e['source'] !== 'core' && !app(ModuleRegistry::class)->active($e['source'])) return [];
        $source = $e['derived'] !== null ? $this->derivedRows($e) : $this->tableRows($e);
        $key = Wire::keyColumn($stream);
        $out = [];
        foreach ($source as $raw) {
            $row = $this->project($e['columns'], (array) $raw);
            if (!isset($row[$key]) || $row[$key] === '') continue;
            $out[$row[$key]] = $row;
        }
        return $out;
    }

    /** Applies column rules. Only 'keep', 'mask_name' and 'scrub_answer_keys' read the source value. */
    public function project(array $rules, array $raw): array
    {
        $row = [];
        foreach ($rules as $c => $rule) {
            if ($rule === 'keep') $row[$c] = $raw[$c] ?? null;
            elseif ($rule === 'mask_name') $row[$c] = self::maskName($raw[$c] ?? null);
            elseif ($rule === 'scrub_answer_keys') $row[$c] = Wire::scrubAnswerKeys(isset($raw[$c]) ? (string) $raw[$c] : null);
            elseif ($rule === 'null') $row[$c] = null;
            else $row[$c] = substr($rule, 6); // const:<value>
        }
        return Wire::normalize($row);
    }

    public static function maskName(mixed $v): ?string
    {
        if ($v === null) return null;
        return class_exists(\Saan\Modules\WebNotices\NameMask::class) ? \Saan\Modules\WebNotices\NameMask::apply((string) $v, 'first_initial') : '';
    }

    private function moduleRows(): array
    {
        $out = [];
        foreach (app(ModuleRegistry::class)->all() as $id => $m) {
            if ($m['active'] && in_array($m['edition'], ['public', 'both'], true)) $out[$id] = ['module_id' => $id];
        }
        ksort($out);
        return $out;
    }

    private function tableRows(array $e): iterable
    {
        $q = DB::table($e['table']);
        // one information_schema lookup per table per Builder (same reason as foreignTables())
        $have = $this->columnNames[$e['table']] ??= array_column(Schema::getColumns($e['table']), 'name');
        $cols = array_keys(array_filter($e['columns'], fn ($r) => in_array($r, ['keep', 'mask_name', 'scrub_answer_keys'], true)));
        $key = Wire::keyColumn($e['table']);
        if (!in_array($key, $cols, true)) $cols[] = $key;
        if (in_array('school_id', $have, true)) $q->where('school_id', self::school());
        foreach ($e['where'] as $w) self::applyWhere($q, $w['column'], $w['op'], $w['value']);
        if ($e['filter'] !== null) $this->applyFilter($q, $e['filter']);
        return $q->orderBy($key)->get(array_values(array_unique($cols)));
    }

    public static function token(mixed $v): mixed
    {
        if ($v === '@now') return now()->toDateTimeString();
        if ($v === '@today') return CarbonImmutable::now('Asia/Bangkok')->toDateString();
        return $v;
    }

    public static function applyWhere(Query $q, string $c, string $op, mixed $v): void
    {
        $v = is_array($v) ? array_map([self::class, 'token'], $v) : self::token($v);
        match ($op) {
            'null' => $q->whereNull($c), 'not_null' => $q->whereNotNull($c),
            'in' => $q->whereIn($c, $v), 'not_in' => $q->whereNotIn($c, $v),
            default => $q->where($c, $op, $v),
        };
    }

    /** Same conditions evaluated on an already projected row (the receiver re-checks them). A column the row does not carry is not testable here and is skipped. */
    public static function rowPasses(array $where, array $row): bool
    {
        foreach ($where as $w) {
            $c = $w['column'];
            if (!array_key_exists($c, $row)) continue;
            $have = $row[$c];
            $v = is_array($w['value']) ? array_map([self::class, 'token'], $w['value']) : self::token($w['value']);
            $ok = match ($w['op']) {
                'null' => $have === null, 'not_null' => $have !== null,
                'in' => in_array((string) $have, array_map('strval', (array) $v), true), 'not_in' => !in_array((string) $have, array_map('strval', (array) $v), true),
                '=' => $have !== null && (string) $have === (string) $v,
                '!=' => (string) $have !== (string) $v,
                default => $have !== null && match ($w['op']) {
                    '>' => strcmp((string) $have, (string) $v) > 0, '>=' => strcmp((string) $have, (string) $v) >= 0,
                    '<' => strcmp((string) $have, (string) $v) < 0, '<=' => strcmp((string) $have, (string) $v) <= 0,
                },
            };
            if (!$ok) return false;
        }
        return true;
    }

    // ---------------------------------------------------------------- named filters

    private function applyFilter(Query $q, string $filter): void
    {
        $school = self::school();
        switch ($filter) {
            case 'published_media':
                $ids = array_map('intval', app(\App\Http\Controllers\WebsiteMediaController::class)->publicIds($school));
                // a published post's cover and the media of published ITA items must be there too, otherwise the foreign keys on the public side would fail
                $ids = array_merge($ids, DB::table('posts')->where('school_id', $school)->where('status', 'published')->whereNull('deleted_at')->whereNotNull('cover_media_id')->pluck('cover_media_id')->map('intval')->all());
                if (app(ModuleRegistry::class)->active('web-ita') && Schema::hasTable('ita_items')) $ids = array_merge($ids, DB::table('ita_items')->whereIn('topic_id', $this->publishedTopicIds())->whereNotNull('media_id')->pluck('media_id')->map('intval')->all());
                // the published observance (popup picture, ribbon picture) is rendered by the public edition too, so its media must be copied
                $site = app(\App\Domain\Cms\SiteSettings::class)->read($school)['published'];
                // only the pictures a public page really prints (an unused ribbon or popup picture stays internal)
                $ids = array_merge($ids, \App\Domain\Cms\Observance::renderedMedia(is_array($site['observance'] ?? null) ? $site['observance'] : []));
                // files of published hub documents (F4) go to the public side too, but only as media rows the counting route /portal/d/{id} serves: they are NOT in publicIds(), so /media/{id} stays closed for them
                if (class_exists(\Saan\Modules\WebCms\Hubs::class) && Schema::hasTable('cms_hub_documents')) $ids = array_merge($ids, $this->hubDocumentQuery()->whereNotNull('media_id')->pluck('media_id')->map('intval')->all());
                $q->whereIn('id', array_values(array_unique(array_filter($ids))) ?: [0]);
                break;
            case 'public_categories':
                if (class_exists(\Saan\Modules\WebCms\Hubs::class)) $q->whereNotIn('slug', \Saan\Modules\WebCms\Hubs::draftNewsCategories());
                break;
            case 'hub_categories':
                $q->whereIn('id', \Saan\Modules\WebCms\Hubs::visibleCategoryIds() ?: [0]);
                break;
            case 'hub_documents':
                $q->whereIn('id', $this->hubDocumentQuery()->pluck('id')->all() ?: [0]);
                break;
            case 'ita_published_topics':
                $q->whereIn('year_id', DB::table('ita_years')->where('school_id', $school)->where('status', 'published')->pluck('id')->all() ?: [0]);
                break;
            case 'ita_published_items':
                $q->whereIn('topic_id', $this->publishedTopicIds() ?: [0]);
                break;
            case 'learning_public_links':
                $rooms = $this->learningRoomIds();
                $term = TermScope::currentId();
                $q->where(fn ($w) => $w->whereIn('classroom_id', $rooms ?: [0])->orWhere(fn ($x) => $x->whereNull('classroom_id')->whereNull('course_id')))
                    ->where(fn ($w) => $w->whereNull('term_id')->when($term, fn ($x) => $x->orWhere('term_id', $term)));
                break;
            case 'current_term':
                $q->where('id', TermScope::currentId() ?? 0);
                break;
            case 'directory_teachers':
                $q->whereIn('id', DB::table('per_profiles')->where('school_id', $school)->where('show_in_directory', 1)->pluck('teacher_id')->all() ?: [0]);
                if (Schema::hasColumn('teachers', 'is_active')) $q->where('is_active', 1);
                break;
            case 'directory_profiles':
                // same teachers as directory_teachers: the profile and photo of an inactive or departed teacher never leave
                $q->whereIn('teacher_id', $this->directoryTeacherIds() ?: [0]);
                break;
            default:
                throw new RuntimeException('filter ที่ยังไม่รองรับ: '.$filter);
        }
    }

    /** Hub documents that may leave: published, in a visible category of a published hub, with a file or an https link. */
    private function hubDocumentQuery(): Query
    {
        return DB::table('cms_hub_documents')->where('school_id', self::school())->where('is_published', 1)->whereIn('category_id', \Saan\Modules\WebCms\Hubs::visibleCategoryIds() ?: [0])
            ->where(fn ($w) => $w->whereNotNull('media_id')->orWhere('external_url', 'like', 'https://%'));
    }

    /** @return list<int> teachers shown in the public directory (profile shown and teacher active) */
    private function directoryTeacherIds(): array
    {
        $q = DB::table('teachers')->whereIn('id', DB::table('per_profiles')->where('school_id', self::school())->where('show_in_directory', 1)->pluck('teacher_id')->all() ?: [0]);
        if (Schema::hasColumn('teachers', 'school_id')) $q->where('school_id', self::school());
        if (Schema::hasColumn('teachers', 'is_active')) $q->where('is_active', 1);
        return $q->pluck('id')->map('intval')->all();
    }

    private function publishedTopicIds(): array
    {
        $years = DB::table('ita_years')->where('school_id', self::school())->where('status', 'published')->pluck('id')->all();
        return $years ? DB::table('ita_topics')->whereIn('year_id', $years)->pluck('id')->all() : [];
    }

    private function learningRoomIds(): array
    {
        return DB::table('lrn_access')->where('school_id', self::school())->where('expires_at', '>', now()->toDateTimeString())->pluck('classroom_id')->map('intval')->all();
    }

    // ---------------------------------------------------------------- derived (vetted extractors)

    private function derivedRows(array $e): iterable
    {
        return match ($e['derived']) {
            'certificate_verify' => $this->certificates(),
            'notice_masked_rows' => $this->noticeRows(),
            'learning_rooms' => $this->learningRooms(),
            'popular_posts' => \Saan\Modules\WebCms\PostViews::projectedRows(),
            'complaint_status' => \Saan\Modules\Complaints\PublicStatus::projectedRows(),
            'complaint_reports' => \Saan\Modules\Complaints\Reports::projectedRows(),
            default => throw new RuntimeException('extractor ที่ยังไม่รองรับ: '.$e['derived']),
        };
    }

    private function certificates(): array
    {
        $out = [];
        $svc = app(\Saan\Modules\Surveys\SurveyService::class);
        $rows = DB::table('srv_responses as r')->join('srv_surveys as s', 's.id', '=', 'r.survey_id')
            ->where('s.school_id', self::school())->whereNotNull('r.certificate_no')->where('r.certificate_no', '!=', '')
            ->whereNotNull('r.display_name')->where('r.display_name', '!=', '')->orderBy('r.certificate_no')
            ->get(['r.certificate_no', 'r.display_name', 'r.created_at', 's.title']);
        // the raw name only goes into the row; the declared column rule (mask_name) masks it in project(), so no unmasked name can leave
        foreach ($rows as $r) {
            $out[] = ['number' => $r->certificate_no, 'holder_name' => $r->display_name, 'survey_title' => $r->title, 'issued_at' => $r->created_at,
                // check value of the recipient's ?k= token: lets the public edition show name and survey only to the holder of the link
                'key_hash' => hash('sha256', $svc->certKey((string) $r->certificate_no))];
        }
        return $out;
    }

    public const CAPS_KEY = 'saan:publish:peer_caps';

    /** What the public side announced (Receiver caps / verify reply); [] = unknown or an older receiver. */
    public static function peerCaps(): array
    {
        $v = cache()->get(self::CAPS_KEY);
        return is_array($v) ? $v : [];
    }

    public static function rememberPeerCaps(array $caps, int $ttl = 600): void
    {
        cache()->put(self::CAPS_KEY, $caps, $ttl);
    }

    /** Full-name cells may leave only when the peer supports full_name_until (column present, receiver masks) and has Turnstile configured. */
    public static function peerAllowsFullNames(): bool
    {
        $c = self::peerCaps();
        return ($c['notice_full_name_until'] ?? false) === true && ($c['turnstile'] ?? false) === true;
    }

    /** A search-only list may leave only when the peer announced that it keeps the search_only flag (an older public side would drop it and list every row in the page). */
    public static function peerAllowsSearchOnly(): bool
    {
        return (self::peerCaps()['notice_search_only'] ?? false) === true;
    }

    /** True when a live full-name window or a search-only list exists here, i.e. the publisher needs to know what the peer supports before building notice rows. */
    public function needsPeerCaps(): bool
    {
        if (!class_exists(\Saan\Modules\WebNotices\NoticeRepository::class) || !\Illuminate\Support\Facades\Schema::hasTable('notice_lists') || !\App\Support\Saan\Turnstile::enabled()) return false;
        return DB::table('notice_lists')->where('school_id', self::school())->where('status', 'published')->where(fn ($q) => $q->where(fn ($w) => $w->where('name_mode', 'full')->where('full_name_until', '>', \Saan\Modules\WebNotices\NoticeRepository::nowLocal()))->orWhere('search_only', 1))->exists();
    }
    /** One row per notice row of every live list; cells are exactly what NoticeRepository::mask returns (mode full is published as first_initial). */
    private function noticeRows(): array
    {
        $repo = new \Saan\Modules\WebNotices\NoticeRepository();
        $out = [];
        $lists = DB::table('notice_lists')->where('school_id', self::school())->where('status', 'published')->orderBy('id')->get()->filter(fn ($l) => $repo->isLive($l));
        foreach ($lists as $l) {
            // search-only is delivered whole-list-free only by a public side that keeps the flag: for any other peer nothing of this list is sent (fail closed)
            if ($repo->searchOnly($l) && !self::peerAllowsSearchOnly()) continue;
            $rows = DB::table('notice_rows')->where('school_id', self::school())->where('list_id', $l->id)->orderBy('order_no')->orderBy('id')->limit(\Saan\Modules\WebNotices\RowParser::MAX_ROWS)->get();
            // F9: full names leave only while full_name_until is in the future (re-evaluated on every scheduler tick, not only on edit) and only
            // when the anti-bot step is configured here AND the public side said (caps probe) that it understands full_name_until and has Turnstile\n            // on: an unknown or older peer gets masked cells only. Afterwards the very same rows are sent masked and the public row loses full_name_until.
            $full = $repo->fullActive($l) && \App\Support\Saan\Turnstile::enabled() && self::peerAllowsFullNames();
            $cells = $repo->mask($l, $rows, $full ? 'full' : null); // never full unless asked: a full list is first_initial otherwise
            foreach ($rows as $i => $r) {
                $out[] = ['id' => $r->id, 'school_id' => self::school(), 'list_id' => $l->id, 'order_no' => $r->order_no, 'cells' => Wire::json($cells[$i]),
                    'full_name_until' => $full ? $repo->untilOf($l) : null];
            }
        }
        return $out;
    }

    private function learningRooms(): array
    {
        $rooms = $this->learningRoomIds();
        if (!$rooms) return [];
        return DB::table('classrooms')->where('school_id', self::school())->whereIn('id', $rooms)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($r) => ['id' => $r->id, 'school_id' => self::school(), 'name' => $r->name])->all();
    }

    // ---------------------------------------------------------------- checksums and files

    /** @return array<string,array{count:int,sha256:string}> per stream, from the current internal projection */
    public function checksums(): array
    {
        $out = [];
        foreach ($this->streams() as $s) $out[$s] = self::checksumOf($this->rows($s));
        return $out;
    }

    public static function checksumOf(array $rows): array
    {
        return Wire::checksum(array_map([Wire::class, 'rowHash'], $rows));
    }

    /**
     * Files a batch of rows needs on the public side: cms_media.path and per_profiles.photo_path, only for rows being sent.
     * @return list<array{path:string,sha256:string,size:int,mime:string}>
     */
    public function filesFor(string $stream, array $rows): array
    {
        $col = ['cms_media' => 'path', 'per_profiles' => 'photo_path'][$stream] ?? null;
        if ($col === null) return [];
        $disk = Storage::disk('local');
        $out = [];
        foreach ($rows as $row) {
            $p = $row[$col] ?? null;
            if (!is_string($p) || $p === '') continue;
            if (!preg_match(Wire::FILE_PATH, $p)) { $this->skipped[] = $p.' (ชื่อไฟล์ไม่อยู่ในรูปแบบที่ส่งได้)'; continue; }
            if (!$disk->exists($p)) { $this->skipped[] = $p.' (ไม่พบไฟล์)'; continue; }
            $abs = $disk->path($p);
            $size = (int) filesize($abs);
            if ($size > self::MAX_FILE_BYTES) { $this->skipped[] = $p.' (ใหญ่เกินกำหนด)'; continue; }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($abs);
            $out[$p] = ['path' => $p, 'sha256' => (string) hash_file('sha256', $abs), 'size' => $size, 'mime' => $mime];
        }
        return array_values($out);
    }
}
