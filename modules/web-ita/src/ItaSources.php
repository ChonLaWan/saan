<?php

namespace Saan\Modules\WebIta;

use App\Domain\Cms\SiteSettings;
use App\Http\Controllers\SchoolWebsiteController;
use App\Support\Fiscal;
use App\Support\Modules\ModuleRegistry;
use App\Support\WebsiteView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic sources of ITA items ("ลิงก์อัตโนมัติจากระบบ"). An item stores only a source key and a few options; the link and the evidence are computed
 * here each time the page is drawn, from tables that exist on BOTH editions (published posts, categories, published hubs, the personnel directory and the
 * published complaint reports), so the public edition resolves exactly what the internal one does from the projected rows. The link is a site-relative
 * path (the view wraps it in url()), never a host, and always a PUBLIC page: an internal route can never come out of here.
 *
 * resolve() returns ['ok' => bool, 'path' => ?string, 'evidence' => string, 'reason' => string, 'extra' => list<array{title,path}>].
 * ok = false means the source has no data for that fiscal year: the item is shown as missing, not as a dead link.
 */
final class ItaSources
{
    public const KEYS = [
        'directory_executives' => 'ผู้บริหาร (ทำเนียบบุคลากรของเว็บไซต์)',
        'news' => 'ข่าวประชาสัมพันธ์ของปีงบประมาณ (เลือกหมวดได้)',
        'procurement' => 'ประกาศจัดซื้อจัดจ้างของปีงบประมาณ',
        'complaints_form' => 'แบบฟอร์มร้องเรียนออนไลน์',
        'complaints_channel' => 'ช่องทางร้องเรียน พร้อมรายงานสรุปของปีงบประมาณ (ถ้าเผยแพร่แล้ว)',
        'complaints_report' => 'รายงานสรุปเรื่องร้องเรียนของปีงบประมาณที่เผยแพร่แล้ว',
        'contact' => 'หน้าติดต่อโรงเรียน',
        'page' => 'หน้าเนื้อหาของเว็บไซต์ (ระบุ slug)',
        'hub' => 'หน้ากลุ่มงาน (ระบุ slug หรือใช้กลุ่มที่ชื่อมีคำว่า "งบประมาณ")',
        'finance_report' => 'งบทดลองรายเดือนของปีงบประมาณ (ระบุ slug ของหน้ารายงานการเงิน หรือใช้หน้าแรกที่มีหมวดรายเดือน)',
    ];
    /** Options each source accepts (anything else is dropped on save). */
    public const OPTS = ['news' => ['category'], 'procurement' => ['stage'], 'page' => ['slug'], 'hub' => ['slug'], 'finance_report' => ['slug']];
    /** Procurement stages (metadata.stage of a procurement post) an item can be limited to; "results" = the announced outcome (award and contract). The same words filter /portal/procurement?stage=. */
    public const STAGES = ['results' => ['label' => 'ประกาศผู้ชนะและสัญญา', 'in' => ['award', 'contract']], 'tor' => ['label' => 'ร่างขอบเขตงาน (TOR)', 'in' => ['tor']],
        'invitation' => ['label' => 'ประกาศเชิญชวน', 'in' => ['invitation']], 'award' => ['label' => 'ประกาศผู้ชนะ', 'in' => ['award']], 'contract' => ['label' => 'สัญญา', 'in' => ['contract']],
        'cancelled' => ['label' => 'ยกเลิก', 'in' => ['cancelled']], 'information' => ['label' => 'ข้อมูลทั่วไป', 'in' => ['information']]];
    private const SLUG = '/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/D';
    /** Per-request memo of resolve(): only alive inside scoped(), so a long-lived worker never serves stale data. */
    private static ?array $memo = null;

    /** Runs $fn with resolve() memoised (several items using the same source cost one lookup). Nested calls share the outer memo. */
    public static function scoped(callable $fn): mixed
    {
        if (self::$memo !== null) return $fn();
        self::$memo = [];
        try { return $fn(); } finally { self::$memo = null; }
    }

