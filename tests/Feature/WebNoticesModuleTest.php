<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saan\Modules\WebNotices\NameMask;
use Saan\Modules\WebNotices\NoticeRepository;
use Tests\TestCase;

class WebNoticesModuleTest extends TestCase
{
    use RefreshDatabase;

    private const TSV = "ลำดับ\tชื่อ-สกุล\tห้อง\tกลุ่ม\tหมายเหตุ\n1\tเด็กชายสมชาย ใจดีมาก\tม.1/1\tA\t\n2\tเด็กหญิงสมหญิง แสนสุข\tม.1/2\tB\tสำรอง\n3\tนางสาว มานี เมตตา\tม.1/1\t\t";

    protected function setUp(): void
    {
        parent::setUp(); config(['school.demo' => true]);
        // Core does not load module commands yet (reported as open issue), so the test registers it itself.
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Saan\Modules\WebNotices\Console\PruneCommand()); $this->seed(); $this->actingAs(User::where('role', 'admin')->first());
    }

    private function create(array $o = []): array
    {
        $today = NoticeRepository::today();
        return $this->postJson('/api/notices/lists', $o + ['title' => 'ประกาศทดสอบ', 'publish_from' => $today, 'expires_on' => date('Y-m-d', strtotime('+30 days')),
            'columns' => ['order_no', 'display_name', 'classroom', 'group', 'note'], 'name_mode' => 'first_initial'])->assertCreated()->json();
    }

    private function loaded(array $o = []): array
    {
        $n = $this->create($o); $id = $n['list']['id'];
        return $this->putJson("/api/notices/lists/$id/rows", ['version' => $n['list']['version'], 'text' => self::TSV])->assertOk()->json();
    }

    private function publish(array $n): array
    {
        return $this->postJson('/api/notices/lists/'.$n['list']['id'].'/status', ['version' => $n['list']['version'], 'action' => 'publish'])->assertOk()->json();
    }

    private function guest(): void { $this->app['auth']->forgetGuards(); }
    private function admin(): void { $this->actingAs(User::where('role', 'admin')->first()); }

    public function test_name_masking_modes(): void
    {
        $this->assertSame('เด็กชายสมชาย จ.', NameMask::apply('เด็กชายสมชาย ใจดีมาก', 'first_initial'));
        $this->assertSame('เด็กชายสมชาย ก.', NameMask::apply('เด็กชายสมชาย  กล้าหาญ', 'first_initial'));
        $this->assertSame('นางสาว มานี ม.', NameMask::apply('นางสาว มานี เมตตา', 'first_initial'));
        $this->assertSame('นางสาว มานี', NameMask::apply('นางสาว มานี เมตตา', 'first_only'));
        $this->assertSame('สมชาย', NameMask::apply('สมชาย ใจดี', 'first_only'));
        $this->assertSame('สม...', NameMask::apply('สมศรี', 'first_initial')); // no surname token: fail closed, never the full name
        $this->assertSame('สมศรี', NameMask::apply('สมศรี', 'first_only'));
        // zero-width space (pasted from Word/Excel) between first name and surname counts as a separator
        $this->assertSame('สมชาย จ.', NameMask::apply("สมชาย\u{200B}ใจดี", 'first_initial'));
        $this->assertSame('สมชาย ก.', NameMask::apply("สมชาย\u{FEFF}\u{200C}กล้าหาญ", 'first_initial'));
        $this->assertSame('สมชาย', NameMask::apply("สมชาย\u{200B}ใจดี", 'first_only'));
        $this->assertSame('นาย สม...', NameMask::apply('นาย สมศรี', 'first_initial'));
        $this->assertStringNotContainsString('ใจดี', NameMask::apply("สมชาย\u{2060}ใจดี", 'first_initial'));
        $this->assertStringNotContainsString('สมศรีรัตน์', NameMask::apply('สมศรีรัตน์', 'first_initial'));
        $this->assertSame('สมชาย ใจดี', NameMask::apply('สมชาย  ใจดี', 'full'));
    }

    public function test_lifecycle_masked_public_page_and_headers(): void
    {
        $n = $this->loaded(); $this->assertSame(3, $n['rows_total']);
        $this->assertSame('เด็กชายสมชาย จ.', $n['preview'][0]['display_name']);
        $this->assertStringNotContainsString('ใจดีมาก', json_encode($n, JSON_UNESCAPED_UNICODE));
        $slug = $n['list']['slug']; $this->assertMatchesRegularExpression('/^n-[a-z0-9]{12}$/', $slug);
        $this->guest(); $this->get("/portal/notices/$slug")->assertNotFound(); // draft
        $this->admin(); $this->publish($n);
        $this->guest();
        $res = $this->get("/portal/notices/$slug")->assertOk()->assertSee('เด็กชายสมชาย จ.')->assertSee('สำรอง')->assertDontSee('ใจดีมาก')->assertDontSee('แสนสุข')->assertDontSee('เมตตา')
            ->assertSee('<meta name="robots" content="noindex,nofollow,noarchive">', false);
        $this->assertStringContainsString('noindex', $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->get('/portal/notices/ab')->assertNotFound()->assertHeader('X-Robots-Tag');
        $this->get('/portal/notices/n-doesnotexist1')->assertNotFound()->assertHeader('X-Robots-Tag');
    }

    public function test_expiry_archive_and_window_return_404_not_410(): void
    {
        $n = $this->loaded(); $slug = $n['list']['slug']; $pub = $this->publish($n);
        $this->guest(); $this->get("/portal/notices/$slug")->assertOk();
        $this->travelTo(now()->addDays(29)); $this->get("/portal/notices/$slug")->assertOk();
        $this->travelTo(now()->addDays(3)); $this->get("/portal/notices/$slug")->assertNotFound()->assertHeader('X-Robots-Tag');
        $this->travelBack();
        $this->admin(); $arch = $this->postJson('/api/notices/lists/'.$n['list']['id'].'/status', ['version' => $pub['list']['version'], 'action' => 'archive'])->assertOk()->json();
        $this->assertSame('archived', $arch['list']['status']);
        $this->guest(); $this->get("/portal/notices/$slug")->assertNotFound();
        // not yet started
        $this->admin(); $f = $this->loaded(['publish_from' => date('Y-m-d', strtotime('+5 days')), 'expires_on' => date('Y-m-d', strtotime('+20 days'))]); $this->publish($f);
        $this->guest(); $this->get('/portal/notices/'.$f['list']['slug'])->assertNotFound();
    }

    public function test_date_rules_and_expiry_required(): void
    {
        $today = NoticeRepository::today();
        $base = ['title' => 'x', 'publish_from' => $today, 'columns' => ['display_name']];
        $this->postJson('/api/notices/lists', $base)->assertUnprocessable();
        $this->postJson('/api/notices/lists', $base + ['expires_on' => date('Y-m-d', strtotime('+121 days'))])->assertUnprocessable()->assertJsonValidationErrors('expires_on');
        $this->postJson('/api/notices/lists', $base + ['expires_on' => date('Y-m-d', strtotime('+120 days'))])->assertCreated();
        $this->postJson('/api/notices/lists', $base + ['expires_on' => date('Y-m-d', strtotime('-1 day'))])->assertUnprocessable();
        $n = $this->create();
        $this->putJson('/api/notices/lists/'.$n['list']['id'], ['version' => 1, 'expires_on' => date('Y-m-d', strtotime('+200 days'))])->assertUnprocessable();
    }

    public function test_access_code_flow_and_hash_never_leaks(): void
    {
        $n = $this->loaded(); $id = $n['list']['id']; $slug = $n['list']['slug'];
        $set = $this->putJson("/api/notices/lists/$id", ['version' => $n['list']['version'], 'access_code' => 'รหัส-1234'])->assertOk()->json();
        $this->assertTrue($set['list']['has_access_code']); $this->assertStringNotContainsString('access_code_hash', json_encode($set));
        $stored = DB::table('notice_lists')->where('id', $id)->value('access_code_hash');
        $this->assertStringNotContainsString('รหัส-1234', $stored); $this->assertTrue(NoticeRepository::checkCode($stored, 'รหัส-1234')); $this->assertFalse(NoticeRepository::checkCode($stored, 'x'));
        $pub = $this->publish($set);
        $this->guest();
        $this->get("/portal/notices/$slug")->assertOk()->assertSee('รหัสเข้าดูรายชื่อ')->assertDontSee('เด็กชายสมชาย')->assertHeader('X-Robots-Tag');
        $this->post("/portal/notices/$slug/unlock", ['code' => 'wrong'])->assertStatus(422)->assertSee('รหัสเข้าดูไม่ถูกต้อง')->assertDontSee('เด็กชายสมชาย');
        $this->post("/portal/notices/$slug/unlock", [])->assertStatus(422);
        $this->get("/portal/notices/$slug")->assertDontSee('เด็กชายสมชาย');
        $this->post("/portal/notices/$slug/unlock", ['code' => 'รหัส-1234'])->assertRedirect("/portal/notices/$slug");
        $this->get("/portal/notices/$slug")->assertOk()->assertSee('เด็กชายสมชาย จ.');
        // changing the code revokes the existing unlock
        $this->admin(); $cur = $this->getJson("/api/notices/lists/$id")->json('list');
        $this->putJson("/api/notices/lists/$id", ['version' => $cur['version'], 'access_code' => 'newcode9'])->assertOk();
        $this->get("/portal/notices/$slug")->assertSee('รหัสเข้าดูรายชื่อ')->assertDontSee('เด็กชายสมชาย');
    }

    public function test_unlock_is_throttled(): void
    {
        $n = $this->loaded(); $id = $n['list']['id']; $slug = $n['list']['slug'];
        $s = $this->putJson("/api/notices/lists/$id", ['version' => $n['list']['version'], 'access_code' => 'abcd1234'])->assertOk()->json(); $this->publish($s);
        $this->guest(); $last = 0;
        for ($i = 0; $i < 12; $i++) $last = $this->post("/portal/notices/$slug/unlock", ['code' => 'bad'.$i])->getStatusCode();
        $this->assertSame(429, $last);
    }

    public function test_sensitive_columns_and_values_are_rejected(): void
    {
        $n = $this->create(); $id = $n['list']['id']; $v = $n['list']['version'];
        $this->postJson('/api/notices/lists', ['title' => 'x', 'publish_from' => NoticeRepository::today(), 'expires_on' => date('Y-m-d', strtotime('+10 days')), 'columns' => ['display_name', 'phone']])->assertUnprocessable();
        $this->putJson("/api/notices/lists/$id", ['version' => $v, 'columns' => ['display_name', 'birth_date']])->assertUnprocessable();
        $put = fn (string $text) => $this->putJson("/api/notices/lists/$id/rows", ['version' => $v, 'text' => $text]);
        $put("ชื่อ\tเบอร์โทร\nสมชาย ใจดี\t0812345678")->assertUnprocessable()->assertJsonValidationErrors('text');
        $put("ชื่อ\tวันเกิด\nสมชาย ใจดี\t1/1/2560")->assertUnprocessable();
        $put("ชื่อ\tเลขบัตรประชาชน\nสมชาย ใจดี\t1234567890123")->assertUnprocessable();
        $put("ชื่อ\tอีเมล\nสมชาย ใจดี\ta@example.org")->assertUnprocessable();
        $put("ชื่อ\tที่อยู่\nสมชาย ใจดี\tบ้านเลขที่ 1")->assertUnprocessable();
        $put("ชื่อ\tรหัสนักเรียน\nสมชาย ใจดี\t12345")->assertUnprocessable();
        $put("ชื่อ\tสีผม\nสมชาย ใจดี\tดำ")->assertUnprocessable();
        // values inside allowed columns
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\tโทร 081-234-5678")->assertUnprocessable();
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\t1-2345-67890-12-3")->assertUnprocessable();
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\tเกิด 5 มกราคม 2560")->assertUnprocessable();
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\t2017-01-05")->assertUnprocessable();
        // Thai / fullwidth digits, 2-digit Buddhist year and English month names must not slip through
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\tโทร ๐๘๑๒๓๔๕๖๗๘")->assertUnprocessable();
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\t๑๒๓๔๕๖๗๘๙๐๑๒๓")->assertUnprocessable();
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\t๕/๑/๒๕๖๐")->assertUnprocessable();
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\t０８１２３４５６７８")->assertUnprocessable();
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\t01/01/60")->assertUnprocessable();
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\t5 Jan 2017")->assertUnprocessable();
        $put("ชื่อ\tห้อง\nสมชาย ใจดี\tม.1/1")->assertOk(); // clean data still accepted
        $this->assertSame(1, DB::table('notice_rows')->where('list_id', $id)->count());
        // a rejected paste leaves old rows untouched
        $cur = $this->getJson("/api/notices/lists/$id")->json('list'); $v = $cur['version'];
        $put("ชื่อ\tหมายเหตุ\nสมชาย ใจดี\t0812345678")->assertUnprocessable();
        $this->assertSame(1, DB::table('notice_rows')->where('list_id', $id)->count());
        // CSV and headerless paste follow the list columns
        $r = $this->putJson("/api/notices/lists/$id/rows", ['version' => $v, 'text' => "ลำดับ,ชื่อ\n1,\"สมชาย, ใจดี\""])->assertOk()->json(); // quoted comma inside a CSV cell is kept in one cell
        $this->assertSame('สมชาย, จ.', $r['preview'][0]['display_name']);
        // trailing empty header/data cells (copied range from a spreadsheet) are tolerated
        $r = $this->putJson("/api/notices/lists/$id/rows", ['version' => $r['list']['version'], 'text' => "ชื่อ\tห้อง\t\t\nสมชาย ใจดี\tม.1/1\t\t"])->assertOk()->json();
        $this->assertSame(1, $r['rows_total']);
    }

    public function test_csv_and_headerless_paste(): void
    {
        $n = $this->create(['columns' => ['order_no', 'display_name']]); $id = $n['list']['id'];
        $r = $this->putJson("/api/notices/lists/$id/rows", ['version' => 1, 'text' => "ลำดับ,ชื่อ\n1,\"สมชาย ใจดี\"\n2,สมหญิง แสนสุข"])->assertOk()->json();
        $this->assertSame(2, $r['rows_total']);
        $v = $r['list']['version'];
        // headerless paste is refused: a student-number first column could otherwise be published as the order number
        $this->putJson("/api/notices/lists/$id/rows", ['version' => $v, 'text' => "12345\tสมชาย ใจดี"])->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->putJson("/api/notices/lists/$id/rows", ['version' => $v, 'text' => "5\tสมปอง ดีเลิศ\n\tสมศักดิ์ ดีมาก"])->assertUnprocessable();
        $this->assertSame(2, DB::table('notice_rows')->where('list_id', $id)->count());
        $r = $this->putJson("/api/notices/lists/$id/rows", ['version' => $v, 'text' => "ลำดับ\tชื่อ\n5\tสมปอง ดีเลิศ\n\tสมศักดิ์ ดีมาก"])->assertOk()->json();
        $this->assertSame([5, 6], array_map('intval', array_column($r['preview'], 'order_no')));
        $this->putJson("/api/notices/lists/$id/rows", ['version' => $r['list']['version'], 'text' => "ลำดับ\tชื่อ\tห้อง\n1\tสมชาย ใจดี\tม.1"])->assertUnprocessable(); // column not enabled
    }

    public function test_roles_scoping_conflicts_and_delete_rules(): void
    {
        $n = $this->loaded(); $id = $n['list']['id']; $slug = $n['list']['slug'];
        $teacher = User::where('role', 'teacher')->first();
        $this->actingAs($teacher)->getJson('/api/notices/lists')->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/notices/lists', [])->assertForbidden();
        foreach (['principal', 'editor'] as $role) {
            $teacher->forceFill(['role' => $role])->save();
            $this->actingAs($teacher->fresh())->getJson('/api/notices/lists')->assertOk();
        }
        $this->app['auth']->forgetGuards(); $this->getJson('/api/notices/lists')->assertUnauthorized();
        $this->admin();
        // other school's list is invisible and untouchable
        $other = DB::table('notice_lists')->insertGetId(['school_id' => 999, 'slug' => 'other-school-list', 'title' => 'อื่น', 'columns' => '["display_name"]', 'name_mode' => 'full', 'publish_from' => date('Y-m-d', strtotime('-1 day')), 'expires_on' => date('Y-m-d', strtotime('+5 days')), 'status' => 'published', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson("/api/notices/lists/$other")->assertNotFound();
        $this->putJson("/api/notices/lists/$other", ['version' => 1, 'title' => 'hack'])->assertNotFound();
        $this->deleteJson("/api/notices/lists/$other", ['version' => 1])->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/notices/lists')->json('data'));
        $this->guest(); $this->get('/portal/notices/other-school-list')->assertNotFound(); $this->admin();
        // slug conflict -> 409, version conflict -> 409
        $this->postJson('/api/notices/lists', ['title' => 'dup', 'slug' => $slug, 'publish_from' => NoticeRepository::today(), 'expires_on' => date('Y-m-d', strtotime('+5 days')), 'columns' => ['display_name']])->assertConflict();
        $this->postJson('/api/notices/lists', ['title' => 'Up', 'slug' => strtoupper($slug), 'publish_from' => NoticeRepository::today(), 'expires_on' => date('Y-m-d', strtotime('+5 days')), 'columns' => ['display_name']])->assertUnprocessable();
        $this->putJson("/api/notices/lists/$id", ['version' => 1, 'title' => 'stale'])->assertConflict()->assertJsonMissingPath('current.access_code_hash');
        // publish needs rows; live list cannot be deleted; archived can
        $empty = $this->create(); $this->postJson('/api/notices/lists/'.$empty['list']['id'].'/status', ['version' => 1, 'action' => 'publish'])->assertUnprocessable();
        $cur = $this->getJson("/api/notices/lists/$id")->json('list'); $pub = $this->publish(['list' => $cur]);
        $this->deleteJson("/api/notices/lists/$id", ['version' => $pub['list']['version']])->assertUnprocessable();
        $arch = $this->postJson("/api/notices/lists/$id/status", ['version' => $pub['list']['version'], 'action' => 'archive'])->json();
        $this->deleteJson("/api/notices/lists/$id", ['version' => 1])->assertConflict();
        $this->deleteJson("/api/notices/lists/$id", ['version' => $arch['list']['version']])->assertOk();
        $this->assertSame(0, DB::table('notice_rows')->where('list_id', $id)->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'notices.deleted']);
        // an expired but not archived list can also be deleted
        $e = $this->loaded(); $this->publish($e); $this->travelTo(now()->addDays(40));
        $cur = $this->getJson('/api/notices/lists/'.$e['list']['id'])->json('list');
        $this->deleteJson('/api/notices/lists/'.$e['list']['id'], ['version' => $cur['version']])->assertOk();
        $this->travelBack();
    }

    public function test_unique_violation_race_returns_409_not_500(): void
    {
        $n = $this->create(); $slug = $n['list']['slug'];
        $repo = app(NoticeRepository::class); $m = new \ReflectionMethod($repo, 'guard');
        try {
            $m->invoke($repo, fn () => DB::table('notice_lists')->insert(['school_id' => (int) config('school.school_id'), 'slug' => $slug, 'title' => 'dup', 'columns' => '["display_name"]', 'name_mode' => 'full', 'publish_from' => '2026-01-01', 'expires_on' => '2026-02-01', 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]));
            $this->fail('expected a conflict');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_disabled_column_values_never_reach_public_page(): void
    {
        $n = $this->loaded(); $id = $n['list']['id'];
        $u = $this->putJson("/api/notices/lists/$id", ['version' => $n['list']['version'], 'columns' => ['order_no', 'display_name']])->assertOk()->json();
        $this->publish($u); $this->guest();
        $this->get('/portal/notices/'.$n['list']['slug'])->assertOk()->assertSee('เด็กชายสมชาย จ.')->assertDontSee('สำรอง');
    }

    public function test_access_code_minimum_length(): void
    {
        $n = $this->create();
        $this->putJson('/api/notices/lists/'.$n['list']['id'], ['version' => $n['list']['version'], 'access_code' => '1234'])->assertUnprocessable()->assertJsonValidationErrors('access_code');
        // short all-digit codes are a 10^6-10^7 search space: need 8+ digits or a mix of characters
        $this->putJson('/api/notices/lists/'.$n['list']['id'], ['version' => $n['list']['version'], 'access_code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('access_code');
        $this->putJson('/api/notices/lists/'.$n['list']['id'], ['version' => $n['list']['version'], 'access_code' => '1234567'])->assertUnprocessable()->assertJsonValidationErrors('access_code');
        $r = $this->putJson('/api/notices/lists/'.$n['list']['id'], ['version' => $n['list']['version'], 'access_code' => 'ab3456'])->assertOk()->json();
        $this->putJson('/api/notices/lists/'.$n['list']['id'], ['version' => $r['list']['version'], 'access_code' => '12345678'])->assertOk();
    }

    public function test_unlock_has_a_list_wide_failure_ceiling_and_ipv6_shares_a_64(): void
    {
        $n = $this->loaded(); $id = $n['list']['id']; $slug = $n['list']['slug'];
        $s = $this->putJson("/api/notices/lists/$id", ['version' => $n['list']['version'], 'access_code' => 'abcd1234'])->assertOk()->json(); $this->publish($s);
        config(['school.demo' => false, 'school.local_only' => false]); // demo mode refuses non-local addresses; the test sends other REMOTE_ADDRs
        $this->guest();
        $rl = \Illuminate\Support\Facades\RateLimiter::class;
        // many different networks, none of them over its own limit: the list-wide budget still closes the form (even for the right code)
        for ($i = 0; $i < \Saan\Modules\WebNotices\Http\PublicNoticeController::LIST_FAILS_PER_HOUR; $i++) $rl::hit('notice-unlock-list:'.$id, 3600);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->post("/portal/notices/$slug/unlock", ['code' => 'abcd1234'])->assertStatus(429);
        $this->get("/portal/notices/$slug")->assertDontSee('เด็กชายสมชาย');
        $rl::clear('notice-unlock-list:'.$id);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->post("/portal/notices/$slug/unlock", ['code' => 'abcd1234'])->assertRedirect("/portal/notices/$slug");
        // a wrong code is counted on the list-wide key and on the /64 key
        $this->guest();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:7:7:1:2:3:4'])->post("/portal/notices/$slug/unlock", ['code' => 'nope'])->assertStatus(422);
        $this->assertSame(1, $rl::attempts('notice-unlock-list:'.$id));
        $this->assertSame(1, $rl::attempts('notice-unlock:2001:db8:7:7::/64|'.$slug));
        for ($i = 0; $i < 10; $i++) $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:7:7:'.dechex($i + 100).'::9'])->post("/portal/notices/$slug/unlock", ['code' => 'x'.$i]);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:7:7:ffff::1'])->post("/portal/notices/$slug/unlock", ['code' => 'abcd1234'])->assertStatus(429); // same /64, different address
    }

    public function test_one_client_failing_codes_cannot_lock_the_list_for_everybody_else(): void
    {
        $n = $this->loaded(); $id = $n['list']['id']; $slug = $n['list']['slug'];
        $s = $this->putJson("/api/notices/lists/$id", ['version' => $n['list']['version'], 'access_code' => 'abcd1234'])->assertOk()->json(); $this->publish($s);
        config(['school.demo' => false, 'school.local_only' => false]);
        $this->guest();
        $rl = \Illuminate\Support\Facades\RateLimiter::class;
        $codes = [];
        for ($i = 0; $i < 105; $i++) {
            $rl::clear('notice-unlock:203.0.113.9|'.$slug); // the per-minute counter is not what is under test
            $codes[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post("/portal/notices/$slug/unlock", ['code' => 'bad'.$i])->getStatusCode();
        }
        $this->assertSame(429, end($codes), 'the noisy client is stopped by its own hourly share');
        $this->assertLessThanOrEqual(\Saan\Modules\WebNotices\Http\PublicNoticeController::NET_FAILS_PER_HOUR, $rl::attempts('notice-unlock-list:'.$id));
        $this->guest();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->post("/portal/notices/$slug/unlock", ['code' => 'abcd1234'])->assertRedirect("/portal/notices/$slug"); // somebody else still gets in
    }

    public function test_zero_width_separators_in_pasted_names_are_normalised_and_masked(): void
    {
        $n = $this->create(['name_mode' => 'first_initial', 'columns' => ['display_name']]);
        $id = $n['list']['id'];
        $this->putJson("/api/notices/lists/$id/rows", ['version' => $n['list']['version'], 'text' => "ชื่อ\nสมชาย\u{200B}ใจดี\nสมหญิง\u{200B}\u{200C}รักเรียน"])->assertOk();
        $stored = DB::table('notice_rows')->where('list_id', $id)->orderBy('id')->pluck('display_name')->all();
        $this->assertSame(['สมชาย ใจดี', 'สมหญิง รักเรียน'], $stored);
        foreach ($stored as $name) $this->assertStringNotContainsString("\u{200B}", $name);
    }

    public function test_not_in_sitemap_or_search_and_module_registered(): void
    {
        $n = $this->loaded(['title' => 'ประกาศลับค้นหาไม่เจอ']); $this->publish($n); $slug = $n['list']['slug'];
        $this->guest();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee($slug)->assertDontSee('/portal/notices');
        $this->get('/portal/search?q='.urlencode('ประกาศลับ'))->assertOk()->assertDontSee('/portal/notices', false)->assertDontSee($slug);
        $this->assertContains('web-notices', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->admin(); $this->get('/cms')->assertOk()->assertSee('assets/modules/web-notices/notices-admin.js', false)->assertSee('ประกาศรายชื่อ');
        $this->assertFileExists(public_path('assets/modules/web-notices/notices-admin.js'));
    }

    public function test_prune_command_deletes_rows_of_lists_expired_over_30_days(): void
    {
        $mk = fn (string $slug, string $exp, string $status = 'published') => DB::table('notice_lists')->insertGetId(['school_id' => 1, 'slug' => $slug, 'title' => $slug, 'columns' => '["display_name"]', 'name_mode' => 'full', 'publish_from' => date('Y-m-d', strtotime($exp.' -10 days')), 'expires_on' => $exp, 'access_code_hash' => 'a:b', 'status' => $status, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $row = fn (int $list, int $school = 1) => DB::table('notice_rows')->insert(['school_id' => $school, 'list_id' => $list, 'order_no' => 1, 'display_name' => 'สมชาย ใจดี']);
        $school = (int) config('school.school_id');
        $old = $mk('old-list', date('Y-m-d', strtotime('-31 days'))); $edge = $mk('edge-list', date('Y-m-d', strtotime('-30 days'))); $fresh = $mk('fresh-list', date('Y-m-d', strtotime('-5 days'))); $live = $mk('live-list', date('Y-m-d', strtotime('+5 days')));
        DB::table('notice_lists')->update(['school_id' => $school]);
        foreach ([$old, $edge, $fresh, $live] as $l) $row($l, $school);
        $foreign = DB::table('notice_lists')->insertGetId(['school_id' => 999, 'slug' => 'foreign-old', 'title' => 'f', 'columns' => '["display_name"]', 'name_mode' => 'full', 'publish_from' => '2020-01-01', 'expires_on' => '2020-02-01', 'status' => 'published', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $row($foreign, 999);
        $this->artisan('saan:notices-prune', ['--dry-run' => true])->expectsOutputToContain('rows_deleted=1')->assertSuccessful();
        $this->assertSame(1, DB::table('notice_rows')->where('list_id', $old)->count());
        $this->artisan('saan:notices-prune')->expectsOutputToContain('[OK]')->assertSuccessful();
        $this->assertSame(0, DB::table('notice_rows')->where('list_id', $old)->count());
        $o = DB::table('notice_lists')->find($old); $this->assertSame('archived', $o->status); $this->assertNull($o->access_code_hash); $this->assertSame(2, (int) $o->version);
        foreach ([$edge, $fresh, $live, $foreign] as $l) $this->assertSame(1, DB::table('notice_rows')->where('list_id', $l)->count());
        $this->artisan('saan:notices-prune')->expectsOutputToContain('lists=0')->assertSuccessful(); // idempotent
        $this->artisan('saan:notices-prune', ['--days' => 0])->assertSuccessful(); // edge now expired >= cutoff
        $this->assertSame(0, DB::table('notice_rows')->where('list_id', $edge)->count());
        $this->assertSame(1, DB::table('notice_rows')->where('list_id', $live)->count());
        $this->assertSame(1, DB::table('notice_rows')->where('list_id', $foreign)->count());
    }
}
