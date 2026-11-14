<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Maintenance\Maintenance;
use Saan\Modules\Maintenance\Notifications\MntNotification;
use Tests\TestCase;

class MaintenanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $diskRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saan-maintenance-'.bin2hex(random_bytes(6));
        mkdir($this->diskRoot, 0700, true);
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::purge('local');
    }

    protected function tearDown(): void
    {
        Storage::purge('local');
        if (is_dir($this->diskRoot)) (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function as(string $role): static { return $this->actingAs(User::where('role', $role)->where('school_id', 1)->orderBy('id')->first()); }

    private function addUser(string $role, string $name, int $school = 1): User
    {
        $id = DB::table('users')->insertGetId(['school_id' => $school, 'name' => $name.' (สมมติ)', 'email' => $name.$school.'@demo.school.test', 'password' => Hash::make(bin2hex(random_bytes(12))),
            'role' => $role, 'is_demo' => true, 'created_at' => now(), 'updated_at' => now()]);
        return User::find($id);
    }

    private function makeTech(User ...$users): void
    {
        $this->as('admin');
        $v = (int) $this->getJson('/api/maintenance/technicians')->json('version');
        $this->putJson('/api/maintenance/technicians', ['version' => $v, 'technician_user_ids' => array_map(fn ($u) => $u->id, $users)])->assertOk();
    }

    private function png(string $name = 'leak.png', int $pad = 0): UploadedFile
    {
        $img = imagecreatetruecolor(8, 8); ob_start(); imagepng($img); $bin = ob_get_clean();
        return UploadedFile::fake()->createWithContent($name, $bin.str_repeat("\0", $pad));
    }

    private function report(array $over = [], ?UploadedFile $photo = null): array
    {
        $body = array_merge(['title' => 'ก๊อกน้ำรั่วทดสอบ', 'location' => 'อาคาร 1 ห้องน้ำชาย', 'detail' => 'น้ำหยดตลอด'], $over);
        if ($photo) $body['photo'] = $photo;
        return $this->post('/api/maintenance/requests', $body, ['Accept' => 'application/json'])->assertCreated()->json();
    }

    private function go(int $id, string $to, int $version, array $extra = []) { return $this->postJson('/api/maintenance/requests/'.$id.'/transition', ['to' => $to, 'version' => $version] + $extra); }

    public function test_staff_reports_with_private_photo_and_only_owner_and_technicians_see_it(): void
    {
        $owner = $this->addUser('teacher', 'owner'); $other = $this->addUser('teacher', 'other'); $tech = $this->addUser('teacher', 'tech');
        $this->makeTech($tech);
        $this->actingAs($owner);
        $r = $this->report([], $this->png('ชื่อไทย/../x.png'));
        $this->assertSame('new', $r['status']); $this->assertTrue($r['has_photo']); $this->assertSame(['cancelled'], $r['allowed']);
        $row = DB::table('mnt_requests')->find($r['id']);
        $this->assertMatchesRegularExpression('#^maintenance/1/requests/[0-9a-f]{32}\.png$#', $row->photo_path);
        $this->assertTrue(Storage::disk('local')->exists($row->photo_path));
        $this->assertFileDoesNotExist(public_path($row->photo_path));
        $this->assertFileDoesNotExist(public_path('storage/'.$row->photo_path));
        $this->assertGreaterThan(0, (int) $row->photo_size);
        $this->get('/api/maintenance/requests/'.$r['id'].'/photo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($tech)->get('/api/maintenance/requests/'.$r['id'].'/photo')->assertOk();
        $this->actingAs($other)->get('/api/maintenance/requests/'.$r['id'].'/photo')->assertNotFound();
        $this->getJson('/api/maintenance/requests/'.$r['id'])->assertNotFound();
        $this->getJson('/api/maintenance/requests?scope=all')->assertForbidden();
        $this->assertSame(0, $this->getJson('/api/maintenance/requests')->json('total'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/maintenance/requests/'.$r['id'].'/photo')->assertUnauthorized();
    }

    public function test_photo_validation_and_quota(): void
    {
        $this->actingAs($this->addUser('teacher', 'p'));
        $fake = UploadedFile::fake()->createWithContent('a.jpg', "<?php echo 1; ?> not an image");
        $this->post('/api/maintenance/requests', ['title' => 'x1', 'location' => 'ห้อง', 'photo' => $fake], ['Accept' => 'application/json'])->assertUnprocessable();
        $big = UploadedFile::fake()->createWithContent('b.png', substr($this->png()->getContent(), 0, 40).str_repeat('a', (Maintenance::MAX_PHOTO_MB + 1) * 1048576));
        $this->post('/api/maintenance/requests', ['title' => 'x2', 'location' => 'ห้อง', 'photo' => $big], ['Accept' => 'application/json'])->assertUnprocessable();
        config(['saan.storage.quota_mb' => 1]);
        DB::table('mnt_requests')->insert(['school_id' => 1, 'location' => 'x', 'title' => 'fill', 'reported_by' => 1, 'status' => 'new', 'photo_path' => 'maintenance/1/requests/f.png', 'photo_size' => 1048576, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/api/maintenance/requests', ['title' => 'x3', 'location' => 'ห้อง', 'photo' => $this->png()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, DB::table('mnt_requests')->where('title', 'x3')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('maintenance'));
        $this->postJson('/api/maintenance/requests', ['title' => 'no photo ok', 'location' => 'ห้อง'])->assertCreated();
    }

    public function test_transitions_allowed_and_forbidden(): void
    {
        $owner = $this->addUser('teacher', 'owner'); $tech = $this->addUser('editor', 'tech');
        $this->makeTech($tech);
        $this->actingAs($owner);
        $r = $this->report(); $id = $r['id'];
        // reporter cannot run technician steps
        $this->go($id, 'accepted', $r['version'])->assertForbidden();
        $this->actingAs($tech);
        $this->go($id, 'done', $r['version'], ['resolution' => 'ซ่อมแล้ว'])->assertUnprocessable(); // new -> done not allowed
        $this->go($id, 'new', $r['version'])->assertUnprocessable();
        $this->go($id, 'rejected', $r['version'])->assertUnprocessable(); // needs a reason
        $a = $this->go($id, 'accepted', $r['version'])->assertOk()->json();
        $this->assertSame($tech->id, $a['assigned_to']);
        $this->assertSame(['in_progress', 'waiting_parts', 'done', 'rejected', 'cancelled'], $a['allowed']);
        $p = $this->go($id, 'in_progress', $a['version'])->assertOk()->json();
        $this->assertSame(['waiting_parts', 'done', 'rejected'], $p['allowed']);
        $w = $this->go($id, 'waiting_parts', $p['version'], ['note' => 'รออะไหล่'])->assertOk()->json();
        $this->go($id, 'cancelled', $w['version'])->assertUnprocessable(); // cannot cancel once work started
        $i = $this->go($id, 'in_progress', $w['version'])->assertOk()->json();
        $this->go($id, 'done', $i['version'])->assertUnprocessable(); // resolution required
        $d = $this->go($id, 'done', $i['version'], ['resolution' => 'เปลี่ยนซีลก๊อกน้ำ', 'cost_satang' => 12050])->assertOk()->json();
        $this->assertSame('done', $d['status']); $this->assertSame(12050, $d['cost_satang']); $this->assertNotNull($d['closed_at']); $this->assertSame([], $d['allowed']);
        $this->go($id, 'in_progress', $d['version'])->assertUnprocessable();
        $this->go($id, 'accepted', $d['version'])->assertUnprocessable();
        $this->assertSame(['new', 'accepted', 'in_progress', 'waiting_parts', 'in_progress', 'done'], collect($d['logs'])->pluck('to_status')->all());
        $this->assertSame('รออะไหล่', $d['logs'][3]['note']);
        $this->assertGreaterThanOrEqual(6, DB::table('audit_events')->where('entity', 'mnt_requests')->count());
        // reporter cancel: own, new/accepted only
        $this->actingAs($owner);
        $c = $this->report(['title' => 'ยกเลิกเอง']);
        $this->assertSame(['cancelled'], $c['allowed']);
        $this->go($c['id'], 'cancelled', $c['version'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->actingAs($this->addUser('teacher', 'stranger'));
        $c2 = $this->actingAs($owner)->report(['title' => 'ของคนอื่น']);
        $this->actingAs($this->addUser('teacher', 'stranger2'));
        $this->go($c2['id'], 'cancelled', $c2['version'])->assertNotFound();
        // bad status value is a validation error
        $this->actingAs($tech)->go($c2['id'], 'bogus', 1)->assertUnprocessable();
    }

    public function test_concurrent_transitions_use_version(): void
    {
        $owner = $this->addUser('teacher', 'o'); $t1 = $this->addUser('teacher', 't1'); $t2 = $this->addUser('teacher', 't2');
        $this->makeTech($t1, $t2);
        $r = $this->actingAs($owner)->report();
        $this->actingAs($t1)->go($r['id'], 'accepted', $r['version'])->assertOk();
        $res = $this->actingAs($t2)->go($r['id'], 'accepted', $r['version'])->assertStatus(409);
        $this->assertSame('accepted', $res->json('current.status'));
        $this->assertSame(2, (int) $res->json('current.version'));
        $this->assertSame(2, DB::table('mnt_request_logs')->where('request_id', $r['id'])->count());
        $cur = $this->getJson('/api/maintenance/requests/'.$r['id'])->json();
        $this->putJson('/api/maintenance/requests/'.$r['id'], ['version' => 1, 'title' => 'เก่า'])->assertStatus(409);
        $this->putJson('/api/maintenance/requests/'.$r['id'], ['version' => $cur['version'], 'urgency' => 'urgent'])->assertOk()->assertJsonPath('urgency', 'urgent');
    }

    public function test_reporter_notified_on_done_and_rejected_only(): void
    {
        Notification::fake();
        $owner = $this->addUser('teacher', 'owner'); $tech = $this->addUser('teacher', 'tech');
        $this->makeTech($tech);
        $a = $this->actingAs($owner)->report(); $b = $this->report(['title' => 'เรื่องที่สอง']);
        $this->actingAs($tech);
        $x = $this->go($a['id'], 'accepted', $a['version'])->json();
        $x = $this->go($a['id'], 'in_progress', $x['version'])->json();
        Notification::assertNothingSent();
        $this->go($a['id'], 'done', $x['version'], ['resolution' => 'เรียบร้อย'])->assertOk();
        Notification::assertSentTo($owner, MntNotification::class, fn ($n) => $n->event === 'done' && str_contains(implode(' ', $n->lines), 'เรียบร้อย') && str_contains($n->subject, 'ซ่อมเสร็จ'));
        $this->go($b['id'], 'rejected', $b['version'], ['note' => 'อยู่นอกขอบเขตงานช่าง'])->assertOk()->assertJsonPath('resolution', 'อยู่นอกขอบเขตงานช่าง');
        Notification::assertSentTo($owner, MntNotification::class, fn ($n) => $n->event === 'rejected' && str_contains(implode(' ', $n->lines), 'อยู่นอกขอบเขต'));
        Notification::assertSentToTimes($owner, MntNotification::class, 2);
        Notification::assertNotSentTo($tech, MntNotification::class);
        // a technician closing their own report is not mailed about it
        $own = $this->report(['title' => 'ของช่างเอง']);
        $y = $this->go($own['id'], 'accepted', $own['version'])->json();
        $this->go($own['id'], 'done', $y['version'], ['resolution' => 'ทำเอง'])->assertOk();
        Notification::assertNotSentTo($tech, MntNotification::class);
        // the real mail renders in Thai
        $mail = (new MntNotification('done', 'แจ้งซ่อม #1 ซ่อมเสร็จแล้ว', ['เรื่อง: ทดสอบ'], 'repairs/1'))->toMail($owner);
        $this->assertStringContainsString('เรื่อง: ทดสอบ', view($mail->view[0], $mail->viewData)->render());
    }

    public function test_assets_unique_code_status_sync_history_and_disposal(): void
    {
        $this->as('admin');
        $a = $this->postJson('/api/maintenance/assets', ['asset_code' => 'ค.ภ. 1/2567-001', 'name' => 'เครื่องปรับอากาศ', 'category' => 'ไฟฟ้า', 'location' => 'ห้อง 101', 'price_satang' => 2500000, 'acquired_on' => '2024-05-01'])->assertCreated()->json();
        $this->assertSame('in_use', $a['status']);
        $this->postJson('/api/maintenance/assets', ['asset_code' => ' ค.ภ.  1/2567-001 ', 'name' => 'ซ้ำ'])->assertStatus(409);
        $this->postJson('/api/maintenance/assets', ['asset_code' => 'ab-1', 'name' => 'x'])->assertCreated();
        $this->postJson('/api/maintenance/assets', ['asset_code' => 'AB-1', 'name' => 'x'])->assertStatus(409);
        $this->postJson('/api/maintenance/assets', ['asset_code' => 'ok1', 'name' => 'x', 'responsible_teacher_id' => 999999])->assertUnprocessable();
        // DB unique index is the backstop (race that passes the code check) and maps to 409
        $this->assertSame(1, DB::table('mnt_assets')->where('asset_code_key', 'ab-1')->count());
        $this->expectUniqueViolation(fn () => DB::table('mnt_assets')->insert(['school_id' => 1, 'asset_code' => 'AB-1', 'asset_code_key' => 'ab-1', 'name' => 'dup', 'status' => 'in_use', 'version' => 1]));
        // report against the asset: location defaults from the asset
        $owner = $this->addUser('teacher', 'owner');
        $r = $this->actingAs($owner)->report(['asset_id' => $a['id'], 'location' => '']);
        $this->assertSame('ห้อง 101', $r['location']); $this->assertSame('ค.ภ. 1/2567-001', $r['asset_code']);
        $this->assertSame([$a['asset_code']], array_column($this->getJson('/api/maintenance/assets/lookup?q=2567')->json('data'), 'asset_code'));
        $this->assertSame('in_use', DB::table('mnt_assets')->find($a['id'])->status); // new does not flip the asset
        $this->as('admin');
        $x = $this->go($r['id'], 'accepted', $r['version'])->assertOk()->json();
        $this->assertSame('repair', DB::table('mnt_assets')->find($a['id'])->status);
        $this->go($r['id'], 'done', $x['version'], ['resolution' => 'ล้างแอร์', 'cost_satang' => 80000])->assertOk();
        $this->assertSame('in_use', DB::table('mnt_assets')->find($a['id'])->status);
        $h = $this->getJson('/api/maintenance/assets/'.$a['id'])->assertOk()->json();
        $this->assertSame(1, $h['history_count']); $this->assertSame(80000, $h['history_cost_satang']); $this->assertSame($r['id'], $h['history'][0]['id']);
        // delete blocked by history; update with stale version 409; disposed blocks new reports
        $this->deleteJson('/api/maintenance/assets/'.$a['id'], ['version' => $h['version']])->assertUnprocessable();
        $cur = $this->getJson('/api/maintenance/assets/'.$a['id'])->json();
        $this->putJson('/api/maintenance/assets/'.$a['id'], ['version' => $cur['version'] + 5, 'name' => 'x'])->assertStatus(409);
        $this->putJson('/api/maintenance/assets/'.$a['id'], ['version' => $cur['version'], 'status' => 'disposed'])->assertOk();
        $this->actingAs($owner)->postJson('/api/maintenance/requests', ['title' => 'จำหน่ายแล้ว', 'asset_id' => $a['id']])->assertUnprocessable();
        $this->assertSame([], $this->getJson('/api/maintenance/assets/lookup?q=2567')->json('data'));
        // a never-used asset can be deleted
        $this->as('admin');
        $n = $this->postJson('/api/maintenance/assets', ['asset_code' => 'tmp-9', 'name' => 'tmp'])->json();
        $this->deleteJson('/api/maintenance/assets/'.$n['id'], ['version' => $n['version']])->assertOk();
    }

    private function expectUniqueViolation(\Closure $fn): void
    {
        try { $fn(); $this->fail('unique index did not fire'); } catch (\Illuminate\Database\QueryException $e) { $this->assertTrue(\App\Support\Saan\DbError::isUnique($e)); }
    }

    public function test_stock_moves_never_go_negative_and_are_idempotent(): void
    {
        $this->as('admin');
        $it = $this->postJson('/api/maintenance/stock', ['code' => 'LAMP-LED', 'name' => 'หลอดไฟ LED', 'unit' => 'หลอด', 'reorder_level' => 5, 'initial_qty' => 10])->assertCreated()->json();
        $this->assertSame(10, $it['qty_on_hand']); $this->assertFalse($it['below_reorder']);
        $this->postJson('/api/maintenance/stock', ['code' => ' lamp-led', 'name' => 'ซ้ำ', 'unit' => 'x'])->assertStatus(409);
        $url = '/api/maintenance/stock/'.$it['id'].'/moves';
        $this->postJson($url, ['kind' => 'out', 'qty' => 11])->assertUnprocessable();
        $this->assertSame(10, DB::table('mnt_stock_items')->find($it['id'])->qty_on_hand);
        $this->postJson($url, ['kind' => 'out', 'qty' => 0])->assertUnprocessable();
        $this->postJson($url, ['kind' => 'out', 'qty' => -3])->assertUnprocessable();
        $this->postJson($url, ['kind' => 'adjust', 'qty' => 0])->assertUnprocessable();
        $this->postJson($url, ['kind' => 'adjust', 'qty' => -11])->assertUnprocessable();
        $o = $this->postJson($url, ['kind' => 'out', 'qty' => 6, 'ref' => 'งานซ่อม #1'], ['Idempotency-Key' => 'k-1'])->assertCreated()->json();
        $this->assertSame(4, $o['qty_on_hand']); $this->assertTrue($o['below_reorder']);
        $this->postJson($url, ['kind' => 'out', 'qty' => 6, 'ref' => 'งานซ่อม #1'], ['Idempotency-Key' => 'k-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(4, DB::table('mnt_stock_items')->find($it['id'])->qty_on_hand); // replay did not withdraw twice
        $this->postJson($url, ['kind' => 'out', 'qty' => 5])->assertUnprocessable();
        $this->postJson($url, ['kind' => 'in', 'qty' => 3])->assertCreated();
        $this->postJson($url, ['kind' => 'adjust', 'qty' => -2, 'note' => 'นับสต็อกพบขาด'])->assertCreated();
        $this->assertSame(5, DB::table('mnt_stock_items')->find($it['id'])->qty_on_hand);
        $m = $this->getJson($url)->assertOk()->json();
        $this->assertSame(['adjust', 'in', 'out', 'in'], array_column($m['data'], 'kind'));
        $this->assertSame(-2, $m['data'][0]['qty']);
        // balance always equals the sum of signed moves
        $sum = 0; foreach (DB::table('mnt_stock_moves')->where('item_id', $it['id'])->get() as $mv) $sum += $mv->kind === 'out' ? -$mv->qty : $mv->qty;
        $this->assertSame(5, $sum);
        $this->assertCount(1, $this->getJson('/api/maintenance/reports/stock-below')->json('data'));
        $this->putJson('/api/maintenance/stock/'.$it['id'], ['version' => 99, 'name' => 'x'])->assertStatus(409);
        $cur = DB::table('mnt_stock_items')->find($it['id']);
        $this->putJson('/api/maintenance/stock/'.$it['id'], ['version' => $cur->version, 'reorder_level' => 2])->assertOk();
        $this->assertSame([], $this->getJson('/api/maintenance/reports/stock-below')->json('data'));
        $this->expectUniqueViolation(fn () => DB::table('mnt_stock_items')->insert(['school_id' => 1, 'code' => 'X', 'code_key' => 'lamp-led', 'name' => 'd', 'unit' => 'u', 'qty_on_hand' => 0, 'reorder_level' => 0, 'version' => 1]));
    }

    public function test_reports_open_by_age_and_cost_per_month(): void
    {
        $this->as('admin');
        $now = now();
        $mk = fn (string $title, string $status, $created, $closed = null, $cost = null) => DB::table('mnt_requests')->insertGetId(['school_id' => 1, 'location' => 'ห้อง', 'title' => $title, 'reported_by' => 1,
            'status' => $status, 'cost_satang' => $cost, 'closed_at' => $closed, 'created_at' => $created, 'updated_at' => $created, 'version' => 1]);
        $mk('ใหม่', 'new', $now->copy()->subDays(1)); $mk('สัปดาห์', 'accepted', $now->copy()->subDays(5)); $mk('เดือน', 'waiting_parts', $now->copy()->subDays(20)); $mk('เก่ามาก', 'in_progress', $now->copy()->subDays(90));
        $mk('ปิดแล้ว', 'done', ($now->year - 1).'-06-01 10:00:00', ($now->year - 1).'-06-02 10:00:00', 100);
        $rep = $this->getJson('/api/maintenance/reports/open-by-age')->assertOk()->json();
        $this->assertSame(['0-2' => 1, '3-7' => 1, '8-14' => 0, '15-30' => 1, '31+' => 1], $rep['buckets']);
        $this->assertSame(4, $rep['total']); $this->assertSame('เก่ามาก', $rep['data'][0]['title']);
        $y = (int) $now->format('Y');
        $mk('เม.ย.', 'done', "$y-04-01 10:00:00", "$y-04-10 10:00:00", 150000); $mk('เม.ย.2', 'done', "$y-04-01 10:00:00", "$y-04-30 23:00:00", 5050);
        $mk('ก.ค.', 'done', "$y-06-01 10:00:00", "$y-07-02 10:00:00", 700); $mk('ยกเลิก', 'cancelled', "$y-06-01 10:00:00", "$y-07-02 10:00:00", 999999);
        $mk('ปีก่อน', 'done', ($y - 1).'-12-01 10:00:00', ($y - 1).'-12-31 23:59:59', 123);
        $c = $this->getJson('/api/maintenance/reports/cost-by-month?year='.$y)->assertOk()->json();
        $this->assertSame(155050, $c['months'][3]['cost_satang']); $this->assertSame(2, $c['months'][3]['count']);
        $this->assertSame(700, $c['months'][6]['cost_satang']); $this->assertSame(0, $c['months'][0]['count']);
        $this->assertSame(155750, $c['total_cost_satang']); $this->assertSame(3, $c['total_count']);
        $this->getJson('/api/maintenance/reports/cost-by-month?year=1999')->assertUnprocessable();
    }

    public function test_scoping_between_schools(): void
    {
        $this->as('admin');
        $now = now();
        $fa = DB::table('mnt_assets')->insertGetId(['school_id' => 2, 'asset_code' => 'F-1', 'asset_code_key' => 'f-1', 'name' => 'ของโรงเรียนอื่น', 'status' => 'in_use', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $fr = DB::table('mnt_requests')->insertGetId(['school_id' => 2, 'location' => 'x', 'title' => 'ของโรงเรียนอื่น', 'reported_by' => 1, 'status' => 'new', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $fi = DB::table('mnt_stock_items')->insertGetId(['school_id' => 2, 'code' => 'F', 'code_key' => 'f', 'name' => 'other', 'unit' => 'u', 'qty_on_hand' => 5, 'reorder_level' => 9, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        // the same code may exist in two schools
        $this->postJson('/api/maintenance/assets', ['asset_code' => 'F-1', 'name' => 'ของเรา'])->assertCreated();
        $this->postJson('/api/maintenance/stock', ['code' => 'F', 'name' => 'ของเรา', 'unit' => 'u'])->assertCreated();
        $this->getJson('/api/maintenance/assets/'.$fa)->assertNotFound();
        $this->putJson('/api/maintenance/assets/'.$fa, ['version' => 1, 'name' => 'x'])->assertNotFound();
        $this->getJson('/api/maintenance/requests/'.$fr)->assertNotFound();
        $this->postJson('/api/maintenance/requests/'.$fr.'/transition', ['to' => 'accepted', 'version' => 1])->assertNotFound();
        $this->getJson('/api/maintenance/stock/'.$fi.'/moves')->assertNotFound();
        $this->postJson('/api/maintenance/stock/'.$fi.'/moves', ['kind' => 'in', 'qty' => 1])->assertNotFound();
        $this->assertSame(5, DB::table('mnt_stock_items')->find($fi)->qty_on_hand);
        $this->postJson('/api/maintenance/requests', ['title' => 'ครุภัณฑ์ข้ามโรงเรียน', 'location' => 'x', 'asset_id' => $fa])->assertUnprocessable();
        $this->assertSame(0, $this->getJson('/api/maintenance/requests?scope=all')->json('total'));
        $this->assertSame([], $this->getJson('/api/maintenance/reports/stock-below')->json('data'));
        $foreign = $this->addUser('admin', 'fadmin', 2);
        // a technician id from another school is rejected
        $this->as('admin');
        $this->putJson('/api/maintenance/technicians', ['version' => 0, 'technician_user_ids' => [$foreign->id]])->assertUnprocessable();
    }

    public function test_permissions_export_labels_and_technician_setting(): void
    {
        $teacher = $this->addUser('teacher', 'plain'); $tech = $this->addUser('teacher', 'tech');
        $this->as('admin');
        $this->postJson('/api/maintenance/assets', ['asset_code' => '=SUM(1+1)', 'name' => '@cmd formula', 'location' => 'อาคาร 2', 'category' => 'เฟอร์นิเจอร์'])->assertCreated();
        $this->postJson('/api/maintenance/assets', ['asset_code' => 'CH-002', 'name' => 'เก้าอี้ครู', 'location' => 'ห้องพักครู'])->assertCreated();
        $this->actingAs($teacher);
        foreach (['/api/maintenance/assets', '/api/maintenance/stock', '/api/maintenance/reports/open-by-age', '/api/maintenance/export/assets', '/maintenance/labels', '/api/maintenance/technicians'] as $u) $this->get($u, ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson('/api/maintenance/assets', ['asset_code' => 'x', 'name' => 'y'])->assertForbidden();
        $this->postJson('/api/maintenance/stock', ['code' => 'x', 'name' => 'y', 'unit' => 'z'])->assertForbidden();
        $this->putJson('/api/maintenance/technicians', ['version' => 0, 'technician_user_ids' => [$teacher->id]])->assertForbidden();
        $this->assertFalse($this->getJson('/api/maintenance/meta')->json('is_tech'));
        $this->makeTech($tech);
        $this->actingAs($tech);
        $this->assertTrue($this->getJson('/api/maintenance/meta')->json('is_tech'));
        $this->getJson('/api/maintenance/assets')->assertOk();
        $csv = $this->get('/api/maintenance/export/assets')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=SUM(1+1)", $csv); $this->assertStringContainsString("'@cmd formula", $csv);
        $this->assertStringNotContainsString("\n=SUM", $csv);
        $this->get('/api/maintenance/export/nothing')->assertNotFound();
        foreach (['requests', 'stock', 'moves'] as $w) $this->get('/api/maintenance/export/'.$w)->assertOk();
        $html = $this->get('/maintenance/labels')->assertOk()->assertSee('CH-002')->assertSee('เก้าอี้ครู')->assertSee('ห้องพักครู')->getContent();
        $this->assertStringNotContainsString('qrserver', $html); $this->assertStringNotContainsString('chart.googleapis', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $ids = DB::table('mnt_assets')->where('asset_code', 'CH-002')->value('id');
        $this->assertStringNotContainsString('=SUM', $this->get('/maintenance/labels?ids='.$ids)->assertOk()->getContent());
        // revoke: technician list replaced, old technician loses access
        $this->makeTech($teacher);
        $this->actingAs($tech)->getJson('/api/maintenance/assets')->assertForbidden();
        $this->as('admin');
        $this->assertSame([$teacher->id], $this->getJson('/api/maintenance/technicians')->json('technician_user_ids'));
        $this->assertContains('maintenance.export', DB::table('audit_events')->pluck('action')->all());
    }

    public function test_assignment_and_request_edit_rules(): void
    {
        $owner = $this->addUser('teacher', 'owner'); $tech = $this->addUser('teacher', 'tech'); $plain = $this->addUser('teacher', 'plain');
        $this->makeTech($tech);
        $r = $this->actingAs($owner)->report();
        $e = $this->putJson('/api/maintenance/requests/'.$r['id'], ['version' => $r['version'], 'title' => 'ก๊อกน้ำรั่วหนักมาก'])->assertOk()->json();
        $this->assertSame('ก๊อกน้ำรั่วหนักมาก', $e['title']);
        $this->postJson('/api/maintenance/requests/'.$r['id'].'/assign', ['version' => $e['version'], 'user_id' => $tech->id])->assertForbidden();
        $this->actingAs($tech);
        $this->postJson('/api/maintenance/requests/'.$r['id'].'/assign', ['version' => $e['version'], 'user_id' => $plain->id])->assertUnprocessable();
        $a = $this->postJson('/api/maintenance/requests/'.$r['id'].'/assign', ['version' => $e['version'], 'user_id' => $tech->id])->assertOk()->json();
        $this->assertSame($tech->id, $a['assigned_to']);
        $x = $this->go($r['id'], 'accepted', $a['version'])->json();
        // reporter can no longer edit once accepted
        $this->actingAs($owner)->putJson('/api/maintenance/requests/'.$r['id'], ['version' => $x['version'], 'title' => 'แก้ทีหลัง'])->assertUnprocessable();
        $this->actingAs($tech);
        $d = $this->go($r['id'], 'done', $x['version'], ['resolution' => 'เสร็จ'])->json();
        $this->postJson('/api/maintenance/requests/'.$r['id'].'/assign', ['version' => $d['version'], 'user_id' => $tech->id])->assertUnprocessable();
        $this->assertSame(1, $this->getJson('/api/maintenance/requests?scope=all&status=closed')->json('total'));
        $this->assertSame(0, $this->getJson('/api/maintenance/requests?scope=all&status=open')->json('total'));
        $this->postJson('/api/maintenance/requests', ['title' => 'x', 'location' => 'a'])->assertUnprocessable(); // title min 2
        $this->postJson('/api/maintenance/requests', ['title' => 'ไม่มีสถานที่'])->assertUnprocessable();
    }

    // ---- review round fixes ----------------------------------------------------------------------------------------

    public function test_unique_violation_after_the_code_check_is_a_409_not_a_500(): void
    {
        $this->as('admin');
        $once = false;
        DB::beforeExecuting(function ($sql) use (&$once) {
            if (!$once && str_starts_with(str_replace(['"', '`'], '', $sql), 'insert into mnt_assets')) {
                $once = true; // a concurrent request wins the race right after our code check passed
                DB::table('mnt_assets')->insert(['school_id' => 1, 'asset_code' => 'RACE', 'asset_code_key' => 'race', 'name' => 'x', 'status' => 'in_use', 'version' => 1]);
            }
        });
        $this->postJson('/api/maintenance/assets', ['asset_code' => 'race', 'name' => 'y'])->assertStatus(409);
        $this->assertTrue($once);
        // (the simulated competitor row lives on the same connection, so it rolls back with the failed transaction)
        $once2 = false;
        DB::beforeExecuting(function ($sql) use (&$once2) {
            if (!$once2 && str_starts_with(str_replace(['"', '`'], '', $sql), 'insert into mnt_stock_items')) {
                $once2 = true;
                DB::table('mnt_stock_items')->insert(['school_id' => 1, 'code' => 'RACE', 'code_key' => 'race', 'name' => 'x', 'unit' => 'u', 'qty_on_hand' => 0, 'reorder_level' => 0, 'version' => 1]);
            }
        });
        $this->postJson('/api/maintenance/stock', ['code' => 'race', 'name' => 'y', 'unit' => 'u'])->assertStatus(409);
        $this->assertTrue($once2);
        $this->assertSame(0, DB::table('mnt_stock_moves')->count());
    }

    public function test_technician_list_uses_version_and_teachers_endpoint_is_for_technicians(): void
    {
        $t = $this->addUser('teacher', 'tx'); $u = $this->addUser('teacher', 'ty');
        $this->as('admin');
        $cur = $this->getJson('/api/maintenance/technicians')->assertOk()->json();
        $this->assertSame(0, $cur['version']); $this->assertSame(12, $cur['photo_retention_months']);
        $this->putJson('/api/maintenance/technicians', ['technician_user_ids' => [$t->id]])->assertUnprocessable(); // version required
        $a = $this->putJson('/api/maintenance/technicians', ['version' => 0, 'technician_user_ids' => [$t->id], 'photo_retention_months' => 6])->assertOk()->json();
        $this->assertSame(1, $a['version']); $this->assertSame(6, $a['photo_retention_months']);
        $res = $this->putJson('/api/maintenance/technicians', ['version' => 0, 'technician_user_ids' => [$u->id]])->assertStatus(409);
        $this->assertSame([$t->id], $res->json('current.technician_user_ids'));
        $this->assertSame([$t->id], $this->getJson('/api/maintenance/technicians')->json('technician_user_ids'));
        $this->putJson('/api/maintenance/technicians', ['version' => 1, 'technician_user_ids' => [$t->id, $u->id]])->assertOk();
        $teacherRow = DB::table('teachers')->where('school_id', 1)->first();
        $names = array_column($this->getJson('/api/maintenance/teachers')->assertOk()->json('data'), 'name');
        $this->assertContains($teacherRow->name, $names);
        $this->actingAs($this->addUser('teacher', 'plain2'))->getJson('/api/maintenance/teachers')->assertForbidden();
        // the picker value is stored and can be cleared
        $this->as('admin');
        $as = $this->postJson('/api/maintenance/assets', ['asset_code' => 'T-1', 'name' => 'x', 'responsible_teacher_id' => $teacherRow->id])->assertCreated()->json();
        $this->assertSame($teacherRow->name, $as['responsible_name']);
        $this->putJson('/api/maintenance/assets/'.$as['id'], ['version' => $as['version'], 'responsible_teacher_id' => null])->assertOk()->assertJsonPath('responsible_teacher_id', null);
    }

    public function test_asset_status_sync_is_audited_keeps_manual_repair_and_disposal_needs_closed_requests(): void
    {
        $this->as('admin');
        $a = $this->postJson('/api/maintenance/assets', ['asset_code' => 'S-1', 'name' => 'เครื่องพิมพ์'])->json();
        $m = $this->postJson('/api/maintenance/assets', ['asset_code' => 'S-2', 'name' => 'ส่งซ่อมร้านนอก'])->json();
        // a person puts S-2 in repair (no request); a request that opens and closes on it must not flip it back
        $this->putJson('/api/maintenance/assets/'.$m['id'], ['version' => $m['version'], 'status' => 'repair'])->assertOk();
        $r2 = $this->report(['asset_id' => $m['id']]);
        $x2 = $this->go($r2['id'], 'accepted', $r2['version'])->json();
        $this->go($r2['id'], 'cancelled', $x2['version'])->assertOk();
        $this->assertSame('repair', DB::table('mnt_assets')->find($m['id'])->status);
        // an auto "repair" returns to in_use and both flips are audited
        $r = $this->report(['asset_id' => $a['id']]);
        $x = $this->go($r['id'], 'accepted', $r['version'])->json();
        $this->assertSame('repair', DB::table('mnt_assets')->find($a['id'])->status);
        $cur = $this->getJson('/api/maintenance/assets/'.$a['id'])->json();
        $this->putJson('/api/maintenance/assets/'.$a['id'], ['version' => $cur['version'], 'status' => 'disposed'])->assertUnprocessable()->assertJsonPath('message', 'ยังมีงานแจ้งซ่อมค้างอยู่ ปิดงานก่อนจำหน่าย');
        $this->assertSame('repair', DB::table('mnt_assets')->find($a['id'])->status);
        $this->go($r['id'], 'done', $x['version'], ['resolution' => 'เปลี่ยนตลับหมึก'])->assertOk();
        $this->assertSame('in_use', DB::table('mnt_assets')->find($a['id'])->status);
        $sync = DB::table('audit_events')->where('action', 'maintenance.asset.status_sync')->where('entity_id', $a['id'])->orderBy('id')->get();
        $this->assertSame(['repair', 'in_use'], $sync->map(fn ($e) => json_decode($e->detail, true)['to'])->all());
        $cur = $this->getJson('/api/maintenance/assets/'.$a['id'])->json();
        $this->putJson('/api/maintenance/assets/'.$a['id'], ['version' => $cur['version'], 'status' => 'disposed'])->assertOk();
    }

    public function test_editing_an_auto_repair_asset_with_the_unchanged_status_keeps_the_request_sync(): void
    {
        $this->as('admin');
        $a = $this->postJson('/api/maintenance/assets', ['asset_code' => 'S-9', 'name' => 'พัดลม'])->json();
        $r = $this->report(['asset_id' => $a['id']]);
        $x = $this->go($r['id'], 'accepted', $r['version'])->json();
        $cur = $this->getJson('/api/maintenance/assets/'.$a['id'])->json();
        // the UI always resends the unchanged status when only the name is edited
        $this->putJson('/api/maintenance/assets/'.$a['id'], ['version' => $cur['version'], 'name' => 'พัดลมเพดาน', 'status' => $cur['status']])->assertOk();
        $row = DB::table('mnt_assets')->find($a['id']);
        $this->assertSame('repair', $row->status);
        $this->assertSame(1, (int) $row->status_auto);
        $this->go($r['id'], 'done', $x['version'], ['resolution' => 'ซ่อมแล้ว'])->assertOk();
        $this->assertSame('in_use', DB::table('mnt_assets')->find($a['id'])->status);
    }

    public function test_stock_balance_cannot_overflow(): void
    {
        $this->as('admin');
        $it = $this->postJson('/api/maintenance/stock', ['code' => 'BIG', 'name' => 'big', 'unit' => 'u'])->json();
        DB::table('mnt_stock_items')->where('id', $it['id'])->update(['qty_on_hand' => Maintenance::MAX_QTY - 5]);
        $url = '/api/maintenance/stock/'.$it['id'].'/moves';
        $this->postJson($url, ['kind' => 'in', 'qty' => 6])->assertUnprocessable();
        $this->postJson($url, ['kind' => 'adjust', 'qty' => 6])->assertUnprocessable();
        $this->assertSame(Maintenance::MAX_QTY - 5, DB::table('mnt_stock_items')->find($it['id'])->qty_on_hand);
        $this->postJson($url, ['kind' => 'in', 'qty' => 5])->assertCreated()->assertJsonPath('qty_on_hand', Maintenance::MAX_QTY);
        $this->assertSame(1, DB::table('mnt_stock_moves')->where('item_id', $it['id'])->count());
    }

    public function test_plain_text_mail_is_not_html_escaped_and_labels_use_buddhist_year(): void
    {
        $u = $this->addUser('teacher', 'm1');
        $mail = (new MntNotification('done', 'x', ['เรื่อง: ก๊อก & "วาล์ว" <ใหม่> \'ดี\''], 'repairs/1'))->toMail($u);
        $text = view($mail->view[1], $mail->viewData)->render();
        $this->assertStringContainsString('เรื่อง: ก๊อก & "วาล์ว" <ใหม่> \'ดี\'', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $html = view($mail->view[0], $mail->viewData)->render();
        $this->assertStringContainsString('ก๊อก &amp; &quot;วาล์ว&quot; &lt;ใหม่&gt;', $html);
        $this->assertSame('6 พ.ย. 2569', Maintenance::thaiDate(\Carbon\Carbon::create(2026, 11, 6)));
        $this->as('admin');
        $this->postJson('/api/maintenance/assets', ['asset_code' => 'L-1', 'name' => 'ป้าย'])->assertCreated();
        $this->get('/maintenance/labels')->assertOk()->assertSee((string) (now('Asia/Bangkok')->year + 543));
    }

    private function jpegWithExif(int $w, int $h, string $marker): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h); ob_start(); imagejpeg($img); $bin = ob_get_clean();
        $payload = "Exif\0\0".$marker;
        $bin = substr($bin, 0, 2)."\xFF\xE1".pack('n', 2 + strlen($payload)).$payload.substr($bin, 2);
        return UploadedFile::fake()->createWithContent('gps.jpg', $bin);
    }

    public function test_photo_is_reencoded_without_metadata_and_downscaled(): void
    {
        $this->actingAs($this->addUser('teacher', 'ph'));
        $src = $this->jpegWithExif(3000, 200, 'GPSLAT-13.7563-MARKER');
        $this->assertStringContainsString('GPSLAT-13.7563-MARKER', $src->getContent());
        $r = $this->report([], $src);
        $row = DB::table('mnt_requests')->find($r['id']);
        $stored = Storage::disk('local')->get($row->photo_path);
        $this->assertStringNotContainsString('GPSLAT-13.7563-MARKER', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        [$w, $h, $type] = getimagesizefromstring($stored);
        $this->assertSame(2000, $w); $this->assertSame(133, $h); $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame(strlen($stored), (int) $row->photo_size); // quota counts the stored bytes
        $this->assertSame('gps.jpg', $row->photo_name);
        // a small PNG keeps its type and size
        $r2 = $this->report(['title' => 'png ok'], $this->png());
        $s2 = Storage::disk('local')->get(DB::table('mnt_requests')->find($r2['id'])->photo_path);
        $this->assertSame([8, 8, IMAGETYPE_PNG], array_slice(getimagesizefromstring($s2), 0, 3));
    }

    public function test_photo_too_big_for_the_remaining_memory_is_a_422_not_a_fatal_error(): void
    {
        $this->actingAs($this->addUser('teacher', 'mem'));
        $src = $this->jpegWithExif(3000, 2000, 'M');
                Maintenance::$memoryLeftOverride = 20 * 1048576; // a 3000x2000 truecolor bitmap needs ~30 MB
        try {
            $this->post('/api/maintenance/requests', ['title' => 'big', 'location' => 'ห้อง', 'photo' => $src], ['Accept' => 'application/json'])->assertUnprocessable();
            Maintenance::$memoryLeftOverride = 64 * 1048576;
            $this->post('/api/maintenance/requests', ['title' => 'fits', 'location' => 'ห้อง', 'photo' => $this->jpegWithExif(3000, 2000, 'M')], ['Accept' => 'application/json'])->assertCreated();
        } finally {
            Maintenance::$memoryLeftOverride = null;
        }
    }

public function test_open_by_age_totals_are_exact_beyond_the_listed_rows(): void
    {
        $this->as('admin');
        $now = now();
        $rows = [];
        for ($i = 0; $i < 230; $i++) $rows[] = ['school_id' => 1, 'location' => 'ห้อง', 'title' => 'งาน '.$i, 'reported_by' => 1, 'status' => 'new', 'version' => 1,
            'created_at' => $now->copy()->subDays($i % 40), 'updated_at' => $now];
        DB::table('mnt_requests')->insert($rows);
        $rep = $this->getJson('/api/maintenance/reports/open-by-age')->assertOk()->json();
        $this->assertSame(230, $rep['total']); $this->assertSame(230, array_sum($rep['buckets'])); $this->assertCount(200, $rep['data']);
        $this->assertGreaterThanOrEqual($rep['data'][199]['age_days'], $rep['data'][0]['age_days']);
        $this->assertSame(0, array_sum(array_map(fn ($r) => $r['age_days'] < 15 ? 1 : 0, array_slice($rep['data'], 0, 100)))); // oldest first
    }

    public function test_prune_command_removes_photos_of_old_closed_requests_only(): void
    {
        $disk = Storage::disk('local');
        $mk = function (string $status, ?string $closed, string $name) use ($disk) {
            $path = 'maintenance/1/requests/'.$name.'.png'; $disk->put($path, 'x');
            return DB::table('mnt_requests')->insertGetId(['school_id' => 1, 'location' => 'x', 'title' => $name, 'reported_by' => 1, 'status' => $status, 'closed_at' => $closed,
                'photo_path' => $path, 'photo_name' => $name.'.png', 'photo_mime' => 'image/png', 'photo_size' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        };
        $old = $mk('done', now()->subMonths(14)->toDateTimeString(), 'old'); $recent = $mk('done', now()->subMonths(2)->toDateTimeString(), 'recent');
        $open = $mk('in_progress', null, 'open'); $oldReject = $mk('rejected', now()->subMonths(20)->toDateTimeString(), 'oldreject');
        $this->artisan('saan:maintenance-prune --dry-run')->assertSuccessful();
        $this->assertNotNull(DB::table('mnt_requests')->find($old)->photo_path);
        $this->artisan('saan:maintenance-prune')->assertSuccessful();
        foreach ([$old, $oldReject] as $id) {
            $r = DB::table('mnt_requests')->find($id);
            $this->assertNull($r->photo_path); $this->assertNull($r->photo_size); $this->assertSame(2, (int) $r->version);
        }
        $this->assertFalse($disk->exists('maintenance/1/requests/old.png')); $this->assertFalse($disk->exists('maintenance/1/requests/oldreject.png'));
        $this->assertTrue($disk->exists('maintenance/1/requests/recent.png')); $this->assertTrue($disk->exists('maintenance/1/requests/open.png'));
        $this->assertNotNull(DB::table('mnt_requests')->find($recent)->photo_path); $this->assertNotNull(DB::table('mnt_requests')->find($open)->photo_path);
        // --months overrides the setting; 0 disables
        $this->artisan('saan:maintenance-prune --months=0')->assertSuccessful();
        $this->assertTrue($disk->exists('maintenance/1/requests/recent.png'));
        $this->artisan('saan:maintenance-prune --months=1')->assertSuccessful();
        $this->assertFalse($disk->exists('maintenance/1/requests/recent.png')); $this->assertTrue($disk->exists('maintenance/1/requests/open.png'));
    }
}