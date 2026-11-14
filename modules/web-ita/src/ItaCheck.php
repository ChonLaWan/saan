<?php

namespace Saan\Modules\WebIta;

use App\Domain\Cms\SafeContent;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Edition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completeness check of the O1..O18 items of one fiscal year (admin page, JSON endpoint and saan:ita-check share this class).
 *
 * Status of one O item: complete (a valid manual link, file or text), auto-ok (an automatic source with data), missing (nothing, or an automatic
 * source with no data for that year) or link-broken (a manual link or file that points nowhere, or an unknown source). Internal links are checked by
 * resolving the route and the existence of the target rows; https links are only checked for syntax. Nothing here opens a network connection.
 */
final class ItaCheck
{
    public const COMPLETE = 'complete';
    public const AUTO_OK = 'auto-ok';
    public const MISSING = 'missing';
    public const BROKEN = 'link-broken';

    public const INTERNAL_LINK = 'ลิงก์ชี้ไปหน้าภายในที่ผู้ชมเปิดไม่ได้';

    /** @return array{fiscal_year:int,year_id:?int,year_status:?string,published:bool,ok:bool,summary:array<string,int>,items:list<array<string,mixed>>} */
    public static function run(int $fy, ?int $school = null): array
    {
        return ItaSources::scoped(fn () => self::compute($fy, $school));
    }

    private static function compute(int $fy, ?int $school): array
    {
        $school ??= (int) config('school.school_id');
        $year = DB::table('ita_years')->where('school_id', $school)->where('fiscal_year', $fy)->first();
        $topics = $year ? self::topics((int) $year->id) : [];
        $g = ItaCatalog::group($topics);
        $fix = fn () => '/cms#ita'.($year ? '/'.$year->id : '');
        $out = [];
        $sum = ['total' => 0, self::COMPLETE => 0, 'auto_ok' => 0, self::MISSING => 0, 'link_broken' => 0];
        foreach (ItaCatalog::ITEMS as $code => $def) {
            $entries = [];
            foreach ($g['items'][$code] as $i) $entries[] = self::entry($i, $fy, $school);
            if (!$year) { $status = self::MISSING; $reason = 'ยังไม่ได้สร้างข้อมูล ITA ปีงบประมาณ '.$fy; }
            elseif (!$g['seen'][$code]) { $status = self::MISSING; $reason = 'ยังไม่มีหัวข้อรหัส '.$code.' ในปีนี้'; }
            elseif (!$entries) { $status = self::MISSING; $reason = 'หัวข้อ '.$code.' ยังไม่มีรายการ'; }
            else [$status, $reason] = self::combine($entries);
            $first = collect($entries)->first(fn ($e) => $e['status'] === $status) ?? ($entries[0] ?? null);
            // O13/O14: the public page adds the complaints module's own links under such a topic when none of its items has an automatic source: the checker counts what the page shows
            if ($status === self::MISSING && $year && $g['seen'][$code] && ($link = self::complaintsTopicLink($topics, $code, $fy))) {
                $status = self::AUTO_OK;
                $reason = 'ลิงก์อัตโนมัติจากโมดูลร้องเรียน: '.$link['title'];
                $first = ['path' => (string) parse_url($link['url'], PHP_URL_PATH), 'evidence' => $link['title']];
            }
            $sum['total']++;
            $sum[str_replace('-', '_', $status)]++;
            $out[] = ['code' => $code, 'title' => $def['title'], 'status' => $status, 'reason' => $reason, 'path' => $first['path'] ?? null, 'evidence' => $first['evidence'] ?? '', 'fix_path' => $fix(), 'entries' => $entries];
        }
        // a draft year is not on the public site (and the publisher sends none of its rows): complete content is still not "ok" until it is published
        $published = $year && $year->status === 'published';
        return ['fiscal_year' => $fy, 'year_id' => $year ? (int) $year->id : null, 'year_status' => $year->status ?? null, 'published' => (bool) $published,
            'ok' => $published && $sum[self::MISSING] === 0 && $sum['link_broken'] === 0, 'summary' => $sum, 'items' => $out];
    }

    /** First link the complaints module would add under a topic of this O code (same rule as ItaController::public); null when none. @return array{title:string,url:string}|null */
    private static function complaintsTopicLink(array $topics, string $code, int $fy): ?array
    {
        if (!in_array($code, ['O13', 'O14'], true) || !class_exists(\Saan\Modules\Complaints\ItaLinks::class)) return null;
        foreach ($topics as $t) {
            if (array_filter($t['items'], fn ($i) => !empty($i['source']))) continue;
            $mine = ItaCatalog::code((string) $t['code']) === $code || array_filter($t['items'], fn ($i) => ItaCatalog::codeOfTitle((string) ($i['title'] ?? '')) === $code);
            if (!$mine) continue;
            $links = \Saan\Modules\Complaints\ItaLinks::forTopic($t, $fy);
            // O14 is the annual statistics: the form alone is not evidence, only that fiscal year's published report is
            if ($code === 'O14') $links = array_values(array_filter($links, fn ($l) => str_contains((string) parse_url($l['url'], PHP_URL_PATH), '/portal/complaints/reports/')));
            if ($links) return $links[0];
        }
        return null;
    }

