<?php

namespace Saan\Modules\WebNotices;

use App\Support\Saan\Edition;
use App\Support\Saan\Mutation;
use App\Support\Saan\Turnstile;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NoticeRepository
{
    public const MAX_DAYS = 120;
    public const PREVIEW_ROWS = 300;
    private const FIELD = ['order_no' => 'order_no', 'display_name' => 'display_name', 'classroom' => 'classroom', 'group' => 'group_label', 'note' => 'note', 'applicant_no' => 'applicant_no'];

    public static function school(): int { return (int) config('school.school_id'); }

    public static function today(): string { return CarbonImmutable::now('Asia/Bangkok')->toDateString(); }

    public function lists(): array
    {
        $counts = DB::table('notice_rows')->where('school_id', self::school())->selectRaw('list_id, count(*) as n')->groupBy('list_id')->pluck('n', 'list_id');
        return DB::table('notice_lists')->where('school_id', self::school())->orderByDesc('id')->get()
            ->map(fn ($l) => $this->present($l) + ['rows' => (int) ($counts[$l->id] ?? 0)])->all();
    }

    /** Admin view of one list: metadata plus a MASKED preview only. Full names are never sent back to the browser. */
    public function read(int $id): array
    {
        $l = $this->find($id);
        $rows = DB::table('notice_rows')->where('school_id', self::school())->where('list_id', $id)->orderBy('order_no')->orderBy('id')->limit(self::PREVIEW_ROWS)->get();
        // mask() never returns a full name unless asked to, so mode 'full' previews as first_initial: full names never go back to the browser
        return ['list' => $this->present($l), 'rows_total' => DB::table('notice_rows')->where('school_id', self::school())->where('list_id', $id)->count(),
            'preview' => $this->mask($l, $rows), 'preview_limit' => self::PREVIEW_ROWS,
            // what the public sees now and after full_name_until (both masked here; the real full names are only ever delivered to the public after Turnstile)
            'public_view' => [
                'now' => $this->fullActive($l) ? 'full_after_turnstile' : ($l->name_mode === 'full' ? 'first_initial' : $l->name_mode),
                'after' => $l->name_mode === 'full' ? 'first_initial' : $l->name_mode,
                'until' => $this->untilOf($l), 'search_only' => $this->searchOnly($l), 'turnstile' => Turnstile::enabled(),
                // split install only: what the public side announced (null = unknown or not split). false = full names are NOT being sent there.
                'public_ready' => $this->publicReady($l),
            ]];
    }

    /** Internal edition with a push target: true when the public side supports full_name_until and has Turnstile on; false when it announced it does not; null when unknown. */
    private function publicReady(object $l): ?bool
    {
        if (!Edition::isInternal() || !\App\Support\Saan\Publish\Publisher::active()) return null;
        $c = \App\Support\Saan\Publish\Builder::peerCaps();
        if ($c === []) return null;
        $ok = true;
        if ($this->searchOnly($l)) $ok = $ok && \App\Support\Saan\Publish\Builder::peerAllowsSearchOnly(); // a search-only list is held back entirely by a peer that does not keep the flag
        if ($l->name_mode === 'full') $ok = $ok && \App\Support\Saan\Publish\Builder::peerAllowsFullNames();
        return $ok;
    }

    // ------------------------------------------------------------------ F9: full names that expire

    public const FULL_DEFAULT_DAYS = 14;

    /** Current Asia/Bangkok local date-time, the clock of full_name_until. */
    public static function nowLocal(): string { return CarbonImmutable::now('Asia/Bangkok')->format('Y-m-d H:i:s'); }

    /** Normalises a stored or submitted date-time to 'Y-m-d H:i:s' (also accepts the datetime-local form 'Y-m-d\TH:i'); null when empty. */
    public static function normUntil(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') return null;
        $v = str_replace('T', ' ', trim($v));
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/D', $v, $m) !== 1) return null;
        $norm = $m[1].' '.$m[2].':'.$m[3].':'.($m[4] ?? '00');
        // a real calendar date-time only: '2026-02-30' or '25:00' would round-trip to another value
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $norm);
        return $d !== false && $d->format('Y-m-d H:i:s') === $norm ? $norm : null;
    }

    public function untilOf(object $l): ?string { return self::normUntil($l->full_name_until ?? null); }

    /** Full names are shown only while name_mode is full AND full_name_until is set and still in the future. A full list without a date counts as expired. */
    public function fullActive(object $l): bool
    {
        $u = $this->untilOf($l);
        return $l->name_mode === 'full' && $u !== null && $u > self::nowLocal();
    }

    /**
     * What the visitor really gets: combined = fullActive(); public edition = true only while the stored cells still carry a full name that has not
     * expired (a list that says "full" but whose cells were sent masked, or whose window passed, is NOT shown as full).
     */
    public function fullShown(object $l): bool
    {
        if (!$this->fullActive($l)) return false;
        if (!Edition::isPublic()) return true;
        return DB::table('pub_notice_rows')->where('school_id', self::school())->where('list_id', $l->id)->whereNotNull('full_name_until')->where('full_name_until', '>', self::nowLocal())->exists();
    }

    /** Whether the list has the applicant / seat number column, the only column a visitor may search by number. */
    public function hasApplicantNo(object $l): bool
    {
        return in_array('applicant_no', json_decode((string) $l->columns, true) ?: [], true);
    }

    public function searchOnly(object $l): bool { return (int) ($l->search_only ?? 0) === 1; }

    /** Lists delivered only through Turnstile + one-time token (the initial HTML carries no names at all). */
    public function gated(object $l): bool { return $this->fullActive($l) || $this->searchOnly($l); }

    /** Default end of the full-name window: publish_from + 14 days, never later than the end of expires_on. */
    public static function defaultUntil(string $from, string $to): string
    {
        $a = CarbonImmutable::parse(substr($from, 0, 10), 'Asia/Bangkok')->startOfDay()->addDays(self::FULL_DEFAULT_DAYS);
        $b = CarbonImmutable::parse(substr($to, 0, 10), 'Asia/Bangkok')->endOfDay();
        return ($a > $b ? $b : $a)->format('Y-m-d H:i:s');
    }

    private function assertUntil(string $until, string $from, string $to): void
    {
        $u = CarbonImmutable::parse($until, 'Asia/Bangkok');
        $err = null;
        if ($u > CarbonImmutable::parse(substr($to, 0, 10), 'Asia/Bangkok')->endOfDay()) $err = 'เวลาแสดงชื่อเต็มต้องไม่เกินวันหมดอายุของประกาศ ('.substr($to, 0, 10).')';
        elseif ($u < CarbonImmutable::parse(substr($from, 0, 10), 'Asia/Bangkok')->startOfDay()) $err = 'เวลาแสดงชื่อเต็มต้องไม่ก่อนวันเริ่มเผยแพร่';
        if ($err) throw ValidationException::withMessages(['full_name_until' => [$err]]);
    }

    /** Full names and search-only delivery both depend on the anti-bot step: without Turnstile they are refused. */
    private function requireTurnstile(string $field, string $what): void
    {
        if (!Turnstile::enabled()) throw ValidationException::withMessages([$field => ['ไม่อนุญาต'.$what.' เพราะยังไม่ได้ตั้งค่า Cloudflare Turnstile (SAAN_TURNSTILE_SITE_KEY และ SAAN_TURNSTILE_SECRET) ซึ่งเป็นด่านป้องกันบอทเก็บรายชื่อ']]);
    }

    /**
     * Resolves name_mode / full_name_until / search_only for create and update.
     * @param array<string,mixed> $d submitted fields; @param ?object $cur the stored list (update) or null (create)
     * @return array<string,mixed> columns to write
     */
    private function nameSettings(array $d, ?object $cur, string $from, string $to): array
    {
        $mode = $d['name_mode'] ?? $cur->name_mode ?? 'first_initial';
        $out = [];
        $curUntil = $cur ? $this->untilOf($cur) : null;
        // the latest window end ever set on this list (never cleared): once it has passed, no path may open a new window
        $spent = $cur ? self::normUntil($cur->full_name_spent_until ?? null) : null;
        if ($curUntil !== null && ($spent === null || $curUntil > $spent)) $spent = $curUntil;
        $req = array_key_exists('full_name_until', $d) ? self::normUntil($d['full_name_until']) : null;
        if ($req === null && is_string($d['full_name_until'] ?? null) && trim($d['full_name_until']) !== '') throw ValidationException::withMessages(['full_name_until' => ['รูปแบบวันเวลาไม่ถูกต้อง']]);
        if ($mode !== 'full') {
            if ($req !== null) throw ValidationException::withMessages(['full_name_until' => ['กำหนดเวลาแสดงชื่อเต็มได้เฉพาะเมื่อเลือกโหมดชื่อเต็ม']]);
            if ($curUntil !== null) $out['full_name_until'] = null;
        } else {
            if (array_key_exists('name_mode', $d) || array_key_exists('full_name_until', $d)) $this->requireTurnstile('name_mode', 'ชื่อเต็ม');
            // The default window applies only to a list that is NEW as full (create, or switched from another mode). A stored full list without a date
            // is a legacy one: it stays expired (null) until the admin sends full_name_until, so editing a title can never open full names.
            $becameFull = $cur === null || $cur->name_mode !== 'full';
            $until = $req ?? $curUntil ?? ($becameFull ? self::defaultUntil($from, $to) : null);
            if ($until !== null) {
                $this->assertUntil($until, $from, $to);
                // an expired window cannot be reopened (the names were already masked in the public copy), also not after a detour through a masked mode
                // "ended" = the effective end already passed (also when it was shortened to a time that has since passed while the forward-only mark still sits later)
                $ended = ($curUntil !== null && $curUntil <= self::nowLocal()) || ($spent !== null && $spent <= self::nowLocal());
                if ($until !== $curUntil && $ended && $until > self::nowLocal()) {
                    throw ValidationException::withMessages([array_key_exists('full_name_until', $d) ? 'full_name_until' : 'name_mode' => ['ช่วงเวลาแสดงชื่อเต็มของประกาศนี้สิ้นสุดไปแล้ว เปิดกลับมาใหม่ไม่ได้ (ชื่อเต็มถูกปิดบังที่ฝั่งสาธารณะแล้ว) หากจำเป็นต้องสร้างประกาศใหม่']]);
                }
                if ($until !== $curUntil) {
                    $this->requireTurnstile('full_name_until', 'ชื่อเต็ม');
                    $out['full_name_until'] = $until;
                }
            }
        }
        // remember the latest window end (only ever moves forward)
        $newUntil = array_key_exists('full_name_until', $out) ? $out['full_name_until'] : $curUntil;
        $mark = $newUntil !== null && ($spent === null || $newUntil > $spent) ? $newUntil : $spent;
        // the window ENDS when the admin shortens it into the past or leaves full mode: the marker follows the effective end down, so that it cannot be extended again
        $now = self::nowLocal();
        if ($mode !== 'full' && $curUntil !== null && $curUntil > $now && $mark !== null) $mark = min($mark, $now);
        elseif ($mode === 'full' && $newUntil !== null && $newUntil <= $now && $mark !== null) $mark = min($mark, $newUntil);
        if ($mark !== null && $mark !== ($cur ? self::normUntil($cur->full_name_spent_until ?? null) : null)) $out['full_name_spent_until'] = $mark;
        if (array_key_exists('search_only', $d)) {
            $so = (bool) $d['search_only'];
            if ($so) $this->requireTurnstile('search_only', 'โหมดค้นหาชื่อตัวเอง');
            $out['search_only'] = $so ? 1 : 0;
        }
        return $out;
    }

    public function find(int $id): object
    {
        return DB::table('notice_lists')->where('school_id', self::school())->where('id', $id)->first() ?? abort(404);
    }

    public function present(object $l): array
    {
        return ['id' => (int) $l->id, 'slug' => $l->slug, 'title' => $l->title, 'intro' => $l->intro, 'columns' => json_decode((string) $l->columns, true) ?: [],
            'name_mode' => $l->name_mode, 'publish_from' => $l->publish_from, 'expires_on' => $l->expires_on, 'status' => $l->status,
            'full_name_until' => $this->untilOf($l), 'full_active' => $this->fullActive($l), 'search_only' => $this->searchOnly($l),
            'full_name_default_days' => self::FULL_DEFAULT_DAYS, 'turnstile' => Turnstile::enabled(),
            'has_access_code' => $l->access_code_hash !== null && $l->access_code_hash !== '', 'version' => (int) $l->version,
            'live' => $this->isLive($l), 'path' => '/portal/notices/'.$l->slug, 'updated_at' => $l->updated_at];
    }

    public function isLive(object $l): bool
    {
        $today = self::today();
        return $l->status === 'published' && substr((string) $l->publish_from, 0, 10) <= $today && substr((string) $l->expires_on, 0, 10) >= $today;
    }

    /**
     * Rows with only the enabled columns and the name masked by the list's mode. Mode 'full' is NEVER applied implicitly: a full list is masked
     * as first_initial unless the caller passes $mode = 'full' (only the token-protected delivery and the publisher do, while fullActive()).
     */
    public function mask(object $l, iterable $rows, ?string $mode = null): array
    {
        $mode ??= $l->name_mode === 'full' ? 'first_initial' : $l->name_mode;
        $cols = json_decode((string) $l->columns, true) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $row = [];
            foreach ($cols as $c) {
                $f = self::FIELD[$c] ?? null; if ($f === null) continue;
                $row[$c] = $c === 'display_name' ? NameMask::apply((string) $r->display_name, $mode) : $r->{$f};
            }
            $out[] = $row;
        }
        return $out;
    }

    public function publicList(string $slug): ?object
    {
        $slug = strtolower(trim($slug));
        $l = DB::table('notice_lists')->where('school_id', self::school())->where('slug', $slug)->first();
        return $l && $this->isLive($l) ? $l : null;
    }

    /**
     * Cells to show. $allowFull = true is passed ONLY by the token-protected delivery (rows / search endpoints); everything else gets masked names.
     * Even then a full name is served only while fullActive() (combined) or while the row's own full_name_until is in the future (public).
     */
    public function publicRows(object $l, bool $allowFull = false): array
    {
        // S2: the public edition holds only the rows the publisher derived (pub_notice_rows), never notice_rows.
        if (Edition::isPublic()) {
            return DB::table('pub_notice_rows')->where('school_id', self::school())->where('list_id', $l->id)->orderBy('order_no')->orderBy('id')->limit(RowParser::MAX_ROWS)->get(['cells', 'full_name_until'])
                ->map(fn ($r) => self::guardCells((string) $r->cells, $r->full_name_until, $allowFull && $this->fullActive($l)))->all();
        }
        $rows = DB::table('notice_rows')->where('school_id', self::school())->where('list_id', $l->id)->orderBy('order_no')->orderBy('id')->limit(RowParser::MAX_ROWS)->get();
        return $this->mask($l, $rows, $allowFull && $this->fullActive($l) ? 'full' : null);
    }

    public function rowCount(object $l): int
    {
        return (int) (Edition::isPublic()
            ? DB::table('pub_notice_rows')->where('school_id', self::school())->where('list_id', $l->id)->count()
            : DB::table('notice_rows')->where('school_id', self::school())->where('list_id', $l->id)->count());
    }

    /** @return array{rows:list<array>,more:bool} at most NoticeSearch::MAX_RESULTS rows, matched against the cells the visitor may see */
    public function searchRows(object $l, array $query): array
    {
        return NoticeSearch::match($this->publicRows($l, true), $query, $this->fullShown($l));
    }

    /**
     * Cells of one pub_notice_rows row. A row that still carries full_name_until holds a FULL name: it is returned masked (first_initial) when the
     * time has passed or when $allowFull is false. Rows without full_name_until are already masked and returned as they are.
     */
    public static function guardCells(string $cellsJson, mixed $until, bool $allowFull): array
    {
        $cells = json_decode($cellsJson, true);
        if (!is_array($cells)) return [];
        $u = self::normUntil($until);
        if ($u !== null && (!$allowFull || $u <= self::nowLocal())) {
            if (isset($cells['display_name'])) $cells['display_name'] = NameMask::apply((string) $cells['display_name'], 'first_initial');
        }
        return $cells;
    }

    /** Receiver side: a pub_notice_rows row that arrives with an already passed (or unreadable) full_name_until is stored masked. */
    public static function guardReceivedRow(array $row): array
    {
        if (($row['full_name_until'] ?? null) === null) return $row;
        $u = self::normUntil($row['full_name_until']);
        if ($u !== null && $u > self::nowLocal()) return $row;
        $row['cells'] = \App\Support\Saan\Publish\Wire::json(self::guardCells((string) ($row['cells'] ?? ''), '1970-01-01 00:00:00', false));
        $row['full_name_until'] = null;
        return $row;
    }

    /**
     * Public-side defence in depth (scheduled, and applied by the receiver): every pub_notice_rows row whose full_name_until has passed gets its
     * name masked in place and full_name_until cleared; a row that cannot be read is deleted. After this a database snapshot holds no full name of
     * an expired window, even when the internal side is down. Returns the number of rows rewritten or deleted.
     */
    public static function maskExpiredPublicRows(?string $connection = null): int
    {
        $db = DB::connection($connection);
        if (!$db->getSchemaBuilder()->hasTable('pub_notice_rows') || !$db->getSchemaBuilder()->hasColumn('pub_notice_rows', 'full_name_until')) return 0;
        $n = 0;
        $q = $db->table('pub_notice_rows')->whereNotNull('full_name_until')->where('full_name_until', '<=', self::nowLocal());
        foreach ($q->orderBy('id')->get(['id', 'cells'])->chunk(200) as $chunk) {
            foreach ($chunk as $r) {
                $cells = self::guardCells((string) $r->cells, '1970-01-01 00:00:00', false);
                if ($cells === []) $db->table('pub_notice_rows')->where('id', $r->id)->delete();
                else $db->table('pub_notice_rows')->where('id', $r->id)->update(['cells' => \App\Support\Saan\Publish\Wire::json($cells), 'full_name_until' => null]);
                $n++;
            }
        }
        return $n;
    }

    public function create(array $d): array
    {
        $slug = $d['slug'] ?? null ?: 'n-'.Str::lower(Str::random(12));
        $this->assertDates($d['publish_from'], $d['expires_on'], true);
        if (DB::table('notice_lists')->where('school_id', self::school())->where('slug', $slug)->exists()) abort(409, 'ลิงก์ (slug) นี้ถูกใช้แล้ว');
        $values = ['slug' => $slug, 'title' => $d['title'], 'intro' => $d['intro'] ?? null, 'columns' => $this->columns($d['columns'] ?? ['order_no', 'display_name']),
            'name_mode' => $d['name_mode'] ?? 'first_initial', 'publish_from' => $d['publish_from'], 'expires_on' => $d['expires_on'], 'status' => 'draft',
            'access_code_hash' => !empty($d['access_code']) ? self::hashCode($d['access_code']) : null];
        $values += $this->nameSettings($d, null, $d['publish_from'], $d['expires_on']);
        $detail = ['slug' => $slug, 'name_mode' => $values['name_mode'], 'full_name_until' => $values['full_name_until'] ?? null, 'search_only' => (bool) ($values['search_only'] ?? 0)];
        $row = $this->guard(fn () => Mutation::insert('notice_lists', $values, 'notices.created', $detail));
        return $this->read((int) $row->id);
    }

    public function update(int $id, array $d): array
    {
        $cur = $this->find($id);
        $changes = [];
        foreach (['title', 'intro', 'name_mode', 'publish_from', 'expires_on'] as $k) if (array_key_exists($k, $d)) $changes[$k] = $d[$k];
        if (array_key_exists('columns', $d)) $changes['columns'] = $this->columns($d['columns']);
        if (!empty($d['slug']) && $d['slug'] !== $cur->slug) {
            if ($cur->status === 'published') abort(422, 'เปลี่ยนลิงก์ไม่ได้ขณะเผยแพร่ ให้เก็บถาวรหรือสร้างรายการใหม่');
            if (DB::table('notice_lists')->where('school_id', self::school())->where('slug', $d['slug'])->where('id', '!=', $id)->exists()) abort(409, 'ลิงก์ (slug) นี้ถูกใช้แล้ว');
            $changes['slug'] = $d['slug'];
        }
        $from = $changes['publish_from'] ?? substr($cur->publish_from, 0, 10);
        $to = $changes['expires_on'] ?? substr($cur->expires_on, 0, 10);
        $this->assertDates($from, $to, false);
        $changes += $this->nameSettings($d, $cur, $from, $to);
        if (!empty($d['access_code'])) $changes['access_code_hash'] = self::hashCode($d['access_code']);
        elseif (!empty($d['clear_access_code'])) $changes['access_code_hash'] = null;
        $detail = ['fields' => array_keys($changes)];
        foreach (['name_mode', 'full_name_until', 'search_only'] as $k) if (array_key_exists($k, $changes)) $detail[$k] = $changes[$k];
        $this->guard(fn () => Mutation::update('notice_lists', $id, (int) $d['version'], $changes, 'notices.updated', $detail));
        return $this->read($id);
    }

    public function replaceRows(int $id, int $version, string $text): array
    {
        $l = $this->find($id);
        $rows = RowParser::parse($text, json_decode((string) $l->columns, true) ?: []);
        DB::transaction(function () use ($id, $version, $rows) {
            Mutation::update('notice_lists', $id, $version, [], 'notices.rows_replaced', ['rows' => count($rows)]);
            DB::table('notice_rows')->where('school_id', self::school())->where('list_id', $id)->delete();
            foreach (array_chunk($rows, 200) as $chunk) DB::table('notice_rows')->insert(array_map(fn ($r) => $r + ['school_id' => self::school(), 'list_id' => $id], $chunk));
        });
        return $this->read($id);
    }

    public function setStatus(int $id, int $version, string $action): array
    {
        $l = $this->find($id);
        $to = ['publish' => 'published', 'archive' => 'archived', 'restore' => 'draft'][$action];
        $ok = match ($action) { 'publish' => $l->status === 'draft', 'archive' => in_array($l->status, ['draft', 'published'], true), 'restore' => $l->status === 'archived' };
        if (!$ok) abort(422, 'สถานะปัจจุบันทำรายการนี้ไม่ได้');
        if ($action === 'publish') {
            if (substr($l->expires_on, 0, 10) < self::today()) abort(422, 'วันหมดอายุผ่านไปแล้ว กรุณาแก้วันหมดอายุก่อนเผยแพร่');
            if (!DB::table('notice_rows')->where('school_id', self::school())->where('list_id', $id)->exists()) abort(422, 'ยังไม่มีรายชื่อ');
            if ($this->gated($l) && !Turnstile::enabled()) abort(422, 'ประกาศที่แสดงชื่อเต็มหรือโหมดค้นหาเผยแพร่ไม่ได้ เพราะยังไม่ได้ตั้งค่า Cloudflare Turnstile');
        }
        Mutation::update('notice_lists', $id, $version, ['status' => $to], 'notices.'.$action, ['to' => $to]);
        return $this->read($id);
    }

    public function delete(int $id, int $version): void
    {
        $l = $this->find($id);
        if ($this->isLive($l)) abort(422, 'รายการที่กำลังเผยแพร่ ต้องเก็บถาวรก่อนจึงลบได้');
        DB::transaction(function () use ($id, $version) {
            DB::table('notice_rows')->where('school_id', self::school())->where('list_id', $id)->delete();
            Mutation::delete('notice_lists', $id, $version, 'notices.deleted');
        });
    }

    public static function hashCode(string $code): string
    {
        $salt = bin2hex(random_bytes(8));
        // keyed with the derived public-unlock key (see HashKey::publicUnlock): the public edition verifies it without the master key
        return $salt.':'.hash_hmac('sha256', trim($code), $salt.'|'.\App\Support\Saan\HashKey::publicUnlock());
    }

    public static function checkCode(?string $stored, string $code): bool
    {
        if (!$stored || !str_contains($stored, ':')) return false;
        [$salt, $hash] = explode(':', $stored, 2);
        // survives an APP_KEY rotation or a restore onto a different key: try the dedicated key, the app key and APP_PREVIOUS_KEYS (always all, constant work)
        $ok = false;
        foreach (\App\Support\Saan\HashKey::unlockCandidates() as $key) $ok = hash_equals($hash, hash_hmac('sha256', trim($code), $salt.'|'.$key)) || $ok;
        return $ok;
    }

    /** Session proof of a successful unlock; bound to the stored hash so changing the code revokes it. */
    public static function unlockToken(object $l): string
    {
        return hash_hmac('sha256', (string) $l->access_code_hash, 'notice-unlock|'.\App\Support\Saan\HashKey::publicUnlock());
    }

    private function columns(array $columns): array
    {
        $bad = array_diff($columns, RowParser::ALLOWED);
        if ($bad) throw ValidationException::withMessages(['columns' => ['ไม่อนุญาตคอลัมน์: '.implode(', ', array_map('strval', $bad))]]);
        $columns = array_values(array_unique($columns));
        if (!in_array('display_name', $columns, true)) throw ValidationException::withMessages(['columns' => ['ต้องมีคอลัมน์ชื่อ']]);
        return $columns;
    }

    private function assertDates(string $from, string $to, bool $creating): void
    {
        $a = CarbonImmutable::parse($from, 'Asia/Bangkok')->startOfDay(); $b = CarbonImmutable::parse($to, 'Asia/Bangkok')->startOfDay();
        $err = null;
        if ($b < $a) $err = 'วันหมดอายุต้องไม่ก่อนวันเริ่มเผยแพร่';
        elseif ($a->diffInDays($b) > self::MAX_DAYS) $err = 'วันหมดอายุต้องไม่เกิน '.self::MAX_DAYS.' วันหลังวันเริ่มเผยแพร่';
        elseif ($creating && $to < self::today()) $err = 'วันหมดอายุต้องไม่ก่อนวันนี้';
        if ($err) throw ValidationException::withMessages(['expires_on' => [$err]]);
    }

    /** Convert a unique-index race (SQLSTATE 23000/23505) into HTTP 409 instead of 500. */
    private function guard(\Closure $fn): mixed
    {
        try { return $fn(); }
        catch (QueryException $e) {
            if (\App\Support\Saan\DbError::isUnique($e)) abort(409, 'ข้อมูลซ้ำ (ลิงก์นี้ถูกใช้แล้ว)');
            throw $e;
        }
    }
}