    /** Options of a source reduced to what it accepts and to safe slugs; null when there is none. */
    public static function cleanOpts(?string $key, mixed $opts): ?array
    {
        if (!$key || !isset(self::OPTS[$key]) || !is_array($opts)) return null;
        $out = [];
        foreach (self::OPTS[$key] as $k) if (isset($opts[$k]) && is_string($opts[$k]) && $opts[$k] !== '' && preg_match(self::SLUG, $opts[$k]) && strlen($opts[$k]) <= 80 && ($k !== 'stage' || isset(self::STAGES[$opts[$k]]))) $out[$k] = $opts[$k];
        return $out ?: null;
    }

    public static function parseOpts(?string $json): ?array
    {
        $a = $json ? json_decode($json, true) : null;
        return is_array($a) ? $a : null;
    }

    public static function resolve(string $key, ?array $opts, int $fy): array
    {
        $opts ??= [];
        if (self::$memo === null) return self::compute($key, $opts, $fy);
        return self::$memo[$key.'|'.json_encode($opts).'|'.$fy] ??= self::compute($key, $opts, $fy);
    }

    private static function compute(string $key, array $opts, int $fy): array
    {
        return match ($key) {
            'directory_executives' => self::executives(),
            'news' => self::posts('article', 'news', 'ข่าว', $fy, $opts['category'] ?? null),
            'procurement' => self::posts('procurement', 'procurement', 'จัดซื้อจัดจ้าง', $fy, null, $opts['stage'] ?? null),
            'complaints_form' => self::complaintsForm(),
            'complaints_channel' => self::complaintsChannel($fy),
            'complaints_report' => self::complaintsReport($fy),
            'contact' => self::contact(),
            'page' => self::page($opts['slug'] ?? null),
            'hub' => self::hub($opts['slug'] ?? null),
            'finance_report' => self::financeReport($opts['slug'] ?? null, $fy),
            default => self::no('แหล่งข้อมูลอัตโนมัติ "'.$key.'" ไม่รู้จัก'),
        };
    }

    private static function school(): int
    {
        return (int) config('school.school_id');
    }

    /** yearly = the data of this source is tied to a fiscal year (the public page then says "in this fiscal year" when there is none). */
    private static function ok(string $path, string $evidence, array $extra = [], bool $yearly = false): array
    {
        return ['ok' => true, 'path' => $path, 'evidence' => $evidence, 'reason' => '', 'extra' => $extra, 'yearly' => $yearly];
    }

    private static function no(string $reason, bool $yearly = false): array
    {
        return ['ok' => false, 'path' => null, 'evidence' => '', 'reason' => $reason, 'extra' => [], 'yearly' => $yearly];
    }

    private static function active(string $module): bool
    {
        return app(ModuleRegistry::class)->active($module);
    }

    /** Published posts of one content type in the fiscal year: the same rows the public list page shows with ?fy=. */
    private static function posts(string $type, string $route, string $label, int $fy, ?string $category, ?string $stage = null): array
    {
        $q = DB::table('posts')->where('school_id', self::school())->whereNull('deleted_at')->where('status', 'published')->where('content_type', $type);
        [$from, $to] = Fiscal::range($fy);
        $q->where('published_at', '>=', $from)->where('published_at', '<', $to);
        $catLabel = null;
        if ($category !== null) {
            $catLabel = DB::table('cms_categories')->where('school_id', self::school())->where('slug', $category)->value('label');
            if ($catLabel === null) return self::no('ไม่พบหมวด "'.$category.'" ในเว็บไซต์');
            $q->where('category', $category);
        }
        $scope = $catLabel !== null ? ' หมวด '.$catLabel : '';
        if ($stage !== null) {
            if (!isset(self::STAGES[$stage])) return self::no('ขั้นตอนจัดซื้อจัดจ้าง "'.$stage.'" ไม่รู้จัก');
            $q->whereIn('metadata->stage', self::STAGES[$stage]['in']);
            $scope .= ' '.self::STAGES[$stage]['label'];
        }
        $n = (int) $q->count();
        if ($n === 0) return self::no('ไม่มี'.$label.$scope.'ที่เผยแพร่ในปีงบประมาณ '.$fy, true);
        $query = ['fy' => $fy] + ($category !== null ? ['category' => $category] : []) + ($stage !== null ? ['stage' => $stage] : []);
        return self::ok('/portal/'.$route.'?'.http_build_query($query), $label.$scope.' '.$n.' รายการ ในปีงบ '.$fy, [], true);
    }

