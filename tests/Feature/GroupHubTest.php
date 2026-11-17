<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Inbox;
use App\Support\Saan\Inbox\Handlers;
use App\Support\Saan\Inbox\Puller;
use App\Support\Saan\Inbox\PullServer;
use App\Support\Saan\Projection;
use App\Support\Saan\Publish\Builder;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Pairing;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Publish\Receiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\WebCms\Downloads;
use Saan\Modules\WebCms\Hubs;
use Tests\TestCase;

/**
 * F4 group hubs (web-cms): hub pages with document boxes, view-all lists, staff from the public directory allowlist, group news, the counting
 * download route (no personal data, per-network daily ceiling in cache, https-only stored redirect), file type checks, who may manage which hub,
 * and the split editions (projection, public rendering from projected rows, counts flowing back as aggregates).
 */
class GroupHubTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmp = [];
    private const PUSH = 'https://public.test/_saan/push';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        config(['filesystems.disks.local.root' => $this->tempDir()]); // a private root: the shared Storage::fake directory is wiped by tests running in parallel
        Storage::purge('local');
        Handlers::forget();
        $keys = $this->tempDir();
        config(['saan.keys_dir' => $keys]);
        \App\Support\Saan\Publish\Outbox::forget();
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->baseConnection, 'saan.inbox_connection' => null, 'saan.edition' => 'combined']);
        Handlers::forget();
        try { DB::purge('pubdb'); } catch (\Throwable) {}
        parent::tearDown();
        gc_collect_cycles();
        foreach ($this->tmp as $p) is_dir($p) ? $this->rrmdir($p) : @unlink($p);
    }

    private function tempDir(): string
    {
        $d = sys_get_temp_dir().'/saan-hub-'.bin2hex(random_bytes(6));
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

    /** Signs everybody out (the public pages are also served to signed-in staff, so tests that need a plain visitor call this). */
    private function guest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        return $this;
    }

    private function admin(): static
    {
        return $this->actingAs(User::where('role', 'admin')->first());
    }

    /** A staff account of $role, optionally linked to teacher $teacherId. */
    private function staff(string $role, ?int $teacherId = null): User
    {
        $email = $role.'-'.($teacherId ?? 'x').'@hub.test';
        $u = User::where('email', $email)->first();
        if (!$u) {
            $row = (array) DB::table('users')->where('role', 'teacher')->first();
            unset($row['id']);
            DB::table('users')->insert(array_merge($row, ['email' => $email, 'name' => 'ผู้ใช้ทดสอบ '.$role, 'role' => $role, 'teacher_id' => $teacherId, 'classroom_id' => null]));
            $u = User::where('email', $email)->first();
        }
        return $u;
    }

    private function teacher(int $id, string $name, array $profile = []): int
    {
        $row = ['id' => $id, 'school_id' => 1, 'name' => $name, 'subject' => 'x'];
        if (Schema::hasColumn('teachers', 'is_active')) $row['is_active'] = $profile['_active'] ?? 1;
        DB::table('teachers')->insert($row);
        unset($profile['_active']);
        if ($profile !== []) DB::table('per_profiles')->insert($profile + ['school_id' => 1, 'teacher_id' => $id, 'show_in_directory' => 1, 'directory_order' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function pdf(string $name = 'order.pdf', string $seed = 'a'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% fictional ".$seed."\n%%EOF");
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function docx(string $name = 'plan.docx'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hub');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<x>fictional</x>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /** Creates a hub as admin through the API; returns the detail payload. */
    private function makeHub(string $slug = 'academic', string $group = 'บริหารวิชาการ', string $status = 'published', array $extra = []): array
    {
        return $this->admin()->postJson('/api/cms/hubs', $extra + ['slug' => $slug, 'title' => 'กลุ่มงาน'.$group, 'kind' => 'work', 'group_name' => $group, 'status' => $status, 'description' => 'งานวิชาการของโรงเรียนต้นธารวิทยา'])
            ->assertCreated()->json();
    }

    private function catId(array $hub, string $label): int
    {
        return (int) collect($hub['categories'])->firstWhere('label', $label)['id'];
    }

    /** Inserts a document row directly (bulk data for lists and paging). */
    private function doc(array $hub, string $label, string $title, array $o = []): int
    {
        return (int) DB::table('cms_hub_documents')->insertGetId($o + ['school_id' => 1, 'hub_id' => $hub['hub']['id'], 'category_id' => $this->catId($hub, $label), 'title' => $title, 'doc_date' => '2026-02-01',
            'media_id' => null, 'file_name' => null, 'external_url' => 'https://example.org/'.md5($title), 'is_published' => 1, 'sort' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function upload(array $hub, string $label, array $files, array $o = [])
    {
        return $this->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/documents', $o + ['category_id' => $this->catId($hub, $label), 'files' => $files]);
    }

    // ---------------------------------------------------------------- hub, categories, audit

    public function test_admin_creates_a_hub_with_the_default_categories_and_every_write_is_audited(): void
    {
        $hub = $this->makeHub();
        $this->assertSame(['คำสั่ง/ประกาศ', 'แบบฟอร์ม', 'ปฏิทิน/ตารางสอน', 'เอกสารวัดผล', 'ประกันคุณภาพ', 'ผลงาน'], array_column($hub['categories'], 'label'));
        $this->assertSame('published', $hub['hub']['status']);
        $id = $hub['hub']['id'];
        $this->postJson("/api/cms/hubs/$id/categories", ['label' => 'รายงานผลการเรียน'])->assertCreated();
        $parent = $this->catId($hub, 'แบบฟอร์ม');
        $sub = $this->postJson("/api/cms/hubs/$id/categories", ['label' => 'แบบฟอร์มลา', 'parent_id' => $parent])->assertCreated()->json();
        $this->postJson("/api/cms/hubs/$id/categories", ['label' => 'ลึกเกินไป', 'parent_id' => $sub['id']])->assertStatus(422); // one level only
        $cat = $this->putJson("/api/cms/hubs/$id/categories/$parent", ['version' => 1, 'label' => 'แบบฟอร์มทั่วไป'])->assertOk()->json();
        $this->assertSame('แบบฟอร์มทั่วไป', $cat['label']);
        $this->putJson("/api/cms/hubs/$id/categories/$parent", ['version' => 1, 'label' => 'x'])->assertStatus(409); // stale version
        // reorder: move "ผลงาน" first
        $d = $this->getJson("/api/cms/hubs/$id")->assertOk()->json();
        $items = collect($d['categories'])->where('parent_id', null)->sortByDesc(fn ($c) => $c['label'] === 'ผลงาน' ? 1 : 0)->map(fn ($c) => ['id' => $c['id'], 'version' => $c['version']])->values()->all();
        $this->putJson("/api/cms/hubs/$id/categories/order", ['items' => $items])->assertOk();
        $this->assertSame('ผลงาน', $this->getJson("/api/cms/hubs/$id")->json('categories.0.label'));
        $up = $this->upload($hub, 'ผลงาน', [$this->pdf('result.pdf')])->assertCreated()->json('data.0');
        $this->postJson("/api/cms/hubs/$id/documents/{$up['id']}", ['version' => 1, 'title' => 'ผลงานเด่น', 'pinned' => true])->assertOk();
        $this->deleteJson("/api/cms/hubs/$id/documents/{$up['id']}", ['version' => 2])->assertOk();
        $actions = DB::table('audit_events')->pluck('action')->all();
        foreach (['hub.created', 'hub.category.created', 'hub.category.updated', 'hub.category.reordered', 'hub.document.created', 'hub.document.updated', 'hub.document.deleted'] as $a) $this->assertContains($a, $actions, $a);
        // the file of the deleted document is gone from the media library and the disk
        $this->assertSame(0, DB::table('cms_media')->where('folder', 'เอกสารกลุ่มงาน')->count());
        // a hub with docs, then deleting the hub removes everything
        $this->upload($hub, 'ผลงาน', [$this->pdf('keep.pdf')])->assertCreated();
        $this->deleteJson("/api/cms/hubs/$id", ['version' => 1])->assertOk();
        $this->assertSame(0, DB::table('cms_hub_categories')->count());
        $this->assertSame(0, DB::table('cms_hub_documents')->count());
        $this->assertSame(0, DB::table('cms_media')->where('folder', 'เอกสารกลุ่มงาน')->count());
        $this->postJson('/api/cms/hubs', ['slug' => 'Bad Slug', 'title' => 'x'])->assertStatus(422);
    }

    public function test_a_hub_slug_is_unique(): void
    {
        $this->makeHub('same-slug');
        $this->admin()->postJson('/api/cms/hubs', ['slug' => 'same-slug', 'title' => 'อีกหน้า'])->assertStatus(409);
    }

    // ---------------------------------------------------------------- public pages

    public function test_hub_page_shows_several_categories_each_with_view_all_and_the_view_all_lists_filter(): void
    {
        $hub = $this->makeHub('academic', 'บริหารวิชาการ', 'published', ['box_limit' => 3]);
        for ($i = 1; $i <= 17; $i++) $this->doc($hub, 'คำสั่ง/ประกาศ', 'คำสั่งโรงเรียนที่ '.$i, ['doc_date' => sprintf('2026-01-%02d', $i)]);
        $this->doc($hub, 'คำสั่ง/ประกาศ', 'ประกาศปีเก่า', ['doc_date' => '2025-03-01']);
        $this->doc($hub, 'แบบฟอร์ม', 'แบบฟอร์มขอใช้ห้อง');
        $this->doc($hub, 'ปฏิทิน/ตารางสอน', 'ปฏิทินปีการศึกษา');
        $page = $this->get('/portal/groups/academic')->assertOk();
        $html = $page->getContent();
        foreach (['คำสั่ง/ประกาศ', 'แบบฟอร์ม', 'ปฏิทิน/ตารางสอน', 'เอกสารวัดผล', 'ประกันคุณภาพ', 'ผลงาน'] as $label) $this->assertStringContainsString($label, $html);
        $this->assertSame(6, substr_count($html, 'ดูทั้งหมด'), 'one view-all link per category box');
        $cat = $this->catId($hub, 'คำสั่ง/ประกาศ');
        $this->assertStringContainsString("/portal/groups/academic/docs/$cat", $html);
        // the box shows only the newest N (3): 17 + 1 documents exist
        $this->assertStringContainsString('คำสั่งโรงเรียนที่ 17', $html);
        $this->assertStringNotContainsString('คำสั่งโรงเรียนที่ 5<', $html);
        $this->assertStringContainsString('18 รายการ', $html);
        // view-all: paged
        $all = $this->get("/portal/groups/academic/docs/$cat")->assertOk()->getContent();
        $this->assertStringContainsString('พบ 18 รายการ', $all);
        $this->assertStringContainsString('ถัดไป', $all);
        $this->get("/portal/groups/academic/docs/$cat?page=2")->assertOk()->assertSee('คำสั่งโรงเรียนที่ 1<', false);
        // filters: year (Buddhist era) and title search
        $y = $this->get("/portal/groups/academic/docs/$cat?year=2568")->assertOk()->getContent();
        $this->assertStringContainsString('ประกาศปีเก่า', $y);
        $this->assertStringNotContainsString('คำสั่งโรงเรียนที่ 17', $y);
        $this->assertStringContainsString('พบ 1 รายการ', $y);
        $q = $this->get("/portal/groups/academic/docs/$cat?q=".rawurlencode('ที่ 11'))->assertOk()->getContent();
        $this->assertStringContainsString('คำสั่งโรงเรียนที่ 11', $q);
        $this->assertStringNotContainsString('คำสั่งโรงเรียนที่ 12', $q);
        $this->get("/portal/groups/academic/docs/$cat?q=".rawurlencode('%'))->assertOk(); // a wildcard typed by a visitor is not a pattern
        $this->get("/portal/groups/academic/docs/$cat?year=abc")->assertStatus(302); // validation failure
        // another hub's category id is not reachable through this hub's address
        $other = $this->makeHub('budget', 'งบประมาณ');
        $this->get('/portal/groups/academic/docs/'.$this->catId($other, 'แบบฟอร์ม'))->assertNotFound();
        $this->get('/portal/groups')->assertOk()->assertSee('กลุ่มงานบริหารวิชาการ')->assertSee('กลุ่มงานงบประมาณ');
        $this->assertStringContainsString('/portal/groups/academic', $this->get('/sitemap.xml')->getContent());
    }

    public function test_only_published_documents_hubs_and_categories_appear(): void
    {
        $hub = $this->makeHub('academic');
        $draft = $this->doc($hub, 'แบบฟอร์ม', 'เอกสารฉบับร่างลับ', ['is_published' => 0]);
        $live = $this->doc($hub, 'แบบฟอร์ม', 'เอกสารเผยแพร่แล้ว');
        $this->doc($hub, 'ผลงาน', 'ผลงานในหมวดที่ซ่อน');
        $html = $this->get('/portal/groups/academic')->assertOk()->getContent();
        $this->assertStringContainsString('เอกสารเผยแพร่แล้ว', $html);
        $this->assertStringNotContainsString('เอกสารฉบับร่างลับ', $html);
        $cat = $this->catId($hub, 'แบบฟอร์ม');
        $this->assertStringNotContainsString('เอกสารฉบับร่างลับ', $this->get("/portal/groups/academic/docs/$cat")->getContent());
        $this->get("/portal/d/$draft")->assertNotFound();
        $this->get("/portal/d/$live")->assertRedirect('https://example.org/'.md5('เอกสารเผยแพร่แล้ว'));
        // hide a category: its box, list and documents disappear
        $work = $this->catId($hub, 'ผลงาน');
        $this->putJson('/api/cms/hubs/'.$hub['hub']['id']."/categories/$work", ['version' => 1, 'is_hidden' => true])->assertOk();
        $this->assertStringNotContainsString('ผลงานในหมวดที่ซ่อน', $this->get('/portal/groups/academic')->getContent());
        $this->get("/portal/groups/academic/docs/$work")->assertNotFound();
        $hidden = (int) DB::table('cms_hub_documents')->where('title', 'ผลงานในหมวดที่ซ่อน')->value('id');
        $this->get("/portal/d/$hidden")->assertNotFound();
        // draft hub: 404 everywhere, even for a document that is published
        DB::table('cms_hubs')->where('slug', 'academic')->update(['status' => 'draft']);
        $this->get('/portal/groups/academic')->assertNotFound();
        $this->get("/portal/groups/academic/docs/$cat")->assertNotFound();
        $this->get("/portal/d/$live")->assertNotFound();
        // the staff preview shows drafts, marked, and needs a sign-in
        $this->admin()->get('/hub-preview/'.$hub['hub']['id'])->assertOk()->assertSee('เอกสารฉบับร่างลับ')->assertSee('ร่าง');
        $this->guest();
        $this->get('/hub-preview/'.$hub['hub']['id'])->assertRedirect();
    }

    public function test_site_search_lists_published_hubs_and_visible_documents_only(): void
    {
        $hub = $this->makeHub('academic');
        $this->makeHub('budget', 'งบประมาณ', 'draft');
        $this->doc($hub, 'แบบฟอร์ม', 'แบบฟอร์มขอใช้ห้องประชุม');
        $this->doc($hub, 'แบบฟอร์ม', 'แบบฟอร์มลับยังไม่เผยแพร่', ['is_published' => 0]);
        $this->doc($hub, 'ผลงาน', 'แบบฟอร์มในหมวดที่ซ่อน');
        $this->putJson('/api/cms/hubs/'.$hub['hub']['id'].'/categories/'.$this->catId($hub, 'ผลงาน'), ['version' => 1, 'is_hidden' => true])->assertOk();
        $this->guest();
        $html = $this->get('/portal/search?q='.urlencode('แบบฟอร์ม'))->assertOk()->getContent();
        $this->assertStringContainsString('แบบฟอร์มขอใช้ห้องประชุม', $html);
        $this->assertStringNotContainsString('แบบฟอร์มลับยังไม่เผยแพร่', $html);
        $this->assertStringNotContainsString('แบบฟอร์มในหมวดที่ซ่อน', $html);
        $hubs = $this->get('/portal/search?q='.urlencode('กลุ่มงาน'))->assertOk()->getContent();
        $this->assertStringContainsString('/portal/groups/academic', $hubs);
        $this->assertStringNotContainsString('/portal/groups/budget', $hubs); // draft hub
        // a LIKE wildcard typed by a visitor matches nothing extra
        $this->assertStringNotContainsString('search-hub-hits', $this->get('/portal/search?q=%25')->getContent());
    }

    public function test_staff_come_from_the_public_directory_allowlist_and_hidden_staff_are_excluded(): void
    {
        $this->teacher(9001, 'ครูสมศรี วิชาการดี', ['position' => 'หัวหน้ากลุ่มงานบริหารวิชาการ', 'academic_standing' => 'ชำนาญการพิเศษลับ', 'department' => 'ภาษาไทย', 'work_group' => 'บริหารวิชาการ',
            'education' => json_encode([['degree' => 'ปริญญาโทลับ']]), 'start_date' => '2001-05-16', 'directory_order' => 1]);
        $this->teacher(9002, 'ครูมานะ ขยันสอน', ['position' => 'ครู', 'work_group' => ' บริหารวิชาการ ', 'directory_order' => 2]);
        $this->teacher(9003, 'ครูซ่อนตัว ไม่เผยแพร่', ['position' => 'ครู', 'work_group' => 'บริหารวิชาการ', 'show_in_directory' => 0]);
        $this->teacher(9004, 'ครูเกษียณ ไม่ใช้งาน', ['position' => 'ครู', 'work_group' => 'บริหารวิชาการ', '_active' => 0]);
        $this->teacher(9005, 'ครูกลุ่มอื่น งบประมาณ', ['position' => 'หัวหน้ากลุ่มงานงบประมาณ', 'work_group' => 'งบประมาณ']);
        $this->teacher(9006, 'ครูไม่มีแฟ้ม ไม่มีโปรไฟล์');
        $this->makeHub('academic', 'บริหารวิชาการ');
        $html = $this->get('/portal/groups/academic')->assertOk()->getContent();
        $this->assertStringContainsString('ครูสมศรี วิชาการดี', $html);
        $this->assertStringContainsString('หัวหน้ากลุ่มงานบริหารวิชาการ', $html);
        $this->assertStringContainsString('ครูมานะ ขยันสอน', $html);
        foreach (['ครูซ่อนตัว', 'ครูเกษียณ', 'ครูกลุ่มอื่น', 'ครูไม่มีแฟ้ม'] as $n) $this->assertStringNotContainsString($n, $html, $n);
        // only name, position, photo: no standing, education or start date
        // the start date in any form it could be printed (a bare "2001" also turns up inside random tokens and failed CI once)
        foreach (['ชำนาญการพิเศษลับ', 'ปริญญาโทลับ', '2001-05-16', '16/05/2001', '16 พ.ค. 2544', '16 พฤษภาคม 2544', '2544-05-16', 'ภาษาไทย'] as $n) $this->assertStringNotContainsString($n, $html, $n);
        // head first, then the others, and the list is exactly what the directory allowlist yields for this group
        $this->assertLessThan(strpos($html, 'ครูมานะ'), strpos($html, 'ครูสมศรี'));
        $staff = Hubs::staff(DB::table('cms_hubs')->where('slug', 'academic')->first());
        $this->assertSame(['ครูสมศรี วิชาการดี'], array_column($staff['heads'], 'name'));
        $this->assertSame(['ครูมานะ ขยันสอน'], array_column($staff['members'], 'name'));
        $this->assertSame(['name', 'position', 'photo_url', 'order'], array_keys($staff['heads'][0]));
        // withdrawing consent hides the person at once
        DB::table('per_profiles')->where('teacher_id', 9002)->update(['show_in_directory' => 0]);
        $this->assertStringNotContainsString('ครูมานะ', $this->get('/portal/groups/academic')->getContent());
    }

    public function test_news_of_the_group_by_tag_or_category_and_the_view_all_page(): void
    {
        $this->makeHub('academic', 'บริหารวิชาการ', 'published', ['news_tag' => 'วิชาการ', 'news_category' => 'activity', 'news_limit' => 2]);
        $post = fn (string $title, array $o = []) => DB::table('posts')->insert($o + ['school_id' => 1, 'author_id' => 1, 'slug' => 'p-'.md5($title), 'title' => $title, 'body' => 'x', 'status' => 'published', 'content_type' => 'article',
            'category' => 'news', 'metadata' => '{}', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $post('ข่าวติดแท็กกลุ่ม', ['metadata' => json_encode(['tags' => ['วิชาการ']], JSON_UNESCAPED_UNICODE), 'published_at' => now()->subMinutes(3)]);
        $post('ข่าวในหมวดกิจกรรม', ['category' => 'activity', 'published_at' => now()->subMinutes(2)]);
        $post('ข่าวเก่ากว่าของกลุ่ม', ['category' => 'activity', 'published_at' => now()->subDays(2)]);
        $post('ข่าวไม่เกี่ยวข้อง');
        $post('ข่าวแท็กแต่ยังเป็นร่าง', ['metadata' => json_encode(['tags' => ['วิชาการ']], JSON_UNESCAPED_UNICODE), 'status' => 'draft']);
        $post('ข่าวแท็กแต่ถูกลบ', ['metadata' => json_encode(['tags' => ['วิชาการ']], JSON_UNESCAPED_UNICODE), 'deleted_at' => now()]);
        $html = $this->get('/portal/groups/academic')->assertOk()->getContent();
        $this->assertStringContainsString('ข่าวติดแท็กกลุ่ม', $html);
        $this->assertStringContainsString('ข่าวในหมวดกิจกรรม', $html);
        $this->assertStringNotContainsString('ข่าวเก่ากว่าของกลุ่ม', $html, 'latest N only');
        foreach (['ข่าวไม่เกี่ยวข้อง', 'แต่ยังเป็นร่าง', 'แต่ถูกลบ'] as $n) $this->assertStringNotContainsString($n, $html, $n);
        $this->assertStringContainsString('/portal/groups/academic/news', $html);
        $all = $this->get('/portal/groups/academic/news')->assertOk()->getContent();
        $this->assertStringContainsString('ข่าวเก่ากว่าของกลุ่ม', $all);
        $this->assertStringNotContainsString('ข่าวไม่เกี่ยวข้อง', $all);
        // a hub with no news binding has no news block and no news page
        $this->makeHub('budget', 'งบประมาณ');
        $this->assertStringNotContainsString('hub-news-h', $this->get('/portal/groups/budget')->getContent());
        $this->get('/portal/groups/budget/news')->assertNotFound();
    }

    // ---------------------------------------------------------------- downloads

    public function test_download_counter_increments_is_limited_per_network_per_day_and_stores_no_personal_data(): void
    {
        $hub = $this->makeHub();
        $id = (int) $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('form.pdf')])->assertCreated()->json('data.0.id');
        $url = "/portal/d/$id";
        config(['school.demo' => false, 'school.local_only' => false]); // demo and local installs answer only to 127.0.0.1 (SchoolBoundary): real visitors come from elsewhere
        $from = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $limit = (int) config('saan.hubs.count_per_net_day');
        $this->assertSame(3, $limit);
        for ($i = 0; $i < $limit + 4; $i++) {
            $res = $from('203.0.113.9')->get($url)->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
            $this->assertStringContainsString('inline', (string) $res->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        }
        $this->assertSame($limit, (int) DB::table('cms_hub_downloads')->where('document_id', $id)->sum('n'), 'the same network counts at most N times a day, and still gets the file');
        $from('198.51.100.4')->get($url)->assertOk(); // another network counts
        $this->assertSame($limit + 1, (int) DB::table('cms_hub_downloads')->where('document_id', $id)->sum('n'));
        $this->assertSame(1, DB::table('cms_hub_downloads')->count(), 'one aggregate row per document per day');
        // HEAD and a later byte range of the same viewer do not count
        $from('192.0.2.77')->call('HEAD', $url)->assertOk();
        $from('192.0.2.78')->get($url, ['Range' => 'bytes=5-9']);
        $this->assertSame($limit + 1, (int) DB::table('cms_hub_downloads')->sum('n'));
        // an IPv6 visitor rotating inside its /64 is one network
        for ($i = 0; $i < 6; $i++) $from('2001:db8:1:2:'.dechex(4096 + $i).'::1')->get($url)->assertOk();
        $this->assertSame($limit + 1 + $limit, (int) DB::table('cms_hub_downloads')->sum('n'));
        // nothing personal is stored: no address, browser string or session in the table, the audit trail or the cache keys
        $this->assertSame(['id', 'school_id', 'document_id', 'day', 'n', 'sent_n', 'created_at', 'updated_at'], Schema::getColumnListing('cms_hub_downloads'));
        $dump = json_encode([DB::table('cms_hub_downloads')->get(), DB::table('audit_events')->get()]);
        foreach (['203.0.113', '198.51.100', '2001:db8', '192.0.2.', 'Symfony'] as $n) $this->assertStringNotContainsString($n, $dump, $n);
        $store = Cache::getStore();
        $prop = new \ReflectionProperty($store, 'storage');
        $keys = array_keys($prop->getValue($store));
        $ceil = array_values(array_filter($keys, fn ($k) => str_contains($k, 'hubdl:')));
        $this->assertNotEmpty($ceil, 'the ceiling lives in the cache');
        foreach ($ceil as $k) foreach (['203.0.113', '198.51.100', '2001', '192.0.2'] as $n) $this->assertStringNotContainsString($n, $k, 'cache keys hold a keyed hash of the network, never the address');
        // the counter is aggregate per day: a new day starts a new row
        $this->travel(1)->day();
        $from('203.0.113.9')->get($url)->assertOk();
        $this->assertSame(2, DB::table('cms_hub_downloads')->count());
        // internal admin sees the counts
        config(['school.demo' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $d = $this->admin()->getJson('/api/cms/hubs/'.$this->hubId('academic'))->assertOk()->json('documents.0');
        $this->assertSame($limit + 1 + $limit + 1, $d['downloads']);
    }

    private function hubId(string $slug): int
    {
        return (int) DB::table('cms_hubs')->where('slug', $slug)->value('id');
    }

    public function test_the_file_is_reachable_only_through_the_counting_route_not_through_media(): void
    {
        $hub = $this->makeHub();
        $d = $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('form.pdf')])->assertCreated()->json('data.0');
        $media = (int) $d['media_id'];
        $this->guest();
        $this->get("/media/$media")->assertNotFound();
        $this->get("/portal/d/{$d['id']}")->assertOk();
        // a file inside a document is "in use": the media library will not delete it from under the document
        $this->admin()->deleteJson("/api/site/media/$media", ['version' => 1])->assertStatus(422);
        // staff preview of the file works, does not count, and is closed to guests
        $before = (int) DB::table('cms_hub_downloads')->sum('n');
        $this->admin()->get("/api/cms/hubs/documents/{$d['id']}/file")->assertOk();
        $this->assertSame($before, (int) DB::table('cms_hub_downloads')->sum('n'));
    }

    public function test_other_staff_cannot_download_or_list_draft_hub_files(): void
    {
        $hub = $this->makeHub();
        $id = (int) $hub['hub']['id'];
        $pub = $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('public.pdf', 'p')])->assertCreated()->json('data.0');
        $draft = $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('draft.pdf', 'd')], ['is_published' => false])->assertCreated()->json('data.0');
        $this->assertFalse((bool) $draft['is_published']);
        $teacher = $this->staff('teacher', 9011); // a staff role that is neither admin nor head of this group
        $this->actingAs($teacher)->get("/api/cms/hubs/documents/{$draft['id']}/file")->assertNotFound();
        $this->actingAs($teacher)->get("/api/cms/hubs/documents/{$pub['id']}/file")->assertOk();
        $this->actingAs($teacher)->get("/hub-preview/$id")->assertForbidden();
        $list = $this->actingAs($teacher)->getJson("/api/cms/hubs/$id")->assertOk();
        $ids = collect($list->json('documents.data') ?? $list->json('documents') ?? [])->pluck('id')->all();
        $this->assertNotContains($draft['id'], $ids);
        $this->assertStringNotContainsString('draft.pdf', (string) $list->getContent());
        $this->admin()->get("/api/cms/hubs/documents/{$draft['id']}/file")->assertOk();
        $this->admin()->get("/hub-preview/$id")->assertOk();
    }

    public function test_external_link_redirects_only_to_the_stored_https_link(): void
    {
        $hub = $this->makeHub();
        $id = $hub['hub']['id'];
        $cat = $this->catId($hub, 'แบบฟอร์ม');
        $ok = $this->postJson("/api/cms/hubs/$id/documents", ['category_id' => $cat, 'title' => 'แบบฟอร์มออนไลน์', 'external_url' => 'https://forms.example.org/a?b=1', 'doc_date' => '2026-02-01'])->assertCreated()->json('data.0');
        $res = $this->get('/portal/d/'.$ok['id'].'?url='.rawurlencode('https://evil.example/x').'&next=//evil.example')->assertStatus(302);
        $this->assertSame('https://forms.example.org/a?b=1', $res->headers->get('Location'), 'nothing from the request reaches the redirect');
        $this->assertSame('no-referrer', $res->headers->get('Referrer-Policy'));
        $this->assertSame(1, (int) DB::table('cms_hub_downloads')->sum('n'));
        foreach (['http://forms.example.org/a', 'javascript:alert(1)', '//evil.example/x', 'https://user:pw@forms.example.org/a', 'https://localhost/a', 'ftp://forms.example.org/a', 'https://a b.example/x', 'data:text/html,x', 'https://nodot/x'] as $bad) {
            $this->postJson("/api/cms/hubs/$id/documents", ['category_id' => $cat, 'title' => 'ลิงก์ไม่ปลอดภัย', 'external_url' => $bad])->assertStatus(422);
        }
        $this->assertSame(1, DB::table('cms_hub_documents')->count());
        // a stored value that was tampered with in the database is not followed either
        DB::table('cms_hub_documents')->where('id', $ok['id'])->update(['external_url' => 'http://forms.example.org/plain']);
        $this->get('/portal/d/'.$ok['id'])->assertNotFound();
        DB::table('cms_hub_documents')->where('id', $ok['id'])->update(['external_url' => 'javascript:alert(1)']);
        $this->get('/portal/d/'.$ok['id'])->assertNotFound();
        $this->get('/portal/d/999999')->assertNotFound();
        $this->get('/portal/d/abc')->assertNotFound();
    }

    public function test_file_types_are_checked_from_the_content_and_a_bad_file_stores_nothing(): void
    {
        $hub = $this->makeHub();
        $media = DB::table('cms_media')->count();
        $bad = [
            'evil.php' => '<?php echo 1;', 'evil.pdf' => '<?php echo 1;', 'page.html' => '<html><script>1</script></html>', 'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'clip.mp4' => 'not really a video', 'pack.zip' => "PK\x03\x04", 'notes.txt' => 'plain', 'x.docx' => 'plain text pretending to be Word',
        ];
        foreach ($bad as $name => $bytes) {
            $this->upload($hub, 'แบบฟอร์ม', [UploadedFile::fake()->createWithContent($name, $bytes)])->assertStatus(422);
        }
        // a real PNG named .pdf, and a PDF named .png: the extension must match the content
        $this->upload($hub, 'แบบฟอร์ม', [UploadedFile::fake()->createWithContent('image.pdf', base64_decode(self::PNG))])->assertStatus(422);
        $this->upload($hub, 'แบบฟอร์ม', [UploadedFile::fake()->createWithContent('paper.png', "%PDF-1.4\n%%EOF")])->assertStatus(422);
        // one bad file in a bulk upload stops the whole upload before anything is stored
        $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('good-1.pdf'), UploadedFile::fake()->createWithContent('bad.exe', 'MZ'), $this->pdf('good-2.pdf', 'b')])->assertStatus(422);
        $this->assertSame($media, DB::table('cms_media')->count());
        $this->assertSame(0, DB::table('cms_hub_documents')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('cms'));
        // too many files at once
        $this->upload($hub, 'แบบฟอร์ม', array_map(fn ($i) => $this->pdf("f$i.pdf", (string) $i), range(1, 21)))->assertStatus(422);
        // PDF, picture and Word are accepted; bulk upload makes one document per file, titled from the file name
        $res = $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('คำขอ_ใช้ห้อง.pdf'), $this->png('บรรยากาศ.png'), $this->docx('แผนงาน.docx')], ['doc_date' => '2026-03-04'])->assertCreated()->json('data');
        $this->assertSame(['คำขอ ใช้ห้อง', 'บรรยากาศ', 'แผนงาน'], array_column($res, 'title'));
        $this->assertSame(['PDF', 'รูปภาพ', 'Word'], array_column($res, 'type'));
        $this->assertSame(3, DB::table('cms_media')->where('folder', 'เอกสารกลุ่มงาน')->count());
        $this->assertTrue(collect($res)->every(fn ($d) => $d['is_published'] === true && $d['doc_date'] === '2026-03-04'));
        // the public download name keeps the title's extension of the sniffed type, never a client-chosen one
        $this->assertSame('คำขอ_ใช้ห้อง.pdf', DB::table('cms_hub_documents')->where('id', $res[0]['id'])->value('file_name'));
        // replacing a file of a document releases the old one
        $old = $res[0]['media_id'];
        $this->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/documents/'.$res[0]['id'], ['version' => 1, 'file' => $this->pdf('new.pdf', 'z')])->assertOk();
        $this->assertSame(0, DB::table('cms_media')->where('id', $old)->count());
        $this->assertSame(3, DB::table('cms_media')->where('folder', 'เอกสารกลุ่มงาน')->count());
    }

    // ---------------------------------------------------------------- permissions

    public function test_head_of_group_manages_only_their_own_hub_and_everybody_else_reads(): void
    {
        $this->teacher(9001, 'ครูสมศรี หัวหน้าวิชาการ', ['position' => 'หัวหน้ากลุ่มงานบริหารวิชาการ', 'work_group' => 'บริหารวิชาการ']);
        $this->teacher(9002, 'ครูมานะ สมาชิก', ['position' => 'ครู', 'work_group' => 'บริหารวิชาการ']);
        $this->teacher(9003, 'ครูรองหัวหน้า', ['position' => 'รองหัวหน้ากลุ่มงานบริหารวิชาการ', 'work_group' => 'บริหารวิชาการ']);
        $mine = $this->makeHub('academic', 'บริหารวิชาการ');
        $other = $this->makeHub('budget', 'งบประมาณ');
        $a = $mine['hub']['id'];
        $b = $other['hub']['id'];
        $head = $this->staff('teacher', 9001);
        // the head reads both, manages own
        $this->actingAs($head)->getJson('/api/cms/hubs')->assertOk()->assertJsonPath('can_create', false);
        $list = collect($this->actingAs($head)->getJson('/api/cms/hubs')->json('data'))->keyBy('slug');
        $this->assertTrue($list['academic']['can_manage']);
        $this->assertFalse($list['budget']['can_manage']);
        $this->actingAs($head)->getJson("/api/cms/hubs/$b")->assertOk()->assertJsonPath('can.manage', false)->assertJsonPath('hub.internal_note', null);
        $cat = $this->catId($mine, 'แบบฟอร์ม');
        $this->actingAs($head)->postJson("/api/cms/hubs/$a/categories", ['label' => 'หมวดของหัวหน้า'])->assertCreated();
        $this->actingAs($head)->postJson("/api/cms/hubs/$a/documents", ['category_id' => $cat, 'title' => 'ลิงก์ของหัวหน้า', 'external_url' => 'https://example.org/h'])->assertCreated();
        $this->actingAs($head)->putJson("/api/cms/hubs/$a", ['version' => 1, 'description' => 'แก้โดยหัวหน้า', 'status' => 'published', 'internal_note' => 'บันทึก'])->assertOk()->assertJsonPath('hub.description', 'แก้โดยหัวหน้า');
        $this->actingAs($head)->postJson("/api/cms/hubs/$a/documents", ['category_id' => $cat, 'files' => [$this->pdf('h.pdf')]])->assertCreated();
        // but never another group's hub
        $otherCat = $this->catId($other, 'แบบฟอร์ม');
        $this->actingAs($head)->postJson("/api/cms/hubs/$b/categories", ['label' => 'บุกรุก'])->assertForbidden();
        $this->actingAs($head)->postJson("/api/cms/hubs/$b/documents", ['category_id' => $otherCat, 'title' => 'บุกรุก', 'external_url' => 'https://example.org/x'])->assertForbidden();
        $this->actingAs($head)->putJson("/api/cms/hubs/$b", ['version' => 1, 'title' => 'บุกรุก'])->assertForbidden();
        $this->actingAs($head)->deleteJson("/api/cms/hubs/$b", ['version' => 1])->assertForbidden();
        // a category or document id of the other hub through the head's own hub id is not found
        $this->actingAs($head)->putJson("/api/cms/hubs/$a/categories/$otherCat", ['version' => 1, 'label' => 'x'])->assertNotFound();
        // the head cannot create hubs or change a hub's address, kind, group or order
        $this->actingAs($head)->postJson('/api/cms/hubs', ['slug' => 'new-hub', 'title' => 'ใหม่'])->assertForbidden();
        foreach (['slug' => 'taken-over', 'group_name' => 'งบประมาณ', 'kind' => 'subject', 'sort' => 5] as $k => $v) {
            $this->actingAs($head)->putJson("/api/cms/hubs/$a", ['version' => 2, $k => $v])->assertForbidden();
        }
        $this->assertSame('academic', DB::table('cms_hubs')->where('id', $a)->value('slug'));
        // the deputy head, an ordinary member, a principal and an editor read only
        foreach ([$this->staff('teacher', 9003), $this->staff('teacher', 9002), $this->staff('principal'), $this->staff('editor'), $this->staff('teacher')] as $u) {
            $this->actingAs($u)->getJson("/api/cms/hubs/$a")->assertOk()->assertJsonPath('can.manage', false);
            $this->actingAs($u)->postJson("/api/cms/hubs/$a/categories", ['label' => 'x'])->assertForbidden();
            $this->actingAs($u)->postJson("/api/cms/hubs/$a/documents", ['category_id' => $cat, 'title' => 'x', 'external_url' => 'https://example.org/x'])->assertForbidden();
            $this->actingAs($u)->putJson("/api/cms/hubs/$a", ['version' => 2, 'title' => 'x'])->assertForbidden();
            $this->actingAs($u)->postJson('/api/cms/hubs', ['slug' => 'zzz', 'title' => 'x'])->assertForbidden();
        }
        // a teacher whose profile is in another group is not the head of this one even with the same position
        $this->teacher(9007, 'ครูหัวหน้ากลุ่มอื่น', ['position' => 'หัวหน้ากลุ่มงานบุคคล', 'work_group' => 'บุคคล']);
        $this->actingAs($this->staff('teacher', 9007))->postJson("/api/cms/hubs/$a/categories", ['label' => 'x'])->assertForbidden();
        // other roles (finance) have no hub screens at all
        $this->actingAs($this->staff('finance'))->getJson('/api/cms/hubs')->assertForbidden();
        // guests: nothing
        $this->guest();
        $this->getJson('/api/cms/hubs')->assertUnauthorized();
        // admin manages every hub
        $this->admin()->postJson("/api/cms/hubs/$b/categories", ['label' => 'โดยผู้ดูแล'])->assertCreated();
        // the head's changes are in the audit trail with the head as actor
        $this->assertGreaterThan(0, DB::table('audit_events')->where('actor_id', $head->id)->where('action', 'hub.document.created')->count());
    }

    // ---------------------------------------------------------------- split editions

    public function test_projection_declares_the_hub_tables_without_internal_columns_and_never_the_counts(): void
    {
        $decl = collect(Projection::declared(true, true))->keyBy('table');
        foreach (['cms_hubs', 'cms_hub_categories', 'cms_hub_documents'] as $t) $this->assertTrue($decl->has($t), $t);
        $this->assertFalse($decl->has('cms_hub_downloads'));
        $this->assertTrue(Projection::isForbiddenTable('cms_hub_downloads'));
        $this->assertArrayNotHasKey('internal_note', $decl['cms_hubs']['columns']);
        $this->assertArrayNotHasKey('note', $decl['cms_hub_documents']['columns']);
        $this->assertSame('hub_documents', $decl['cms_hub_documents']['filter']);
        $this->expectExceptionMessage('ห้ามส่งออก');
        Projection::validate([['table' => 'cms_hub_downloads', 'columns' => ['id' => 'keep']]], 'test', false);
    }

    public function test_builder_exports_only_published_visible_rows_and_their_files_but_never_notes_or_counts(): void
    {
        $hub = $this->makeHub('academic', 'บริหารวิชาการ', 'published', ['internal_note' => 'บันทึกภายในลับ']);
        $draftHub = $this->makeHub('draft-hub', 'งบประมาณ', 'draft');
        $live = $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('live.pdf')], ['note' => 'หมายเหตุเอกสารลับ'])->assertCreated()->json('data.0');
        $draft = $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('draft.pdf', 'd')], ['is_published' => false])->assertCreated()->json('data.0');
        $hid = $this->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/categories', ['label' => 'หมวดซ่อน'])->json('id');
        $this->putJson('/api/cms/hubs/'.$hub['hub']['id']."/categories/$hid", ['version' => 1, 'is_hidden' => true])->assertOk();
        $inHidden = $this->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/documents', ['category_id' => $hid, 'files' => [$this->pdf('hidden.pdf', 'h')]])->json('data.0');
        $inDraftHub = $this->postJson('/api/cms/hubs/'.$draftHub['hub']['id'].'/documents', ['category_id' => $this->catId($draftHub, 'แบบฟอร์ม'), 'files' => [$this->pdf('dh.pdf', 'x')]])->json('data.0');
        $this->doc($hub, 'ผลงาน', 'ลิงก์ http ที่ไม่ปลอดภัย', ['external_url' => 'http://example.org/x']);
        DB::table('cms_hub_downloads')->insert(['school_id' => 1, 'document_id' => $live['id'], 'day' => '2026-02-01', 'n' => 7, 'sent_n' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $b = new Builder();
        $this->assertNotContains('cms_hub_downloads', $b->streams());
        $this->assertSame([(int) $hub['hub']['id']], array_map('intval', array_column($b->rows('cms_hubs'), 'id')));
        $cats = array_column($b->rows('cms_hub_categories'), 'label');
        $this->assertContains('แบบฟอร์ม', $cats);
        $this->assertNotContains('หมวดซ่อน', $cats);
        $this->assertCount(6, $cats, 'the six default categories of the published hub only');
        $docs = $b->rows('cms_hub_documents');
        $this->assertSame([(string) $live['id']], array_map('strval', array_column($docs, 'id')));
        $row = array_values($docs)[0];
        $this->assertArrayNotHasKey('note', $row);
        $this->assertArrayNotHasKey('internal_note', array_values($b->rows('cms_hubs'))[0]);
        $media = array_map('intval', array_column($b->rows('cms_media'), 'id'));
        $this->assertContains((int) $live['media_id'], $media);
        foreach ([$draft, $inHidden, $inDraftHub] as $d) $this->assertNotContains((int) $d['media_id'], $media, 'the file of a document that is not shown never leaves');
        $json = json_encode([$b->rows('cms_hubs'), $b->rows('cms_hub_categories'), $docs], JSON_UNESCAPED_UNICODE);
        foreach (['บันทึกภายในลับ', 'หมายเหตุเอกสารลับ', 'draft.pdf', 'hidden.pdf', 'dh.pdf', 'http://example.org'] as $n) $this->assertStringNotContainsString($n, $json, $n);
        // the original upload name stays internal, the media row sent to the public side has none
        $this->assertSame('', array_values(array_filter($b->rows('cms_media'), fn ($m) => (int) $m['id'] === (int) $live['media_id']))[0]['original_name']);
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

    public function test_public_edition_renders_from_projected_rows_and_the_counts_flow_back_as_aggregates(): void
    {
        $this->as('internal');
        config(['saan.public_push_url' => self::PUSH]);
        $this->teacher(9001, 'ครูสมศรี วิชาการดี', ['position' => 'หัวหน้ากลุ่มงานบริหารวิชาการ', 'academic_standing' => 'ชำนาญการพิเศษลับ', 'work_group' => 'บริหารวิชาการ', 'directory_order' => 1]);
        $this->teacher(9003, 'ครูซ่อนตัว ไม่เผยแพร่', ['position' => 'ครู', 'work_group' => 'บริหารวิชาการ', 'show_in_directory' => 0]);
        $hub = $this->makeHub('academic', 'บริหารวิชาการ', 'published', ['internal_note' => 'บันทึกภายในลับ', 'news_tag' => 'วิชาการ']);
        $live = $this->upload($hub, 'คำสั่ง/ประกาศ', [$this->pdf('order.pdf')], ['title' => 'คำสั่งแต่งตั้งคณะกรรมการ', 'note' => 'หมายเหตุลับ'])->assertCreated()->json('data.0');
        $link = $this->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/documents', ['category_id' => $this->catId($hub, 'แบบฟอร์ม'), 'title' => 'แบบฟอร์มออนไลน์', 'external_url' => 'https://forms.example.org/a'])->json('data.0');
        $draft = $this->upload($hub, 'คำสั่ง/ประกาศ', [$this->pdf('secret-draft.pdf', 'd')], ['title' => 'เอกสารร่างที่ยังไม่เผยแพร่', 'is_published' => false])->json('data.0');
        DB::table('posts')->insert(['school_id' => 1, 'author_id' => 1, 'slug' => 'tagged-1', 'title' => 'ข่าวของกลุ่มวิชาการ', 'body' => 'x', 'status' => 'published', 'content_type' => 'article', 'category' => 'news',
            'metadata' => json_encode(['tags' => ['วิชาการ']], JSON_UNESCAPED_UNICODE), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        // internal -> public (real builder, publisher, receiver)
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
        $this->assertSame(['academic'], $p->table('cms_hubs')->pluck('slug')->all());
        $this->assertSame(6, $p->table('cms_hub_categories')->count());
        $this->assertEqualsCanonicalizing([(int) $live['id'], (int) $link['id']], $p->table('cms_hub_documents')->pluck('id')->map('intval')->all());
        $this->assertNull($p->table('cms_hubs')->value('internal_note'));
        $this->assertSame(0, $p->table('cms_hub_downloads')->count());
        $this->assertTrue(Storage::disk('pubfiles')->exists(DB::table('cms_media')->where('id', $live['media_id'])->value('path')));
        $this->assertFalse(Storage::disk('pubfiles')->exists(DB::table('cms_media')->where('id', $draft['media_id'])->value('path')));
        $dump = json_encode([$p->table('cms_hubs')->get(), $p->table('cms_hub_categories')->get(), $p->table('cms_hub_documents')->get(), $p->table('cms_media')->get(), $p->table('per_profiles')->get(), $p->table('teachers')->get()], JSON_UNESCAPED_UNICODE);
        foreach (['บันทึกภายในลับ', 'หมายเหตุลับ', 'secret-draft', 'เอกสารร่างที่ยังไม่เผยแพร่', 'ครูซ่อนตัว'] as $n) $this->assertStringNotContainsString($n, $dump, $n);

        // pair the inbox, then switch to "being the public edition": its files live on the public disk, its database is the public one
        $this->as('internal');
        $c = Pairing::issue(1, null, self::PUSH);
        [$st, $reply] = Pairing::redeem($c['code'], self::PUSH, '10.0.0.1');
        $this->assertSame(200, $st);
        Keys::applyBundle($reply['bundle']);
        config(['filesystems.disks.local' => config('filesystems.disks.pubfiles')]);
        Storage::purge('local');
        $this->as('public', 'pubdb');
        $html = $this->get('/portal/groups/academic')->assertOk()->getContent();
        $this->assertStringContainsString('คำสั่งแต่งตั้งคณะกรรมการ', $html);
        $this->assertStringContainsString('แบบฟอร์มออนไลน์', $html);
        $this->assertStringContainsString('ครูสมศรี วิชาการดี', $html);
        $this->assertStringContainsString('ข่าวของกลุ่มวิชาการ', $html);
        foreach (['เอกสารร่างที่ยังไม่เผยแพร่', 'ครูซ่อนตัว', 'ชำนาญการพิเศษลับ', 'บันทึกภายในลับ'] as $n) $this->assertStringNotContainsString($n, $html, $n);
        config(['school.demo' => false, 'school.local_only' => false]);
        $this->get('/portal/groups/academic/docs/'.$this->catId($hub, 'คำสั่ง/ประกาศ'))->assertOk()->assertSee('คำสั่งแต่งตั้งคณะกรรมการ');
        $this->get("/media/{$live['media_id']}")->assertNotFound(); // closed: only the counting route serves hub files
        $this->get("/portal/d/{$draft['id']}")->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get("/portal/d/{$live['id']}")->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])->get("/portal/d/{$live['id']}")->assertOk();
        $this->get("/portal/d/{$link['id']}")->assertRedirect('https://forms.example.org/a');
        // the public side counts into its own table and the internal admin routes are not there
        $this->assertSame(2, (int) DB::table('cms_hub_downloads')->where('document_id', $live['id'])->sum('n'));
        $this->get('/api/cms/hubs')->assertNotFound();
        // counts back: ONE sealed envelope of aggregate numbers, no plaintext on the public side
        $this->assertSame(0, DB::table('pub_inbox')->count());
        $this->assertSame(2, Downloads::flush(), 'two (document, day) rows changed');
        $row = DB::table('pub_inbox')->first();
        $this->assertSame('hub_downloads', $row->kind);
        $this->assertSame(1, DB::table('pub_inbox')->count());
        $this->assertStringNotContainsString('"rows"', base64_decode($row->ciphertext));
        $this->assertSame(0, Downloads::flush(), 'nothing changed: nothing is sent again');
        Artisan::call('saan:hubs-send-counts');
        $this->assertSame(1, DB::table('pub_inbox')->count());

        $this->as('internal');
        $this->assertSame(0, (int) DB::table('cms_hub_downloads')->sum('n'), 'nothing counted on the internal side yet');
        $pull = fn () => (new Puller(function ($m, $pa, $q, $bd, $h) {
            return (new PullServer('pubdb', null, null))->handle($m, $pa, $q, $bd, $h);
        }, null))->run();
        $res = $pull();
        $this->assertSame(['pulled' => 1, 'handled' => 1, 'quarantined' => 0, 'failed' => 0], $res);
        $this->assertSame(2, (int) DB::table('cms_hub_downloads')->where('document_id', $live['id'])->sum('n'));
        $this->assertSame(1, (int) DB::table('cms_hub_downloads')->where('document_id', $link['id'])->sum('n'));
        $this->assertSame(0, $pull()['pulled']);
        // more downloads on the public side: the next envelope carries the new CUMULATIVE totals, the internal side ends at the same numbers
        $this->as('public', 'pubdb');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->get("/portal/d/{$live['id']}")->assertOk();
        $this->assertSame(1, Downloads::flush());
        $this->as('internal');
        $this->assertSame(1, $pull()['handled']);
        $this->assertSame(3, (int) DB::table('cms_hub_downloads')->where('document_id', $live['id'])->sum('n'));
        // internal admin shows the counts
        config(['school.demo' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $docs = collect($this->admin()->getJson('/api/cms/hubs/'.$hub['hub']['id'])->assertOk()->json('documents'))->keyBy('id');
        $this->assertSame(3, $docs[$live['id']]['downloads']);
        $this->assertSame(1, $docs[$link['id']]['downloads']);
        $this->assertSame(0, $docs[$draft['id']]['downloads']);
    }

    public function test_the_inbox_handler_of_counts_is_idempotent_bounded_and_ignores_unknown_documents(): void
    {
        $hub = $this->makeHub();
        $id = $this->doc($hub, 'ผลงาน', 'เอกสารนับยอด');
        $day = Downloads::today();
        $p = ['rows' => [['d' => $id, 'day' => $day, 'n' => 5]]];
        $this->assertSame(1, Downloads::receive($p)['rows']);
        Downloads::receive($p); // the same envelope twice
        $this->assertSame(5, (int) DB::table('cms_hub_downloads')->sum('n'));
        Downloads::receive(['rows' => [['d' => $id, 'day' => $day, 'n' => 3]]]); // an older, lower total never lowers the count
        $this->assertSame(5, (int) DB::table('cms_hub_downloads')->sum('n'));
        Downloads::receive(['rows' => [['d' => $id, 'day' => $day, 'n' => 9]]]);
        $this->assertSame(9, (int) DB::table('cms_hub_downloads')->sum('n'));
        // unknown document, day far in the past or future, absurd or zero numbers: ignored
        $this->assertSame(0, Downloads::receive(['rows' => [['d' => 999999, 'day' => $day, 'n' => 5], ['d' => $id, 'day' => '1999-01-01', 'n' => 5], ['d' => $id, 'day' => '2999-01-01', 'n' => 5],
            ['d' => $id, 'day' => $day, 'n' => 0], ['d' => $id, 'day' => $day, 'n' => 999999999999]]])['rows']);
        $this->assertSame(9, (int) DB::table('cms_hub_downloads')->sum('n'));
        // malformed payloads are refused, not half applied
        foreach ([['rows' => 'x'], ['rows' => [['d' => 'a', 'day' => $day, 'n' => 1]]], ['rows' => [['d' => $id, 'day' => 'tomorrow', 'n' => 1]]], ['rows' => array_fill(0, 1001, ['d' => $id, 'day' => $day, 'n' => 1])], []] as $bad) {
            try { Downloads::receive($bad); $this->fail('accepted '.json_encode($bad)); } catch (Inbox\InboxRejected) { $this->assertTrue(true); }
        }
        // the registered handler of the kind is this one (the puller dispatches by kind)
        $this->assertSame(['status' => 'ok', 'rows' => 1], Handlers::dispatch('hub_downloads', ['rows' => [['d' => $id, 'day' => $day, 'n' => 10]]]));
        $this->assertSame(10, (int) DB::table('cms_hub_downloads')->sum('n'));
    }

    // ---------------------------------------------------------------- review fixes (head scope, counts, N+1, links, audit, order)

    public function test_head_is_bound_to_the_field_of_the_hub_kind_and_to_the_head_position_of_that_kind(): void
    {
        // a subject-group head who also sits in the academic work group, and sub-heads of the work group
        $this->teacher(9101, 'ครูหัวหน้ากลุ่มสาระคณิต', ['position' => 'หัวหน้ากลุ่มสาระการเรียนรู้คณิตศาสตร์', 'department' => 'คณิตศาสตร์', 'work_group' => 'บริหารวิชาการ', 'directory_order' => 1]);
        $this->teacher(9102, 'ครูหัวหน้างานทะเบียน', ['position' => 'หัวหน้างานทะเบียน', 'department' => 'ภาษาไทย', 'work_group' => 'บริหารวิชาการ', 'directory_order' => 2]);
        $this->teacher(9103, 'ครูหัวหน้ากลุ่มงานวิชาการ', ['position' => 'หัวหน้ากลุ่มงานบริหารวิชาการ', 'department' => 'คณิตศาสตร์', 'work_group' => 'บริหารวิชาการ', 'directory_order' => 3]);
        $work = $this->makeHub('academic', 'บริหารวิชาการ');
        $subject = $this->makeHub('math', 'คณิตศาสตร์', 'published', ['kind' => 'subject']);
        $w = $work['hub']['id'];
        $s = $subject['hub']['id'];
        $cat = $this->catId($work, 'แบบฟอร์ม');
        $link = ['category_id' => $cat, 'title' => 'ลิงก์ทดสอบ', 'external_url' => 'https://example.org/t'];
        // subject head: matches the work group by the OTHER field only -> no access to the work hub, full access to the subject hub
        $subjHead = $this->staff('teacher', 9101);
        $this->actingAs($subjHead)->postJson("/api/cms/hubs/$w/documents", $link)->assertForbidden();
        $this->actingAs($subjHead)->putJson("/api/cms/hubs/$w", ['version' => 1, 'status' => 'draft'])->assertForbidden();
        $this->actingAs($subjHead)->putJson("/api/cms/hubs/$w", ['version' => 1, 'news_tag' => 'x'])->assertForbidden();
        $this->actingAs($subjHead)->postJson("/api/cms/hubs/$s/categories", ['label' => 'หมวดของหัวหน้ากลุ่มสาระ'])->assertCreated();
        // a head of a work group has no access to a subject hub just because the department text equals its group name
        $workHead = $this->staff('teacher', 9103);
        $this->actingAs($workHead)->postJson("/api/cms/hubs/$s/categories", ['label' => 'บุกรุก'])->assertForbidden();
        $this->actingAs($workHead)->postJson("/api/cms/hubs/$w/documents", $link)->assertCreated();
        // a sub-head (หัวหน้างาน...) is not the head of the group
        $this->actingAs($this->staff('teacher', 9102))->postJson("/api/cms/hubs/$w/documents", $link)->assertForbidden();
        $list = collect($this->actingAs($subjHead)->getJson('/api/cms/hubs')->json('data'))->keyBy('slug');
        $this->assertFalse($list['academic']['can_manage']);
        $this->assertTrue($list['math']['can_manage']);
        // the public page agrees: heads of the work hub are only the work-group head, heads of the subject hub only the subject-group head
        $this->guest();
        $this->assertSame(['ครูหัวหน้ากลุ่มงานวิชาการ'], array_column(Hubs::staff(DB::table('cms_hubs')->find($w))['heads'], 'name'));
        $this->assertSame(['ครูหัวหน้ากลุ่มสาระคณิต'], array_column(Hubs::staff(DB::table('cms_hubs')->find($s))['heads'], 'name'));
        $this->assertEqualsCanonicalizing(['ครูหัวหน้ากลุ่มสาระคณิต', 'ครูหัวหน้างานทะเบียน'], array_column(Hubs::staff(DB::table('cms_hubs')->find($w))['members'], 'name'));
    }

    public function test_a_head_who_is_no_longer_an_active_teacher_cannot_manage_the_hub(): void
    {
        $this->teacher(9201, 'ครูหัวหน้าย้ายออก', ['position' => 'หัวหน้ากลุ่มงานบริหารวิชาการ', 'work_group' => 'บริหารวิชาการ']);
        $hub = $this->makeHub('academic', 'บริหารวิชาการ');
        $id = $hub['hub']['id'];
        $head = $this->staff('teacher', 9201);
        $this->actingAs($head)->postJson("/api/cms/hubs/$id/categories", ['label' => 'ก่อนย้าย'])->assertCreated();
        DB::table('teachers')->where('id', 9201)->update(['is_active' => 0]);
        $this->actingAs($head)->postJson("/api/cms/hubs/$id/categories", ['label' => 'หลังย้าย'])->assertForbidden();
        $this->assertFalse(collect($this->actingAs($head)->getJson('/api/cms/hubs')->json('data'))->firstWhere('slug', 'academic')['can_manage']);
    }

    public function test_the_hub_page_and_the_internal_overview_use_a_fixed_number_of_queries(): void
    {
        $hub = $this->makeHub('academic', 'บริหารวิชาการ');
        for ($i = 0; $i < 12; $i++) $this->admin()->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/categories', ['label' => 'หมวดเพิ่ม '.$i])->assertCreated();
        $hub = $this->admin()->getJson('/api/cms/hubs/'.$hub['hub']['id'])->json();
        foreach (['คำสั่ง/ประกาศ', 'หมวดเพิ่ม 3'] as $l) for ($i = 0; $i < 7; $i++) $this->doc($hub, $l, "$l เอกสาร $i", ['sort' => $i === 6 ? 100 : 0, 'doc_date' => '2026-03-0'.($i + 1)]);
        $this->guest();
        DB::enableQueryLog();
        $html = $this->get('/portal/groups/academic')->assertOk()->getContent();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertCount(1, array_filter($log, fn ($q) => str_contains($q, 'cms_hub_documents')), 'one documents query for all boxes');
        $this->assertCount(1, array_filter($log, fn ($q) => str_contains($q, 'cms_hub_categories') && str_contains($q, 'is_hidden')), 'visible categories computed once');
        // newest 5 per box, pinned first: 7 documents -> the pinned one and the 4 newest, "view all" shows the real total
        $this->assertStringContainsString('คำสั่ง/ประกาศ เอกสาร 6', $html);
        $this->assertStringContainsString('คำสั่ง/ประกาศ เอกสาร 4', $html);
        $this->assertStringNotContainsString('คำสั่ง/ประกาศ เอกสาร 0', $html);
        $this->assertStringContainsString('คำสั่ง/ประกาศ 7 รายการ', preg_replace('/\s+/u', ' ', strip_tags($html)));
        // overview reads the head profile once, not once per hub
        $this->teacher(9301, 'ครูหัวหน้า', ['position' => 'หัวหน้ากลุ่มงานบริหารวิชาการ', 'work_group' => 'บริหารวิชาการ']);
        foreach (['b1', 'b2', 'b3', 'b4'] as $k) $this->makeHub($k, 'กลุ่ม'.$k);
        $head = $this->staff('teacher', 9301);
        DB::enableQueryLog();
        $this->actingAs($head)->getJson('/api/cms/hubs')->assertOk();
        $q = array_filter(array_column(DB::getQueryLog(), 'query'), fn ($q) => str_starts_with(str_replace('`', '"', $q), 'select "p"."position", "p"."work_group", "p"."department" from')); // backticks on MariaDB // the head profile lookup, not the other per_profiles readers of the request
        DB::disableQueryLog();
        $this->assertSame(1, count($q), 'profile read once for 5 hubs');
        $this->assertLessThanOrEqual(1, count($q), implode("\n", $q));
    }

    public function test_links_are_stored_in_lower_case_and_an_upper_case_scheme_still_downloads(): void
    {
        $hub = $this->makeHub();
        $cat = $this->catId($hub, 'แบบฟอร์ม');
        $id = (int) $this->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/documents', ['category_id' => $cat, 'title' => 'ลิงก์ตัวใหญ่', 'external_url' => 'HTTPS://Forms.Example.org/A?x=Y'])->assertCreated()->json('data.0.id');
        $this->assertSame('https://forms.example.org/A?x=Y', DB::table('cms_hub_documents')->where('id', $id)->value('external_url'));
        $this->guest();
        $this->get("/portal/d/$id")->assertRedirect('https://forms.example.org/A?x=Y');
        // a row stored by an older version with an upper case scheme still downloads (same case-insensitive test as the box query)
        DB::table('cms_hub_documents')->where('id', $id)->update(['external_url' => 'HTTPS://forms.example.org/old']);
        $this->assertNotNull(Hubs::downloadable($id));
        // editing normalises too
        $this->admin()->postJson('/api/cms/hubs/'.$hub['hub']['id']."/documents/$id", ['version' => 1, 'external_url' => 'Https://Other.Example.org/b'])->assertOk();
        $this->assertSame('https://other.example.org/b', DB::table('cms_hub_documents')->where('id', $id)->value('external_url'));
    }

    public function test_counts_envelope_with_an_impossible_day_is_refused_whole_and_writes_nothing(): void
    {
        $hub = $this->makeHub();
        $id = $this->doc($hub, 'ผลงาน', 'เอกสารนับยอด');
        $day = Downloads::today();
        foreach (['2026-02-30', '2026-13-01', '2026-00-10'] as $bad) {
            try { Downloads::receive(['rows' => [['d' => $id, 'day' => $day, 'n' => 4], ['d' => $id, 'day' => $bad, 'n' => 1]]]); $this->fail("accepted $bad"); }
            catch (Inbox\InboxRejected) { $this->assertTrue(true); }
            $this->assertSame(0, DB::table('cms_hub_downloads')->count(), "nothing written for $bad");
        }
    }

    public function test_public_counts_survive_a_withdrawn_document_and_continue_after_it_is_published_again(): void
    {
        $this->as('internal');
        $this->publicSide('pubdb', 'pubfiles');
        $this->as('public', 'pubdb');
        $now = ['created_at' => now(), 'updated_at' => now()];
        DB::table('cms_hubs')->insert(['id' => 1, 'school_id' => 1, 'slug' => 'academic', 'title' => 'x', 'status' => 'published', 'version' => 1] + $now);
        DB::table('cms_hub_categories')->insert(['id' => 1, 'school_id' => 1, 'hub_id' => 1, 'label' => 'ผลงาน', 'version' => 1] + $now);
        $docRow = ['id' => 7, 'school_id' => 1, 'hub_id' => 1, 'category_id' => 1, 'title' => 'เอกสาร', 'doc_date' => '2026-02-01', 'external_url' => 'https://example.org/a', 'is_published' => 1, 'version' => 1] + $now;
        DB::table('cms_hub_documents')->insert($docRow);
        for ($i = 0; $i < 40; $i++) Downloads::hit(7);
        DB::table('cms_hub_downloads')->update(['sent_n' => 35]);
        // withdrawn: the public receiver deletes the document row; the day's row (and its 5 unsent hits) must stay
        DB::table('cms_hub_documents')->where('id', 7)->delete();
        $this->assertSame(40, (int) DB::table('cms_hub_downloads')->where('document_id', 7)->value('n'));
        $this->assertSame(0, Downloads::prune(), 'an unsent row is never pruned');
        // published again, 20 more hits: the cumulative total of the day is 60, so the internal max() keeps every hit
        DB::table('cms_hub_documents')->insert($docRow);
        for ($i = 0; $i < 20; $i++) Downloads::hit(7);
        $this->assertSame(60, (int) DB::table('cms_hub_downloads')->where('document_id', 7)->value('n'));
        $this->as('internal');
        $this->assertSame(['status' => 'ok', 'rows' => 0], Downloads::receive(['rows' => [['d' => 7, 'day' => Downloads::today(), 'n' => 60]]]), 'unknown on the internal side here: ignored, not an error');
    }

    public function test_internal_deletes_remove_the_counts_and_the_counts_survive_unpublishing(): void
    {
        $hub = $this->makeHub();
        $a = $this->doc($hub, 'ผลงาน', 'เอกสาร ก');
        $b = $this->doc($hub, 'ผลงาน', 'เอกสาร ข');
        $day = Downloads::today();
        Downloads::receive(['rows' => [['d' => $a, 'day' => $day, 'n' => 3], ['d' => $b, 'day' => $day, 'n' => 5]]]);
        $this->admin()->postJson('/api/cms/hubs/'.$hub['hub']['id'].'/documents/publish', ['published' => false, 'items' => [['id' => $a, 'version' => 1]]])->assertOk();
        $this->assertSame(3, (int) DB::table('cms_hub_downloads')->where('document_id', $a)->sum('n'), 'withdrawing keeps the count');
        $this->admin()->deleteJson('/api/cms/hubs/'.$hub['hub']['id']."/documents/$a", ['version' => 2])->assertOk();
        $this->assertSame(0, DB::table('cms_hub_downloads')->where('document_id', $a)->count());
        $this->assertSame(5, (int) DB::table('cms_hub_downloads')->where('document_id', $b)->sum('n'));
        $this->admin()->deleteJson('/api/cms/hubs/'.$hub['hub']['id'], ['version' => 1])->assertOk();
        $this->assertSame(0, DB::table('cms_hub_downloads')->count());
    }

    public function test_document_files_are_audited_like_the_media_library_and_a_rolled_back_batch_leaves_a_matching_audit(): void
    {
        $hub = $this->makeHub();
        $d = $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('a.pdf')])->assertCreated()->json('data.0');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'media.uploaded')->where('entity_id', $d['media_id'])->count());
        $this->admin()->deleteJson('/api/cms/hubs/'.$hub['hub']['id'].'/documents/'.$d['id'], ['version' => 1])->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'media.deleted')->where('entity_id', $d['media_id'])->count());
        $this->assertFalse(DB::table('cms_media')->where('id', $d['media_id'])->exists());
        // a batch that fails half way: every created document gets its deleted event, no media is left behind
        $media = DB::table('cms_media')->count();
        $docs = DB::table('cms_hub_documents')->count();
        $inserts = 0;
        DB::listen(function ($q) use (&$inserts) {
            if (str_starts_with(str_replace('`', '"', $q->sql), 'insert into "cms_media"') && ++$inserts === 2) throw new \RuntimeException('simulated failure of the second file');
        });
        try {
            $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('b1.pdf', 'b1'), $this->pdf('b2.pdf', 'b2')]);
        } catch (\Throwable) {}
        $this->assertSame(2, $inserts);
        $this->assertSame($media, DB::table('cms_media')->count(), 'no media left behind');
        $this->assertSame($docs, DB::table('cms_hub_documents')->count(), 'no document left behind');
        $created = DB::table('audit_events')->where('action', 'hub.document.created')->count();
        $deleted = DB::table('audit_events')->where('action', 'hub.document.deleted')->count();
        $this->assertSame(2, $created, 'the earlier upload plus the first file of the failed batch');
        $this->assertSame(2, $deleted, 'the earlier delete plus the rollback of the failed batch');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'hub.document.deleted')->get()->filter(fn ($e) => str_contains((string) $e->detail, 'rollback'))->count());
    }

    public function test_documents_can_be_ordered_by_hand_inside_a_category_by_the_manager_only(): void
    {
        $this->teacher(9401, 'ครูหัวหน้า', ['position' => 'หัวหน้ากลุ่มงานบริหารวิชาการ', 'work_group' => 'บริหารวิชาการ']);
        $hub = $this->makeHub();
        $id = $hub['hub']['id'];
        $x = $this->doc($hub, 'แบบฟอร์ม', 'เอกสาร X', ['doc_date' => '2026-01-01']);
        $y = $this->doc($hub, 'แบบฟอร์ม', 'เอกสาร Y', ['doc_date' => '2026-02-01']);
        $z = $this->doc($hub, 'แบบฟอร์ม', 'เอกสาร Z', ['doc_date' => '2026-03-01']);
        $other = $this->doc($hub, 'ผลงาน', 'เอกสารหมวดอื่น');
        $cat = $this->catId($hub, 'แบบฟอร์ม');
        $order = fn () => DB::table('cms_hub_documents')->where('category_id', $cat)->orderByDesc('sort')->orderByDesc('doc_date')->orderByDesc('id')->pluck('title')->all();
        $this->assertSame(['เอกสาร Z', 'เอกสาร Y', 'เอกสาร X'], $order());
        $this->actingAs($this->staff('teacher', 9401))->putJson("/api/cms/hubs/$id/documents/order", ['category_id' => $cat, 'items' => [['id' => $x, 'version' => 1], ['id' => $z, 'version' => 1], ['id' => $y, 'version' => 1]]])->assertOk();
        $this->assertSame(['เอกสาร X', 'เอกสาร Z', 'เอกสาร Y'], $order());
        $this->assertSame(3, DB::table('audit_events')->where('action', 'hub.document.reordered')->count());
        // a document of another category, or an unknown one, is refused and nothing changes
        $this->admin()->putJson("/api/cms/hubs/$id/documents/order", ['category_id' => $cat, 'items' => [['id' => $y, 'version' => 2], ['id' => $other, 'version' => 1]]])->assertStatus(422);
        $this->assertSame(['เอกสาร X', 'เอกสาร Z', 'เอกสาร Y'], $order());
        // not the manager of this hub: refused
        $this->teacher(9402, 'ครูหัวหน้ากลุ่มอื่น', ['position' => 'หัวหน้ากลุ่มงานบุคคล', 'work_group' => 'บุคคล']);
        $this->actingAs($this->staff('teacher', 9402))->putJson("/api/cms/hubs/$id/documents/order", ['category_id' => $cat, 'items' => [['id' => $x, 'version' => 2]]])->assertForbidden();
    }

    public function test_hub_module_is_a_normal_web_cms_surface_and_adds_no_public_route_outside_portal(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains((string) $r->getActionName(), 'WebCms'))->map(fn ($r) => $r->uri())->all();
        $this->assertNotEmpty($uris);
        foreach ($uris as $u) $this->assertTrue(str_starts_with($u, 'portal/') || str_starts_with($u, 'api/cms/hubs') || str_starts_with($u, 'hub-preview/'), $u);
        $this->assertTrue(app(ModuleRegistry::class)->active('web-cms'));
        // none of the staff routes is reachable on the public edition
        $this->as('public');
        $this->getJson('/api/cms/hubs')->assertNotFound();
        $this->get('/hub-preview/1')->assertNotFound();
    }

    // ---------------------------------------------------------------- audit fixes (2026-11-07)

    public function test_robots_and_link_previews_get_the_file_but_are_not_counted_as_downloads(): void
    {
        $hub = $this->makeHub();
        $id = (int) $this->upload($hub, 'แบบฟอร์ม', [$this->pdf('form.pdf')])->assertCreated()->json('data.0.id');
        config(['school.demo' => false, 'school.local_only' => false]);
        $from = fn (string $ip, array $h) => $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($h)->get("/portal/d/$id");
        $from('66.249.66.1', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])->assertOk();
        $from('203.0.113.50', ['User-Agent' => 'facebookexternalhit/1.1'])->assertOk();
        $from('203.0.113.51', ['User-Agent' => 'Mozilla/5.0 Chrome/130.0', 'Sec-Purpose' => 'prefetch'])->assertOk();
        $this->assertSame(0, (int) DB::table('cms_hub_downloads')->where('document_id', $id)->sum('n'));
        $from('203.0.113.52', ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36'])->assertOk();
        $this->assertSame(1, (int) DB::table('cms_hub_downloads')->where('document_id', $id)->sum('n'), 'a browser still counts');
        $this->assertStringContainsString('rel="nofollow', $this->guest()->get('/portal/groups/academic')->getContent());
    }

    public function test_deleting_a_draft_association_page_removes_the_news_category_that_carried_its_title(): void
    {
        $this->admin();
        $res = $this->postJson('/api/cms/hubs/template', ['template' => 'alumni', 'slug' => 'probe-alumni', 'title' => 'ProbeSecretTitleXYZ'])->assertCreated();
        $id = (int) DB::table('cms_hubs')->where('slug', 'probe-alumni')->value('id');
        $slug = (string) DB::table('cms_hubs')->where('id', $id)->value('news_category');
        $this->assertNotSame('', $slug);
        $this->assertTrue(DB::table('cms_categories')->where('slug', $slug)->exists());
        $this->deleteJson('/api/cms/hubs/'.$id, ['version' => (int) DB::table('cms_hubs')->where('id', $id)->value('version') ?: 1])->assertOk();
        $this->assertFalse(DB::table('cms_categories')->where('slug', $slug)->exists(), 'the category with the unpublished title is gone');
        // a category that already holds a post stays
        $res = $this->postJson('/api/cms/hubs/template', ['template' => 'alumni', 'slug' => 'probe-two', 'title' => 'ProbeSecondTitle'])->assertCreated();
        $id2 = (int) DB::table('cms_hubs')->where('slug', 'probe-two')->value('id');
        $slug2 = (string) DB::table('cms_hubs')->where('id', $id2)->value('news_category');
        DB::table('posts')->insert(['school_id' => 1, 'author_id' => 1, 'slug' => 'kept-post', 'title' => 'ข่าวที่ยังอยู่', 'body' => 'เนื้อข่าว', 'status' => 'published', 'content_type' => 'article', 'category' => $slug2,
            'metadata' => json_encode(['source_kind' => 'original']), 'published_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson('/api/cms/hubs/'.$id2, ['version' => (int) DB::table('cms_hubs')->where('id', $id2)->value('version')])->assertOk();
        $this->assertTrue(DB::table('cms_categories')->where('slug', $slug2)->exists());
        // pointing the page at another category releases its own one
        $res = $this->postJson('/api/cms/hubs/template', ['template' => 'alumni', 'slug' => 'probe-three', 'title' => 'ProbeThirdTitle'])->assertCreated();
        $id3 = (int) DB::table('cms_hubs')->where('slug', 'probe-three')->value('id');
        $slug3 = (string) DB::table('cms_hubs')->where('id', $id3)->value('news_category');
        $this->putJson('/api/cms/hubs/'.$id3, ['version' => (int) DB::table('cms_hubs')->where('id', $id3)->value('version'), 'news_category' => 'news'])->assertOk();
        $this->assertFalse(DB::table('cms_categories')->where('slug', $slug3)->exists());
    }
}