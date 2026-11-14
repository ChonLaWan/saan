<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Documents\Documents;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DocumentsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        // own disk root: other test classes call Storage::fake('local') and would wipe a shared directory mid-test
        $this->diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saan-documents-'.bin2hex(random_bytes(6));
        mkdir($this->diskRoot, 0700, true);
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::purge('local');
    }

    private string $diskRoot = '';

    protected function tearDown(): void
    {
        Documents::$afterRead = null;
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

    private function body(array $over = []): array
    {
        return array_merge(['kind' => 'incoming', 'doc_no' => 'ศธ 04001/123', 'doc_date' => Documents::today(), 'from_org' => 'สำนักงานเขตพื้นที่การศึกษาสมมติ', 'subject' => 'ขอเชิญประชุมทดสอบ'], $over);
    }

    private function doc(array $over = []): array { return $this->postJson('/api/documents', $this->body($over))->assertCreated()->json(); }

    private function pdf(string $name = 'letter.pdf', int $pad = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% documents marker\n".str_repeat('a', $pad)."\n%%EOF");
    }

        /** PUT the clerk list with the current version of the setting (0 while it was never saved). */
    private function putClerks(array $ids, ?int $version = null)
    {
        $version ??= (int) DB::table('saan_settings')->where('key', 'documents.clerk_user_ids')->value('version');
        return $this->putJson('/api/documents/clerks', ['version' => $version, 'clerk_user_ids' => $ids]);
    }

public function test_numbering_runs_per_book_and_year(): void
    {
        $this->as('admin');
        $a = $this->doc(); $b = $this->doc(['subject' => 'เรื่องที่สอง']);
        $yearBe = (int) date('Y') + 543;
        $this->assertSame([1, 2], [$a['reg_no'], $b['reg_no']]);
        $this->assertSame('1/'.$a['year_be'], $a['reg_label']);
        $this->assertSame('incoming', $a['kind']);
        $out = $this->doc(['kind' => 'outgoing', 'from_org' => null, 'to_org' => 'ผู้ปกครองสมมติ', 'subject' => 'แจ้งผล']);
        $this->assertSame(1, $out['reg_no']); // another book restarts
        $old = $this->doc(['registered_on' => now('Asia/Bangkok')->subYears(2)->toDateString()]);
        $this->assertSame(1, $old['reg_no']);
        $this->assertSame($a['year_be'] - 2, $old['year_be']); // another Buddhist year restarts
        $this->assertSame(3, $this->doc()['reg_no']);
        $this->assertSame(3, DB::table('doc_books')->where('school_id', 1)->count());
        $this->postJson('/api/documents', $this->body(['from_org' => '']))->assertUnprocessable();
        $this->postJson('/api/documents', $this->body(['kind' => 'outgoing', 'from_org' => null]))->assertUnprocessable();
        $this->postJson('/api/documents', $this->body(['registered_on' => now('Asia/Bangkok')->addDay()->toDateString()]))->assertUnprocessable();
        $this->postJson('/api/documents', $this->body(['kind' => 'bogus']))->assertUnprocessable();
        $this->assertGreaterThan(2500, $yearBe);
    }

    public function test_concurrent_registrars_never_share_a_number(): void
    {
        $this->as('admin');
        $this->doc(); // reg 1, next_no = 2
        $svc = app(Documents::class);
        // another registrar claims 2 after this request read next_no = 2 but before it claimed it
        Documents::$afterRead = fn () => $svc->register($this->body(['subject' => 'จากผู้ลงทะเบียนอีกคน']));
        $mine = $this->doc(['subject' => 'ของฉัน']);
        $nos = DB::table('doc_documents')->orderBy('reg_no')->pluck('reg_no')->all();
        $this->assertSame([1, 2, 3], $nos);
        $this->assertSame(3, $mine['reg_no']);
        $this->assertSame(4, (int) DB::table('doc_books')->value('next_no'));
        // the unique index is the backstop and a collision becomes 409, never 500
        $row = (array) DB::table('doc_documents')->first();
        unset($row['id']);
        try { DB::table('doc_documents')->insert($row); $this->fail('duplicate reg_no was accepted'); } catch (QueryException) { $this->assertTrue(true); }
        try { Documents::guard(fn () => DB::table('doc_documents')->insert($row)); $this->fail('no 409'); } catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
    }

    public function test_cancel_keeps_the_number_and_locks_the_document(): void
    {
        $this->as('admin');
        $a = $this->doc();
        $this->postJson('/api/documents/'.$a['id'].'/cancel', ['version' => $a['version']])->assertUnprocessable();
        $c = $this->postJson('/api/documents/'.$a['id'].'/cancel', ['version' => $a['version'], 'reason' => 'ลงทะเบียนซ้ำ'])->assertOk()->json();
        $this->assertSame('cancelled', $c['status']);
        $this->assertSame(1, $c['reg_no']);
        $this->postJson('/api/documents/'.$a['id'].'/cancel', ['version' => $c['version'], 'reason' => 'อีกครั้ง'])->assertUnprocessable();
        $this->putJson('/api/documents/'.$a['id'], ['version' => $c['version'], 'subject' => 'แก้'])->assertUnprocessable();
        $this->postJson('/api/documents/'.$a['id'].'/routes', ['user_ids' => [User::where('role', 'teacher')->value('id')]])->assertUnprocessable();
        $this->assertSame(2, $this->doc()['reg_no']); // the cancelled number 1 is never reused
        $this->assertSame(1, DB::table('doc_documents')->where('reg_no', 1)->where('status', 'cancelled')->count());
        $this->getJson('/api/documents?status=cancelled')->assertOk()->assertJsonPath('total', 1);
        // edit with optimistic version
        $b = $this->getJson('/api/documents/2')->json();
        $this->putJson('/api/documents/2', ['version' => $b['version'], 'subject' => 'แก้ไขแล้ว'])->assertOk()->assertJsonPath('subject', 'แก้ไขแล้ว');
        $this->putJson('/api/documents/2', ['version' => $b['version'], 'subject' => 'stale'])->assertConflict();
        $this->assertTrue(DB::table('audit_events')->where('action', 'documents.cancelled')->exists());
    }

    public function test_confidential_visibility(): void
    {
        $this->as('admin');
        $secret = $this->doc(['secrecy' => 'confidential', 'subject' => 'เรื่องลับทดสอบ']);
        $open = $this->doc(['subject' => 'เรื่องทั่วไป']);
        $clerk = $this->addUser('teacher', 'clerk'); $reader = $this->addUser('teacher', 'reader'); $other = $this->addUser('teacher', 'other');
        $this->putClerks([$clerk->id])->assertOk();
        $this->as('principal')->getJson('/api/documents/'.$secret['id'])->assertOk();
        // clerk: sees normal documents, never the confidential one, cannot create one
        $this->actingAs($clerk);
        $this->getJson('/api/documents/'.$open['id'])->assertOk();
        $this->getJson('/api/documents/'.$secret['id'])->assertNotFound();
        $this->getJson('/api/documents')->assertOk()->assertJsonPath('total', 1)->assertJsonMissing(['subject' => 'เรื่องลับทดสอบ']);
        $this->getJson('/api/documents?q='.rawurlencode('ลับ'))->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/documents', $this->body(['secrecy' => 'confidential']))->assertForbidden();
        $this->putJson('/api/documents/'.$open['id'], ['version' => $open['version'], 'secrecy' => 'confidential'])->assertForbidden();
        // routed recipient (not a clerk) reads it; an unrouted staff member cannot
        $this->actingAs(User::where('role', 'admin')->first());
        $this->postJson('/api/documents/'.$secret['id'].'/routes', ['user_ids' => [$reader->id]])->assertOk();
        $this->actingAs($reader);
        $this->getJson('/api/documents/'.$secret['id'])->assertOk()->assertJsonPath('subject', 'เรื่องลับทดสอบ');
        $this->getJson('/api/documents/'.$open['id'])->assertNotFound(); // not routed, not a clerk
        $this->actingAs($other);
        $this->getJson('/api/documents/'.$secret['id'])->assertNotFound();
        $this->getJson('/api/documents/inbox')->assertOk()->assertJsonPath('total', 0);
        // once routed to the clerk, the clerk can read it too
        $this->actingAs(User::where('role', 'admin')->first());
        $this->postJson('/api/documents/'.$secret['id'].'/routes', ['user_ids' => [$clerk->id]])->assertOk();
        $this->actingAs($clerk)->getJson('/api/documents/'.$secret['id'])->assertOk();
    }

    public function test_routing_inbox_and_acknowledge(): void
    {
        $this->as('admin');
        $a = $this->addUser('teacher', 'ta'); $b = $this->addUser('editor', 'eb'); $foreign = $this->addUser('teacher', 'fx', 2);
        $d = $this->doc();
        $this->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => [$a->id, 9999]])->assertUnprocessable();
        $this->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => [$foreign->id]])->assertUnprocessable();
        $this->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => []])->assertUnprocessable();
        $r = $this->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => [$a->id, $b->id], 'note' => 'โปรดทราบและแจ้งครู'])->assertOk()->json();
        $this->assertSame('routed', $r['status']);
        $this->assertCount(2, $r['routes']);
        $again = $this->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => [$a->id]])->assertOk()->json();
        $this->assertCount(2, $again['routes']); // no duplicate route
        $this->assertSame(2, DB::table('doc_routes')->count());

        $this->actingAs($a);
        $inbox = $this->getJson('/api/documents/inbox')->assertOk()->json();
        $this->assertSame(1, $inbox['unread']);
        $this->assertSame('โปรดทราบและแจ้งครู', $inbox['data'][0]['note']);
        $this->assertNull($inbox['data'][0]['read_at']);
        $this->assertSame(1, $this->getJson('/api/documents/meta')->json('unread'));
        $shown = $this->getJson('/api/documents/'.$d['id'])->assertOk()->json();
        $this->assertCount(1, $shown['routes']); // a recipient sees only their own route
        $this->assertNotNull($shown['routes'][0]['read_at']);
        $this->assertSame(0, $this->getJson('/api/documents/inbox')->json('unread'));
        $this->getJson('/api/documents/inbox?state=pending')->assertJsonPath('total', 1);
        $rid = $shown['routes'][0]['id'];
        $this->postJson('/api/documents/inbox/'.$rid.'/ack', ['note' => 'รับทราบ จะแจ้งต่อ'])->assertOk()->assertJsonPath('routes.0.action_note', 'รับทราบ จะแจ้งต่อ');
        $first = DB::table('doc_routes')->where('id', $rid)->value('acknowledged_at');
        $this->assertNotNull($first);
        $this->postJson('/api/documents/inbox/'.$rid.'/ack', ['note' => 'อีกครั้ง'])->assertOk(); // idempotent: first acknowledgement kept
        $this->assertSame('รับทราบ จะแจ้งต่อ', DB::table('doc_routes')->where('id', $rid)->value('action_note'));
        $this->getJson('/api/documents/inbox?state=pending')->assertJsonPath('total', 0);
        // someone else's route is invisible
        $this->actingAs($b);
        $other = DB::table('doc_routes')->where('to_user_id', $a->id)->value('id');
        $this->postJson('/api/documents/inbox/'.$other.'/ack', [])->assertNotFound();
        $this->getJson('/api/documents')->assertForbidden(); // editor is not a clerk
        // withdraw: pending route yes, acknowledged route no
        $this->as('admin');
        $full = $this->getJson('/api/documents/'.$d['id'])->json();
        $mine = collect($full['routes'])->firstWhere('user_id', $a->id);
        $pending = collect($full['routes'])->firstWhere('user_id', $b->id);
        $this->deleteJson('/api/documents/'.$d['id'].'/routes/'.$mine['id'], ['version' => $mine['version'] + 1])->assertUnprocessable();
        $left = $this->deleteJson('/api/documents/'.$d['id'].'/routes/'.$pending['id'], ['version' => $pending['version']])->assertOk()->json();
        $this->assertCount(1, $left['routes']);
        $done = $this->postJson('/api/documents/'.$d['id'].'/done', ['version' => $left['version'], 'done' => true])->assertOk()->json();
        $this->assertSame('done', $done['status']);
        $this->assertSame('routed', $this->postJson('/api/documents/'.$d['id'].'/done', ['version' => $done['version'], 'done' => false])->json('status'));
        $this->assertTrue(DB::table('audit_events')->where('action', 'documents.acknowledged')->exists());
    }

    public function test_files_are_private_quota_checked_and_downloads_audited(): void
    {
        $this->as('admin');
        $d = $this->doc(['secrecy' => 'confidential']);
        $reader = $this->addUser('teacher', 'rd'); $other = $this->addUser('teacher', 'ot');
        $up = $this->post('/api/documents/'.$d['id'].'/files', ['file' => $this->pdf('หนังสือ ฉบับจริง.pdf')], ['Accept' => 'application/json'])->assertCreated()->json();
        $f = $up['files'][0];
        $this->assertSame('หนังสือ ฉบับจริง.pdf', $f['original_name']);
        $row = DB::table('doc_files')->first();
        $this->assertMatchesRegularExpression('#^documents/1/'.$d['id'].'/[0-9a-f]{32}\.pdf$#', $row->path);
        $this->assertSame(64, strlen($row->sha256));
        Storage::disk('local')->assertExists($row->path);
        $this->assertStringNotContainsString('path', json_encode($up));
        $this->assertFileDoesNotExist(public_path($row->path));
        $this->post('/api/documents/'.$d['id'].'/files', ['file' => UploadedFile::fake()->createWithContent('x.pdf', "plain text, not a pdf")], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/documents/'.$d['id'].'/files', ['file' => UploadedFile::fake()->createWithContent('s.php', '<?php echo 1;')], ['Accept' => 'application/json'])->assertUnprocessable();

        $res = $this->get('/api/documents/files/'.$f['id'].'/download')->assertOk();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('.php', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString(rawurlencode('หนังสือ ฉบับจริง').'.pdf', (string) $res->headers->get('Content-Disposition')); // original name, extension from the sniffed type
        $this->assertSame(1, DB::table('audit_events')->where('action', 'documents.file.downloaded')->count());
        // not routed -> hidden, nothing audited; routed -> allowed and audited
        $this->actingAs($reader)->get('/api/documents/files/'.$f['id'].'/download')->assertNotFound();
        $this->actingAs(User::where('role', 'admin')->first())->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => [$reader->id]])->assertOk();
        $this->actingAs($reader)->get('/api/documents/files/'.$f['id'].'/download')->assertOk();
        $this->actingAs($other)->get('/api/documents/files/'.$f['id'].'/download')->assertNotFound();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'documents.file.downloaded')->count());
        $this->actingAs($reader)->deleteJson('/api/documents/files/'.$f['id'], ['version' => 1])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/documents/files/'.$f['id'].'/download')->assertUnauthorized();

        // quota shared with the media library
        $this->as('admin');
        config(['saan.storage.quota_mb' => 1, 'saan.storage.max_upload_mb' => 5]);
        // each file is below the 1 MB quota; only the running total of doc_files can make the second one fail
        $this->post('/api/documents/'.$d['id'].'/files', ['file' => $this->pdf('p1.pdf', 600000)], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/documents/'.$d['id'].'/files', ['file' => $this->pdf('p2.pdf', 600000)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(2, DB::table('doc_files')->count());
        // delete removes the stored file
        $this->deleteJson('/api/documents/files/'.$f['id'], ['version' => $f['version']])->assertOk()->assertJsonCount(1, 'files');
        Storage::disk('local')->assertMissing($row->path);
    }

    public function test_search_and_filters(): void
    {
        $this->as('admin');
        $this->doc(['subject' => 'โครงการอบรมครูสมมติ', 'from_org' => 'สพป.สมมติ เขต 1', 'doc_no' => 'ศธ 0001/55']);
        $this->doc(['subject' => 'ขอบคุณการสนับสนุน', 'from_org' => 'บริษัทสมมติ จำกัด', 'doc_no' => 'บส 9/2']);
        $this->doc(['kind' => 'outgoing', 'from_org' => null, 'to_org' => 'โรงเรียนเครือข่ายสมมติ', 'subject' => 'ส่งรายชื่อนักเรียน', 'urgency' => 'urgent']);
        $this->doc(['kind' => 'order', 'subject' => 'คำสั่งแต่งตั้งคณะกรรมการ', 'from_org' => null]);
        $this->doc(['subject' => 'หนังสือปีเก่า', 'registered_on' => now('Asia/Bangkok')->subYears(1)->toDateString()]);
        $q = fn (string $qs) => $this->getJson('/api/documents?'.$qs)->assertOk()->json();
        $this->assertSame(5, $q('')['total']);
        $this->assertSame(1, $q('q='.rawurlencode('อบรมครู'))['total']);
        $this->assertSame(1, $q('q='.rawurlencode('บริษัทสมมติ'))['total']);
        $this->assertSame(1, $q('q='.rawurlencode('ศธ 0001'))['total']);
        $this->assertSame(1, $q('q='.rawurlencode('เครือข่าย'))['total']); // to_org
        $this->assertSame(0, $q('q='.rawurlencode('%'))['total']); // LIKE wildcards are literal
        $this->assertSame(0, $q('q='.rawurlencode('_'))['total']);
        $this->assertSame(2, $q('kind=incoming&year_be='.((int) date('Y') + 543))['total']);
        $this->assertSame(1, $q('kind=incoming&year_be='.((int) date('Y') + 542))['total']);
        $this->assertSame(1, $q('urgency=urgent')['total']);
        $this->assertCount(5, $q('per_page=5&page=1')['data']);
        $this->assertCount(0, $q('per_page=5&page=2')['data']);
        $this->assertSame(1, $q('q=1&kind=outgoing')['total']); // reg_no 1 of the outgoing book
        $this->assertCount(2, $q('')['years']);
        $this->getJson('/api/documents?status=bogus')->assertUnprocessable();
    }

    public function test_print_view(): void
    {
        $this->as('admin');
        DB::table('cms_themes')->insert(['school_id' => 1, 'draft' => '{}', 'published' => json_encode(['school_name' => 'โรงเรียนทดสอบสมมติวิทยา']), 'version' => 1, 'published_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $first = $this->doc(['subject' => 'หนังสือเข้าเดือนนี้']);
        $teacher = $this->addUser('teacher', 'pr');
        $this->postJson('/api/documents/'.$first['id'].'/routes', ['user_ids' => [$teacher->id]])->assertOk();
        $secret = $this->doc(['secrecy' => 'confidential', 'subject' => 'ห้ามเห็นเนื้อหา', 'doc_no' => 'ลับ 1']);
        $gone = $this->doc(['subject' => 'ลงผิด']);
        $this->postJson('/api/documents/'.$gone['id'].'/cancel', ['version' => $gone['version'], 'reason' => 'ลงผิดเล่ม'])->assertOk();
        $yearBe = (int) date('Y') + 543;
        $month = (int) now('Asia/Bangkok')->format('n');
        $url = '/documents/print?kind=incoming&year_be='.$yearBe.'&month='.$month;
        $this->get($url)->assertOk()->assertSee('หนังสือเข้าเดือนนี้')->assertSee('ห้ามเห็นเนื้อหา')->assertSee('ยกเลิก: ลงผิดเล่ม')->assertSee('ทะเบียนหนังสือรับ')->assertSee('documents-print.css', false)
            ->assertSee('โรงเรียนทดสอบสมมติวิทยา')->assertSee('การปฏิบัติ')->assertSee('pr (สมมติ) (รับทราบ 0/1)', false)->assertSee('>ถึง<', false);
        $clerk = $this->addUser('teacher', 'pc');
        $this->putClerks([$clerk->id])->assertOk();
        $this->actingAs($clerk)->get($url)->assertOk()->assertSee('หนังสือเข้าเดือนนี้')->assertSee('หนังสือลับ')->assertDontSee('ห้ามเห็นเนื้อหา')->assertDontSee('ลับ 1')->assertSee('1/', false);
        $other = $month === 1 ? 2 : 1;
        $this->get('/documents/print?kind=incoming&year_be='.$yearBe.'&month='.$other)->assertOk()->assertSee('ไม่มีรายการในเดือนนี้');
        $this->get('/documents/print?kind=incoming&year_be='.$yearBe.'&month=13')->assertStatus(302);
        $css = file_get_contents(public_path('assets/modules/documents/documents-print.css'));
        $this->assertStringContainsString('A4 landscape', $css);
        $this->actingAs($this->addUser('teacher', 'np'))->get($url)->assertForbidden();
    }

    public function test_roles_clerk_setting_and_menu(): void
    {
        $t = $this->addUser('teacher', 'tt'); $e = $this->addUser('editor', 'ee');
        $this->actingAs($t);
        $this->getJson('/api/documents/meta')->assertOk()->assertJsonPath('is_clerk', false);
        $this->getJson('/api/documents')->assertForbidden();
        $this->postJson('/api/documents', $this->body())->assertForbidden();
        $this->getJson('/api/documents/recipients')->assertForbidden();
        $this->getJson('/api/documents/clerks')->assertForbidden();
        $this->putClerks([$t->id])->assertForbidden();
        $this->getJson('/api/documents/inbox')->assertOk();
        $this->actingAs($e)->getJson('/api/documents/inbox')->assertOk();
        $this->as('principal')->getJson('/api/documents/clerks')->assertForbidden(); // only the admin grants clerk rights
        $this->as('principal');
        $d = $this->doc(); // principal is a clerk by role
        $this->assertSame(1, $d['reg_no']);
        $this->as('admin');
        $this->putClerks([$t->id, 999999])->assertUnprocessable();
        $this->putClerks([$t->id])->assertOk()->assertJsonPath('clerk_user_ids.0', $t->id);
        $this->actingAs($t)->getJson('/api/documents/meta')->assertJsonPath('is_clerk', true);
        $this->doc(['subject' => 'โดยเจ้าหน้าที่ที่ได้รับสิทธิ์']);
        $this->getJson('/api/documents/recipients')->assertOk();
        $this->assertTrue(DB::table('audit_events')->where('action', 'documents.clerks.updated')->exists());
        // revoking takes effect at once
        // optimistic version: a save made on a stale copy (another admin saved first) is refused and changes nothing
        $stale = (int) DB::table('saan_settings')->where('key', 'documents.clerk_user_ids')->value('version');
        $this->as('admin')->putClerks([$t->id, $e->id])->assertOk();
        $this->putClerks([], $stale)->assertStatus(409);
        $this->assertSame([$t->id, $e->id], DB::table('saan_settings')->where('key', 'documents.clerk_user_ids')->exists() ? json_decode((string) DB::table('saan_settings')->where('key', 'documents.clerk_user_ids')->value('value'), true) : []);
        $this->putJson('/api/documents/clerks', ['clerk_user_ids' => []])->assertUnprocessable(); // the version is required
        $this->as('admin')->putClerks([])->assertOk();
        $this->actingAs($t)->getJson('/api/documents')->assertForbidden();
        // menu
        $this->assertContains('documents', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->actingAs($t)->get('/work')->assertOk()->assertSee('หนังสือเข้าถึงฉัน')->assertSee('ทะเบียนหนังสือ')->assertSee('assets/modules/documents/documents.js', false);
        $this->actingAs($e)->get('/work')->assertOk()->assertSee('หนังสือเข้าถึงฉัน')->assertDontSee('ทะเบียนหนังสือ');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/documents/meta')->assertUnauthorized();
        $this->assertSame('documents', json_decode(file_get_contents(base_path('modules/documents/module.json')), true)['private_storage'][0]);
    }

    public function test_school_scoping_and_idempotency(): void
    {
        $this->as('admin');
        $now = now();
        $book = DB::table('doc_books')->insertGetId(['school_id' => 2, 'kind' => 'incoming', 'label' => 'อีกโรงเรียน', 'year_be' => (int) date('Y') + 543, 'next_no' => 8, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreign = DB::table('doc_documents')->insertGetId(['school_id' => 2, 'book_id' => $book, 'reg_no' => 7, 'doc_date' => date('Y-m-d'), 'registered_on' => date('Y-m-d'), 'subject' => 'หนังสือโรงเรียนอื่น',
            'urgency' => 'normal', 'secrecy' => 'normal', 'status' => 'registered', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->getJson('/api/documents/'.$foreign)->assertNotFound();
        $this->putJson('/api/documents/'.$foreign, ['version' => 1, 'subject' => 'x'])->assertNotFound();
        $this->postJson('/api/documents/'.$foreign.'/cancel', ['version' => 1, 'reason' => 'xx'])->assertNotFound();
        $this->getJson('/api/documents')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/documents/books')->assertOk()->assertJsonCount(0, 'data');
        $mine = $this->doc();
        $this->assertSame(1, $mine['reg_no']); // school 2's counter is separate
        $this->assertSame(8, (int) DB::table('doc_books')->where('id', $book)->value('next_no'));
        // an idempotent retry does not register twice
        $h = ['Idempotency-Key' => 'doc-create-1'];
        $one = $this->postJson('/api/documents', $this->body(['subject' => 'ส่งซ้ำ']), $h)->assertCreated()->json();
        $two = $this->postJson('/api/documents', $this->body(['subject' => 'ส่งซ้ำ']), $h)->assertCreated()->json();
        $this->assertSame($one['id'], $two['id']);
        $this->assertSame(1, DB::table('doc_documents')->where('school_id', 1)->where('subject', 'ส่งซ้ำ')->count());
        // book label/prefix feed the registry label
        $b = DB::table('doc_books')->where('school_id', 1)->first();
        $this->putJson('/api/documents/books/'.$b->id, ['version' => $b->version - 1, 'prefix' => 'ที่ รับ'])->assertConflict(); // next_no bumps the version, so a stale one is refused
        $this->putJson('/api/documents/books/'.$b->id, ['version' => $b->version, 'prefix' => 'ที่ รับ'])->assertOk();
        $this->assertStringStartsWith('ที่ รับ 1/', $this->getJson('/api/documents/'.$mine['id'])->json('reg_label'));
        $this->putJson('/api/documents/books/'.$book, ['version' => 1, 'prefix' => 'x'])->assertNotFound();
    }

    public function test_clerk_recipient_cannot_manage_confidential_document(): void
    {
        $this->as('admin');
        $secret = $this->doc(['secrecy' => 'confidential']);
        $clerk = $this->addUser('teacher', 'cl'); $peer = $this->addUser('teacher', 'pe');
        $this->putClerks([$clerk->id])->assertOk();
        $this->postJson('/api/documents/'.$secret['id'].'/routes', ['user_ids' => [$clerk->id, $peer->id]])->assertOk();
        $this->post('/api/documents/'.$secret['id'].'/files', ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $detail = $this->getJson('/api/documents/'.$secret['id'])->json();
        $fid = $detail['files'][0]['id'];
        $this->actingAs($clerk);
        $mine = $this->getJson('/api/documents/'.$secret['id'])->assertOk()->json();
        $this->assertCount(1, $mine['routes']); // only the clerk's own route, not the other recipient
        $this->assertTrue($mine['routes'][0]['mine']);
        $this->assertFalse($mine['is_clerk']);
        $v = $mine['version'];
        $this->postJson('/api/documents/'.$secret['id'].'/cancel', ['version' => $v, 'reason' => 'ทดสอบสิทธิ์'])->assertForbidden();
        $this->postJson('/api/documents/'.$secret['id'].'/done', ['version' => $v, 'done' => true])->assertForbidden();
        $this->postJson('/api/documents/'.$secret['id'].'/routes', ['user_ids' => [$this->addUser('teacher', 'zz')->id]])->assertForbidden();
        $this->deleteJson('/api/documents/'.$secret['id'].'/routes/'.$detail['routes'][1]['id'], ['version' => 1])->assertForbidden();
        $this->post('/api/documents/'.$secret['id'].'/files', ['file' => $this->pdf('b.pdf')], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/documents/files/'.$fid, ['version' => 1])->assertForbidden();
        $this->assertSame(2, DB::table('doc_routes')->count());
        $this->assertSame(1, DB::table('doc_files')->count());
        $this->assertSame('routed', DB::table('doc_documents')->where('id', $secret['id'])->value('status'));
    }

    public function test_monthly_print_hides_other_recipients_of_a_confidential_document_from_a_routed_clerk(): void
    {
        $this->as('admin');
        $secret = $this->doc(['secrecy' => 'confidential', 'subject' => 'เรื่องลับที่ส่งถึงเสมียน']);
        $clerk = $this->addUser('teacher', 'cl2'); $peer = $this->addUser('teacher', 'pe2');
        $this->putClerks([$clerk->id])->assertOk();
        $this->postJson('/api/documents/'.$secret['id'].'/routes', ['user_ids' => [$clerk->id, $peer->id]])->assertOk();
        $url = '/documents/print?kind=incoming&year_be='.((int) date('Y') + 543).'&month='.(int) now('Asia/Bangkok')->format('n');
        $this->get($url)->assertOk()->assertSee('pe2 (สมมติ)', false); // a manager sees every recipient
        $this->actingAs($clerk)->get($url)->assertOk()->assertSee('เรื่องลับที่ส่งถึงเสมียน')->assertDontSee('pe2 (สมมติ)', false)->assertDontSee('รับทราบ 0/2', false);
    }

    public function test_stale_versions_and_route_race_return_409(): void
    {
        $this->as('admin');
        $a = $this->addUser('teacher', 'ra'); $b = $this->addUser('teacher', 'rb');
        $d = $this->doc();
        $this->post('/api/documents/'.$d['id'].'/files', ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $full = $this->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => [$a->id]])->assertOk()->json();
        $f = $full['files'][0]; $r = $full['routes'][0];
        $this->postJson('/api/documents/'.$d['id'].'/done', ['version' => $full['version'] - 1, 'done' => true])->assertConflict();
        $this->deleteJson('/api/documents/files/'.$f['id'], ['version' => $f['version'] + 5])->assertConflict();
        $this->deleteJson('/api/documents/'.$d['id'].'/routes/'.$r['id'], ['version' => $r['version'] + 5])->assertConflict();
        $this->postJson('/api/documents/'.$d['id'].'/cancel', ['version' => $full['version'] - 1, 'reason' => 'เวอร์ชันเก่า'])->assertConflict();
        $this->assertSame('routed', DB::table('doc_documents')->where('id', $d['id'])->value('status'));
        // another request inserts the same recipient between the lookup and the insert: unique index, converted to 409
        $armed = true;
        DB::beforeExecuting(function ($query) use (&$armed, $d, $b) {
            if ($armed && str_starts_with(strtolower(str_replace(['"', '`'], '', $query)), 'insert into doc_routes')) {
                $armed = false;
                DB::table('doc_routes')->insert(['school_id' => 1, 'document_id' => $d['id'], 'to_user_id' => $b->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => [$b->id]])->assertConflict();
    }

    public function test_backdating_is_manager_only_and_cancelled_documents_leave_the_inbox(): void
    {
        $this->as('admin');
        $clerk = $this->addUser('teacher', 'bd'); $reader = $this->addUser('teacher', 'rr');
        $this->putClerks([$clerk->id])->assertOk();
        $past = now('Asia/Bangkok')->subYears(1)->toDateString();
        $old = $this->doc(['registered_on' => $past]);
        $this->assertSame($past, $old['received_on']); // received date follows the registration date, never later than it
        $this->assertSame(true, json_decode(DB::table('audit_events')->where('action', 'documents.registered')->orderByDesc('id')->value('detail'), true)['backdated'] ?? null);
        $this->actingAs($clerk)->postJson('/api/documents', $this->body(['registered_on' => $past]))->assertUnprocessable();
        $this->postJson('/api/documents', $this->body(['registered_on' => Documents::today()]))->assertCreated();

        $this->as('admin');
        $d = $this->doc(['subject' => 'จะถูกยกเลิก']);
        $routed = $this->postJson('/api/documents/'.$d['id'].'/routes', ['user_ids' => [$reader->id]])->assertOk()->json();
        $this->actingAs($reader)->getJson('/api/documents/inbox?state=unread')->assertJsonPath('total', 1)->assertJsonPath('unread', 1);
        $rid = DB::table('doc_routes')->where('to_user_id', $reader->id)->value('id');
        $this->as('admin')->postJson('/api/documents/'.$d['id'].'/cancel', ['version' => $routed['version'], 'reason' => 'ลงผิด'])->assertOk();
        $this->actingAs($reader);
        $this->getJson('/api/documents/inbox?state=unread')->assertJsonPath('total', 0)->assertJsonPath('unread', 0);
        $this->getJson('/api/documents/inbox?state=pending')->assertJsonPath('total', 0);
        $this->getJson('/api/documents/inbox')->assertJsonPath('total', 1)->assertJsonPath('data.0.document.status', 'cancelled');
        $this->postJson('/api/documents/inbox/'.$rid.'/ack', [])->assertUnprocessable();
        $this->assertNull(DB::table('doc_routes')->where('id', $rid)->value('acknowledged_at'));
        // withdrawing a recipient of a cancelled document is refused as well
        $this->as('admin')->deleteJson('/api/documents/'.$d['id'].'/routes/'.$rid, ['version' => 1])->assertUnprocessable();
    }}