    /** People shown by the staff page of this site: the personnel register when the site is set to use it, otherwise the hand-written person posts. */
    private static function executives(): array
    {
        $n = 0;
        $site = app(SiteSettings::class)->read(self::school())['published'] ?? [];
        if (($site['directory_source'] ?? 'manual') === 'personnel' && self::active('personnel') && Schema::hasTable('per_profiles')) {
            foreach (\Saan\Modules\Personnel\Staff::directory() as $p) if (WebsiteView::isExecutive((string) ($p['position'] ?? ''))) $n++;
        } else {
            foreach (DB::table('posts')->where('school_id', self::school())->whereNull('deleted_at')->where('status', 'published')->where('content_type', 'person')->limit(1000)->pluck('metadata') as $m) {
                $meta = $m ? json_decode((string) $m, true) : [];
                if (is_array($meta) && WebsiteView::isExecutive((string) ($meta['position'] ?? ''))) $n++;
            }
        }
        if ($n === 0) return self::no('ยังไม่มีข้อมูลผู้บริหารในหน้าบุคลากรของเว็บไซต์ (ตำแหน่งขึ้นต้นด้วย ผู้อำนวยการ รองผู้อำนวยการ หรือ รักษาการ/รักษาราชการแทน)');
        // the staff page puts id="executives" on its first executive block (same rule, WebsiteView::isExecutive), so the link lands on them
        return self::ok('/portal/people#executives', 'ผู้บริหาร '.$n.' คน ในหน้าบุคลากร');
    }

    private static function complaintsForm(): array
    {
        if (!self::active('complaints')) return self::no('โมดูลร้องเรียนและรับเรื่องยังไม่เปิดใช้งาน');
        return self::ok('/portal/complaints', 'แบบฟอร์มร้องเรียนออนไลน์เปิดใช้งาน', [['title' => 'ตรวจสอบสถานะเรื่องที่แจ้งไว้', 'path' => '/portal/complaints/status']]);
    }

    private static function reportOf(int $fy): ?array
    {
        return self::active('complaints') && class_exists(\Saan\Modules\Complaints\Reports::class) ? \Saan\Modules\Complaints\Reports::find($fy) : null;
    }

    private static function reportEvidence(int $fy, array $r): string
    {
        return 'รายงานเรื่องร้องเรียนปีงบ '.$fy.' เผยแพร่ '.substr((string) $r['published_at'], 0, 10);
    }

    private static function complaintsChannel(int $fy): array
    {
        $f = self::complaintsForm();
        if (!$f['ok']) return $f;
        // O14 is the yearly statistic: a form alone is not evidence for that fiscal year
        $r = self::reportOf($fy);
        if (!$r) return self::no('ยังไม่เผยแพร่รายงานเรื่องร้องเรียนปีงบประมาณ '.$fy, true);
        $f['extra'][] = ['title' => 'รายงานสรุปเรื่องร้องเรียนปีงบประมาณ '.$fy, 'path' => '/portal/complaints/reports/'.$fy];
        $f['extra'][] = ['title' => 'ดาวน์โหลดรายงานปีงบประมาณ '.$fy.' (CSV)', 'path' => '/portal/complaints/reports/'.$fy.'.csv'];
        $f['evidence'] .= ' และ'.self::reportEvidence($fy, $r);
        $f['yearly'] = true;
        return $f;
    }

    private static function complaintsReport(int $fy): array
    {
        if (!self::active('complaints')) return self::no('โมดูลร้องเรียนและรับเรื่องยังไม่เปิดใช้งาน');
        $r = self::reportOf($fy);
        if (!$r) return self::no('ยังไม่เผยแพร่รายงานเรื่องร้องเรียนปีงบประมาณ '.$fy, true);
        return self::ok('/portal/complaints/reports/'.$fy, self::reportEvidence($fy, $r), [['title' => 'ดาวน์โหลดรายงานปีงบประมาณ '.$fy.' (CSV)', 'path' => '/portal/complaints/reports/'.$fy.'.csv']], true);
    }

