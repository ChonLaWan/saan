<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Fiscal;
use App\Support\Saan\Inbox\Handlers;
use App\Support\Saan\Projection;
use App\Support\Saan\Publish\Builder;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Pairing;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\Receiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\WebCms\Hubs;
use Saan\Modules\WebIta\ItaSources;
use Tests\TestCase;

/**
 * F5b: association pages (template, committee typed by the admin, bound news category, members list as a PDF) and monthly trial-balance reports
 * (month and Buddhist-era year per document, Thai fiscal year grouping, missing months, duplicate month refused or replaced with internal history),
 * on the group hub tables, so the same visibility rules, projection and download route apply. Fictional names only.
 */
class AssociationReportsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmp = [];
    private const PUSH = 'https://public.test/_saan/push';
    private const XSS = '<script>alert(1)</script>';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2027-12-20 10:00:00'); // December 2570: every month these tests use is in the past, fiscal year 2571 has begun
        config(['school.demo' => true]);
        $this->seed();
        config(['filesystems.disks.local.root' => $this->tempDir()]); // a private root: the shared Storage::fake directory is wiped by tests running in parallel
        Storage::purge('local');
        Handlers::forget();
        config(['saan.keys_dir' => $this->tempDir()]);
        \App\Support\Saan\Publish\Outbox::forget();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        config(['database.default' => $this->baseConnection, 'saan.inbox_connection' => null, 'saan.edition' => 'combined']);
        Handlers::forget();
        try { DB::purge('pubdb'); } catch (\Throwable) {}
        parent::tearDown();
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? $this->rrmdir($p) : @unlink($p);
    }

    private function tempDir(): string
    {
        $d = sys_get_temp_dir().'/saan-assoc-'.bin2hex(random_bytes(6));
        mkdir($d, 0777, true);
        return $this->tmp[] = $d;
    }

    private function rrmdir(string $d): void
    {
        foreach (glob($d.'/{,.}*', GLOB_BRACE) ?: [] as $p) {
            if (in_array(basename($p), ['.', '..'], true)) continue;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    // ---------------------------------------------------------------- helpers

    private function admin(): static
    {
        return $this->actingAs(User::where('role', 'admin')->first());
    }

    private function pdf(string $name = 'list.pdf', string $seed = 'a'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% fictional ".$seed."\n%%EOF");
    }

    private function docx(string $name = 'members.docx'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'asc');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<x>fictional</x>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /** Creates a page from a template as admin. */
    private function tpl(string $template, array $o = [])
    {
        return $this->admin()->postJson('/api/cms/hubs/template', ['template' => $template] + $o);
    }

    private function catId(array $hub, string $label): int
    {
        return (int) collect($hub['categories'])->firstWhere('label', $label)['id'];
    }

    private function alumni(string $status = 'published', array $o = []): array
    {
        return $this->tpl('alumni', $o + ['slug' => 'alumni', 'title' => 'สมาคมศิษย์เก่าโรงเรียนต้นธารวิทยา', 'description' => 'สมาคมของศิษย์เก่าโรงเรียนต้นธารวิทยา', 'status' => $status])->assertCreated()->json();
    }

    private function finance(string $status = 'published'): array
    {
        return $this->tpl('trial_balance', ['slug' => 'finance-reports', 'status' => $status])->assertCreated()->json();
    }

    private function report(array $hub, $month, $year, array $o = [], ?UploadedFile $file = null)
    {
        return $this->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/documents', $o + ['category_id' => $this->catId($hub, 'งบทดลองรายเดือน'), 'period_month' => $month, 'period_year' => $year,
            'files' => [$file ?? $this->pdf("tb-$month-$year.pdf", $month.'-'.$year.'-'.random_int(1, 99999))]]);
    }

    private function monthlyUrl(array $hub, string $qs = ''): string
    {
        return '/portal/groups/'.$hub['hub']['slug'].'/docs/'.$this->catId($hub, 'งบทดลองรายเดือน').$qs;
    }

    private function newsPost(string $title, array $o = []): void
    {
        DB::table('posts')->insert($o + ['school_id' => 1, 'author_id' => 1, 'slug' => 'p-'.md5($title), 'title' => $title, 'body' => 'x', 'status' => 'published', 'content_type' => 'article',
            'category' => 'news', 'metadata' => '{}', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---------------------------------------------------------------- association template

    public function test_templates_create_association_pages_with_their_categories_and_a_bound_news_category(): void
    {
        $a = $this->alumni('draft');
        $this->assertSame('assoc', $a['hub']['kind']);
        $this->assertSame('draft', $a['hub']['status'], 'a new page starts as a draft');
        $this->assertSame('alumni-news', $a['hub']['news_category']);
        $this->assertSame(['รายชื่อคณะกรรมการและสมาชิก (PDF)', 'ระเบียบและประกาศ', 'เอกสารอื่น'], array_column($a['categories'], 'label'));
        $this->assertSame(['pdf', null, null], array_column($a['categories'], 'rule'));
        $this->assertSame('ข่าวสมาคมศิษย์เก่าโรงเรียนต้นธารวิทยา', DB::table('cms_categories')->where('slug', 'alumni-news')->value('label'));
        $this->assertNull($a['hub']['internal_note']);
        $this->assertSame([], $a['hub']['committee'], 'nothing is seeded: the admin types the committee');

        $p = $this->tpl('parents')->assertCreated()->json();
        $this->assertSame('parent-teacher', $p['hub']['slug']);
        $this->assertSame('สมาคมผู้ปกครองและครู', $p['hub']['title']);
        $this->assertSame('parent-teacher-news', $p['hub']['news_category']);
        $this->assertNotSame($a['hub']['news_category'], $p['hub']['news_category']);

        $actions = DB::table('audit_events')->pluck('action')->all();
        foreach (['hub.created', 'hub.category.created', 'category.saved'] as $x) $this->assertContains($x, $actions, $x);

        $this->tpl('alumni', ['slug' => 'alumni'])->assertStatus(409);
        $this->tpl('nope')->assertStatus(422);
        $this->tpl('alumni', ['slug' => 'Bad Slug'])->assertStatus(422);
        // a taken category slug is never reused: the second page of the same name gets a numbered one
        DB::table('cms_categories')->insert(['school_id' => 1, 'slug' => 'club-news', 'label' => 'ข่าวอื่น', 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->tpl('association', ['slug' => 'club', 'title' => 'ชมรมศิษย์เก่ารุ่นที่หนึ่ง'])->assertCreated()->json();
        $this->assertSame('club-news-2', $c['hub']['news_category']);
        // admin only
        $teacher = User::where('role', 'teacher')->first();
        $this->actingAs($teacher)->postJson('/api/cms/hubs/template', ['template' => 'alumni', 'slug' => 'x-alumni'])->assertStatus(403);
        // the template list is offered to the admin screen
        $over = $this->admin()->getJson('/api/cms/hubs')->assertOk()->json();
        $this->assertSame(['alumni', 'parents', 'association', 'trial_balance'], array_keys($over['templates']));
    }

    public function test_association_page_shows_about_committee_contact_and_news_of_its_category_newest_first_published_only(): void
    {
        $a = $this->alumni('draft');
        $id = $a['hub']['id'];
        $this->get('/portal/groups/alumni')->assertNotFound(); // draft: not public
        $this->assertStringNotContainsString('/portal/groups/alumni', $this->get('/sitemap.xml')->getContent());
        $this->assertStringNotContainsString('สมาคมศิษย์เก่าโรงเรียนต้นธารวิทยา', $this->get('/portal/groups')->getContent());

        $this->putJson("/api/cms/hubs/$id", ['version' => $a['hub']['version'], 'status' => 'published', 'contact' => "โทร 000-0000000\nอีเมล assoc@example.org",
            'committee' => [['name' => 'นายสมชาย ตัวอย่างดี', 'role' => 'นายกสมาคม'], ['name' => 'นางสาวสมหญิง ทดสอบ', 'role' => 'เลขานุการ'], ['name' => 'นายไม่มีตำแหน่ง', 'role' => '']]])->assertOk();
        $this->newsPost('ข่าวสมาคมเก่าสุด', ['category' => 'alumni-news', 'published_at' => now()->subDays(5)]);
        $this->newsPost('ข่าวสมาคมใหม่สุด', ['category' => 'alumni-news', 'published_at' => now()->subMinute()]);
        $this->newsPost('ข่าวสมาคมที่ปักหมุดแต่เก่า', ['category' => 'alumni-news', 'published_at' => now()->subDays(30), 'is_pinned' => 1]);
        $this->newsPost('ข่าวสมาคมฉบับร่าง', ['category' => 'alumni-news', 'status' => 'draft']);
        $this->newsPost('ข่าวสมาคมที่ถูกลบ', ['category' => 'alumni-news', 'deleted_at' => now()]);
        $this->newsPost('ข่าวหมวดอื่นของโรงเรียน', ['category' => 'news']);

        $html = $this->get('/portal/groups/alumni')->assertOk()->getContent();
        foreach (['สมาคมของศิษย์เก่าโรงเรียนต้นธารวิทยา', 'นายสมชาย ตัวอย่างดี', 'นายกสมาคม', 'เลขานุการ', 'นายไม่มีตำแหน่ง', 'assoc@example.org', 'คณะกรรมการ'] as $s) $this->assertStringContainsString($s, $html, $s);
        foreach (['ข่าวสมาคมฉบับร่าง', 'ข่าวสมาคมที่ถูกลบ', 'ข่าวหมวดอื่นของโรงเรียน'] as $s) $this->assertStringNotContainsString($s, $html, $s);
        $this->assertLessThan(strpos($html, 'ข่าวสมาคมเก่าสุด'), strpos($html, 'ข่าวสมาคมใหม่สุด'), 'newest first');
        $this->assertLessThan(strpos($html, 'ข่าวสมาคมที่ปักหมุดแต่เก่า'), strpos($html, 'ข่าวสมาคมเก่าสุด'), 'an association page does not float pinned news above newer ones');
        $all = $this->get('/portal/groups/alumni/news')->assertOk()->getContent();
        $this->assertLessThan(strpos($all, 'ข่าวสมาคมเก่าสุด'), strpos($all, 'ข่าวสมาคมใหม่สุด'));
        $this->assertStringNotContainsString('ข่าวสมาคมฉบับร่าง', $all);
        // published: in the sitemap, the index of pages and the site search
        $this->assertStringContainsString('/portal/groups/alumni', $this->get('/sitemap.xml')->getContent());
        $this->get('/portal/groups')->assertOk()->assertSee('สมาคมศิษย์เก่าโรงเรียนต้นธารวิทยา')->assertSee('สมาคม');
        // unpublish again: gone from the public side
        $cur = $this->getJson("/api/cms/hubs/$id")->json('hub.version');
        $this->putJson("/api/cms/hubs/$id", ['version' => $cur, 'status' => 'draft'])->assertOk();
        $this->get('/portal/groups/alumni')->assertNotFound();
        $this->get('/portal/groups/alumni/news')->assertNotFound();
        $this->assertStringNotContainsString('/portal/groups/alumni', $this->get('/sitemap.xml')->getContent());
    }

    public function test_committee_contact_titles_and_roles_are_escaped_and_the_committee_is_bounded(): void
    {
        $a = $this->alumni();
        $id = $a['hub']['id'];
        $x = self::XSS;
        $this->putJson("/api/cms/hubs/$id", ['version' => $a['hub']['version'], 'contact' => "ติดต่อ $x", 'description' => "เกี่ยวกับ $x", 'title' => "สมาคม $x",
            'committee' => [['name' => "นาย $x", 'role' => "ตำแหน่ง $x"]]])->assertOk();
        foreach (['/portal/groups/alumni', '/portal/groups', '/hub-preview/'.$id] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($x, $html, $url);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $url);
        }
        $cur = $this->getJson("/api/cms/hubs/$id")->json('hub.version');
        $tooMany = array_map(fn ($i) => ['name' => "ผู้ทดสอบ $i", 'role' => 'กรรมการ'], range(1, Hubs::COMMITTEE_MAX + 1));
        $this->putJson("/api/cms/hubs/$id", ['version' => $cur, 'committee' => $tooMany])->assertStatus(422);
        $this->putJson("/api/cms/hubs/$id", ['version' => $cur, 'committee' => [['name' => '', 'role' => 'x']]])->assertStatus(422);
        $this->putJson("/api/cms/hubs/$id", ['version' => $cur, 'committee' => [['name' => str_repeat('ก', 121)]]])->assertStatus(422);
        $this->putJson("/api/cms/hubs/$id", ['version' => $cur, 'committee' => [['name' => 'ผู้ทดสอบ', 'role' => 'x', 'phone' => '1']]])->assertStatus(422); // only name and role are stored
        $ok = $this->putJson("/api/cms/hubs/$id", ['version' => $cur, 'committee' => []])->assertOk()->json();
        $this->assertSame([], $ok['hub']['committee']);
    }

    public function test_the_member_list_is_a_pdf_document_never_another_type_or_a_link(): void
    {
        $a = $this->alumni();
        $id = $a['hub']['id'];
        $cat = $this->catId($a, 'รายชื่อคณะกรรมการและสมาชิก (PDF)');
        $up = fn ($files, array $o = []) => $this->postJson("/api/cms/hubs/$id/documents", $o + ['category_id' => $cat, 'files' => $files]);
        $up([$this->docx()])->assertStatus(422);
        $up([UploadedFile::fake()->createWithContent('members.pdf', '<html><table><tr><td>x</td></tr></table></html>')])->assertStatus(422); // named pdf, content is not
        $up([UploadedFile::fake()->createWithContent('members.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))])->assertStatus(422);
        $this->postJson("/api/cms/hubs/$id/documents", ['category_id' => $cat, 'title' => 'ลิงก์รายชื่อ', 'external_url' => 'https://example.org/members'])->assertStatus(422);
        $this->assertSame(0, DB::table('cms_hub_documents')->count());
        $this->assertSame(0, DB::table('cms_media')->where('folder', 'เอกสารกลุ่มงาน')->count(), 'a refused file stores nothing');
        $doc = $up([$this->pdf('members.pdf')], ['title' => 'รายชื่อคณะกรรมการ ประจำปี 2569'])->assertCreated()->json('data.0');
        $this->assertSame('PDF', $doc['type']);
        $html = $this->get('/portal/groups/alumni')->assertOk()->getContent();
        $this->assertStringContainsString('รายชื่อคณะกรรมการ ประจำปี 2569', $html);
        $this->assertStringContainsString('/portal/d/'.$doc['id'], $html);
        $this->get('/portal/d/'.$doc['id'])->assertOk()->assertHeader('content-type', 'application/pdf');
        // a Word file can live in another category of the page, but cannot be moved into the PDF-only one
        $other = $this->postJson("/api/cms/hubs/$id/documents", ['category_id' => $this->catId($a, 'เอกสารอื่น'), 'files' => [$this->docx('rules.docx')]])->assertCreated()->json('data.0');
        $this->postJson("/api/cms/hubs/$id/documents/{$other['id']}", ['version' => 1, 'category_id' => $cat])->assertStatus(422);
        $this->postJson("/api/cms/hubs/$id/documents/{$other['id']}", ['version' => 1, 'category_id' => $cat, 'file' => $this->docx('x.docx')])->assertStatus(422);
        $this->postJson("/api/cms/hubs/$id/documents/{$doc['id']}", ['version' => 1, 'file' => $this->docx('x.docx')])->assertStatus(422);
        $this->postJson("/api/cms/hubs/$id/documents/{$doc['id']}", ['version' => 1, 'external_url' => 'https://example.org/x'])->assertStatus(422);
        // the rule cannot be loosened while the category holds files, and a ruled category has no sub-categories
        $this->putJson("/api/cms/hubs/$id/categories/$cat", ['version' => 1, 'rule' => null])->assertStatus(422);
        $this->postJson("/api/cms/hubs/$id/categories", ['label' => 'หมวดย่อย', 'parent_id' => $cat])->assertStatus(422);
        $this->postJson("/api/cms/hubs/$id/categories", ['label' => 'หมวดกฎผิด', 'rule' => 'xml'])->assertStatus(422);
        $made = $this->postJson("/api/cms/hubs/$id/categories", ['label' => 'รายชื่อฝ่ายต่าง ๆ', 'rule' => 'pdf'])->assertCreated()->json();
        $this->assertSame('pdf', $made['rule']);
    }

    // ---------------------------------------------------------------- fiscal year and monthly reports

    public function test_fiscal_year_of_a_month_in_the_buddhist_era_switches_in_october(): void
    {
        $this->assertSame(2569, Fiscal::ofMonth(9, 2569), 'September 2569 closes fiscal year 2569');
        $this->assertSame(2570, Fiscal::ofMonth(10, 2569), 'October 2569 opens fiscal year 2570');
        $this->assertSame(2570, Fiscal::ofMonth(12, 2569));
        $this->assertSame(2570, Fiscal::ofMonth(1, 2570));
        $this->assertSame(2570, Fiscal::ofMonth(9, 2570));
        $this->assertSame(2571, Fiscal::ofMonth(10, 2570));
        $months = Fiscal::months(2570);
        $this->assertCount(12, $months);
        $this->assertSame([10, 2569], $months[0]);
        $this->assertSame([1, 2570], $months[3]);
        $this->assertSame([9, 2570], $months[11]);
        foreach ($months as [$m, $y]) $this->assertSame(2570, Fiscal::ofMonth($m, $y));
        $this->assertSame('ตุลาคม', Fiscal::MONTHS_TH[10]);
    }

    public function test_the_monthly_list_groups_by_fiscal_year_sorts_by_month_and_shows_missing_months_as_not_published(): void
    {
        $f = $this->finance();
        $this->assertSame('finance', $f['hub']['kind']);
        $this->assertSame('monthly', collect($f['categories'])->firstWhere('label', 'งบทดลองรายเดือน')['rule']);
        $oct = $this->report($f, 10, 2569)->assertCreated()->json('data.0');
        $jan = $this->report($f, 1, 2570)->assertCreated()->json('data.0');
        $sep70 = $this->report($f, 9, 2570)->assertCreated()->json('data.0');
        $sep69 = $this->report($f, 9, 2569)->assertCreated()->json('data.0');
        $this->assertSame('ตุลาคม 2569', $oct['period_label']);
        $this->assertSame(10, $oct['period_month']);
        $this->assertSame('งบทดลองรายเดือน เดือนตุลาคม 2569', $oct['title']);

        $y70 = $this->get($this->monthlyUrl($f, '?fy=2570'))->assertOk()->getContent();
        $this->assertStringContainsString('ปีงบประมาณ 2570 เผยแพร่แล้ว 3 จาก 12 เดือน', $y70);
        $this->assertSame(9, substr_count($y70, 'ยังไม่เผยแพร่</small>'), 'nine months of fiscal year 2570 have no document');
        foreach ([$oct, $jan, $sep70] as $d) $this->assertStringContainsString('/portal/d/'.$d['id'], $y70);
        $this->assertStringNotContainsString('/portal/d/'.$sep69['id'], $y70, 'September 2569 belongs to fiscal year 2569');
        $pos = fn (string $s) => strpos($y70, '>'.$s.'<');
        $this->assertLessThan($pos('ธันวาคม 2569'), $pos('ตุลาคม 2569'));
        $this->assertLessThan($pos('มกราคม 2570'), $pos('ธันวาคม 2569'));
        $this->assertLessThan($pos('กันยายน 2570'), $pos('กรกฎาคม 2570'));

        $y69 = $this->get($this->monthlyUrl($f, '?fy=2569'))->assertOk()->getContent();
        $this->assertStringContainsString('ปีงบประมาณ 2569 เผยแพร่แล้ว 1 จาก 12 เดือน', $y69);
        $this->assertStringContainsString('/portal/d/'.$sep69['id'], $y69);
        $this->assertStringNotContainsString('/portal/d/'.$oct['id'], $y69);
        $this->assertStringContainsString('>ตุลาคม 2568<', $y69, 'fiscal year 2569 starts in October 2568');

        // month filter: one row; an empty month still says it is not published
        $m = $this->get($this->monthlyUrl($f, '?fy=2570&month=10'))->assertOk()->getContent();
        $this->assertStringContainsString('/portal/d/'.$oct['id'], $m);
        $this->assertStringNotContainsString('/portal/d/'.$jan['id'], $m);
        $this->assertStringContainsString('เผยแพร่แล้ว 1 จาก 1 เดือน', $m);
        $empty = $this->get($this->monthlyUrl($f, '?fy=2570&month=11'))->assertOk()->getContent();
        $this->assertStringContainsString('ยังไม่เผยแพร่</small>', $empty);
        $this->assertStringContainsString('เผยแพร่แล้ว 0 จาก 1 เดือน', $empty);
        $this->assertStringNotContainsString('/portal/d/', $empty, 'a missing month is not a link');
        // no filter: the newest fiscal year that has a document
        $this->assertStringContainsString('ปีงบประมาณ 2570', $this->get($this->monthlyUrl($f))->assertOk()->getContent());
        // bad filters are refused by validation, an unknown year falls back
        $this->get($this->monthlyUrl($f, '?month=13'))->assertStatus(302);
        $this->get($this->monthlyUrl($f, '?fy=abc'))->assertStatus(302);
        $this->assertStringContainsString('ปีงบประมาณ 2570', $this->get($this->monthlyUrl($f, '?fy=2650'))->assertOk()->getContent());
        // the hub page lists the newest month first and labels documents by month
        $box = $this->get('/portal/groups/finance-reports')->assertOk()->getContent();
        $this->assertLessThan(strpos($box, 'ตุลาคม 2569'), strpos($box, 'กันยายน 2570'));
    }

    public function test_a_second_published_document_for_the_same_month_is_refused_unless_it_replaces_the_first_and_the_history_stays_internal(): void
    {
        $f = $this->finance();
        $first = $this->report($f, 11, 2569)->assertCreated()->json('data.0');
        $this->report($f, 11, 2569)->assertStatus(409);
        $this->assertSame(1, DB::table('cms_hub_documents')->count());
        $this->assertSame(1, DB::table('cms_media')->where('folder', 'เอกสารกลุ่มงาน')->count(), 'the refused file was not stored');
        // another month, another year, and a draft of the same month are all fine
        $this->report($f, 12, 2569)->assertCreated();
        $this->report($f, 11, 2570)->assertCreated();
        $draft = $this->report($f, 11, 2569, ['is_published' => false])->assertCreated()->json('data.0');
        // publishing that draft would duplicate the month
        $this->postJson('/api/cms/hubs/'.$f['hub']['id'].'/documents/publish', ['published' => true, 'items' => [['id' => $draft['id'], 'version' => $draft['version']]]])->assertStatus(409);
        $this->assertSame(0, (int) DB::table('cms_hub_documents')->where('id', $draft['id'])->value('is_published'));
        // replace: the old file is withdrawn and kept internally
        $new = $this->report($f, 11, 2569, ['replace' => true], $this->pdf('tb-new.pdf', 'new'))->assertCreated()->json('data.0');
        $old = DB::table('cms_hub_documents')->where('id', $first['id'])->first();
        $this->assertSame(0, (int) $old->is_published);
        $this->assertNotNull($old->superseded_at);
        $this->assertTrue(DB::table('cms_media')->where('id', $old->media_id)->exists(), 'the replaced file is kept');
        $this->assertTrue(Storage::disk('local')->exists(DB::table('cms_media')->where('id', $old->media_id)->value('path')));
        $this->assertContains('hub.document.superseded', DB::table('audit_events')->pluck('action')->all());
        $detail = $this->getJson('/api/cms/hubs/'.$f['hub']['id'])->assertOk()->json('documents');
        $this->assertTrue(collect($detail)->firstWhere('id', $first['id'])['superseded']);
        $this->assertFalse(collect($detail)->firstWhere('id', $new['id'])['superseded']);
        // public side: only the new file, the old one is not downloadable and not projected
        $html = $this->get($this->monthlyUrl($f, '?fy=2570'))->assertOk()->getContent();
        $this->assertStringContainsString('/portal/d/'.$new['id'], $html);
        $this->assertStringNotContainsString('/portal/d/'.$first['id'], $html);
        $this->get('/portal/d/'.$first['id'])->assertNotFound();
        $b = new Builder();
        $ids = array_map('intval', array_column($b->rows('cms_hub_documents'), 'id'));
        $this->assertContains((int) $new['id'], $ids);
        $this->assertNotContains((int) $first['id'], $ids);
        $this->assertNotContains((int) $old->media_id, array_map('intval', array_column($b->rows('cms_media'), 'id')));
        $this->assertStringNotContainsString('superseded', json_encode($b->rows('cms_hub_documents')));
        // editing: moving a published document onto an occupied month needs the replace flag as well
        $dec = DB::table('cms_hub_documents')->where('period_month', 12)->first();
        $this->postJson('/api/cms/hubs/'.$f['hub']['id']."/documents/{$dec->id}", ['version' => $dec->version, 'period_month' => 11])->assertStatus(409);
        $this->postJson('/api/cms/hubs/'.$f['hub']['id']."/documents/{$dec->id}", ['version' => $dec->version, 'period_month' => 11, 'replace' => true])->assertOk();
        $this->assertSame(0, (int) DB::table('cms_hub_documents')->where('id', $new['id'])->value('is_published'));
        // the replaced file cannot simply come back while another file holds that month
        $this->postJson('/api/cms/hubs/'.$f['hub']['id'].'/documents/publish', ['published' => true, 'items' => [['id' => $new['id'], 'version' => (int) DB::table('cms_hub_documents')->where('id', $new['id'])->value('version')]]])->assertStatus(409);
    }

    public function test_invalid_month_year_file_count_and_missing_file_are_refused(): void
    {
        $f = $this->finance();
        foreach ([[0, 2569], [13, 2569], [10, 2400], [10, 2701], ['abc', 2569], [10, 'xyz']] as [$m, $y]) {
            $this->report($f, $m, $y)->assertStatus(422);
        }
        $cat = $this->catId($f, 'งบทดลองรายเดือน');
        $url = '/api/cms/hubs/'.$f['hub']['id'].'/documents';
        $this->postJson($url, ['category_id' => $cat, 'files' => [$this->pdf()]])->assertStatus(422); // month and year missing
        $this->postJson($url, ['category_id' => $cat, 'period_month' => 10, 'files' => [$this->pdf()]])->assertStatus(422);
        $this->postJson($url, ['category_id' => $cat, 'period_month' => 10, 'period_year' => 2569])->assertStatus(422); // no file
        $this->postJson($url, ['category_id' => $cat, 'period_month' => 10, 'period_year' => 2569, 'external_url' => 'https://example.org/tb'])->assertStatus(422); // no link
        $this->postJson($url, ['category_id' => $cat, 'period_month' => 10, 'period_year' => 2569, 'files' => [$this->pdf('a.pdf', 'a'), $this->pdf('b.pdf', 'b')]])->assertStatus(422); // one file per month
        $this->postJson($url, ['category_id' => $cat, 'period_month' => 10, 'period_year' => 2569, 'files' => [$this->docx('tb.docx')]])->assertStatus(422);
        $this->assertSame(0, DB::table('cms_hub_documents')->count());
        $this->assertSame(0, DB::table('cms_media')->where('folder', 'เอกสารกลุ่มงาน')->count());
        $ok = $this->report($f, 10, 2569)->assertCreated()->json('data.0');
        $this->postJson("$url/{$ok['id']}", ['version' => $ok['version'], 'period_month' => 14])->assertStatus(422);
        $this->postJson("$url/{$ok['id']}", ['version' => $ok['version'], 'period_year' => 1999])->assertStatus(422);
        // a document of an ordinary category keeps no period even if one is sent
        $other = $this->postJson($url, ['category_id' => $this->catId($f, 'รายงานการเงินอื่น'), 'period_month' => 3, 'period_year' => 2570, 'files' => [$this->docx('other.docx')]])->assertCreated()->json('data.0');
        $this->assertNull($other['period_month']);
    }

    public function test_titles_of_reports_are_escaped_and_draft_months_are_not_listed(): void
    {
        $f = $this->finance();
        $x = self::XSS;
        $d = $this->report($f, 10, 2569, ['title' => "งบทดลอง $x"])->assertCreated()->json('data.0');
        $draft = $this->report($f, 11, 2569, ['is_published' => false, 'title' => 'งบทดลองฉบับร่างลับ'])->assertCreated()->json('data.0');
        $html = $this->get($this->monthlyUrl($f, '?fy=2570'))->assertOk()->getContent();
        $this->assertStringNotContainsString($x, $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('งบทดลองฉบับร่างลับ', $html);
        $this->assertStringNotContainsString('/portal/d/'.$draft['id'], $html);
        $this->assertStringContainsString('เผยแพร่แล้ว 1 จาก 12 เดือน', $html);
        $this->get('/portal/d/'.$draft['id'])->assertNotFound();
        $this->assertStringNotContainsString($x, $this->get('/portal/groups/finance-reports')->getContent());
        // a draft page has no public list at all
        $cur = $this->getJson('/api/cms/hubs/'.$f['hub']['id'])->json('hub.version');
        $this->putJson('/api/cms/hubs/'.$f['hub']['id'], ['version' => $cur, 'status' => 'draft'])->assertOk();
        $this->get($this->monthlyUrl($f, '?fy=2570'))->assertNotFound();
        $this->get('/portal/d/'.$d['id'])->assertNotFound();
    }

    // ---------------------------------------------------------------- ITA source

    public function test_the_trial_balance_is_an_automatic_ita_source_with_the_months_as_evidence(): void
    {
        $this->assertArrayHasKey('finance_report', ItaSources::KEYS);
        $r = fn (int $fy, ?array $o = null) => ItaSources::resolve('finance_report', $o, $fy);
        $this->assertFalse($r(2570)['ok'], 'no page yet');
        $f = $this->finance();
        $this->assertFalse($r(2570)['ok'], 'no document yet');
        $cat = $this->catId($f, 'งบทดลองรายเดือน');
        foreach ([[10, 2569], [11, 2569], [9, 2569]] as [$m, $y]) $this->report($f, $m, $y)->assertCreated();
        $ok = $r(2570);
        $this->assertTrue($ok['ok']);
        $this->assertSame("/portal/groups/finance-reports/docs/$cat?fy=2570", $ok['path']);
        $this->assertSame('งบทดลอง 2 เดือน ในปีงบ 2570', $ok['evidence']);
        $this->assertSame('งบทดลอง 1 เดือน ในปีงบ 2569', $r(2569)['evidence']);
        $miss = $r(2571);
        $this->assertFalse($miss['ok']);
        $this->assertTrue($miss['yearly']);
        $this->assertTrue($r(2570, ['slug' => 'finance-reports'])['ok']);
        $this->assertFalse($r(2570, ['slug' => 'ghost'])['ok']);
        $this->assertSame(['slug' => 'finance-reports'], ItaSources::cleanOpts('finance_report', ['slug' => 'finance-reports', 'x' => 'y']));
        // a draft page gives nothing
        $cur = $this->getJson('/api/cms/hubs/'.$f['hub']['id'])->json('hub.version');
        $this->putJson('/api/cms/hubs/'.$f['hub']['id'], ['version' => $cur, 'status' => 'draft'])->assertOk();
        $this->assertFalse($r(2570)['ok']);
    }

    // ---------------------------------------------------------------- projection and the public edition

    public function test_projection_carries_the_new_public_columns_and_nothing_internal(): void
    {
        $decl = collect(Projection::declared(true, true))->keyBy('table');
        foreach (['contact', 'committee'] as $c) $this->assertSame('keep', $decl['cms_hubs']['columns'][$c]);
        $this->assertSame('keep', $decl['cms_hub_categories']['columns']['rule']);
        foreach (['period_month', 'period_year'] as $c) $this->assertSame('keep', $decl['cms_hub_documents']['columns'][$c]);
        $this->assertArrayNotHasKey('superseded_at', $decl['cms_hub_documents']['columns']);
        $this->assertArrayNotHasKey('note', $decl['cms_hub_documents']['columns']);
        $this->assertArrayNotHasKey('internal_note', $decl['cms_hubs']['columns']);
    }

    public function test_builder_exports_published_pages_and_documents_only(): void
    {
        $draft = $this->alumni('draft', ['slug' => 'alumni-draft', 'contact' => 'ฉบับร่างลับ']);
        $live = $this->tpl('parents', ['status' => 'published', 'contact' => 'โทร 000-0000000', 'committee' => [['name' => 'นายตัวอย่าง ทดสอบ', 'role' => 'นายกสมาคม']]])->assertCreated()->json();
        $list = $this->catId($live, 'รายชื่อคณะกรรมการและสมาชิก (PDF)');
        $shown = $this->postJson('/api/cms/hubs/'.$live['hub']['id'].'/documents', ['category_id' => $list, 'files' => [$this->pdf('shown.pdf', 's')], 'title' => 'รายชื่อกรรมการที่เผยแพร่'])->assertCreated()->json('data.0');
        $hidden = $this->postJson('/api/cms/hubs/'.$live['hub']['id'].'/documents', ['category_id' => $list, 'files' => [$this->pdf('unpub.pdf', 'u')], 'is_published' => false, 'title' => 'รายชื่อสมาชิกที่ยังไม่เผยแพร่'])->assertCreated()->json('data.0');
        $inDraft = $this->postJson('/api/cms/hubs/'.$draft['hub']['id'].'/documents', ['category_id' => $this->catId($draft, 'รายชื่อคณะกรรมการและสมาชิก (PDF)'), 'files' => [$this->pdf('dh.pdf', 'h')]])->assertCreated()->json('data.0');
        $f = $this->finance();
        $tb = $this->report($f, 10, 2569)->assertCreated()->json('data.0');
        $tbDraft = $this->report($f, 11, 2569, ['is_published' => false])->assertCreated()->json('data.0');
        $b = new Builder();
        $hubs = $b->rows('cms_hubs');
        $this->assertEqualsCanonicalizing(['parent-teacher', 'finance-reports'], array_column($hubs, 'slug'));
        $row = collect($hubs)->firstWhere('slug', 'parent-teacher');
        $this->assertSame('โทร 000-0000000', $row['contact']);
        $this->assertStringContainsString('นายตัวอย่าง ทดสอบ', (string) $row['committee']);
        $docs = array_map('intval', array_column($b->rows('cms_hub_documents'), 'id'));
        $this->assertEqualsCanonicalizing([(int) $shown['id'], (int) $tb['id']], $docs);
        $media = array_map('intval', array_column($b->rows('cms_media'), 'id'));
        foreach ([$hidden, $inDraft, $tbDraft] as $d) $this->assertNotContains((int) $d['media_id'], $media);
        $tbRow = collect($b->rows('cms_hub_documents'))->firstWhere('id', (string) $tb['id']);
        $this->assertSame('10', (string) $tbRow['period_month']);
        $this->assertSame('2569', (string) $tbRow['period_year']);
        $cats = collect($b->rows('cms_hub_categories'))->where('rule', 'monthly');
        $this->assertCount(1, $cats);
        $json = json_encode([$hubs, $b->rows('cms_hub_categories'), $b->rows('cms_hub_documents')], JSON_UNESCAPED_UNICODE);
        foreach (['ฉบับร่างลับ', 'ที่ยังไม่เผยแพร่', 'unpub.pdf', 'dh.pdf'] as $n) $this->assertStringNotContainsString($n, $json, $n);
    }

    private function publicSide(string $name, string $disk): string
    {
        $db = $this->migratedTempConnection($name); // a fresh copy of a fully migrated temporary database, dropped at teardown
        config(["filesystems.disks.$disk" => ['driver' => 'local', 'root' => $this->tempDir()]]);
        return $db;
    }

    private function as(string $edition, ?string $conn = null): void
    {
        config(['saan.edition' => $edition, 'database.default' => $conn ?? $this->baseConnection, 'saan.inbox_connection' => $conn]);
    }

    public function test_public_edition_renders_both_kinds_of_page_from_projected_rows_and_hides_drafts(): void
    {
        $this->as('internal');
        config(['saan.public_push_url' => self::PUSH]);
        $a = $this->alumni('published', ['contact' => 'โทร 000-0000000', 'committee' => [['name' => 'นายสมชาย ตัวอย่างดี', 'role' => 'นายกสมาคม']]]);
        $draftPage = $this->tpl('parents', ['status' => 'draft', 'contact' => 'ฉบับร่างลับ'])->assertCreated()->json();
        $members = $this->postJson('/api/cms/hubs/'.$a['hub']['id'].'/documents', ['category_id' => $this->catId($a, 'รายชื่อคณะกรรมการและสมาชิก (PDF)'), 'files' => [$this->pdf('m.pdf')], 'title' => 'รายชื่อกรรมการสมาคม'])->assertCreated()->json('data.0');
        $this->newsPost('ข่าวสมาคมที่เผยแพร่', ['category' => 'alumni-news']);
        $this->newsPost('ข่าวสมาคมที่ยังเป็นร่าง', ['category' => 'alumni-news', 'status' => 'draft']);
        $f = $this->finance();
        $oct = $this->report($f, 10, 2569, ['title' => 'งบทดลอง ตุลาคม 2569 ฉบับทดสอบ'])->assertCreated()->json('data.0');
        $draftTb = $this->report($f, 11, 2569, ['is_published' => false, 'title' => 'งบทดลองร่างลับ'])->assertCreated()->json('data.0');
        $sep = $this->report($f, 9, 2570)->assertCreated()->json('data.0');
        $url = $this->monthlyUrl($f, '?fy=2570');
        $internal = $this->get($url)->assertOk()->getContent();
        $internalCount = substr_count($internal, 'ยังไม่เผยแพร่</small>');
        $this->assertSame(10, $internalCount);

        $this->publicSide('pubdb', 'pubfiles');
        $receiver = new Receiver('pubdb', Keys::publicKey(), null);
        $pub = new Publisher(null, function (string $body, array $headers) use ($receiver) {
            config(['saan.push.receive_disk' => 'pubfiles']);
            return $receiver->handle($body, $headers);
        });
        $r = $pub->run(600);
        $this->assertSame(0, $r['failed'], json_encode($r));
        $this->assertTrue($pub->verify()['ok']);
        $p = DB::connection('pubdb');
        $this->assertEqualsCanonicalizing(['alumni', 'finance-reports'], $p->table('cms_hubs')->pluck('slug')->all());
        $this->assertEqualsCanonicalizing([(int) $members['id'], (int) $oct['id'], (int) $sep['id']], $p->table('cms_hub_documents')->pluck('id')->map('intval')->all());
        $this->assertSame(1, $p->table('cms_hub_categories')->where('rule', 'monthly')->count());
        $this->as('internal');
        $c = Pairing::issue(1, null, self::PUSH);
        [$st, $reply] = Pairing::redeem($c['code'], self::PUSH, '10.0.0.1');
        $this->assertSame(200, $st);
        Keys::applyBundle($reply['bundle']);
        config(['filesystems.disks.local' => config('filesystems.disks.pubfiles')]);
        Storage::purge('local');
        $this->as('public', 'pubdb');
        config(['school.demo' => false, 'school.local_only' => false]);

        $page = $this->get('/portal/groups/alumni')->assertOk()->getContent();
        foreach (['นายสมชาย ตัวอย่างดี', 'นายกสมาคม', 'โทร 000-0000000', 'รายชื่อกรรมการสมาคม', 'ข่าวสมาคมที่เผยแพร่', '/portal/d/'.$members['id']] as $s) $this->assertStringContainsString($s, $page, $s);
        foreach (['ข่าวสมาคมที่ยังเป็นร่าง', 'ฉบับร่างลับ'] as $s) $this->assertStringNotContainsString($s, $page, $s);
        $this->get('/portal/groups/parent-teacher')->assertNotFound();
        $this->assertStringNotContainsString('parent-teacher', $this->get('/sitemap.xml')->getContent());
        $this->assertStringContainsString('/portal/groups/alumni', $this->get('/sitemap.xml')->getContent());

        $public = $this->get($url)->assertOk()->getContent();
        $this->assertSame($internalCount, substr_count($public, 'ยังไม่เผยแพร่</small>'), 'same missing months as the internal edition');
        $this->assertStringContainsString('ปีงบประมาณ 2570 เผยแพร่แล้ว 2 จาก 12 เดือน', $public);
        $this->assertStringContainsString('/portal/d/'.$oct['id'], $public);
        $this->assertStringContainsString('/portal/d/'.$sep['id'], $public);
        $this->assertStringNotContainsString('งบทดลองร่างลับ', $public);
        $this->assertStringNotContainsString('/portal/d/'.$draftTb['id'], $public);
        $this->assertStringContainsString('ปีงบประมาณ 2570 เผยแพร่แล้ว 1 จาก 1 เดือน', $this->get($this->monthlyUrl($f, '?fy=2570&month=9'))->assertOk()->getContent());
        $this->get('/portal/d/'.$draftTb['id'])->assertNotFound();
        $this->get("/media/{$oct['media_id']}")->assertNotFound();
    }

    // ---------------------------------------------------------------- review fixes (R7)

    public function test_changing_the_file_of_a_published_monthly_report_keeps_the_old_file_internally(): void
    {
        $f = $this->finance();
        $first = $this->report($f, 11, 2569)->assertCreated()->json('data.0');
        $oldMedia = (int) $first['media_id'];
        $url = '/api/cms/hubs/'.$f['hub']['id']."/documents/{$first['id']}";
        $row = $this->postJson($url, ['version' => $first['version'], 'period_month' => 11, 'period_year' => 2569, 'file' => $this->pdf('tb-fixed.pdf', 'fixed')])->assertOk()->json();
        $this->assertNotSame((int) $first['id'], (int) $row['id'], 'the new file is a new row');
        $this->assertNotSame($oldMedia, (int) $row['media_id']);
        $this->assertTrue((bool) $row['is_published']);
        $this->assertSame('พฤศจิกายน 2569', $row['period_label']);
        $old = DB::table('cms_hub_documents')->where('id', $first['id'])->first();
        $this->assertSame(0, (int) $old->is_published);
        $this->assertNotNull($old->superseded_at);
        $this->assertSame($oldMedia, (int) $old->media_id);
        $this->assertTrue(DB::table('cms_media')->where('id', $oldMedia)->exists(), 'the replaced file row is kept');
        $this->assertTrue(Storage::disk('local')->exists(DB::table('cms_media')->where('id', $oldMedia)->value('path')), 'and the file on disk');
        $this->assertSame(1, DB::table('cms_hub_documents')->where('category_id', $this->catId($f, 'งบทดลองรายเดือน'))->where('period_month', 11)->where('period_year', 2569)->where('is_published', 1)->count());
        $html = $this->get($this->monthlyUrl($f, '?fy=2570'))->assertOk()->getContent();
        $this->assertStringContainsString('/portal/d/'.$row['id'], $html);
        $this->assertStringNotContainsString('/portal/d/'.$first['id'], $html);
        $this->get('/portal/d/'.$first['id'])->assertNotFound();
        $this->assertNotContains($oldMedia, array_map('intval', array_column((new Builder())->rows('cms_media'), 'id')), 'the old file never leaves');
        // a stale version of the old row is still refused (optimistic lock)
        $this->postJson($url, ['version' => $first['version'], 'file' => $this->pdf('again.pdf', 'again')])->assertStatus(409);
        // the edit form hides the file field of a monthly category
        $js = (string) file_get_contents(public_path('assets/modules/web-cms/hubs.js'));
        $this->assertStringContainsString('data-hide-monthly', $js);
    }

    public function test_monthly_period_must_be_a_past_month_inside_the_allowed_years(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2027-06-15 10:00:00'); // June 2570
        $f = $this->finance();
        $this->report($f, 6, 2570)->assertCreated(); // this month
        $this->report($f, 7, 2570)->assertStatus(422); // later this year
        $this->report($f, 5, 2659)->assertStatus(422); // typo of 2569
        $this->report($f, 5, 2571)->assertStatus(422);
        $this->report($f, 5, 2549)->assertStatus(422); // older than 20 years
        $this->report($f, 5, 2550)->assertCreated();
        $doc = $this->report($f, 4, 2570)->assertCreated()->json('data.0');
        $edit = fn (array $o) => $this->postJson('/api/cms/hubs/'.$f['hub']['id']."/documents/{$doc['id']}", ['version' => $doc['version']] + $o);
        $edit(['period_month' => 8, 'period_year' => 2570])->assertStatus(422);
        $edit(['period_month' => 4, 'period_year' => 2659])->assertStatus(422);
        $this->assertSame([2550, 2570], \App\Support\Fiscal::reportYears());
    }

    public function test_the_fiscal_year_list_follows_real_documents_and_a_mistyped_year_cannot_hide_the_real_ones(): void
    {
        $f = $this->finance();
        $real = $this->report($f, 11, 2569)->assertCreated()->json('data.0'); // fiscal year 2570
        // a legacy row typed 2659 instead of 2569 (the API no longer accepts it) and one in October 2700
        foreach ([[5, 2659], [10, 2700]] as [$m, $y]) {
            DB::table('cms_hub_documents')->insert(['school_id' => 1, 'hub_id' => $f['hub']['id'], 'category_id' => $this->catId($f, 'งบทดลองรายเดือน'), 'title' => "legacy $m/$y", 'doc_date' => '2026-02-01',
                'media_id' => (int) $real['media_id'], 'is_published' => 1, 'sort' => 0, 'period_month' => $m, 'period_year' => $y, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $hub = DB::table('cms_hubs')->where('id', $f['hub']['id'])->first();
        $cat = DB::table('cms_hub_categories')->where('id', $this->catId($f, 'งบทดลองรายเดือน'))->first();
        $fys = Hubs::monthly($hub, $cat, [])['fys'];
        foreach ([2701, 2659, 2571, 2570] as $y) $this->assertContains($y, $fys, (string) $y);
        $this->assertNotContains(2600, $fys, 'no run of invented years between the real ones');
        $this->assertSame($fys, collect($fys)->sortDesc()->values()->all());
        $m = Hubs::monthly($hub, $cat, ['fy' => 2570]);
        $this->assertSame(2570, $m['fy'], 'the real fiscal year is still reachable');
        $this->assertSame(1, $m['published']);
        $this->assertTrue(ItaSources::resolve('finance_report', null, 2570)['ok'], 'ITA still sees fiscal year 2570');
        // a year inside the allowed range without any document is shown as 12 months not published
        $old = Hubs::monthly($hub, $cat, ['fy' => 2565]);
        $this->assertSame(2565, $old['fy']);
        $this->assertSame(0, $old['published']);
        $this->assertCount(12, $old['rows']);
        $html = $this->get($this->monthlyUrl($f, '?fy=2565'))->assertOk()->getContent();
        $this->assertStringContainsString('ปีงบประมาณ 2565 เผยแพร่แล้ว 0 จาก 12 เดือน', $html);
        // far outside the range falls back quietly; the highest year the fiscal calendar can produce is accepted by the route
        $this->assertNotSame(2650, Hubs::monthly($hub, $cat, ['fy' => 2650])['fy']);
        $this->get($this->monthlyUrl($f, '?fy=2701'))->assertOk()->assertSee('ปีงบประมาณ 2701');
        $this->get($this->monthlyUrl($f, '?fy=2702'))->assertStatus(302);
    }

    public function test_the_news_category_of_a_draft_page_stays_off_the_site_until_the_page_is_published(): void
    {
        $a = $this->alumni('draft', ['slug' => 'alumni-new', 'title' => 'สมาคมศิษย์เก่ารุ่นใหม่']);
        $label = 'ข่าวสมาคมศิษย์เก่ารุ่นใหม่';
        $this->assertSame($label, DB::table('cms_categories')->where('slug', $a['hub']['news_category'])->value('label'));
        $this->assertStringNotContainsString($label, $this->get('/portal/news')->assertOk()->getContent());
        $this->assertNotContains($a['hub']['news_category'], array_column((new Builder())->rows('cms_categories'), 'slug'), 'not projected to the public edition');
        $this->assertContains('news', array_column((new Builder())->rows('cms_categories'), 'slug'), 'ordinary categories still are');
        $this->putJson('/api/cms/hubs/'.$a['hub']['id'], ['version' => $a['hub']['version'], 'status' => 'published'])->assertOk();
        $this->assertStringContainsString($label, $this->get('/portal/news')->assertOk()->getContent());
        $this->assertContains($a['hub']['news_category'], array_column((new Builder())->rows('cms_categories'), 'slug'));
    }

    public function test_menu_items_that_point_to_an_unpublished_page_are_not_shown(): void
    {
        $a = $this->alumni('draft');
        $nav = [['label' => 'เมนูหน้าแรกทดสอบ', 'url' => '/portal'], ['label' => 'เมนูสมาคมทดสอบ', 'url' => '/portal/groups/alumni'],
            ['label' => 'เมนูกลุ่มทดสอบ', 'url' => '/portal/groups', 'children' => [['label' => 'เมนูซ้อนสมาคมทดสอบ', 'url' => '/portal/groups/alumni?x=1']]]];
        DB::table('cms_site_settings')->insertOrIgnore(['school_id' => 1, 'draft' => '{}', 'published' => '{}', 'version' => 0, 'published_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cms_site_settings')->where('school_id', 1)->update(['published' => json_encode(array_replace(\App\Domain\Cms\SiteSettings::defaults(), ['navigation' => $nav]), JSON_UNESCAPED_UNICODE)]);
        $html = $this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('เมนูหน้าแรกทดสอบ', $html);
        $this->assertStringContainsString('เมนูกลุ่มทดสอบ', $html, 'the index of pages has no slug and stays');
        foreach (['เมนูสมาคมทดสอบ', 'เมนูซ้อนสมาคมทดสอบ'] as $s) $this->assertStringNotContainsString($s, $html, $s);
        $this->putJson('/api/cms/hubs/'.$a['hub']['id'], ['version' => $a['hub']['version'], 'status' => 'published'])->assertOk();
        $html = $this->get('/portal')->assertOk()->getContent();
        foreach (['เมนูสมาคมทดสอบ', 'เมนูซ้อนสมาคมทดสอบ'] as $s) $this->assertStringContainsString($s, $html, $s);
        $this->assertStringContainsString('กลุ่มงาน กลุ่มสาระ สมาคม และรายงาน', (string) file_get_contents(public_path('assets/cms-complete.js')));
    }

    public function test_demo_hub_pages_are_seeded_once_with_fictional_data(): void
    {
        $this->admin();
        $r = \Saan\Modules\WebCms\HubDemo::seed();
        $this->assertSame(['demo-alumni', 'demo-finance'], $r['created']);
        $again = \Saan\Modules\WebCms\HubDemo::seed();
        $this->assertSame([], $again['created']);
        $this->assertSame(2, DB::table('cms_hubs')->whereIn('slug', ['demo-alumni', 'demo-finance'])->count());
        $this->assertSame(4, DB::table('cms_hub_documents')->count(), 'one member list and three monthly reports, nothing doubled');
        $page = $this->get('/portal/groups/demo-alumni')->assertOk()->getContent();
        foreach (['นายสมชาย ตัวอย่างดี (สมมติ)', 'นายกสมาคม', 'รายชื่อคณะกรรมการสมาคม (ตัวอย่าง)'] as $s) $this->assertStringContainsString($s, $page, $s);
        $cat = (int) DB::table('cms_hub_categories')->where('rule', 'monthly')->value('id');
        $list = $this->get('/portal/groups/demo-finance/docs/'.$cat)->assertOk()->getContent();
        $this->assertStringContainsString('ปีงบประมาณ 2571 เผยแพร่แล้ว 2 จาก 12 เดือน', $list); // October and November 2570
        $this->assertStringContainsString('ปีงบประมาณ 2570 เผยแพร่แล้ว 1 จาก 12 เดือน', $this->get('/portal/groups/demo-finance/docs/'.$cat.'?fy=2570')->assertOk()->getContent()); // September 2570
        $doc = DB::table('cms_hub_documents')->where('category_id', $cat)->first();
        $this->get('/portal/d/'.$doc->id)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertTrue(ItaSources::resolve('finance_report', null, 2571)['ok'], 'the ITA source finds the demo months');
    }

    public function test_ita_budget_item_uses_the_trial_balance_and_falls_back_to_the_budget_group_page(): void
    {
        $this->assertSame('finance_report', \Saan\Modules\WebIta\ItaCatalog::ITEMS['O16']['source']);
        $this->assertFalse(ItaSources::resolve('finance_report', null, 2570)['ok'], 'nothing yet');
        DB::table('cms_hubs')->insert(['school_id' => 1, 'slug' => 'budget', 'title' => 'กลุ่มงานงบประมาณ', 'kind' => 'work', 'status' => 'published', 'news_limit' => 5, 'box_limit' => 5, 'sort' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $hubId = (int) DB::table('cms_hubs')->where('slug', 'budget')->value('id');
        $catId = (int) DB::table('cms_hub_categories')->insertGetId(['school_id' => 1, 'hub_id' => $hubId, 'label' => 'แผนงบประมาณ', 'sort' => 10, 'is_hidden' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cms_hub_documents')->insert(['school_id' => 1, 'hub_id' => $hubId, 'category_id' => $catId, 'title' => 'แผนงบประมาณตัวอย่าง', 'doc_date' => '2027-01-01', 'external_url' => 'https://example.org/plan', 'is_published' => 1, 'sort' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $r = ItaSources::resolve('finance_report', null, 2570);
        $this->assertTrue($r['ok']);
        $this->assertSame('/portal/groups/budget', $r['path'], 'no trial-balance page: the budget group page, as before');
        $f = $this->finance();
        $this->report($f, 10, 2569)->assertCreated();
        $this->assertStringContainsString('?fy=2570', ItaSources::resolve('finance_report', null, 2570)['path'], 'once the trial-balance page exists it wins');
    }
}
