<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Fiscal;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Edition;
use App\Support\Saan\Publish\Builder;
use App\Support\Saan\Publish\Wire;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Saan\Modules\Complaints\Complaints;
use Saan\Modules\WebIta\ItaCatalog;
use Saan\Modules\WebIta\ItaCheck;
use Saan\Modules\WebIta\ItaSources;
use Tests\TestCase;

/** F6: ITA items that link themselves to real data, the per-item override, the public edition and the completeness checker. */
class ItaAutoLinksTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,array> env values replaced by a test (restored in tearDown) */
    private array $envSet = [];
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        // a clean slate for the content the sources count: the demo content must not change an expected number
        DB::table('cms_post_revisions')->delete();
        DB::table('posts')->whereIn('content_type', ['article', 'procurement', 'person', 'page'])->delete();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        try {
            parent::tearDown();
        } finally {
            // always, even when the teardown throws: a leaked DB_DATABASE would run every later test of the suite on this test's temporary database
            foreach ($this->envSet as $k => $old) {
                if ($old['getenv'] === false) putenv($k); else putenv("$k={$old['getenv']}");
                if ($old['had_env']) $_ENV[$k] = $old['env']; else unset($_ENV[$k]);
                if ($old['had_server']) $_SERVER[$k] = $old['server']; else unset($_SERVER[$k]);
            }
            $this->envSet = [];
        }
    }

    private function school(): int { return (int) config('school.school_id'); }

    private function addPost(string $type, string $title, string $at, array $o = []): int
    {
        $this->n++;
        return DB::table('posts')->insertGetId(['school_id' => $this->school(), 'title' => $title, 'body' => 'x', 'status' => $o['status'] ?? 'published', 'author_id' => $o['author'] ?? User::query()->value('id'),
            'published_at' => $at, 'slug' => $o['slug'] ?? $type.'-t'.$this->n, 'category' => $o['category'] ?? ($type === 'procurement' ? 'procurement' : 'news'), 'content_type' => $type,
            'metadata' => isset($o['meta']) ? json_encode($o['meta'], JSON_UNESCAPED_UNICODE) : null, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function hub(string $title = 'กลุ่มบริหารงบประมาณ', string $slug = 'budget', int $docs = 2): void
    {
        $s = $this->school(); $now = now();
        $h = DB::table('cms_hubs')->insertGetId(['school_id' => $s, 'slug' => $slug, 'title' => $title, 'kind' => 'work', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $c = DB::table('cms_hub_categories')->insertGetId(['school_id' => $s, 'hub_id' => $h, 'label' => 'คำสั่ง', 'created_at' => $now, 'updated_at' => $now]);
        for ($i = 1; $i <= $docs; $i++) DB::table('cms_hub_documents')->insert(['school_id' => $s, 'hub_id' => $h, 'category_id' => $c, 'title' => 'เอกสาร '.$i, 'doc_date' => '2026-11-0'.$i, 'external_url' => 'https://example.org/d'.$i, 'is_published' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function report(int $fy, string $at = '2026-10-15 09:00:00'): void
    {
        DB::table('cmp_reports')->insert(['school_id' => $this->school(), 'fiscal_year' => $fy, 'snapshot' => json_encode(Complaints::report($fy), JSON_UNESCAPED_UNICODE), 'status' => 'published', 'published_at' => $at, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Demo content of fiscal year 2570: 3 news (two in a category), 2 procurement, 2 executives and one teacher, a budget hub with documents, a published report. */
    private function data2570(): void
    {
        DB::table('cms_categories')->insert(['school_id' => $this->school(), 'slug' => 'sports', 'label' => 'กีฬา', 'created_at' => now(), 'updated_at' => now()]);
        $this->addPost('article', 'ข่าวต้นปีงบ', '2026-10-01 00:00:00');
        $this->addPost('article', 'ข่าวกีฬากลางปี', '2027-02-01 10:00:00', ['category' => 'sports']);
        $this->addPost('article', 'ข่าวกีฬาปลายปี', '2027-09-30 23:59:59', ['category' => 'sports']);
        $this->addPost('procurement', 'ประกาศจัดซื้อหนังสือเรียน', '2026-11-20 09:00:00', ['meta' => ['stage' => 'award']]);
        $this->addPost('procurement', 'ประกาศจ้างซ่อมแซมอาคาร', '2027-03-20 09:00:00', ['meta' => ['stage' => 'tor']]);
        $this->addPost('person', 'ผู้อำนวยการ โรงเรียนต้นธารวิทยา', '2026-05-01 09:00:00', ['meta' => ['position' => 'ผู้อำนวยการโรงเรียน']]);
        $this->addPost('person', 'รองผู้อำนวยการ', '2026-05-01 09:00:00', ['meta' => ['position' => 'รองผู้อำนวยการโรงเรียน']]);
        $this->addPost('person', 'ครูสมมติ', '2026-05-01 09:00:00', ['meta' => ['position' => 'ครูชำนาญการ']]);
        $this->hub();
        $this->report(2570);
        $this->contactInfo();
    }

    /** The demo school has no contact details: the contact source is rightly missing until the school fills in an e-mail, phone or address. */
    private function contactInfo(): void
    {
        $row = DB::table('cms_site_settings')->where('school_id', $this->school())->first();
        $pub = $row ? (json_decode((string) $row->published, true) ?: []) : [];
        $pub['email'] = 'office@example.test';
        $row ? DB::table('cms_site_settings')->where('id', $row->id)->update(['published' => json_encode($pub, JSON_UNESCAPED_UNICODE)]) : DB::table('cms_site_settings')->insert(['school_id' => $this->school(), 'draft' => '{}', 'published' => json_encode($pub), 'version' => 1, 'published_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Inserts a year straight into the tables (no auth, works on any edition). @return int year id */
    private function year(int $fy, array $topics, string $status = 'published'): int
    {
        $s = $this->school(); $now = now();
        $y = DB::table('ita_years')->insertGetId(['school_id' => $s, 'fiscal_year' => $fy, 'title' => 'ITA ปีงบประมาณ '.$fy, 'status' => $status, 'version' => 1, 'published_at' => $status === 'published' ? $now : null, 'created_at' => $now, 'updated_at' => $now]);
        foreach (array_values($topics) as $ti => $t) {
            $tid = DB::table('ita_topics')->insertGetId(['school_id' => $s, 'year_id' => $y, 'code' => $t['code'], 'title' => $t['title'] ?? $t['code'], 'sort' => $ti, 'created_at' => $now, 'updated_at' => $now]);
            foreach (array_values($t['items'] ?? []) as $ii => $i) {
                DB::table('ita_items')->insert(['school_id' => $s, 'topic_id' => $tid, 'title' => $i['title'], 'url' => $i['url'] ?? null, 'media_id' => $i['media_id'] ?? null, 'note' => $i['note'] ?? '', 'source' => $i['source'] ?? null,
                    'source_opts' => isset($i['source_opts']) ? json_encode($i['source_opts']) : null, 'sort' => $ii, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        return $y;
    }

    /** The ready-made O1..O18 set; items without an automatic source get a manual https link. */
    private function presetTopics(): array
    {
        return array_map(function ($t) {
            $t['items'] = array_map(fn ($i) => ['title' => $i['title'], 'source' => $i['source'], 'source_opts' => $i['source_opts'] ?? null, 'url' => $i['source'] ? null : 'https://example.org/'.strtolower($t['code'])], $t['items']);
            return $t;
        }, ItaCatalog::preset());
    }

    private function admin(): User { return User::where('role', 'admin')->first(); }

    private function codes(array $r): array { return array_column($r['items'], 'status', 'code'); }

    // ------------------------------------------------------------------ resolution

    public function test_fiscal_year_boundaries(): void
    {
        $this->assertSame(2569, Fiscal::of(CarbonImmutable::parse('2026-09-30 23:59:59', 'Asia/Bangkok')));
        $this->assertSame(2570, Fiscal::of(CarbonImmutable::parse('2026-10-01 00:00:00', 'Asia/Bangkok')));
        $this->assertSame(2570, Fiscal::of(CarbonImmutable::parse('2026-09-30 17:00:00', 'UTC')), '17:00 UTC on 30 Sep is already 1 Oct in Bangkok');
        $this->assertSame(2570, Fiscal::of(CarbonImmutable::parse('2027-09-30 23:59:59', 'Asia/Bangkok')));
        $this->assertSame(2571, Fiscal::of(CarbonImmutable::parse('2027-10-01 00:00:00', 'Asia/Bangkok')));
        $this->assertSame(['2026-10-01 00:00:00', '2027-10-01 00:00:00'], Fiscal::range(2570));
        // the same boundaries decide what an automatic source counts, and what the public list shows with ?fy=
        $this->addPost('article', 'ก่อนสิ้นปี 2569', '2026-09-30 23:59:59');
        $this->addPost('article', 'วันแรกของ 2570', '2026-10-01 00:00:00');
        $this->addPost('article', 'วันสุดท้ายของ 2570', '2027-09-30 23:59:59');
        $this->addPost('article', 'วันแรกของ 2571', '2027-10-01 00:00:00');
        $this->assertSame('ข่าว 2 รายการ ในปีงบ 2570', ItaSources::resolve('news', null, 2570)['evidence']);
        $this->assertSame('ข่าว 1 รายการ ในปีงบ 2569', ItaSources::resolve('news', null, 2569)['evidence']);
        $this->assertSame('ข่าว 1 รายการ ในปีงบ 2571', ItaSources::resolve('news', null, 2571)['evidence']);
        $html = $this->get('/portal/news?fy=2570')->assertOk()->getContent();
        $this->assertStringContainsString('วันแรกของ 2570', $html);
        $this->assertStringContainsString('วันสุดท้ายของ 2570', $html);
        $this->assertStringNotContainsString('ก่อนสิ้นปี 2569', $html);
        $this->assertStringNotContainsString('วันแรกของ 2571', $html);
        $this->get('/portal/news?fy=12')->assertStatus(302); // out of range: validation redirects back, never a wrong list
    }

    public function test_each_auto_mapping_resolves_to_the_right_path_and_evidence(): void
    {
        $this->data2570();
        $r = fn (string $k, ?array $o = null, int $fy = 2570) => ItaSources::resolve($k, $o, $fy);
        $n = $r('news');
        $this->assertTrue($n['ok']);
        $this->assertSame('/portal/news?fy=2570', $n['path']);
        $this->assertSame('ข่าว 3 รายการ ในปีงบ 2570', $n['evidence']);
        $c = $r('news', ['category' => 'sports']);
        $this->assertSame('/portal/news?fy=2570&category=sports', $c['path']);
        $this->assertSame('ข่าว หมวด กีฬา 2 รายการ ในปีงบ 2570', $c['evidence']);
        $this->assertFalse($r('news', ['category' => 'nope'])['ok']);
        $p = $r('procurement');
        $o = $r('procurement', ['stage' => 'results']);
        $this->assertSame(['/portal/procurement?fy=2570&stage=results', 'จัดซื้อจัดจ้าง ประกาศผู้ชนะและสัญญา 1 รายการ ในปีงบ 2570'], [$o['path'], $o['evidence']], 'O10 is the announced outcome only');
        $this->assertSame(['/portal/procurement?fy=2570', 'จัดซื้อจัดจ้าง 2 รายการ ในปีงบ 2570'], [$p['path'], $p['evidence']]);
        $e = $r('directory_executives');
        $this->assertSame(['/portal/people#executives', 'ผู้บริหาร 2 คน ในหน้าบุคลากร'], [$e['path'], $e['evidence']]);
        $f = $r('complaints_form');
        $this->assertSame('/portal/complaints', $f['path']);
        $this->assertSame('/portal/complaints/status', $f['extra'][0]['path']);
        $ch = $r('complaints_channel');
        $this->assertSame('/portal/complaints', $ch['path'], 'O14 links to the complaints page');
        $this->assertSame(['/portal/complaints/status', '/portal/complaints/reports/2570', '/portal/complaints/reports/2570.csv'], array_column($ch['extra'], 'path'), 'and to the published report of that year');
        $this->assertStringContainsString('รายงานเรื่องร้องเรียนปีงบ 2570 เผยแพร่ 2026-10-15', $ch['evidence']);
        $rep = $r('complaints_report');
        $this->assertSame(['/portal/complaints/reports/2570', 'รายงานเรื่องร้องเรียนปีงบ 2570 เผยแพร่ 2026-10-15'], [$rep['path'], $rep['evidence']]);
        $this->assertSame('/portal/contact', $r('contact')['path']);
        $h = $r('hub');
        $this->assertSame(['/portal/groups/budget', 'หน้ากลุ่มงาน "กลุ่มบริหารงบประมาณ" มีเอกสาร 2 รายการ'], [$h['path'], $h['evidence']]);
        $this->assertSame('/portal/groups/budget', $r('hub', ['slug' => 'budget'])['path']);
        $this->addPost('page', 'เกี่ยวกับโรงเรียน', '2026-05-01 09:00:00', ['slug' => 'about']);
        $pg = $r('page', ['slug' => 'about']);
        $this->assertSame(['/portal/pages/about', 'หน้า "เกี่ยวกับโรงเรียน"'], [$pg['path'], $pg['evidence']]);
        $this->assertFalse($r('page', ['slug' => 'ghost'])['ok']);
        $this->assertFalse($r('bogus')['ok']);
        // never a host, never an internal route
        foreach (array_keys(ItaSources::KEYS) as $k) {
            $res = $r($k, in_array($k, ['page'], true) ? ['slug' => 'about'] : null);
            foreach (array_merge([$res['path']], array_column($res['extra'], 'path')) as $path) {
                if ($path === null) continue;
                $this->assertMatchesRegularExpression('~^/(portal|media)(/|\?|$)~', $path, $k);
                $this->assertStringNotContainsString('//', $path);
            }
        }
    }

    public function test_a_year_without_data_is_missing_not_a_dead_link(): void
    {
        $this->data2570();
        foreach (['news', 'procurement', 'complaints_report'] as $k) {
            $x = ItaSources::resolve($k, null, 2575);
            $this->assertFalse($x['ok'], $k);
            $this->assertNull($x['path']);
            $this->assertStringContainsString('2575', $x['reason']);
        }
        $this->assertTrue(ItaSources::resolve('directory_executives', null, 2570)['ok']);
        DB::table('posts')->where('content_type', 'person')->delete();
        $this->assertFalse(ItaSources::resolve('directory_executives', null, 2570)['ok']);
        // the public page shows the title without a link and says so; the checker counts it as missing
        $this->year(2575, [['code' => 'O4', 'title' => 'ข่าว', 'items' => [['title' => 'ข่าวประชาสัมพันธ์', 'source' => 'news']]]]);
        $html = $this->ita($this->get('/portal/ita/2575')->assertOk()->getContent());
        $this->assertStringContainsString('ยังไม่มีข้อมูลในปีงบประมาณนี้', $html);
        $this->assertStringNotContainsString('/portal/news', $html);
        $this->assertSame('missing', $this->codes(ItaCheck::run(2575))['O4']);
    }

    public function test_public_page_links_auto_items_and_o14_links_to_complaints_page_and_report(): void
    {
        $this->data2570();
        $this->year(2570, $this->presetTopics());
        $html = $this->ita($this->get('/portal/ita')->assertOk()->getContent());
        foreach (['/portal/people#executives', '/portal/news?fy=2570', '/portal/procurement?fy=2570&stage=results', '/portal/contact', '/portal/groups/budget', '/portal/complaints', '/portal/complaints/status', '/portal/complaints/reports/2570', '/portal/complaints/reports/2570.csv'] as $p) {
            $this->assertStringContainsString('href="'.e(url($p)).'"', $html, $p);
        }
        $this->assertStringContainsString('ข่าว 3 รายการ ในปีงบ 2570', $html);
        $this->assertStringContainsString('จัดซื้อจัดจ้าง ประกาศผู้ชนะและสัญญา 1 รายการ ในปีงบ 2570', $html);
        $this->assertStringContainsString('รายงานเรื่องร้องเรียนปีงบ 2570 เผยแพร่ 2026-10-15', $html);
        // O14 topic: the complaints module's own topic links are not added a second time
        $this->assertSame(1, substr_count($html, 'href="'.e(url('/portal/complaints/reports/2570')).'"'));
        foreach (['/cms', '/login', '/api/', '/work', '/admin'] as $internal) $this->assertStringNotContainsString('href="'.url($internal), $html, $internal);
    }

    public function test_manual_override_wins_over_auto(): void
    {
        $this->data2570();
        $this->year(2570, [['code' => 'O4', 'title' => 'ข่าว', 'items' => [
            ['title' => 'ข่าวที่ใส่ลิงก์เอง', 'source' => 'news', 'url' => 'https://example.org/own-news'],
            ['title' => 'ข่าวอัตโนมัติ', 'source' => 'news'],
        ]]]);
        $html = $this->get('/portal/ita')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<a href="https://example.org/own-news"[^>]*>ข่าวที่ใส่ลิงก์เอง</a>~u', $html);
        $this->assertSame(1, substr_count($html, 'href="'.e(url('/portal/news?fy=2570')).'"'), 'only the automatic item links to the news list');
        $this->actingAs($this->admin());
        $yid = DB::table('ita_years')->value('id');
        $items = $this->getJson('/api/ita/years/'.$yid)->assertOk()->json('topics.0.items');
        $this->assertSame('manual', $items[0]['resolved']['mode']);
        $this->assertSame('auto', $items[1]['resolved']['mode']);
        $this->assertSame('complete', ItaCheck::run(2570)['items'][3]['entries'][0]['status']);
        $this->assertSame('auto-ok', ItaCheck::run(2570)['items'][3]['entries'][1]['status']);
    }

    // ------------------------------------------------------------------ public edition

    public function test_public_edition_renders_the_same_links_from_projected_rows_and_never_an_internal_route(): void
    {
        $this->data2570();
        $this->year(2570, $this->presetTopics());
        $combined = $this->ita($this->get('/portal/ita')->assertOk()->getContent());
        $hrefs = fn (string $html) => collect($this->hrefs($html))->filter(fn ($h) => str_contains($h, '/portal/') && !str_contains($h, '/portal/ita'))->unique()->sort()->values()->all();
        $expected = $hrefs($combined);
        $this->assertNotEmpty($expected);

        // the public edition: its own database that holds only projected rows (here: the same rows, as the receiver would write them)
        // exactly what the publisher sends (every declared stream, projected and filtered), not a raw copy of the tables
        $builder = app(Builder::class);
        $rows = [];
        foreach ($builder->streams() as $s) if ($s !== Wire::MODULES) $rows[$s] = array_values($builder->rows($s));
        $this->assertNotEmpty($rows['posts'] ?? [], 'posts are projected');
        $this->assertNotEmpty($rows['ita_items'] ?? [], 'ita_items are projected (source and source_opts travel with them)');
        $this->assertArrayHasKey('source_opts', $rows['ita_items'][0]);
        $this->bootEdition('public', ['DB_DATABASE' => $this->tempDatabase($this->editionTemplate('public'))]);
        foreach (array_keys(app(ModuleRegistry::class)->all()) as $id) DB::table('pub_active_modules')->insert(['module_id' => $id]);
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($rows as $t => $list) foreach ($list as $r) { if ($t === 'posts') $r['author_id'] = 1; DB::table($t)->insert($r); }
        $this->refreshApplication();
        $this->assertSame('public', Edition::current());

        $html = $this->ita($this->get('/portal/ita')->assertOk()->getContent());
        $this->assertSame($expected, $hrefs($html), 'the public edition resolves the same links from the projected rows');
        $this->assertStringContainsString('ข่าว 3 รายการ ในปีงบ 2570', $html);
        $this->assertStringContainsString('รายงานเรื่องร้องเรียนปีงบ 2570 เผยแพร่ 2026-10-15', $html);
        foreach (array_filter($this->hrefs($html), fn ($h) => str_starts_with($h, url('/'))) as $h) { // links to this site (external https links are the school's own)
            $path = (string) parse_url($h, PHP_URL_PATH);
            $this->assertFalse(Edition::blockedInPublic($path), 'a link on the public ITA page points outside the public site: '.$h);
        }
        foreach (['/cms', '/login', '/api/', '/work', '/admin'] as $internal) $this->assertStringNotContainsString('href="'.url($internal), $html, $internal);
        // and the target pages exist on that side
        $this->get('/portal/news?fy=2570')->assertOk()->assertSee('ข่าวต้นปีงบ');
        $this->get('/portal/complaints/reports/2570')->assertOk();
        $this->get('/portal/groups/budget')->assertOk();
        // the checker is an internal-only endpoint
        $this->getJson('/api/ita/check?year=2570')->assertNotFound();
    }

    // ------------------------------------------------------------------ checker

    public function test_checker_statuses_summary_and_json_endpoint_make_no_outbound_call(): void
    {
        Http::fake();
        $this->data2570();
        $this->report(2571);
        $item = fn (string $t, array $o = []) => ['title' => $t] + $o;
        $y = $this->year(2570, [
            ['code' => 'O1', 'items' => [$item('ลิงก์ภายในที่ไม่มีเนื้อหา', ['url' => '/portal/news/missing-slug'])]],
            ['code' => 'O2', 'items' => [$item('ผู้บริหาร', ['source' => 'directory_executives'])]],
            ['code' => 'O3', 'items' => [$item('ข้อความอย่างเดียว', ['note' => 'ดูที่ห้องธุรการ'])]],
            ['code' => 'O4', 'items' => [$item('ข่าว', ['source' => 'news'])]],
            ['code' => 'O5', 'items' => [$item('ลิงก์ภายนอก', ['url' => 'https://example.org/contact'])]],
            ['code' => 'O6', 'items' => [$item('ลิงก์ https ที่รูปแบบเสีย', ['url' => 'https://'])]],
            ['code' => 'O7', 'items' => [$item('ยังว่าง')]],
            ['code' => 'O8', 'items' => [$item('ไฟล์ที่หาย', ['media_id' => 999999])]],
            ['code' => 'O9', 'items' => [$item('หน้ากลุ่มงานที่ไม่มี', ['source' => 'hub', 'source_opts' => ['slug' => 'ghost']])]],
            ['code' => 'O10', 'items' => [$item('หน้ากลุ่มงานที่มีจริง', ['url' => '/portal/groups/budget'])]],
            ['code' => 'O11', 'items' => [$item('รายงานที่เผยแพร่แล้ว', ['url' => '/portal/complaints/reports/2570'])]],
            ['code' => 'O12', 'items' => [$item('รายงานที่ยังไม่เผยแพร่', ['url' => '/portal/complaints/reports/2572'])]],
            ['code' => 'O13', 'items' => [$item('ช่องทาง', ['source' => 'complaints_form'])]],
            ['code' => 'O14', 'items' => [$item('ช่องทางและรายงาน', ['source' => 'complaints_channel'])]],
        ], 'draft');
        $this->actingAs($this->admin());
        $r = $this->getJson('/api/ita/check?year=2570')->assertOk()->json();
        $this->assertSame(2570, $r['fiscal_year']);
        $this->assertSame($y, $r['year_id']);
        $s = $this->codes($r);
        $this->assertSame(['O1' => 'link-broken', 'O2' => 'auto-ok', 'O3' => 'complete', 'O4' => 'auto-ok', 'O5' => 'complete', 'O6' => 'link-broken', 'O7' => 'missing', 'O8' => 'link-broken',
            'O9' => 'missing', 'O10' => 'complete', 'O11' => 'complete', 'O12' => 'link-broken', 'O13' => 'auto-ok', 'O14' => 'auto-ok',
            'O15' => 'missing', 'O16' => 'missing', 'O17' => 'missing', 'O18' => 'missing'], $s);
        $this->assertSame(['total' => 18, 'complete' => 4, 'auto_ok' => 4, 'missing' => 6, 'link_broken' => 4], $r['summary']);
        $this->assertFalse($r['ok']);
        $by = array_column($r['items'], null, 'code');
        $this->assertStringContainsString('ไม่พบเนื้อหาที่เผยแพร่', $by['O1']['reason']);
        $this->assertStringContainsString('ไฟล์ที่แนบถูกลบ', $by['O8']['reason']);
        $this->assertStringContainsString('ไม่พบหน้ากลุ่มงานที่เผยแพร่ slug "ghost"', $by['O9']['reason']);
        $this->assertStringContainsString('ยังไม่มีหัวข้อรหัส O15', $by['O15']['reason']);
        $this->assertStringContainsString('ยังไม่เผยแพร่รายงานเรื่องร้องเรียนปีงบประมาณ 2572', $by['O12']['reason']);
        $this->assertSame('/cms#ita/'.$y, $by['O7']['fix_path']);
        $this->assertSame('/portal/news?fy=2570', $by['O4']['path']);
        // a year that was never created: every item is missing and the link goes to the ITA list
        $none = $this->getJson('/api/ita/check?year=2580')->assertOk()->json();
        $this->assertNull($none['year_id']);
        $this->assertSame(18, $none['summary']['missing']);
        $this->assertSame('/cms#ita', $none['items'][0]['fix_path']);
        $this->getJson('/api/ita/check?year=12')->assertUnprocessable();
        Http::assertNothingSent();
        // teachers cannot read it, guests are sent to sign in
        $this->actingAs(User::where('role', 'teacher')->first())->getJson('/api/ita/check?year=2570')->assertForbidden();
        auth()->logout();
        $this->getJson('/api/ita/check?year=2570')->assertUnauthorized();
    }

    public function test_command_exit_code_follows_the_check(): void
    {
        $this->data2570();
        $this->year(2570, array_slice($this->presetTopics(), 0, 17)); // O18 left out
        $this->artisan('saan:ita-check', ['--year' => 2570])->expectsOutputToContain('[WARN] O18')->expectsOutputToContain('ขาด 1')->assertExitCode(1);
        DB::table('ita_years')->where('fiscal_year', 2570)->delete();
        $this->year(2570, $this->presetTopics());
        $this->artisan('saan:ita-check', ['--year' => 2570])->expectsOutputToContain('สรุป: ครบ 11, ลิงก์อัตโนมัติ 7, ขาด 0, ลิงก์เสีย 0 จากทั้งหมด 18 ข้อ')->assertExitCode(0);
        // a broken link makes it fail again; a missing year as well; a bad option is a usage error
        DB::table('ita_items')->where('title', 'โครงสร้างหน่วยงาน')->update(['url' => '/portal/groups/ghost']);
        $this->artisan('saan:ita-check', ['--year' => 2570])->assertExitCode(1);
        $this->artisan('saan:ita-check', ['--year' => 2599])->assertExitCode(1);
        $this->artisan('saan:ita-check', ['--year' => 'abc'])->assertExitCode(2);
        // default year = the fiscal year of today
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 00:00:01', 'Asia/Bangkok'));
        $this->assertSame(2570, Fiscal::current());
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 23:59:59', 'Asia/Bangkok'));
        $this->assertSame(2569, Fiscal::current());
        $this->artisan('saan:ita-check', ['--json' => true, '--year' => 2570])->expectsOutputToContain('"fiscal_year": 2570')->assertExitCode(1);
    }

    // ------------------------------------------------------------------ editor API

    public function test_api_preset_save_validation_and_copy_keep_the_source(): void
    {
        $this->actingAs($this->admin());
        $y = $this->postJson('/api/ita/years', ['fiscal_year' => 2570, 'preset' => true])->assertCreated()->json();
        $this->assertCount(18, $y['topics']);
        $this->assertSame('O4', $y['topics'][3]['code']);
        $this->assertSame('news', $y['topics'][3]['items'][0]['source']);
        $this->assertSame('complaints_channel', $y['topics'][13]['items'][0]['source']);
        $this->assertNull($y['topics'][0]['items'][0]['source']);
        $this->assertArrayHasKey('news', $y['sources']);
        $save = fn (array $item) => $this->putJson('/api/ita/years/'.$y['year']['id'], ['version' => DB::table('ita_years')->where('id', $y['year']['id'])->value('version'), 'title' => 'ITA', 'topics' => [['code' => 'O4', 'title' => 'ข่าว', 'items' => [$item + ['title' => 'x', 'url' => null, 'media_id' => null, 'note' => '']]]]]);
        $save(['source' => 'bogus'])->assertUnprocessable();
        $save(['source' => 'news', 'source_opts' => ['category' => 'bad slug!']])->assertUnprocessable();
        $save(['source' => 'news', 'source_opts' => ['other' => 'x']])->assertUnprocessable();
        $ok = $save(['source' => 'news', 'source_opts' => ['category' => 'sports']])->assertOk()->json('topics.0.items.0');
        $this->assertSame(['category' => 'sports'], $ok['source_opts']);
        $this->assertSame('news', $ok['source']);
        $none = $save(['source' => 'procurement', 'source_opts' => ['category' => 'sports']])->assertOk()->json('topics.0.items.0');
        $this->assertNull($none['source_opts'], 'options a source does not accept are dropped');
        $manual = $save(['source' => null, 'url' => 'https://example.org/a'])->assertOk()->json('topics.0.items.0');
        $this->assertNull($manual['source']);
        $save(['source' => 'news', 'url' => 'javascript:alert(1)'])->assertUnprocessable();
        // copying a year keeps the structure and the automatic source, not the evidence
        $save(['source' => 'news', 'source_opts' => ['category' => 'sports'], 'url' => 'https://example.org/own'])->assertOk();
        $copy = $this->postJson('/api/ita/years', ['fiscal_year' => 2571, 'copy_from' => $y['year']['id']])->assertCreated()->json();
        $this->assertSame('news', $copy['topics'][0]['items'][0]['source']);
        $this->assertSame(['category' => 'sports'], $copy['topics'][0]['items'][0]['source_opts']);
        $this->assertNull($copy['topics'][0]['items'][0]['url']);
        // the default (no preset) is still the 6 neutral headings
        $this->assertCount(6, $this->postJson('/api/ita/years', ['fiscal_year' => 2572])->assertCreated()->json('topics'));
    }

    // ------------------------------------------------------------------ escaping

    public function test_titles_and_evidence_are_escaped(): void
    {
        $evil = '<script>alert(1)</script>';
        $img = '<img src=x onerror=alert(2)>';
        $this->addPost('page', $evil, '2026-05-01 09:00:00', ['slug' => 'about']);
        $this->hub($img, 'budget', 1);
        $this->year(2570, [
            ['code' => 'O1', 'title' => $evil, 'items' => [['title' => $img.' โครงสร้าง', 'source' => 'page', 'source_opts' => ['slug' => 'about']]]],
            ['code' => 'O16', 'title' => 'งบ', 'items' => [['title' => 'งบประมาณ', 'source' => 'hub']]],
        ]);
        $html = $this->get('/portal/ita')->assertOk()->getContent();
        $this->assertStringNotContainsString($evil, $html);
        $this->assertStringNotContainsString($img, $html);
        $this->assertStringContainsString('หน้า &quot;&lt;script&gt;alert(1)&lt;/script&gt;&quot;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        // an option can never carry markup or a path
        $this->assertNull(ItaSources::cleanOpts('page', ['slug' => '../x']));
        $this->assertNull(ItaSources::cleanOpts('news', ['category' => '<b>']));
        $this->assertSame(['slug' => 'about'], ItaSources::cleanOpts('page', ['slug' => 'about', 'x' => 'y']));
        // the admin screen escapes every server string it prints
        $js = (string) file_get_contents(public_path('assets/modules/web-ita/ita-admin.js'));
        foreach (['esc(i.reason)', 'esc(i.evidence)', 'esc(r.reason)', 'esc(r.evidence)', 'esc(l)'] as $needle) $this->assertStringContainsString($needle, $js);
    }

    public function test_catalog_codes(): void
    {
        $this->assertCount(18, ItaCatalog::ITEMS);
        $this->assertSame('O4', ItaCatalog::code('o 4'));
        $this->assertSame('O4', ItaCatalog::code('O-04'));
        $this->assertSame('', ItaCatalog::code('O19'));
        $this->assertSame('O13', ItaCatalog::codeOfTitle('O13 แบบฟอร์ม'));
        $this->assertSame('', ItaCatalog::codeOfTitle('O130 ไม่ใช่'));
        $this->assertSame('', ItaCatalog::codeOfTitle('ไม่มีรหัส O13'));
        $this->assertSame(['O2' => 'directory_executives', 'O4' => 'news', 'O9' => null, 'O10' => 'procurement', 'O13' => 'complaints_form', 'O14' => 'complaints_channel'],
            array_filter(array_map(fn ($d) => $d['source'], ItaCatalog::ITEMS), fn ($s, $k) => in_array($k, ['O2', 'O4', 'O9', 'O10', 'O13', 'O14'], true), ARRAY_FILTER_USE_BOTH));
    }

    // ------------------------------------------------------------------ review findings

    public function test_o14_needs_the_report_of_that_year(): void
    {
        $this->data2570();
        $this->year(2570, $this->presetTopics());
        $this->artisan('saan:ita-check', ['--year' => 2570])->assertExitCode(0);
        // no published report for the year: the form alone is not O14 evidence
        DB::table('cmp_reports')->where('fiscal_year', 2570)->delete();
        $x = ItaSources::resolve('complaints_channel', null, 2570);
        $this->assertFalse($x['ok']);
        $this->assertStringContainsString('ยังไม่เผยแพร่รายงานเรื่องร้องเรียนปีงบประมาณ 2570', $x['reason']);
        $this->assertSame('missing', $this->codes(ItaCheck::run(2570))['O14']);
        $this->assertSame('auto-ok', $this->codes(ItaCheck::run(2570))['O13']);
        $this->artisan('saan:ita-check', ['--year' => 2570])->expectsOutputToContain('[WARN] O14')->assertExitCode(1);
        $html = $this->ita($this->get('/portal/ita')->assertOk()->getContent());
        $this->assertStringNotContainsString('href="'.e(url('/portal/complaints/reports/2570')).'"', $html);
        $this->assertStringContainsString('ยังไม่มีข้อมูลในปีงบประมาณนี้', $html, 'a yearly source says so');
        $this->report(2570);
        $this->assertSame('auto-ok', $this->codes(ItaCheck::run(2570))['O14']);
    }

    public function test_manual_links_to_internal_routes_are_refused_and_flagged(): void
    {
        $this->actingAs($this->admin());
        $y = $this->postJson('/api/ita/years', ['fiscal_year' => 2570, 'preset' => true])->assertCreated()->json();
        $save = fn (string $url) => $this->putJson('/api/ita/years/'.$y['year']['id'], ['version' => DB::table('ita_years')->where('id', $y['year']['id'])->value('version'), 'title' => 'ITA',
            'topics' => [['code' => 'O14', 'title' => 'x', 'items' => [['title' => 'ลิงก์', 'url' => $url, 'media_id' => null, 'note' => '']]]]]);
        foreach (['/cms#ita', '/work#complaints', '/api/complaints/reports/2570/csv', '/login', '/guardian'] as $internal) {
            $res = $save($internal)->assertStatus(422);
            $this->assertStringContainsString('ลิงก์ชี้ไปหน้าภายในที่ผู้ชมเปิดไม่ได้', $res->json('message'), $internal);
        }
        $save('/portal/news?fy=2570')->assertOk();
        // rows that got there another way (older data) are flagged by the checker
        foreach (['/cms#ita', '/api/complaints/reports/2570/csv'] as $internal) {
            $this->assertSame('ลิงก์ชี้ไปหน้าภายในที่ผู้ชมเปิดไม่ได้', ItaCheck::linkProblem($internal), $internal);
        }
        $this->year(2599, [['code' => 'O14', 'items' => [['title' => 'ลิงก์', 'url' => '/work#complaints']]]]);
        $r = ItaCheck::run(2599);
        $this->assertSame('link-broken', $this->codes($r)['O14']);
        $this->assertSame('ลิงก์ชี้ไปหน้าภายในที่ผู้ชมเปิดไม่ได้', array_column($r['items'], null, 'code')['O14']['reason']);
    }

    public function test_a_draft_year_is_not_ok_and_the_command_says_so(): void
    {
        $this->data2570();
        $y = $this->year(2570, $this->presetTopics(), 'draft');
        $r = ItaCheck::run(2570);
        $this->assertSame(0, $r['summary']['missing'] + $r['summary']['link_broken']);
        $this->assertFalse($r['published']);
        $this->assertFalse($r['ok'], 'complete content of an unpublished year is not ok');
        $this->artisan('saan:ita-check', ['--year' => 2570])->expectsOutputToContain('[WARN] ปีนี้ยังเป็นฉบับร่าง')->assertExitCode(1);
        $this->get('/portal/ita/2570')->assertNotFound();
        DB::table('ita_years')->where('id', $y)->update(['status' => 'published']);
        $this->assertTrue(ItaCheck::run(2570)['ok']);
        $this->artisan('saan:ita-check', ['--year' => 2570])->assertExitCode(0);
    }

    public function test_rows_a_guest_cannot_open_are_not_complete(): void
    {
        $this->data2570();
        $s = $this->school(); $now = now();
        $uploader = User::query()->value('id');
        // (a) a media file nothing published uses
        $media = fn (string $p) => DB::table('cms_media')->insertGetId(['school_id' => $s, 'uploaded_by' => $uploader, 'path' => $p, 'mime' => 'application/pdf', 'bytes' => 10, 'width' => 0, 'height' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $unused = $media('x/unused.pdf');
        $used = $media('x/used.pdf');
        DB::table('posts')->where('id', $this->addPost('article', 'ข่าวมีไฟล์', '2026-10-05 09:00:00', ['slug' => 'with-media']))->update(['cover_media_id' => $used]);
        $this->assertSame('ไฟล์นี้ไม่ได้ใช้ในเนื้อหาที่เผยแพร่ ผู้ชมจึงเปิดไม่ได้', ItaCheck::linkProblem('/media/'.$unused));
        $this->assertNull(ItaCheck::linkProblem('/media/'.$used), 'used by a published post: guests may open it');
        // (b) a notice list that has expired
        $list = fn (string $slug, string $from, string $to) => DB::table('notice_lists')->insert(['school_id' => $s, 'slug' => $slug, 'title' => $slug, 'columns' => '[]', 'publish_from' => $from, 'expires_on' => $to, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $list('live-list', '2020-01-01', '2099-01-01');
        $list('old-list', '2020-01-01', '2020-02-01');
        $this->assertNull(ItaCheck::linkProblem('/portal/notices/live-list'));
        $this->assertStringContainsString('ไม่พบรายการประกาศรายชื่อ', (string) ItaCheck::linkProblem('/portal/notices/old-list'));
        // (c) a document category that is hidden
        $this->hub('กลุ่มทดสอบ', 'docs-hub', 1);
        $hub = DB::table('cms_hubs')->where('slug', 'docs-hub')->first();
        $cat = DB::table('cms_hub_categories')->where('hub_id', $hub->id)->first();
        $this->assertNull(ItaCheck::linkProblem('/portal/groups/docs-hub/docs/'.$cat->id));
        DB::table('cms_hub_categories')->where('id', $cat->id)->update(['is_hidden' => 1]);
        $this->assertStringContainsString('ไม่พบหมวดเอกสาร', (string) ItaCheck::linkProblem('/portal/groups/docs-hub/docs/'.$cat->id));
        $this->assertNotNull(ItaCheck::linkProblem('/portal/d/'.DB::table('cms_hub_documents')->where('hub_id', $hub->id)->value('id')), 'a document in a hidden category is not downloadable');
    }

    public function test_the_checker_judges_public_links_on_the_internal_edition_too(): void
    {
        $this->data2570();
        $this->hub('กลุ่มทดสอบ', 'docs-hub', 1);
        $s = $this->school(); $now = now();
        DB::table('srv_surveys')->insert(['school_id' => $s, 'slug' => 'satisfaction-2570', 'title' => 'ความพึงพอใจ', 'questions' => '[]', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('srv_surveys')->insert(['school_id' => $s, 'slug' => 'draft-survey', 'title' => 'ร่าง', 'questions' => '[]', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $docId = DB::table('cms_hub_documents')->value('id');
        $paths = ['/portal/surveys/satisfaction-2570', '/portal/d/'.$docId, '/portal/certificates/verify', '/learn/5'];
        $before = array_map(fn ($p) => ItaCheck::linkProblem($p), $paths);
        $this->assertSame([null, null, null, null], $before, 'combined edition');
        $this->assertNotNull(ItaCheck::linkProblem('/portal/surveys/draft-survey'));
        $this->assertNotNull(ItaCheck::linkProblem('/portal/surveys/ghost-survey'));
        $rows = [];
        foreach (['srv_surveys', 'cms_hubs', 'cms_hub_categories', 'cms_hub_documents'] as $t) $rows[$t] = DB::table($t)->get()->map(fn ($r) => (array) $r)->all();
        $this->bootEdition('internal', ['DB_DATABASE' => $this->tempDatabase($this->editionTemplate('internal'))]);
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($rows as $t => $list) foreach ($list as $r) DB::table($t)->insert($r);
        $this->refreshApplication();
        $this->assertSame('internal', Edition::current());
        $this->assertFalse(Edition::serves('site'));
        // this install has no public routes, so the route table must not decide: the rows do
        foreach ($paths as $p) $this->assertNull(ItaCheck::linkProblem($p), 'internal edition: '.$p);
        $this->assertNotNull(ItaCheck::linkProblem('/portal/surveys/draft-survey'));
        $this->assertSame(ItaCheck::INTERNAL_LINK, ItaCheck::linkProblem('/cms#ita'));
        $this->assertSame(0, DB::table('ita_years')->count());
    }
    public function test_o13_and_o14_topics_without_a_source_count_what_the_page_shows(): void
    {
        $this->data2570();
        $this->year(2570, [
            ['code' => 'O13', 'title' => 'ช่องทางร้องเรียน', 'items' => [['title' => 'แบบฟอร์ม']]],
            ['code' => 'O14', 'title' => 'สถิติ', 'items' => []],
        ]);
        $html = $this->ita($this->get('/portal/ita')->assertOk()->getContent());
        $this->assertStringContainsString('href="'.e(url('/portal/complaints')).'"', $html, 'the page links the complaints module');
        $s = $this->codes(ItaCheck::run(2570));
        $this->assertSame('auto-ok', $s['O13']);
        $this->assertSame('auto-ok', $s['O14']);
        $by = array_column(ItaCheck::run(2570)['items'], null, 'code');
        $this->assertStringContainsString('ลิงก์อัตโนมัติจากโมดูลร้องเรียน', $by['O13']['reason']);
        $this->assertSame('/portal/complaints', $by['O13']['path']);
        // a topic whose item has its own (empty) automatic source gets no extra links on the page, so the checker does not invent them either
        DB::table('ita_years')->delete();
        $this->year(2570, [['code' => 'O14', 'title' => 'สถิติ', 'items' => [['title' => 'ช่องทาง', 'source' => 'complaints_report']]]]);
        DB::table('cmp_reports')->delete();
        $this->assertSame('missing', $this->codes(ItaCheck::run(2570))['O14']);
    }

    public function test_an_o14_topic_without_a_source_needs_that_years_report(): void
    {
        $this->data2570(); // publishes the 2570 report only
        $this->year(2571, [['code' => 'O14', 'title' => 'สถิติ', 'items' => []]]);
        $by = array_column(ItaCheck::run(2571)['items'], null, 'code');
        $this->assertSame('missing', $by['O14']['status'], 'the form alone is not the annual statistics of 2571');
        $this->artisan('saan:ita-check', ['--year' => 2571])->assertExitCode(1);
        $hrefs = $this->hrefs($this->ita($this->get('/portal/ita/2571')->assertOk()->getContent()));
        $this->assertNotContains(url('/portal/complaints/reports/2570'), $hrefs, 'the 2571 page does not pass off the 2570 report');
        $this->assertContains(url('/portal/complaints'), $hrefs, 'the channel itself is still listed');
        // once 2571 is published the same topic is evidenced by it
        $this->report(2571);
        $by = array_column(ItaCheck::run(2571)['items'], null, 'code');
        $this->assertSame('auto-ok', $by['O14']['status']);
        $this->assertSame('/portal/complaints/reports/2571', $by['O14']['path']);
    }

    public function test_acting_executive_titles_count_and_the_staff_page_has_the_anchor(): void
    {
        foreach (['รักษาราชการแทนผู้อำนวยการโรงเรียน', 'รักษาการในตำแหน่งผู้อำนวยการ', 'รักษาการรองผู้อำนวยการ', 'ผู้อำนวยการ', 'ครูใหญ่'] as $pos) {
            $this->assertTrue(\App\Support\WebsiteView::isExecutive($pos), $pos);
        }
        foreach (['ครูชำนาญการ', 'เจ้าหน้าที่ธุรการ', 'ที่ปรึกษาผู้อำนวยการ'] as $pos) $this->assertFalse(\App\Support\WebsiteView::isExecutive($pos), $pos);
        $this->addPost('person', 'ครูรักษาราชการ', '2026-05-01 09:00:00', ['meta' => ['position' => 'รักษาราชการแทนผู้อำนวยการโรงเรียน']]);
        $this->addPost('person', 'ครูสมมติ', '2026-05-01 09:00:00', ['meta' => ['position' => 'ครูชำนาญการ']]);
        $e = ItaSources::resolve('directory_executives', null, 2570);
        $this->assertTrue($e['ok']);
        $this->assertSame('/portal/people#executives', $e['path']);
        $this->assertStringContainsString('ผู้บริหาร 1 คน', $e['evidence']);
        $html = $this->get('/portal/people')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'id="executives"'), 'the staff page marks the executive block once');
        DB::table('posts')->where('content_type', 'person')->where('title', 'ครูรักษาราชการ')->delete();
        $this->assertSame(0, substr_count($this->get('/portal/people')->getContent(), 'id="executives"'));
    }

    public function test_executives_in_personnel_mode_resolve_the_same_on_the_public_edition(): void
    {
        $this->data2570();
        $ids = DB::table('teachers')->where('school_id', $this->school())->orderBy('id')->pluck('id');
        $now = now();
        DB::table('per_profiles')->insert(['school_id' => $this->school(), 'teacher_id' => $ids[0], 'position' => 'รักษาราชการแทนผู้อำนวยการโรงเรียน', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('per_profiles')->insert(['school_id' => $this->school(), 'teacher_id' => $ids[1], 'position' => 'ครูชำนาญการ', 'created_at' => $now, 'updated_at' => $now]);
        $row = DB::table('cms_site_settings')->where('school_id', $this->school())->first();
        $pub = json_decode((string) $row->published, true) ?: [];
        $pub['directory_source'] = 'personnel';
        DB::table('cms_site_settings')->where('id', $row->id)->update(['published' => json_encode($pub, JSON_UNESCAPED_UNICODE)]);
        $this->year(2570, [['code' => 'O2', 'title' => 'ผู้บริหาร', 'items' => [['title' => 'ผู้บริหาร', 'source' => 'directory_executives']]]]);
        $e = ItaSources::resolve('directory_executives', null, 2570);
        $this->assertSame(['/portal/people#executives', 'ผู้บริหาร 1 คน ในหน้าบุคลากร'], [$e['path'], $e['evidence']], 'personnel mode, combined');
        $this->assertSame(1, substr_count($this->get('/portal/people')->getContent(), 'id="executives"'));

        $this->toPublicEdition();
        $html = $this->ita($this->get('/portal/ita')->assertOk()->getContent());
        $this->assertStringContainsString('ผู้บริหาร 1 คน ในหน้าบุคลากร', $html, 'public edition, from projected rows');
        $this->assertStringContainsString('href="'.e(url('/portal/people#executives')).'"', $html);
        $this->assertSame(1, substr_count($this->get('/portal/people')->assertOk()->getContent(), 'id="executives"'));
    }

    public function test_procurement_stage_filter_and_option(): void
    {
        $this->data2570();
        $html = $this->get('/portal/procurement?fy=2570&stage=results')->assertOk()->getContent();
        $this->assertStringContainsString('ประกาศจัดซื้อหนังสือเรียน', $html);
        $this->assertStringNotContainsString('ประกาศจ้างซ่อมแซมอาคาร', $html);
        $this->assertStringContainsString('พบ 1 รายการ', $html);
        $this->get('/portal/procurement?stage=tor')->assertOk()->assertSee('ประกาศจ้างซ่อมแซมอาคาร')->assertDontSee('ประกาศจัดซื้อหนังสือเรียน');
        $this->get('/portal/procurement?stage=bogus')->assertStatus(302);
        // the same filter decides what the source counts; O9 (plan) is left to the school
        $this->assertSame('/portal/procurement?fy=2570&stage=tor', ItaSources::resolve('procurement', ['stage' => 'tor'], 2570)['path']);
        $this->assertFalse(ItaSources::resolve('procurement', ['stage' => 'cancelled'], 2570)['ok']);
        $this->assertNull(ItaCatalog::ITEMS['O9']['source']);
        $this->assertSame(['source' => 'procurement', 'source_opts' => ['stage' => 'results']], array_intersect_key(ItaCatalog::preset()[9]['items'][0], ['source' => 1, 'source_opts' => 1]));
        $this->assertNull(ItaSources::cleanOpts('procurement', ['stage' => 'nonsense']));
        $this->assertSame(['stage' => 'results'], ItaSources::cleanOpts('procurement', ['stage' => 'results']));
        $this->actingAs($this->admin());
        $y = $this->postJson('/api/ita/years', ['fiscal_year' => 2572, 'preset' => true])->assertCreated()->json();
        $this->assertSame(['stage' => 'results'], $y['topics'][9]['items'][0]['source_opts']);
        $this->assertNull($y['topics'][8]['items'][0]['source']);
        $save = fn (array $opts) => $this->putJson('/api/ita/years/'.$y['year']['id'], ['version' => DB::table('ita_years')->where('id', $y['year']['id'])->value('version'), 'title' => 'ITA',
            'topics' => [['code' => 'O10', 'title' => 'x', 'items' => [['title' => 'x', 'url' => null, 'media_id' => null, 'note' => '', 'source' => 'procurement', 'source_opts' => $opts]]]]]);
        $save(['stage' => 'nonsense'])->assertUnprocessable();
        $save(['stage' => 'award'])->assertOk();
    }

    public function test_sources_are_memoised_inside_one_read_and_not_between_requests(): void
    {
        $this->data2570();
        $items = array_map(fn ($n) => ['title' => 'ผู้บริหาร '.$n, 'source' => 'directory_executives'], [1, 2, 3, 4]);
        $this->year(2570, [['code' => 'O2', 'title' => 'ผู้บริหาร', 'items' => $items]]);
        $this->actingAs($this->admin());
        $id = DB::table('ita_years')->value('id');
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->getJson('/api/ita/years/'.$id)->assertOk();
        $person = array_filter(DB::getQueryLog(), fn ($q) => str_contains(str_replace('`', '"', $q['query']), '"posts"') && str_contains($q['query'], 'content_type')); // backticks on MariaDB
        $this->assertCount(1, $person, 'four items on the same source cost one lookup');
        // nothing is kept after the call: a later change is seen at once
        DB::table('posts')->where('content_type', 'person')->delete();
        $this->assertFalse(ItaSources::resolve('directory_executives', null, 2570)['ok']);
        $this->assertSame('missing', $this->codes(ItaCheck::run(2570))['O2']);
    }

    public function test_missing_text_does_not_blame_the_fiscal_year_for_sources_without_one(): void
    {
        // the demo school has no contact details (see contactInfo()), and no executives here
        $this->year(2570, [['code' => 'O5', 'title' => 'ติดต่อ', 'items' => [['title' => 'ติดต่อ', 'source' => 'contact']]], ['code' => 'O4', 'title' => 'ข่าว', 'items' => [['title' => 'ข่าว', 'source' => 'news']]]]);
        $html = $this->ita($this->get('/portal/ita')->assertOk()->getContent());
        $this->assertSame(2, substr_count($html, 'data-ita-missing'));
        $this->assertSame(1, substr_count($html, 'ยังไม่มีข้อมูลในปีงบประมาณนี้'), 'only the yearly source (news) says "in this fiscal year"');
        $this->assertSame(1, substr_count($html, '>ยังไม่มีข้อมูล</small>'));
        $this->assertFalse(ItaSources::resolve('contact', null, 2570)['yearly']);
        $this->assertTrue(ItaSources::resolve('news', null, 2570)['yearly']);
    }

    public function test_admin_script_checks_saved_data_and_scrolls_to_the_topic(): void
    {
        $js = (string) file_get_contents(public_path('assets/modules/web-ita/ita-admin.js'));
        $this->assertStringNotContainsString("if (!(await save(true))) return; const box", $js, 'the check never saves first');
        foreach (['savedSig', 'ita-check-year', 'data-fix', 'scrollIntoView', 'ยังไม่บันทึก'] as $needle) $this->assertStringContainsString($needle, $js, $needle);
        $this->assertStringNotContainsString('data-fix-path="#ita', $js);
    }
    // ------------------------------------------------------------------ helpers

    /** The ITA part of the page (the site header and footer have their own links, for example the staff sign-in). */
    private function ita(string $html): string
    {
        $a = strpos($html, '<section class="ita-page">');
        $this->assertNotFalse($a);
        return substr($html, $a, strpos($html, '</section>', $a) - $a);
    }

    /** @return list<string> href values of a page */
    private function hrefs(string $html): array
    {
        preg_match_all('~<a\s[^>]*href="([^"]+)"~u', $html, $m);
        return array_map(fn ($h) => html_entity_decode($h), $m[1]);
    }

    /** Switches this test to the public edition: its own database holding exactly the rows the publisher would send. */
    private function toPublicEdition(): void
    {
        $builder = app(Builder::class);
        $rows = [];
        foreach ($builder->streams() as $s) if ($s !== Wire::MODULES) $rows[$s] = array_values($builder->rows($s));
        $this->bootEdition('public', ['DB_DATABASE' => $this->tempDatabase($this->editionTemplate('public'))]);
        foreach (array_keys(app(ModuleRegistry::class)->all()) as $id) DB::table('pub_active_modules')->insert(['module_id' => $id]);
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($rows as $t => $list) foreach ($list as $r) { if ($t === 'posts') $r['author_id'] = 1; DB::table($t)->insert($r); }
        $this->refreshApplication();
        $this->assertSame('public', Edition::current());
    }
    /** A fully migrated database of the given edition, built once per test process and cloned per test (migrate:fresh takes minutes on the full schema). */
    private function editionTemplate(string $edition): string
    {
        return $this->templateDatabase('ita-edition-'.$edition, function (string $db) use ($edition): void {
            $this->bootEdition($edition, ['DB_DATABASE' => $db]);
            $this->artisan('migrate:fresh')->run();
        });
    }

    private function bootEdition(string $edition, array $env = []): void
    {
        foreach (['SAAN_EDITION' => $edition] + $env as $k => $v) {
            $this->envSet[$k] ??= ['getenv' => getenv($k), 'env' => $_ENV[$k] ?? null, 'server' => $_SERVER[$k] ?? null, 'had_env' => array_key_exists($k, $_ENV), 'had_server' => array_key_exists($k, $_SERVER)];
            putenv("$k=$v"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        $this->refreshApplication();
        $this->assertSame($edition, Edition::current());
    }
}