    private static function contact(): array
    {
        $ctx = app(SchoolWebsiteController::class)->context();
        $has = fn ($v) => trim((string) $v) !== '';
        if (!$has($ctx['theme']['address'] ?? '') && !$has($ctx['theme']['phone'] ?? '') && !$has($ctx['site']['email'] ?? '')) return self::no('ยังไม่ได้กรอกที่อยู่ โทรศัพท์ หรืออีเมลของโรงเรียนในการตั้งค่าเว็บไซต์');
        return self::ok('/portal/contact', 'หน้าติดต่อโรงเรียน (ที่อยู่ โทรศัพท์ อีเมล และแบบฟอร์มข้อความ)');
    }

    private static function page(?string $slug): array
    {
        if ($slug === null) return self::no('ยังไม่ได้ระบุ slug ของหน้าเนื้อหา');
        $p = DB::table('posts')->where('school_id', self::school())->whereNull('deleted_at')->where('status', 'published')->where('content_type', 'page')->where('slug', $slug)->first(['title']);
        if (!$p) return self::no('ไม่พบหน้าเนื้อหาที่เผยแพร่ slug "'.$slug.'"');
        return self::ok('/portal/pages/'.rawurlencode($slug), 'หน้า "'.$p->title.'"');
    }

    /** Monthly trial-balance reports (F5b) of the fiscal year: the months that have a published document, linked to the list filtered by that fiscal year. */
    private static function financeReport(?string $slug, int $fy): array
    {
        if (!self::active('web-cms') || !Schema::hasTable('cms_hubs') || !Schema::hasColumn('cms_hub_categories', 'rule')) return self::no('โมดูลหน้ากลุ่มงานยังไม่เปิดใช้งาน');
        $found = \Saan\Modules\WebCms\Hubs::monthlyCategory($slug);
        if (!$found && $slug === null) return self::hub(null); // no trial-balance page yet: the budget group page, as before
        if (!$found) return self::no($slug !== null ? 'ไม่พบหมวดงบทดลองรายเดือนในหน้า slug "'.$slug.'" ที่เผยแพร่' : 'ยังไม่มีหน้ารายงานการเงินที่เผยแพร่พร้อมหมวดงบทดลองรายเดือน', true);
        [$hub, $cat] = $found;
        $listed = \Saan\Modules\WebCms\Hubs::monthly($hub, $cat, ['fy' => $fy]); // an out-of-range year falls back to another one, which is not this fiscal year
        $n = $listed['published'];
        if ($listed['fy'] !== $fy || $n === 0) return self::no('ยังไม่เผยแพร่งบทดลองรายเดือนของปีงบประมาณ '.$fy, true);
        return self::ok('/portal/groups/'.$hub->slug.'/docs/'.$cat->id.'?fy='.$fy, 'งบทดลอง '.$n.' เดือน ในปีงบ '.$fy, [], true);
    }

    private static function hub(?string $slug): array
    {
        if (!self::active('web-cms') || !Schema::hasTable('cms_hubs')) return self::no('โมดูลหน้ากลุ่มงานยังไม่เปิดใช้งาน');
        $q = DB::table('cms_hubs')->where('school_id', self::school())->where('status', 'published')->orderBy('sort')->orderBy('id');
        $hub = $slug !== null ? $q->where('slug', $slug)->first() : $q->where('title', 'like', '%งบประมาณ%')->first();
        if (!$hub) return self::no($slug !== null ? 'ไม่พบหน้ากลุ่มงานที่เผยแพร่ slug "'.$slug.'"' : 'ยังไม่มีหน้ากลุ่มงานที่ชื่อมีคำว่า "งบประมาณ" เผยแพร่อยู่');
        $docs = (int) \Saan\Modules\WebCms\Hubs::documents($hub)->count();
        if ($docs === 0) return self::no('หน้ากลุ่มงาน "'.$hub->title.'" ยังไม่มีเอกสารที่เผยแพร่');
        return self::ok('/portal/groups/'.$hub->slug, 'หน้ากลุ่มงาน "'.$hub->title.'" มีเอกสาร '.$docs.' รายการ');
    }
}
