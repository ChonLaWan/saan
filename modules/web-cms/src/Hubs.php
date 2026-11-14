<?php

namespace Saan\Modules\WebCms;

use App\Support\Fiscal;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F4 group hubs: read side shared by the public pages, the internal preview and the publisher.
 *
 * A hub is one web-cms page (template "group hub") bound to a group name. Personnel has no group entity (per_profiles.work_group and department are
 * free text), so the binding is that text, compared case/space-insensitively. Staff come ONLY from Saan\Modules\Personnel\Staff::directory(), the
 * allowlisted public staff list (hidden staff and inactive teachers are not in it), so a hub can never publish more than the directory does.
 * Documents and categories are ordinary rows of cms_hub_*; what the public edition may see is decided in ONE place (visibleCategoryIds / publicDocs).
 */
final class Hubs
{
    public const KINDS = ['work' => 'กลุ่มงาน', 'subject' => 'กลุ่มสาระการเรียนรู้', 'assoc' => 'สมาคม', 'finance' => 'รายงานการเงิน'];
    /** Document category rules (F5b). pdf = PDF files only (no link); monthly = PDF only, one published document per month and Buddhist-era year, listed by Thai fiscal year. */
    public const RULES = ['pdf' => 'PDF เท่านั้น', 'monthly' => 'รายเดือน (PDF)'];
    /**
     * Ready templates for new pages (F5b): kind, default address and title, categories [label, rule] and the news category the page is bound to (null = none).
     * The association templates are filled in by the admin (about text, committee names and roles, contact): nothing real is seeded.
     */
    public const TEMPLATES = [
        'alumni' => ['label' => 'สมาคมศิษย์เก่า', 'kind' => 'assoc', 'slug' => 'alumni', 'title' => 'สมาคมศิษย์เก่า', 'news' => true,
            'categories' => [['รายชื่อคณะกรรมการและสมาชิก (PDF)', 'pdf'], ['ระเบียบและประกาศ', null], ['เอกสารอื่น', null]]],
        'parents' => ['label' => 'สมาคมผู้ปกครองและครู', 'kind' => 'assoc', 'slug' => 'parent-teacher', 'title' => 'สมาคมผู้ปกครองและครู', 'news' => true,
            'categories' => [['รายชื่อคณะกรรมการและสมาชิก (PDF)', 'pdf'], ['ระเบียบและประกาศ', null], ['เอกสารอื่น', null]]],
        'association' => ['label' => 'สมาคมหรือชมรมอื่น', 'kind' => 'assoc', 'slug' => 'association', 'title' => 'สมาคม', 'news' => true,
            'categories' => [['รายชื่อคณะกรรมการและสมาชิก (PDF)', 'pdf'], ['ระเบียบและประกาศ', null], ['เอกสารอื่น', null]]],
        'trial_balance' => ['label' => 'รายงานงบทดลองรายเดือน', 'kind' => 'finance', 'slug' => 'finance-reports', 'title' => 'รายงานการเงิน', 'news' => false,
            'categories' => [['งบทดลองรายเดือน', 'monthly'], ['รายงานการเงินอื่น', null]]],
    ];
    public const COMMITTEE_MAX = 30;
    /** Boxes a new hub starts with (the admin can rename, reorder, hide and add). */
    public const DEFAULT_CATEGORIES = ['คำสั่ง/ประกาศ', 'แบบฟอร์ม', 'ปฏิทิน/ตารางสอน', 'เอกสารวัดผล', 'ประกันคุณภาพ', 'ผลงาน'];
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
    public const PER_PAGE = 15;

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    /** Case/space-insensitive key for comparing group names. */
    public static function key(?string $v): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $v)));
    }

    /** Profile column that holds a hub's group: a subject hub is bound to department, a work hub to work_group (never both). */
    public static function groupField(object $hub): string
    {
        return ($hub->kind ?? 'work') === 'subject' ? 'department' : 'work_group';
    }

    /** Position a head of this kind of hub has: "หัวหน้ากลุ่มสาระ..." for a subject hub, "หัวหน้ากลุ่มงาน..." for a work hub (รองหัวหน้า and หัวหน้างาน... are not heads). */
    public static function isHeadPosition(?string $position, object $hub): bool
    {
        $prefix = ($hub->kind ?? 'work') === 'subject' ? 'หัวหน้ากลุ่มสาระ' : 'หัวหน้ากลุ่มงาน';
        return mb_strpos(trim((string) $position), $prefix) === 0;
    }

    /** True when a personnel profile ([position, work_group, department]) is the head of this hub's group. */
    public static function headMatches(object $hub, ?object $profile): bool
    {
        $g = self::key($hub->group_name);
        if (!$profile || $g === '' || !self::isHeadPosition($profile->position ?? null, $hub)) return false;
        return self::key($profile->{self::groupField($hub)} ?? null) === $g;
    }

    /** LIKE pattern of a title search: wildcard characters typed by the visitor are dropped (no escape character is relied on). */
    public static function like(string $q): string
    {
        return '%'.str_replace(['\\', '%', '_'], '', trim($q)).'%';
    }

    // ---------------------------------------------------------------- hubs

    public static function published(string $slug): ?object
    {
        return DB::table('cms_hubs')->where('school_id', self::school())->where('slug', $slug)->where('status', 'published')->first();
    }

    public static function find(int $id): ?object
    {
        return DB::table('cms_hubs')->where('school_id', self::school())->where('id', $id)->first();
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    public static function listPublished()
    {
        return DB::table('cms_hubs')->where('school_id', self::school())->where('status', 'published')->orderBy('kind')->orderBy('sort')->orderBy('id')->get();
    }

    /** News category slugs (cms_categories) that belong only to pages that are not published: their label carries the page title, so it stays off the site until the page is published. @return list<string> */
    public static function draftNewsCategories(): array
    {
        if (!Schema::hasTable('cms_hubs')) return [];
        $rows = DB::table('cms_hubs')->where('school_id', self::school())->whereNotNull('news_category')->get(['news_category', 'status']);
        $live = $rows->where('status', 'published')->pluck('news_category')->all();
        return array_values(array_diff($rows->where('status', '!=', 'published')->pluck('news_category')->all(), $live));
    }

    // ---------------------------------------------------------------- categories

    /** Categories of one hub as top-level rows with ->children, hidden ones (and the children of a hidden one) left out unless $all. @return list<object> */
    public static function tree(int $hubId, bool $all = false): array
    {
        $rows = DB::table('cms_hub_categories')->where('school_id', self::school())->where('hub_id', $hubId)->orderBy('sort')->orderBy('id')->get();
        $top = [];
        foreach ($rows as $r) if ($r->parent_id === null && ($all || !$r->is_hidden)) { $r->children = []; $top[$r->id] = $r; }
        foreach ($rows as $r) if ($r->parent_id !== null && isset($top[$r->parent_id]) && ($all || !$r->is_hidden)) $top[$r->parent_id]->children[] = $r;
        return array_values($top);
    }

    /** Ids of the categories a guest may see: category not hidden, hub published, and the parent (if any) not hidden. The publisher uses the same list. @return list<int> */
    public static function visibleCategoryIds(?int $hubId = null): array
    {
        $school = self::school();
        $hubs = DB::table('cms_hubs')->where('school_id', $school)->where('status', 'published')->when($hubId, fn ($q) => $q->where('id', $hubId))->pluck('id')->all();
        if (!$hubs) return [];
        $rows = DB::table('cms_hub_categories')->where('school_id', $school)->whereIn('hub_id', $hubs)->get(['id', 'parent_id', 'is_hidden']);
        $hidden = [];
        foreach ($rows as $r) if ($r->is_hidden) $hidden[$r->id] = true;
        $out = [];
        foreach ($rows as $r) if (!$r->is_hidden && ($r->parent_id === null || !isset($hidden[$r->parent_id]))) $out[] = (int) $r->id;
        return $out;
    }

    /** A top-level category the guest may open (or any visible one when $preview), with ->children. */
    public static function category(object $hub, int $id, bool $preview = false): ?object
    {
        foreach (self::tree((int) $hub->id, $preview) as $c) if ((int) $c->id === $id) return $c;
        return null;
    }

    // ---------------------------------------------------------------- documents

    /** Documents a guest may see (published, in a visible category, with a file or an https link). Preview: every document of the hub. */
    public static function documents(object $hub, bool $preview = false)
    {
        $q = DB::table('cms_hub_documents as d')->leftJoin('cms_media as m', 'm.id', '=', 'd.media_id')
            ->where('d.school_id', self::school())->where('d.hub_id', $hub->id)
            ->select('d.id', 'd.hub_id', 'd.category_id', 'd.title', 'd.doc_date', 'd.file_name', 'd.external_url', 'd.media_id', 'd.is_published', 'd.sort', 'd.period_month', 'd.period_year', 'm.mime as mime', 'm.bytes as bytes');
        if ($preview) return $q;
        return $q->where('d.is_published', 1)->whereIn('d.category_id', self::visibleCategoryIds((int) $hub->id) ?: [0])
            ->where(fn ($w) => $w->whereNotNull('d.media_id')->orWhere('d.external_url', 'like', 'https://%'));
    }

    /**
     * Site search: published hubs and guest-visible documents whose title matches. Same visibility rules as the hub page (the public edition
     * holds only these rows anyway). @return array{hubs:list<array{title:string,url:string}>,documents:list<array{title:string,hub:string,url:string}>}
     */
    public static function search(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '' || !Schema::hasTable('cms_hubs') || !app(ModuleRegistry::class)->active('web-cms')) return ['hubs' => [], 'documents' => []];
        $like = self::like($q);
        if ($like === '%%') return ['hubs' => [], 'documents' => []]; // only wildcard characters: like() strips them, which would match everything
        $hubs = DB::table('cms_hubs')->where('school_id', self::school())->where('status', 'published')->where('title', 'like', $like)
            ->orderBy('kind')->orderBy('sort')->limit($limit)->get(['title', 'slug'])
            ->map(fn ($h) => ['title' => (string) $h->title, 'url' => url('/portal/groups/'.$h->slug)])->all();
        $visible = self::visibleCategoryIds();
        $docs = !$visible ? [] : DB::table('cms_hub_documents as d')->join('cms_hubs as h', 'h.id', '=', 'd.hub_id')
            ->where('d.school_id', self::school())->where('h.status', 'published')->where('d.is_published', 1)->whereIn('d.category_id', $visible)
            ->where(fn ($w) => $w->whereNotNull('d.media_id')->orWhere('d.external_url', 'like', 'https://%'))
            ->where('d.title', 'like', $like)->orderByDesc('d.doc_date')->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.title', 'h.title as hub_title'])
            ->map(fn ($d) => ['title' => (string) $d->title, 'hub' => (string) $d->hub_title, 'url' => url('/portal/d/'.$d->id)])->all();
        return ['hubs' => $hubs, 'documents' => $docs];
    }

    /** One document a guest may download, or null (unpublished, hidden category, draft hub, no usable target). */
    public static function downloadable(int $id): ?object
    {
        $d = DB::table('cms_hub_documents')->where('school_id', self::school())->where('id', $id)->first();
        if (!$d || !$d->is_published) return null;
        $hub = DB::table('cms_hubs')->where('school_id', self::school())->where('id', $d->hub_id)->first();
        if (!$hub || $hub->status !== 'published') return null;
        if (!in_array((int) $d->category_id, self::visibleCategoryIds((int) $hub->id), true)) return null;
        return $d->media_id !== null || strncasecmp((string) $d->external_url, 'https://', 8) === 0 ? $d : null; // same case-insensitive test as the LIKE in documents()
    }

    /**
     * Boxes of the hub page in ONE documents query: [{category, items (newest N), total}] for the given top-level categories (with ->children),
     * ordered pinned first, newest date, newest id. Visibility is decided once by documents().
     * @param list<object> $tree
     * @return list<array{category:object,items:list<object>,total:int}>
     */
    public static function boxes(object $hub, array $tree, int $limit, bool $preview = false): array
    {
        $limit = max(1, $limit);
        $top = [];
        $out = [];
        foreach ($tree as $i => $cat) {
            $out[$i] = ['category' => $cat, 'items' => [], 'total' => 0];
            $top[(int) $cat->id] = $i;
            foreach ($cat->children ?? [] as $c) $top[(int) $c->id] = $i;
        }
        if (!$top) return [];
        $rows = self::documents($hub, $preview)->whereIn('d.category_id', array_keys($top))->orderByDesc('d.sort')->orderByDesc('d.period_year')->orderByDesc('d.period_month')->orderByDesc('d.doc_date')->orderByDesc('d.id')->get();
        foreach ($rows as $r) {
            $b = &$out[$top[(int) $r->category_id]];
            $b['total']++;
            if (count($b['items']) < $limit) $b['items'][] = $r;
            unset($b);
        }
        return array_values($out);
    }

    /** The newest N documents of a top-level category and its sub-categories, plus how many there are. @return array{items:list<object>,total:int} */
    public static function box(object $hub, object $cat, int $limit, bool $preview = false): array
    {
        $ids = array_merge([(int) $cat->id], array_map(fn ($c) => (int) $c->id, $cat->children ?? []));
        $q = self::documents($hub, $preview)->whereIn('d.category_id', $ids);
        $total = (clone $q)->count('d.id');
        $items = $q->orderByDesc('d.sort')->orderByDesc('d.period_year')->orderByDesc('d.period_month')->orderByDesc('d.doc_date')->orderByDesc('d.id')->limit(max(1, $limit))->get()->all();
        return ['items' => $items, 'total' => $total];
    }

    /** Paged list of one category (with its sub-categories). Filters: year (พ.ศ.), q (title), sub (a sub-category id). */
    public static function paged(object $hub, object $cat, array $f, bool $preview = false): LengthAwarePaginator
    {
        $ids = array_merge([(int) $cat->id], array_map(fn ($c) => (int) $c->id, $cat->children ?? []));
        $q = self::documents($hub, $preview)->whereIn('d.category_id', $ids);
        if (!empty($f['sub']) && in_array((int) $f['sub'], $ids, true)) $q->where('d.category_id', (int) $f['sub']);
        if (!empty($f['year'])) {
            $ce = (int) $f['year'] - 543;
            $q->where('d.doc_date', '>=', sprintf('%04d-01-01', $ce))->where('d.doc_date', '<=', sprintf('%04d-12-31', $ce));
        }
        if (isset($f['q']) && trim((string) $f['q']) !== '') $q->where('d.title', 'like', self::like((string) $f['q']));
        return $q->orderByDesc('d.sort')->orderByDesc('d.doc_date')->orderByDesc('d.id')->paginate(self::PER_PAGE)->withQueryString();
    }

    /** Buddhist years offered by the year filter for one category tree (newest first, at most 40). @return list<int> */
    public static function years(object $hub, object $cat, bool $preview = false): array
    {
        $ids = array_merge([(int) $cat->id], array_map(fn ($c) => (int) $c->id, $cat->children ?? []));
        $q = self::documents($hub, $preview)->whereIn('d.category_id', $ids);
        $min = (clone $q)->min('d.doc_date');
        $max = (clone $q)->max('d.doc_date');
        if (!$min || !$max) return [];
        $out = [];
        for ($y = (int) substr((string) $max, 0, 4); $y >= (int) substr((string) $min, 0, 4) && count($out) < 40; $y--) $out[] = $y + 543;
        return $out;
    }

    /** Short type label of a document row for the box (PDF, Word, ...). */
    public static function typeLabel(object $d): string
    {
        if ($d->media_id === null) return 'ลิงก์';
        $mime = (string) ($d->mime ?? '');
        return match (true) {
            $mime === 'application/pdf' => 'PDF',
            str_contains($mime, 'word') || $mime === 'application/msword' => 'Word',
            str_contains($mime, 'sheet') || $mime === 'application/vnd.ms-excel' => 'Excel',
            str_contains($mime, 'presentation') || $mime === 'application/vnd.ms-powerpoint' => 'PowerPoint',
            str_starts_with($mime, 'image/') => 'รูปภาพ',
            default => 'ไฟล์',
        };
    }

    public static function size(?int $bytes): string
    {
        if (!$bytes) return '';
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    /** Where the visitor goes to get the document: the counting route on the public site, the authenticated file route in the internal preview. */
    public static function downloadUrl(object $d, bool $preview): string
    {
        if ($preview) return $d->media_id !== null ? url('/api/cms/hubs/documents/'.$d->id.'/file') : (string) $d->external_url;
        return url('/portal/d/'.$d->id);
    }

    // ---------------------------------------------------------------- staff (allowlist = the public directory)

    private static function personnelReady(): bool
    {
        return class_exists(\Saan\Modules\Personnel\Staff::class) && app(ModuleRegistry::class)->active('personnel') && Schema::hasTable('per_profiles');
    }

    /**
     * Head(s) and staff of the hub's group, only name, position, photo (a subset of what the public directory prints), ordered head first, then by directory order and name.
     * @return array{heads:list<array>,members:list<array>}
     */
    public static function staff(object $hub): array
    {
        $group = self::key($hub->group_name);
        if ($group === '' || !self::personnelReady()) return ['heads' => [], 'members' => []];
        $rows = [];
        foreach (\Saan\Modules\Personnel\Staff::directory() as $p) {
            if (self::key($p[self::groupField($hub)] ?? null) !== $group) continue;
            $rows[] = ['name' => (string) $p['name'], 'position' => $p['position'], 'photo_url' => $p['photo_url'], 'order' => (int) $p['order']];
        }
        usort($rows, fn ($a, $b) => [self::isHeadPosition($a['position'], $hub) ? 0 : 1, $a['order'], $a['name']] <=> [self::isHeadPosition($b['position'], $hub) ? 0 : 1, $b['order'], $b['name']]);
        $heads = array_values(array_filter($rows, fn ($r) => self::isHeadPosition($r['position'], $hub)));
        $members = array_values(array_filter($rows, fn ($r) => !self::isHeadPosition($r['position'], $hub)));
        return ['heads' => $heads, 'members' => $members];
    }

    // ---------------------------------------------------------------- news of the group

    /** Published news (articles) tagged with the hub's tag or filed in its news category. No binding = no news. */
    public static function newsQuery(object $hub)
    {
        $q = DB::table('posts')->where('school_id', self::school())->whereNull('deleted_at')->where('status', 'published')->where('content_type', 'article')
            ->select('id', 'title', 'excerpt', 'slug', 'content_type', 'category', 'published_at', 'cover_media_id');
        $cat = $hub->news_category ?: null;
        $tag = $hub->news_tag ?: null;
        if ($cat === null && $tag === null) return $q->whereRaw('1 = 0');
        return $q->where(function ($w) use ($cat, $tag) {
            if ($cat !== null) $w->where('category', $cat);
            if ($tag !== null) $cat !== null ? $w->orWhereJsonContains('metadata->tags', $tag) : $w->whereJsonContains('metadata->tags', $tag);
        });
    }

    /** Order of the group's news: pinned first for a group page, strictly newest first for an association page (F5b). */
    public static function newsOrder($q, object $hub)
    {
        if (($hub->kind ?? 'work') !== 'assoc') $q->orderByDesc('is_pinned');
        return $q->orderByDesc('published_at')->orderByDesc('id');
    }

    public static function news(object $hub, int $limit): array
    {
        return self::newsOrder(self::newsQuery($hub), $hub)->limit(max(1, $limit))->get()->all();
    }

    // ---------------------------------------------------------------- association page (F5b)

    /** Office holders typed by the admin: [{name, role}] from the stored JSON, anything malformed dropped, at most COMMITTEE_MAX. @return list<array{name:string,role:string}> */
    public static function committee(object $hub): array
    {
        $a = json_decode((string) ($hub->committee ?? ''), true);
        $out = [];
        foreach (is_array($a) ? $a : [] as $r) {
            if (!is_array($r) || !is_string($r['name'] ?? null) || trim($r['name']) === '') continue;
            $out[] = ['name' => mb_substr(trim($r['name']), 0, 120), 'role' => mb_substr(trim((string) (is_string($r['role'] ?? null) ? $r['role'] : '')), 0, 120)];
            if (count($out) >= self::COMMITTEE_MAX) break;
        }
        return $out;
    }

    // ---------------------------------------------------------------- monthly reports (F5b)

    /** The category of a published hub that holds monthly reports (the first visible one), as [hub, category] or null. Used by the ITA source. @return array{0:object,1:object}|null */
    public static function monthlyCategory(?string $slug = null): ?array
    {
        $hubs = DB::table('cms_hubs')->where('school_id', self::school())->where('status', 'published')->when($slug !== null, fn ($q) => $q->where('slug', $slug))->orderBy('sort')->orderBy('id')->get();
        $visible = array_flip(self::visibleCategoryIds());
        foreach ($hubs as $h) {
            $c = DB::table('cms_hub_categories')->where('school_id', self::school())->where('hub_id', $h->id)->where('rule', 'monthly')->orderBy('sort')->orderBy('id')->get()->first(fn ($x) => isset($visible[(int) $x->id]));
            if ($c) return [$h, $c];
        }
        return null;
    }

    /**
     * One fiscal year (Oct-Sep) of a monthly category for the public list: the 12 months in fiscal order, each with its published document or null
     * (shown as "not published yet", never as a dead link). Filters: fy (Buddhist era, default = newest fiscal year with a document, else the current one), month 1-12.
     * @return array{fy:int,fys:list<int>,month:?int,rows:list<array{month:int,year:int,label:string,doc:?object}>,published:int}
     */
    public static function monthly(object $hub, object $cat, array $f = []): array
    {
        $docs = self::documents($hub)->where('d.category_id', (int) $cat->id)->whereNotNull('d.period_month')->whereNotNull('d.period_year')->orderBy('d.id')->limit(1200)->get();
        $by = [];
        foreach ($docs as $d) $by[Fiscal::ofMonth((int) $d->period_month, (int) $d->period_year)][(int) $d->period_month] = $d; // a later upload of the same month wins
        $have = array_keys($by);
        $cur = Fiscal::current();
        $fy = (int) ($f['fy'] ?? 0);
        [$lo, $hi] = Fiscal::reportFiscalYears();
        $fys = array_merge($have, [$cur], $fy >= $lo && $fy <= $hi ? [$fy] : []); // years that have a document, this year, and a requested year inside the allowed range
        $fys = array_values(array_unique($fys));
        rsort($fys);
        if (!in_array($fy, $fys, true)) $fy = $have ? max($have) : $cur;
        $month = isset($f['month']) && (int) $f['month'] >= 1 && (int) $f['month'] <= 12 ? (int) $f['month'] : null;
        $rows = [];
        foreach (Fiscal::months($fy) as [$m, $y]) {
            if ($month !== null && $m !== $month) continue;
            $rows[] = ['month' => $m, 'year' => $y, 'label' => Fiscal::MONTHS_TH[$m].' '.$y, 'doc' => $by[$fy][$m] ?? null];
        }
        return ['fy' => $fy, 'fys' => $fys, 'month' => $month, 'rows' => $rows, 'published' => count(array_filter($rows, fn ($r) => $r['doc'] !== null))];
    }

    /** "เดือน ปี" label of a document row that carries a period, '' otherwise. */
    public static function periodLabel(object $d): string
    {
        return isset($d->period_month, $d->period_year) ? Fiscal::MONTHS_TH[(int) $d->period_month].' '.(int) $d->period_year : '';
    }

    // ---------------------------------------------------------------- permissions

    /** Personnel profile (position, work_group, department) of the account's teacher, only while that teacher is active (same filter as the public directory); null otherwise. */
    public static function headProfile(?object $user): ?object
    {
        if (!$user || empty($user->teacher_id) || !self::personnelReady()) return null;
        return \Saan\Modules\Personnel\Support::activeTeachers()->join('per_profiles as p', fn ($j) => $j->on('p.teacher_id', '=', 'teachers.id')->where('p.school_id', '=', self::school()))
            ->where('teachers.id', $user->teacher_id)->first(['p.position', 'p.work_group', 'p.department']);
    }

    /**
     * The head of the hub's group, found in personnel: the signed-in account is linked to an ACTIVE teacher (users.teacher_id) whose position is
     * "หัวหน้ากลุ่มงาน..." (work hub) or "หัวหน้ากลุ่มสาระ..." (subject hub) and whose work_group (work hub) or department (subject hub) is the hub's group name.
     */
    public static function isHead(?object $user, object $hub): bool
    {
        return self::headMatches($hub, self::headProfile($user));
    }

    /** Admin: every hub. Head of the group: that hub only. Everyone else reads. */
    public static function canManage(?object $user, object $hub): bool
    {
        if (!$user || (int) $user->school_id !== self::school()) return false;
        return $user->role === 'admin' || self::isHead($user, $hub);
    }
}