    private static function topics(int $yearId): array
    {
        $topics = DB::table('ita_topics')->where('year_id', $yearId)->orderBy('sort')->orderBy('id')->get();
        $items = DB::table('ita_items')->whereIn('topic_id', $topics->pluck('id'))->orderBy('sort')->orderBy('id')->get()->groupBy('topic_id');
        return $topics->map(fn ($t) => ['code' => $t->code, 'items' => ($items[$t->id] ?? collect())->map(fn ($i) => (array) $i)->all()])->all();
    }

    /** Strict: a broken link wins, then no evidence at all is missing, otherwise the best kind of evidence (manual beats automatic). @return array{0:string,1:string} */
    private static function combine(array $entries): array
    {
        $broken = collect($entries)->first(fn ($e) => $e['status'] === self::BROKEN);
        if ($broken) return [self::BROKEN, $broken['reason']];
        $anyOk = collect($entries)->first(fn ($e) => in_array($e['status'], [self::COMPLETE, self::AUTO_OK], true));
        if (!$anyOk) return [self::MISSING, $entries[0]['reason']];
        $manual = collect($entries)->first(fn ($e) => $e['status'] === self::COMPLETE);
        $hit = $manual ?? $anyOk;
        return [$hit['status'], $hit['reason']];
    }

    /** One item row. Manual content wins over an automatic source (same rule as the public page). */
    private static function entry(array $i, int $fy, int $school): array
    {
        $base = ['title' => (string) $i['title'], 'path' => null, 'evidence' => ''];
        if (!empty($i['media_id'])) {
            $ok = Schema::hasTable('cms_media') && DB::table('cms_media')->where('school_id', $school)->where('id', (int) $i['media_id'])->exists();
            return array_replace($base, $ok ? ['status' => self::COMPLETE, 'reason' => 'แนบไฟล์จากคลังสื่อแล้ว', 'path' => '/media/'.(int) $i['media_id']] : ['status' => self::BROKEN, 'reason' => 'ไฟล์ที่แนบถูกลบหรือไม่พบในคลังสื่อ']);
        }
        $url = trim((string) ($i['url'] ?? ''));
        if ($url !== '') {
            $problem = self::linkProblem($url);
            return array_replace($base, $problem === null ? ['status' => self::COMPLETE, 'reason' => 'ใส่ลิงก์เองแล้ว', 'path' => str_starts_with($url, '/') ? $url : null] : ['status' => self::BROKEN, 'reason' => $problem]);
        }
        if (!empty($i['source'])) {
            $key = (string) $i['source'];
            if (!isset(ItaSources::KEYS[$key])) return array_replace($base, ['status' => self::BROKEN, 'reason' => 'แหล่งข้อมูลอัตโนมัติ "'.$key.'" ไม่รู้จัก']);
            $r = ItaSources::resolve($key, ItaSources::parseOpts($i['source_opts'] ?? null), $fy);
            return array_replace($base, $r['ok'] ? ['status' => self::AUTO_OK, 'reason' => 'ลิงก์อัตโนมัติ: '.$r['evidence'], 'path' => $r['path'], 'evidence' => $r['evidence']] : ['status' => self::MISSING, 'reason' => $r['reason']]);
        }
        if (trim((string) ($i['note'] ?? '')) !== '') return $base + ['status' => self::COMPLETE, 'reason' => 'ระบุเป็นข้อความแล้ว'];
        return $base + ['status' => self::MISSING, 'reason' => 'ยังไม่ได้ใส่ลิงก์ ไฟล์ ข้อความ หรือเลือกลิงก์อัตโนมัติ'];
    }

    /** True when a site path (not https, not a fragment) is outside the public site: the public edition answers 404 for it, a combined install sends guests to sign in. */
    public static function internalPath(string $url): bool
    {
        if (!str_starts_with($url, '/')) return false;
        return Edition::blockedInPublic((string) parse_url($url, PHP_URL_PATH));
    }

    /** null = fine. External https links: syntax only. Site paths: the route must exist and so must the row it opens, as a guest sees it. */
    public static function linkProblem(string $url): ?string
    {
        if (str_starts_with($url, 'https://')) return SafeContent::url($url) ? null : 'รูปแบบลิงก์ https ไม่ถูกต้อง';
        if (str_starts_with($url, '#')) return SafeContent::url($url) ? null : 'รูปแบบลิงก์ไม่ถูกต้อง';
        if (!SafeContent::url($url)) return 'ลิงก์ต้องเป็น https หรือเส้นทางในเว็บไซต์';
        if (self::internalPath($url)) return self::INTERNAL_LINK;
        return self::pathProblem($url);
    }

