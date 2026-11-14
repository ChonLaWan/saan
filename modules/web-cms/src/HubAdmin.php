<?php

namespace Saan\Modules\WebCms;

use App\Support\Fiscal;
use App\Support\Saan\DbError;
use App\Support\Saan\Mutation;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * F4 internal admin: hubs, categories, documents. Every write goes through App\Support\Saan\Mutation (school scoped, versioned, audited).
 * Permission is checked by the controller with Hubs::canManage (admin, or the head of the hub's group); creating and deleting a hub and changing
 * its group binding or address are admin only. Reading is open to the staff roles the route allows.
 */
final class HubAdmin
{
    /** Hub columns every manager may change. */
    public const MANAGER_FIELDS = ['title', 'description', 'news_category', 'news_tag', 'news_limit', 'box_limit', 'status', 'internal_note', 'contact', 'committee'];
    /** Hub columns only the admin may change. */
    public const ADMIN_FIELDS = ['slug', 'kind', 'group_name', 'sort'];

    private static function unique(\Closure $fn, string $message): mixed
    {
        try { return $fn(); }
        catch (QueryException $e) { if (DbError::isUnique($e)) abort(409, $message); throw $e; }
    }

    // ---------------------------------------------------------------- presenting

    public static function presentHub(object $h, bool $manage): array
    {
        return ['id' => (int) $h->id, 'slug' => $h->slug, 'title' => $h->title, 'kind' => $h->kind, 'kind_label' => Hubs::KINDS[$h->kind] ?? $h->kind, 'group_name' => $h->group_name,
            'description' => $h->description, 'news_category' => $h->news_category, 'news_tag' => $h->news_tag, 'news_limit' => (int) $h->news_limit, 'box_limit' => (int) $h->box_limit,
            'sort' => (int) $h->sort, 'status' => $h->status, 'internal_note' => $manage ? $h->internal_note : null, 'version' => (int) $h->version,
            'contact' => $h->contact ?? null, 'committee' => Hubs::committee($h)];
    }

    public static function presentCategory(object $c): array
    {
        return ['id' => (int) $c->id, 'hub_id' => (int) $c->hub_id, 'parent_id' => $c->parent_id === null ? null : (int) $c->parent_id, 'label' => $c->label, 'sort' => (int) $c->sort,
            'is_hidden' => (bool) $c->is_hidden, 'version' => (int) $c->version, 'rule' => $c->rule ?? null];
    }

    public static function presentDocument(object $d, bool $manage, array $counts = []): array
    {
        return ['id' => (int) $d->id, 'hub_id' => (int) $d->hub_id, 'category_id' => (int) $d->category_id, 'title' => $d->title, 'doc_date' => $d->doc_date, 'media_id' => $d->media_id === null ? null : (int) $d->media_id,
            'file_name' => $d->file_name, 'external_url' => $d->external_url, 'is_published' => (bool) $d->is_published, 'pinned' => (int) $d->sort > 0, 'note' => $manage ? ($d->note ?? null) : null,
            'type' => Hubs::typeLabel($d), 'bytes' => isset($d->bytes) ? (int) $d->bytes : null, 'version' => (int) $d->version,
            'downloads' => $counts[(int) $d->id]['total'] ?? 0, 'downloads_30d' => $counts[(int) $d->id]['last30'] ?? 0,
            'period_month' => isset($d->period_month) ? (int) $d->period_month : null, 'period_year' => isset($d->period_year) ? (int) $d->period_year : null,
            'period_label' => Hubs::periodLabel($d), 'superseded' => !empty($d->superseded_at)];
    }

    // ---------------------------------------------------------------- reading

    /** Every hub with its document count and total downloads, and what the viewer may do with it. */
    public static function overview(?object $user): array
    {
        $school = Hubs::school();
        $docs = DB::table('cms_hub_documents')->where('school_id', $school)->selectRaw('hub_id, count(*) as n, sum(case when is_published = 1 then 1 else 0 end) as pub')->groupBy('hub_id')->get()->keyBy('hub_id');
        $dl = DB::table('cms_hub_downloads as x')->join('cms_hub_documents as d', 'd.id', '=', 'x.document_id')->where('x.school_id', $school)->selectRaw('d.hub_id as hub_id, sum(x.n) as total')->groupBy('d.hub_id')->get()->keyBy('hub_id');
        $out = [];
        $mine = $user && (int) $user->school_id === $school; // same rule as Hubs::canManage, but the head's profile is read once, not once per hub
        $profile = $mine && $user->role !== 'admin' ? Hubs::headProfile($user) : null;
        foreach (DB::table('cms_hubs')->where('school_id', $school)->orderBy('kind')->orderBy('sort')->orderBy('id')->get() as $h) {
            $can = $mine && ($user->role === 'admin' || Hubs::headMatches($h, $profile));
            $out[] = self::presentHub($h, $can) + ['can_manage' => $can, 'documents' => (int) ($docs[$h->id]->n ?? 0), 'documents_published' => (int) ($docs[$h->id]->pub ?? 0), 'downloads' => (int) ($dl[$h->id]->total ?? 0)];
        }
        return ['data' => $out, 'can_create' => $user && $user->role === 'admin' && (int) $user->school_id === $school, 'kinds' => Hubs::KINDS,
            'templates' => array_map(fn ($t) => ['label' => $t['label'], 'slug' => $t['slug'], 'title' => $t['title']], Hubs::TEMPLATES)];
    }

    /** One hub with all its categories (hidden too) and a page of its documents (filters: category_id, q, status = published|draft, page). */
    public static function detail(object $hub, ?object $user, array $f): array
    {
        $school = Hubs::school();
        $manage = Hubs::canManage($user, $hub);
        $cats = DB::table('cms_hub_categories')->where('school_id', $school)->where('hub_id', $hub->id)->orderBy('sort')->orderBy('id')->get();
        $perCat = DB::table('cms_hub_documents')->where('school_id', $school)->where('hub_id', $hub->id)->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');
        $q = DB::table('cms_hub_documents as d')->leftJoin('cms_media as m', 'm.id', '=', 'd.media_id')->where('d.school_id', $school)->where('d.hub_id', $hub->id)
            ->select('d.*', 'm.mime as mime', 'm.bytes as bytes');
        if (!empty($f['category_id'])) {
            $ids = $cats->filter(fn ($c) => (int) $c->id === (int) $f['category_id'] || (int) $c->parent_id === (int) $f['category_id'])->pluck('id')->all();
            $q->whereIn('d.category_id', $ids ?: [0]);
        }
        if (!$manage) { // drafts (and everything of an unpublished hub) are listed only to admins and the head of the group
            $q->where('d.is_published', 1);
            if ($hub->status !== 'published') $q->whereRaw('1 = 0');
            $perCat = DB::table('cms_hub_documents')->where('school_id', $school)->where('hub_id', $hub->id)->where('is_published', 1)->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');
        }
        if (($f['status'] ?? '') === 'published') $q->where('d.is_published', 1);
        if (($f['status'] ?? '') === 'draft') $q->where('d.is_published', 0);
        if (isset($f['q']) && trim((string) $f['q']) !== '') $q->where('d.title', 'like', Hubs::like((string) $f['q']));
        $total = (clone $q)->count('d.id');
        $per = 30;
        $page = max(1, (int) ($f['page'] ?? 1));
        $rows = $q->orderByDesc('d.sort')->orderByDesc('d.doc_date')->orderByDesc('d.id')->forPage($page, $per)->get();
        $counts = Downloads::totals($rows->pluck('id')->map('intval')->all());
        $staff = Hubs::staff($hub);
        return ['hub' => self::presentHub($hub, $manage), 'can' => ['manage' => $manage, 'admin' => $user && $user->role === 'admin'],
            'categories' => $cats->map(fn ($c) => self::presentCategory($c) + ['documents' => (int) ($perCat[$c->id] ?? 0)])->all(),
            'documents' => $rows->map(fn ($d) => self::presentDocument($d, $manage, $counts))->all(), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per],
            'staff' => ['heads' => count($staff['heads']), 'members' => count($staff['members'])], 'news' => (int) Hubs::newsQuery($hub)->count(),
            'news_categories' => DB::table('cms_categories')->where('school_id', $school)->orderBy('id')->pluck('label', 'slug')->all(), 'kinds' => Hubs::KINDS, 'rules' => Hubs::RULES,
            'months' => Fiscal::MONTHS_TH,
            'public_url' => $hub->status === 'published' ? url('/portal/groups/'.$hub->slug) : null, 'preview_url' => url('/hub-preview/'.$hub->id)];
    }

    /** Document rows with the media columns the presenter needs (type, size), in the order of $ids. @return list<object> */
    public static function rows(array $ids): array
    {
        $rows = DB::table('cms_hub_documents as d')->leftJoin('cms_media as m', 'm.id', '=', 'd.media_id')->whereIn('d.id', $ids ?: [0])->select('d.*', 'm.mime as mime', 'm.bytes as bytes')->get()->keyBy('id');
        return array_values(array_filter(array_map(fn ($i) => $rows[$i] ?? null, $ids)));
    }

    // ---------------------------------------------------------------- hubs

    /** Creates a hub (admin) with the default document categories. */
    public static function createHub(array $d): object
    {
        $school = Hubs::school();
        abort_if(DB::table('cms_hubs')->where('school_id', $school)->where('slug', $d['slug'])->exists(), 409, 'ที่อยู่หน้านี้ถูกใช้แล้ว');
        return self::unique(fn () => DB::transaction(function () use ($d) {
            $hub = Mutation::insert('cms_hubs', array_intersect_key($d, array_flip(array_merge(self::MANAGER_FIELDS, self::ADMIN_FIELDS))) + ['status' => 'draft'], 'hub.created', ['slug' => $d['slug']]);
            self::seedCategories($hub, self::defaultCategories((string) ($d['kind'] ?? 'work')));
            return $hub;
        }), 'ที่อยู่หน้านี้ถูกใช้แล้ว');
    }

    /** Categories a new page of this kind starts with: [[label, rule], ...]. */
    public static function defaultCategories(string $kind): array
    {
        return match ($kind) {
            'assoc' => Hubs::TEMPLATES['association']['categories'],
            'finance' => Hubs::TEMPLATES['trial_balance']['categories'],
            default => array_map(fn ($l) => [$l, null], Hubs::DEFAULT_CATEGORIES),
        };
    }

    private static function seedCategories(object $hub, array $cats): void
    {
        foreach ($cats as $i => [$label, $rule]) {
            Mutation::insert('cms_hub_categories', ['hub_id' => $hub->id, 'parent_id' => null, 'label' => $label, 'sort' => ($i + 1) * 10, 'is_hidden' => false, 'rule' => $rule], 'hub.category.created', ['hub_id' => (int) $hub->id, 'label' => $label]);
        }
    }

    /**
     * Creates a page from a ready template (admin): the page (draft unless told otherwise) with its document categories and, for an association, its own
     * news category (cms_categories) already bound. Nothing real is seeded: the about text, committee and contact come from the admin.
     */
    public static function createFromTemplate(array $d): object
    {
        $t = Hubs::TEMPLATES[$d['template'] ?? ''] ?? abort(422, 'ไม่รู้จักเทมเพลตนี้');
        $slug = (string) ($d['slug'] ?? $t['slug']);
        $title = trim((string) ($d['title'] ?? '')) !== '' ? trim((string) $d['title']) : $t['title'];
        abort_if(DB::table('cms_hubs')->where('school_id', Hubs::school())->where('slug', $slug)->exists(), 409, 'ที่อยู่หน้านี้ถูกใช้แล้ว');
        $fields = array_intersect_key($d, array_flip(['description', 'contact', 'committee', 'status']));
        return self::unique(fn () => DB::transaction(function () use ($t, $slug, $title, $fields, $d) {
            $news = $t['news'] ? self::newsCategory($slug, $title) : null;
            $hub = Mutation::insert('cms_hubs', $fields + ['slug' => $slug, 'title' => $title, 'kind' => $t['kind'], 'news_category' => $news, 'status' => 'draft'], 'hub.created', ['slug' => $slug, 'template' => $d['template']]);
            self::seedCategories($hub, $t['categories']);
            return $hub;
        }), 'ที่อยู่หน้านี้ถูกใช้แล้ว');
    }

    /** A news category (cms_categories) owned by one association page: slug "<page>-news" (letters, digits, -; at most 30), numbered when taken. Returns the slug. */
    private static function newsCategory(string $hubSlug, string $title): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($hubSlug)), '-');
        if ($base === '' || !ctype_alpha($base[0])) $base = 'n-'.$base;
        $base = rtrim(substr($base, 0, 21), '-').'-news';
        $slug = $base;
        for ($i = 2; DB::table('cms_categories')->where('school_id', Hubs::school())->where('slug', $slug)->exists(); $i++) $slug = $base.'-'.$i;
        $row = Mutation::insert('cms_categories', ['slug' => $slug, 'label' => mb_substr('ข่าว'.$title, 0, 80)], 'category.saved', ['slug' => $slug]);
        return (string) $row->slug;
    }

    /**
     * Removes the news category that a template made for this page (slug "<page>-news", label "ข่าว<title>") once the page no longer uses it: it carries the title of a page that
     * may never have been published. Kept when another page uses it, when any post is filed in it, or when it is not the one the template made.
     */
    private static function releaseNewsCategory(object $hub, ?string $slug): void
    {
        if ($slug === null || $slug === '' || !preg_match('/^[a-z][a-z0-9-]*-news(-\d+)?$/D', $slug)) return;
        $row = DB::table('cms_categories')->where('school_id', Hubs::school())->where('slug', $slug)->first();
        if (!$row || (string) $row->label !== mb_substr('ข่าว'.$hub->title, 0, 80)) return;
        if (DB::table('cms_hubs')->where('school_id', Hubs::school())->where('news_category', $slug)->where('id', '!=', $hub->id)->exists()) return;
        if (DB::table('posts')->where('school_id', Hubs::school())->where('category', $slug)->exists()) return;
        DB::table('cms_categories')->where('id', $row->id)->delete();
        Mutation::audit('category.deleted', 'cms_categories', (int) $row->id, ['slug' => $slug]);
    }

    public static function updateHub(object $hub, int $version, array $d, bool $admin): object
    {
        $allowed = $admin ? array_merge(self::MANAGER_FIELDS, self::ADMIN_FIELDS) : self::MANAGER_FIELDS;
        $ch = array_intersect_key($d, array_flip($allowed));
        abort_if($ch === [], 422, 'ไม่มีข้อมูลที่แก้ไขได้');
        if (isset($ch['slug']) && $ch['slug'] !== $hub->slug) abort_if(DB::table('cms_hubs')->where('school_id', Hubs::school())->where('slug', $ch['slug'])->where('id', '!=', $hub->id)->exists(), 409, 'ที่อยู่หน้านี้ถูกใช้แล้ว');
        $row = self::unique(fn () => Mutation::update('cms_hubs', (int) $hub->id, $version, $ch, 'hub.updated', ['fields' => array_keys($ch)]), 'ที่อยู่หน้านี้ถูกใช้แล้ว');
        if (array_key_exists('news_category', $ch) && $ch['news_category'] !== $hub->news_category) self::releaseNewsCategory($hub, $hub->news_category); // the page no longer points at its own news category
        return $row;
    }

    public static function deleteHub(object $hub, int $version): void
    {
        $media = DB::table('cms_hub_documents')->where('hub_id', $hub->id)->whereNotNull('media_id')->pluck('media_id')->map('intval')->all();
        $docs = DB::table('cms_hub_documents')->where('hub_id', $hub->id)->pluck('id')->map('intval')->all();
        DB::transaction(function () use ($hub, $version, $docs) {
            Mutation::delete('cms_hubs', (int) $hub->id, $version, 'hub.deleted'); // categories and documents go with it (foreign keys)
            self::releaseNewsCategory($hub, $hub->news_category); // the template's news category carries the title of the page
            foreach (array_chunk($docs, 500) as $c) DB::table('cms_hub_downloads')->where('school_id', Hubs::school())->whereIn('document_id', $c)->delete(); // counts have no foreign key (see the migration)
        });
        foreach ($media as $id) HubFiles::release($id);
    }

    // ---------------------------------------------------------------- categories

    public static function category(object $hub, int $id): object
    {
        return DB::table('cms_hub_categories')->where('school_id', Hubs::school())->where('hub_id', $hub->id)->where('id', $id)->first() ?? abort(404, 'ไม่พบหมวดเอกสาร');
    }

    public static function addCategory(object $hub, string $label, ?int $parentId, ?string $rule = null): object
    {
        abort_if($rule !== null && !isset(Hubs::RULES[$rule]), 422, 'กฎของหมวดไม่ถูกต้อง');
        if ($parentId !== null) {
            $p = self::category($hub, $parentId);
            abort_if($p->parent_id !== null, 422, 'หมวดย่อยมีได้ชั้นเดียว');
            abort_if($p->rule !== null || $rule !== null, 422, 'หมวดที่กำหนดกฎไฟล์ (PDF เท่านั้นหรือรายเดือน) ไม่มีหมวดย่อย');
        }
        $next = (int) DB::table('cms_hub_categories')->where('hub_id', $hub->id)->max('sort') + 10;
        return Mutation::insert('cms_hub_categories', ['hub_id' => $hub->id, 'parent_id' => $parentId, 'label' => $label, 'sort' => $next, 'is_hidden' => false, 'rule' => $rule], 'hub.category.created', ['hub_id' => (int) $hub->id, 'label' => $label]);
    }

    public static function updateCategory(object $hub, int $id, int $version, array $d): object
    {
        $cat = self::category($hub, $id);
        $ch = array_intersect_key($d, array_flip(['label', 'is_hidden']));
        if (array_key_exists('rule', $d) && ($d['rule'] ?: null) !== $cat->rule) {
            $rule = $d['rule'] ?: null;
            abort_if($rule !== null && !isset(Hubs::RULES[$rule]), 422, 'กฎของหมวดไม่ถูกต้อง');
            // a rule applies to files already in the category and to its sub-categories, so it can only be changed while the category is empty
            abort_if(DB::table('cms_hub_documents')->where('category_id', $cat->id)->exists() || DB::table('cms_hub_categories')->where('parent_id', $cat->id)->exists() || ($rule !== null && $cat->parent_id !== null), 422, 'เปลี่ยนกฎไฟล์ได้เฉพาะหมวดหลักที่ยังไม่มีเอกสารและหมวดย่อย');
            $ch['rule'] = $rule;
        }
        abort_if($ch === [], 422, 'ไม่มีข้อมูลที่แก้ไขได้');
        return Mutation::update('cms_hub_categories', (int) $cat->id, $version, $ch, 'hub.category.updated', ['hub_id' => (int) $hub->id, 'fields' => array_keys($ch)]);
    }

    public static function deleteCategory(object $hub, int $id, int $version): void
    {
        $cat = self::category($hub, $id);
        abort_if(DB::table('cms_hub_categories')->where('parent_id', $cat->id)->exists(), 422, 'ลบหมวดย่อยของหมวดนี้ก่อน');
        abort_if(DB::table('cms_hub_documents')->where('category_id', $cat->id)->exists(), 422, 'ย้ายหรือลบเอกสารในหมวดนี้ก่อน');
        Mutation::delete('cms_hub_categories', (int) $cat->id, $version, 'hub.category.deleted');
    }

    /** New order of the categories: $items = [{id, version}, ...] in the wanted order (all or some of the hub's categories). */
    public static function orderCategories(object $hub, array $items): void
    {
        DB::transaction(function () use ($hub, $items) {
            foreach (array_values($items) as $i => $it) {
                $cat = self::category($hub, (int) $it['id']);
                $sort = ($i + 1) * 10;
                if ((int) $cat->sort !== $sort) Mutation::update('cms_hub_categories', (int) $cat->id, (int) $it['version'], ['sort' => $sort], 'hub.category.reordered', ['hub_id' => (int) $hub->id]);
            }
        });
    }

    // ---------------------------------------------------------------- documents

    public static function document(object $hub, int $id): object
    {
        return DB::table('cms_hub_documents')->where('school_id', Hubs::school())->where('hub_id', $hub->id)->where('id', $id)->first() ?? abort(404, 'ไม่พบเอกสาร');
    }

    /**
     * Adds documents to a category: one per uploaded file (bulk, up to HubFiles::MAX_FILES; every file is checked before any is stored) or one external https link.
     * @param list<UploadedFile> $files
     * @return list<object>
     */
    public static function addDocuments(object $hub, array $d, array $files): array
    {
        $cat = self::category($hub, (int) $d['category_id']);
        $date = $d['doc_date'] ?? Downloads::today();
        $pub = (bool) ($d['is_published'] ?? true);
        $note = $d['note'] ?? null;
        if ($cat->rule !== null) return self::addRuledDocument($hub, $cat, $d, $files, $date, $pub, $note);
        if (!$files) {
            abort_unless(HubFiles::validLink($d['external_url'] ?? null), 422, 'ลิงก์ต้องเป็น https:// ที่ถูกต้อง');
            abort_if(trim((string) ($d['title'] ?? '')) === '', 422, 'กรุณาใส่ชื่อเอกสาร');
            return [Mutation::insert('cms_hub_documents', ['hub_id' => $hub->id, 'category_id' => $cat->id, 'title' => trim((string) $d['title']), 'doc_date' => $date, 'media_id' => null, 'file_name' => null,
                'external_url' => HubFiles::normalizeLink($d['external_url']), 'is_published' => $pub, 'sort' => 0, 'note' => $note], 'hub.document.created', ['hub_id' => (int) $hub->id, 'link' => true])];
        }
        abort_if(count($files) > HubFiles::MAX_FILES, 422, 'อัปโหลดได้ครั้งละไม่เกิน '.HubFiles::MAX_FILES.' ไฟล์');
        $info = array_map(fn ($f) => HubFiles::inspect($f), $files); // 422 here stops everything before any byte is stored
        $made = [];
        $media = [];
        try {
            foreach ($files as $i => $f) {
                $s = HubFiles::store($f, $info[$i]);
                $media[] = $s['media_id'];
                $title = count($files) === 1 && trim((string) ($d['title'] ?? '')) !== '' ? trim((string) $d['title']) : $s['title'];
                $made[] = Mutation::insert('cms_hub_documents', ['hub_id' => $hub->id, 'category_id' => $cat->id, 'title' => $title, 'doc_date' => $date, 'media_id' => $s['media_id'], 'file_name' => $s['file_name'],
                    'external_url' => null, 'is_published' => $pub, 'sort' => 0, 'note' => $note], 'hub.document.created', ['hub_id' => (int) $hub->id, 'media_id' => $s['media_id']]);
            }
        } catch (\Throwable $e) {
            foreach ($made as $m) {
                try {
                    DB::table('cms_hub_documents')->where('id', $m->id)->delete();
                    Mutation::audit('hub.document.deleted', 'cms_hub_documents', (int) $m->id, ['hub_id' => (int) $hub->id, 'rollback' => true]); // pairs the hub.document.created of this batch
                } catch (\Throwable) {}
            }
            foreach ($media as $id) HubFiles::release($id);
            throw $e;
        }
        return $made;
    }

    // ---------------------------------------------------------------- F5b: PDF-only categories and monthly reports

    /** Month (1-12) and Buddhist-era year of a monthly report, from the request or the stored document; both are required and checked. @return array{0:int,1:int} */
    private static function period(array $d, ?object $doc = null): array
    {
        $m = $d['period_month'] ?? $doc?->period_month;
        $y = $d['period_year'] ?? $doc?->period_year;
        abort_if($m === null || $y === null || $m === '' || $y === '', 422, 'กรุณาเลือกเดือนและปี พ.ศ. ของรายงาน');
        abort_unless(is_numeric($m) && is_numeric($y) && (int) $m >= 1 && (int) $m <= 12 && (int) $y >= 2500 && (int) $y <= 2700, 422, 'เดือนหรือปี พ.ศ. ของรายงานไม่ถูกต้อง');
        // a period typed in the request must be a real past month inside the allowed years; one already stored (a legacy row) is only range checked so it can still be edited
        $typed = ($d['period_month'] ?? null) !== null || ($d['period_year'] ?? null) !== null;
        abort_if($typed && !Fiscal::reportMonthAllowed((int) $m, (int) $y), 422, 'เดือนและปี พ.ศ. ของรายงานต้องไม่เป็นอนาคต และย้อนหลังได้ไม่เกิน '.Fiscal::REPORT_SPAN.' ปี');
        return [(int) $m, (int) $y];
    }

    private static function periodName(object $cat, int $m, int $y): string
    {
        return $cat->label.' เดือน'.Fiscal::MONTHS_TH[$m].' '.$y;
    }

    /** Published documents of the same category and month that a new published one would duplicate. 409 unless $replace. @return list<object> */
    private static function sameMonth(object $cat, int $m, int $y, ?int $exceptId, bool $replace): array
    {
        $q = DB::table('cms_hub_documents')->where('school_id', Hubs::school())->where('category_id', $cat->id)->where('period_month', $m)->where('period_year', $y)->where('is_published', 1);
        if ($exceptId !== null) $q->where('id', '!=', $exceptId);
        // locking read: under InnoDB REPEATABLE READ a plain read would use the snapshot taken by an earlier read in this transaction (setPublished reads the
        // document first) and miss a same-month document another request committed while we waited for the category lock; FOR UPDATE reads the latest rows
        $old = $q->lockForUpdate()->get()->all();
        abort_if($old && !$replace, 409, 'มีรายงานเดือน'.Fiscal::MONTHS_TH[$m].' '.$y.' ที่เผยแพร่อยู่แล้ว เลือก "แทนที่ไฟล์เดิม" เพื่อเปลี่ยนไฟล์ (ไฟล์เดิมจะถูกถอนและเก็บไว้ภายในเท่านั้น) หรือถอนไฟล์เดิมก่อน');
        return $old;
    }

    /** Row lock on the category so the same-month check and the write that follows it run one request at a time. Call inside a transaction. */
    private static function lockCategory(object $cat): void
    {
        DB::table('cms_hub_categories')->where('id', $cat->id)->lockForUpdate()->first(['id']);
    }

    /** Withdraws the documents a newer file replaces: unpublished and marked, never deleted (the history stays internal; unpublished rows and their files never leave). */
    private static function supersede(object $hub, array $olds, ?int $byId): void
    {
        foreach ($olds as $o) Mutation::update('cms_hub_documents', (int) $o->id, (int) $o->version, ['is_published' => false, 'superseded_at' => now()->toDateTimeString()], 'hub.document.superseded', ['hub_id' => (int) $hub->id, 'by' => $byId]);
    }

    /** True when the stored file of a document is a PDF (used when a document is moved into a PDF-only category). */
    private static function storedPdf(object $doc): bool
    {
        return $doc->media_id !== null && DB::table('cms_media')->where('id', $doc->media_id)->where('school_id', Hubs::school())->value('mime') === 'application/pdf';
    }

    /**
     * Adds a document to a category with a file rule (F5b): rule pdf = PDF files only, rule monthly = exactly one PDF for one month and Buddhist-era year.
     * No external links in either: the page offers the file itself. A second published document for the same month is refused (409) unless
     * $d['replace'] is set, which withdraws the old one and keeps it internal. The type comes from the content (finfo) like every hub file.
     */
    private static function addRuledDocument(object $hub, object $cat, array $d, array $files, string $date, bool $pub, ?string $note): array
    {
        abort_if(!$files, 422, 'หมวดนี้รับเฉพาะไฟล์ PDF ไม่รับลิงก์ภายนอก กรุณาเลือกไฟล์ PDF');
        abort_if(count($files) > HubFiles::MAX_FILES, 422, 'อัปโหลดได้ครั้งละไม่เกิน '.HubFiles::MAX_FILES.' ไฟล์');
        $monthly = $cat->rule === 'monthly';
        abort_if($monthly && count($files) !== 1, 422, 'หมวดรายเดือนอัปโหลดได้ครั้งละ 1 ไฟล์ต่อ 1 เดือน');
        [$m, $y] = $monthly ? self::period($d) : [null, null];
        $info = array_map(fn ($f) => HubFiles::inspect($f), $files);
        foreach ($info as $i => $x) abort_unless($x['mime'] === 'application/pdf', 422, 'หมวดนี้รับเฉพาะไฟล์ PDF: '.mb_substr((string) $files[$i]->getClientOriginalName(), 0, 60));
        $olds = $monthly && $pub ? self::sameMonth($cat, $m, $y, null, (bool) ($d['replace'] ?? false)) : []; // refused before any byte is stored
        $made = [];
        $media = [];
        try {
            foreach ($files as $i => $f) {
                $s = HubFiles::store($f, $info[$i]);
                $media[] = $s['media_id'];
                $title = $monthly ? (trim((string) ($d['title'] ?? '')) !== '' ? trim((string) $d['title']) : self::periodName($cat, $m, $y))
                    : (count($files) === 1 && trim((string) ($d['title'] ?? '')) !== '' ? trim((string) $d['title']) : $s['title']);
                $made[] = DB::transaction(function () use ($hub, $cat, $d, $s, $title, $date, $pub, $note, $m, $y, $monthly) {
                    $olds = [];
                    if ($monthly) { // repeated under the category lock: the check above runs before the file is stored, this one decides
                        self::lockCategory($cat);
                        if ($pub) $olds = self::sameMonth($cat, $m, $y, null, (bool) ($d['replace'] ?? false));
                    }
                    $row = Mutation::insert('cms_hub_documents', ['hub_id' => $hub->id, 'category_id' => $cat->id, 'title' => $title, 'doc_date' => $date, 'media_id' => $s['media_id'], 'file_name' => $s['file_name'],
                        'external_url' => null, 'is_published' => $pub, 'sort' => 0, 'note' => $note, 'period_month' => $m, 'period_year' => $y], 'hub.document.created', ['hub_id' => (int) $hub->id, 'media_id' => $s['media_id']]);
                    self::supersede($hub, $olds, (int) $row->id);
                    return $row;
                });
            }
        } catch (\Throwable $e) {
            foreach ($made as $row) {
                try {
                    DB::table('cms_hub_documents')->where('id', $row->id)->delete();
                    Mutation::audit('hub.document.deleted', 'cms_hub_documents', (int) $row->id, ['hub_id' => (int) $hub->id, 'rollback' => true]);
                } catch (\Throwable) {}
            }
            foreach ($media as $id) HubFiles::release($id);
            throw $e;
        }
        return $made;
    }

    /** Edits a document; optional replacement file or link. The document always keeps exactly one target (file or https link). */
    public static function updateDocument(object $hub, int $id, int $version, array $d, ?UploadedFile $file): object
    {
        $doc = self::document($hub, $id);
        $ch = array_intersect_key($d, array_flip(['title', 'doc_date', 'is_published', 'note']));
        if (isset($d['category_id'])) $ch['category_id'] = (int) self::category($hub, (int) $d['category_id'])->id;
        if (array_key_exists('pinned', $d)) $ch['sort'] = $d['pinned'] ? 100 : 0;
        $cat = isset($ch['category_id']) ? self::category($hub, $ch['category_id']) : self::category($hub, (int) $doc->category_id);
        $olds = [];
        if ($cat->rule !== null) {
            abort_if(!$file && !empty($d['external_url']), 422, 'หมวดนี้รับเฉพาะไฟล์ PDF ไม่รับลิงก์ภายนอก');
            abort_if(!$file && !self::storedPdf($doc), 422, 'หมวดนี้รับเฉพาะไฟล์ PDF เอกสารนี้ไม่ใช่ไฟล์ PDF');
        }
        if ($cat->rule === 'monthly') {
            [$m, $y] = self::period($d, $doc);
            $ch['period_month'] = $m;
            $ch['period_year'] = $y;
            $willPublish = array_key_exists('is_published', $ch) ? (bool) $ch['is_published'] : (bool) $doc->is_published;
            if ($willPublish) $olds = self::sameMonth($cat, $m, $y, (int) $doc->id, (bool) ($d['replace'] ?? false));
        } elseif ($doc->period_month !== null) {
            $ch['period_month'] = null;
            $ch['period_year'] = null;
        }
        if (!empty($ch['is_published'])) $ch['superseded_at'] = null;
        $oldMedia = $doc->media_id === null ? null : (int) $doc->media_id;
        $newMedia = null;
        if ($file) {
            $info = HubFiles::inspect($file);
            abort_if($cat->rule !== null && $info['mime'] !== 'application/pdf', 422, 'หมวดนี้รับเฉพาะไฟล์ PDF');
            $s = HubFiles::store($file, $info);
            $newMedia = $s['media_id'];
            $ch += ['media_id' => $newMedia, 'file_name' => $s['file_name'], 'external_url' => null];
        } elseif (!empty($d['external_url'])) {
            abort_unless(HubFiles::validLink($d['external_url']), 422, 'ลิงก์ต้องเป็น https:// ที่ถูกต้อง');
            abort_if($oldMedia !== null && empty($d['remove_file']), 422, 'เอกสารนี้มีไฟล์อยู่แล้ว ติ๊ก "เปลี่ยนเป็นลิงก์" เพื่อแทนที่ด้วยลิงก์');
            $ch += ['external_url' => HubFiles::normalizeLink($d['external_url']), 'media_id' => null, 'file_name' => null];
        }
        abort_if($ch === [], 422, 'ไม่มีข้อมูลที่แก้ไขได้');
        // a new file for a monthly report never overwrites the old one: the old row is withdrawn and kept (file included), a new row carries the new file
        $keep = $cat->rule === 'monthly' && $newMedia !== null && $oldMedia !== null;
        $willPublish = array_key_exists('is_published', $ch) ? (bool) $ch['is_published'] : (bool) $doc->is_published;
        try {
            $row = DB::transaction(function () use ($doc, $version, $ch, $hub, $cat, $d, $keep, $willPublish) {
                $olds = [];
                if ($cat->rule === 'monthly') { // the same-month check is repeated under the category lock, so two requests cannot both publish the month
                    self::lockCategory($cat);
                    if ($willPublish) $olds = self::sameMonth($cat, (int) $ch['period_month'], (int) $ch['period_year'], (int) $doc->id, (bool) ($d['replace'] ?? false));
                }
                if ($keep) {
                    Mutation::update('cms_hub_documents', (int) $doc->id, $version, ['is_published' => false, 'superseded_at' => now()->toDateTimeString()], 'hub.document.superseded', ['hub_id' => (int) $hub->id, 'by' => null]);
                    $row = Mutation::insert('cms_hub_documents', ['hub_id' => $hub->id, 'category_id' => $ch['category_id'] ?? $doc->category_id, 'title' => $ch['title'] ?? $doc->title, 'doc_date' => $ch['doc_date'] ?? $doc->doc_date,
                        'media_id' => $ch['media_id'], 'file_name' => $ch['file_name'], 'external_url' => null, 'is_published' => $willPublish, 'sort' => $ch['sort'] ?? $doc->sort, 'note' => array_key_exists('note', $ch) ? $ch['note'] : $doc->note,
                        'period_month' => $ch['period_month'], 'period_year' => $ch['period_year']], 'hub.document.created', ['hub_id' => (int) $hub->id, 'media_id' => $ch['media_id'], 'replaces' => (int) $doc->id]);
                } else {
                    $row = Mutation::update('cms_hub_documents', (int) $doc->id, $version, $ch, 'hub.document.updated', ['hub_id' => (int) $hub->id, 'fields' => array_keys($ch)]);
                }
                self::supersede($hub, $olds, (int) $row->id);
                return $row;
            });
        } catch (\Throwable $e) {
            HubFiles::release($newMedia);
            throw $e;
        }
        if (!$keep && $oldMedia !== null && array_key_exists('media_id', $ch) && $ch['media_id'] !== $oldMedia) HubFiles::release($oldMedia);
        return $row;
    }

    public static function deleteDocument(object $hub, int $id, int $version): void
    {
        $doc = self::document($hub, $id);
        DB::transaction(function () use ($doc, $version) {
            Mutation::delete('cms_hub_documents', (int) $doc->id, $version, 'hub.document.deleted');
            DB::table('cms_hub_downloads')->where('school_id', Hubs::school())->where('document_id', $doc->id)->delete(); // counts have no foreign key (see the migration)
        });
        HubFiles::release($doc->media_id === null ? null : (int) $doc->media_id);
    }

    /**
     * Manual order inside one category: $items = [{id, version}] from first to last. The listed documents are pinned in that order
     * (sort 100 + position from the end), so they come before the unpinned ones, which stay newest first.
     */
    public static function orderDocuments(object $hub, int $categoryId, array $items): void
    {
        $cat = self::category($hub, $categoryId);
        DB::transaction(function () use ($hub, $cat, $items) {
            $n = count($items);
            foreach (array_values($items) as $i => $it) {
                $doc = self::document($hub, (int) $it['id']);
                abort_if((int) $doc->category_id !== (int) $cat->id, 422, 'เอกสารต้องอยู่ในหมวดเดียวกัน');
                $sort = 100 + ($n - $i);
                if ((int) $doc->sort !== $sort) Mutation::update('cms_hub_documents', (int) $doc->id, (int) $it['version'], ['sort' => $sort], 'hub.document.reordered', ['hub_id' => (int) $hub->id]);
            }
        });
    }

    /** Publishes or withdraws many documents at once: $items = [{id, version}]. */
    public static function setPublished(object $hub, array $items, bool $published): int
    {
        return DB::transaction(function () use ($hub, $items, $published) {
            $n = 0;
            foreach ($items as $it) {
                $doc = self::document($hub, (int) $it['id']);
                if ((bool) $doc->is_published === $published) continue;
                if ($published && $doc->period_month !== null) { // monthly report: a second published file for the same month is refused (replace it from the edit form instead)
                    $cat = self::category($hub, (int) $doc->category_id);
                    if ($cat->rule === 'monthly') {
                        self::lockCategory($cat);
                        self::sameMonth($cat, (int) $doc->period_month, (int) $doc->period_year, (int) $doc->id, false);
                    }
                }
                Mutation::update('cms_hub_documents', (int) $doc->id, (int) $it['version'], ['is_published' => $published] + ($published ? ['superseded_at' => null] : []), $published ? 'hub.document.published' : 'hub.document.unpublished', ['hub_id' => (int) $hub->id]);
                $n++;
            }
            return $n;
        });
    }
}