    private static function pathProblem(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $school = (int) config('school.school_id');
        $reg = app(ModuleRegistry::class);
        $has = fn (string $table, array $where) => Schema::hasTable($table) && DB::table($table)->where($where)->exists();
        $row = fn (bool $found, string $why) => $found ? null : $why;
        $off = fn (string $mod) => !$reg->active($mod) ? 'ปลายทางไม่พบ: โมดูล '.$mod.' ยังไม่เปิดใช้งาน' : null;
        $static = ['/portal', '/portal/directory', '/portal/contact', '/portal/search', '/sitemap.xml', '/portal/ita', '/portal/groups', '/portal/complaints', '/portal/complaints/status', '/portal/certificates/verify'];
        $path = $path === '' ? '/' : (strlen($path) > 1 ? rtrim($path, '/') : $path);
        if ($path === '/') return null;
        if (in_array($path, $static, true)) {
            $mod = match (true) { str_starts_with($path, '/portal/complaints') => 'complaints', $path === '/portal/groups' => 'web-cms', $path === '/portal/ita' => 'web-ita', $path === '/portal/certificates/verify' => 'surveys', default => null };
            return $mod ? $off($mod) : null;
        }
        if (preg_match('~^/portal/ita/(\d+)$~D', $path, $m)) return $row($has('ita_years', ['school_id' => $school, 'fiscal_year' => (int) $m[1], 'status' => 'published']), 'ไม่พบ ITA ปีงบประมาณ '.$m[1].' ที่เผยแพร่');
        if (preg_match('~^/media/(\d+)$~D', $path, $m)) return $row(Schema::hasTable('cms_media') && in_array((int) $m[1], app(\App\Http\Controllers\WebsiteMediaController::class)->publicIds($school), true), 'ไฟล์นี้ไม่ได้ใช้ในเนื้อหาที่เผยแพร่ ผู้ชมจึงเปิดไม่ได้');
        if (preg_match('~^/portal/complaints/reports/(\d+)(\.csv)?$~D', $path, $m)) {
            if ($why = $off('complaints')) return $why;
            return $row(\Saan\Modules\Complaints\Reports::find((int) $m[1]) !== null, 'ยังไม่เผยแพร่รายงานเรื่องร้องเรียนปีงบประมาณ '.$m[1]);
        }
        if (preg_match('~^/portal/groups/([a-z0-9-]+)(?:/(news|docs/(\d+)))?$~D', $path, $m)) {
            $hub = Schema::hasTable('cms_hubs') ? DB::table('cms_hubs')->where(['school_id' => $school, 'slug' => $m[1], 'status' => 'published'])->first() : null;
            if (!$hub) return 'ไม่พบหน้ากลุ่มงานที่เผยแพร่';
            if (isset($m[3]) && \Saan\Modules\WebCms\Hubs::category($hub, (int) $m[3]) === null) return 'ไม่พบหมวดเอกสารที่ผู้ชมมองเห็นในหน้ากลุ่มงานนี้';
            return null;
        }
        if (preg_match('~^/portal/d/(\d+)$~D', $path, $m)) return $row(\Saan\Modules\WebCms\Hubs::downloadable((int) $m[1]) !== null, 'ไม่พบเอกสารของหน้ากลุ่มงานที่เผยแพร่');
        if (preg_match('~^/portal/notices/([A-Za-z0-9-]+)$~D', $path, $m)) return $off('web-notices') ?? $row(app(\Saan\Modules\WebNotices\NoticeRepository::class)->publicList($m[1]) !== null, 'ไม่พบรายการประกาศรายชื่อที่เผยแพร่และอยู่ในช่วงเวลาแสดง');
        if (preg_match('~^/portal/surveys/([a-z0-9-]{3,80})$~D', $path, $m)) {
            if ($why = $off('surveys')) return $why;
            $s = Schema::hasTable('srv_surveys') ? DB::table('srv_surveys')->where(['school_id' => $school, 'slug' => $m[1]])->first() : null;
            return $row($s !== null && (new \Saan\Modules\Surveys\SurveyService())->closedReason($s) === null, 'ไม่พบแบบสำรวจที่เปิดรับคำตอบอยู่');
        }
        if (preg_match('~^/learn/\d+$~D', $path)) return $off('learning-links');
        $types = [];
        foreach (\App\Domain\Cms\ContentTypes::TYPES as $type => $d) $types[$d['route']] = $type;
        if (preg_match('~^/portal/([a-z]+)(?:/([A-Za-z0-9-]+))?$~D', $path, $m) && isset($types[$m[1]])) {
            if (!isset($m[2])) return null;
            return $row($has('posts', ['school_id' => $school, 'slug' => $m[2], 'status' => 'published', 'content_type' => $types[$m[1]], 'deleted_at' => null]), 'ไม่พบเนื้อหาที่เผยแพร่ตามลิงก์นี้');
        }
        // anything else: the route table of this installation decides. The internal edition does not register the public pages at all, so it cannot judge /portal/* and /learn/* (blockedInPublic already refused the internal ones)
        try {
            app('router')->getRoutes()->match(Request::create($path, 'GET'));
            return null;
        } catch (\Throwable) {
            return !Edition::serves('site') && (str_starts_with($path, '/portal/') || str_starts_with($path, '/learn/')) ? null : 'ไม่พบหน้าปลายทางตามลิงก์นี้';
        }
    }
}