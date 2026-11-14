<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Registry\Identity;
use Tests\TestCase;

class RegistryModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $diskRoot = '';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        // own disk root: other test classes call Storage::fake('local') and would wipe a shared directory mid-test
        $this->diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saan-registry-'.bin2hex(random_bytes(6));
        mkdir($this->diskRoot, 0700, true);
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::purge('local');
        $this->as('admin');
    }

    protected function tearDown(): void
    {
        Storage::purge('local');
        if (is_dir($this->diskRoot)) (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function as(string $role): static { return $this->actingAs(User::where('role', $role)->first()); }

    private function thaiId(string $first12): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int) $first12[$i] * (13 - $i);
        return $first12.((11 - $sum % 11) % 10);
    }

    private function student(array $over = []): array
    {
        static $n = 0; $n++;
        return $this->postJson('/api/registry/students', $over + ['code' => 'REG-T'.$n, 'first_name' => 'ทดสอบ'.$n, 'last_name' => 'ตัวอย่าง', 'prefix' => 'ด.ช.', 'sex' => 'M', 'classroom_id' => 1])->assertCreated()->json('student');
    }

    private function fixture(string $name): string { return base_path('tests/fixtures/registry/'.$name); }

    private function upload(string $kind, string $fixtureName, ?string $content = null)
    {
        $file = UploadedFile::fake()->createWithContent($fixtureName, $content ?? file_get_contents($this->fixture($fixtureName)));
        return $this->post('/api/registry/import/batches', ['kind' => $kind, 'file' => $file], ['Accept' => 'application/json']);
    }

    private function pdf(int $kb = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n% fictional\n".str_repeat('0', $kb * 1024)."\n%%EOF");
    }

    private function schoolTwoStudent(string $code = 'SCH2-01'): int
    {
        return (int) DB::table('students')->insertGetId(['school_id' => 2, 'classroom_id' => null, 'code' => $code, 'code_key' => strtolower($code), 'name' => 'นักเรียนโรงเรียนอื่น',
            'first_name' => 'นักเรียนโรงเรียนอื่น', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---------- students and nationality cases ----------

    public function test_students_for_each_nationality_case(): void
    {
        $thai = $this->student(['nationality_status' => 'thai', 'birth_date' => '2016-06-08']);
        $this->assertSame(2559, $thai['birth_year_be']);
        $foreign = $this->student(['nationality_status' => 'foreign', 'preferred_language' => 'ลาว', 'needs_interpreter' => true, 'first_name' => 'Souksavanh', 'last_name' => 'Keomany']);
        $this->assertTrue($foreign['needs_interpreter']);
        $this->assertSame('Souksavanh Keomany', $foreign['name']);
        $stateless = $this->student(['nationality_status' => 'stateless']);
        $pending = $this->student(['nationality_status' => 'undetermined', 'admission_type' => 'transfer_in', 'previous_school' => 'โรงเรียนบ้านตัวอย่าง (สมมติ)', 'classroom_id' => null]);
        $this->assertNull($pending['classroom_id']);
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $pending['id'], 'kind' => 'pending', 'note' => 'ย้ายเข้ากลางปี ยังไม่มีเอกสาร'])->assertCreated();
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $pending['id'], 'kind' => 'pending'])->assertUnprocessable();

        foreach (['thai' => 1, 'foreign' => 1, 'stateless' => 1, 'undetermined' => 1] as $case => $n) {
            // the seeded demo students are all Thai; add their 24 to the Thai count
            $expect = $case === 'thai' ? 24 + $n : $n;
            $this->getJson('/api/registry/students?per_page=200&nationality_status='.$case)->assertOk()->assertJsonPath('meta.total', $expect);
        }
        $this->getJson('/api/registry/students?classroom_id=none')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pending['id']);
        $this->getJson('/api/registry/students?pending_documents=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/registry/students?nationality_status=alien')->assertUnprocessable();
        $this->assertContains($stateless['code'], array_column($this->getJson('/api/registry/students?q=REG-T&per_page=200')->json('data'), 'code'));

        // duplicate codes are refused in code (case-insensitive), not by DB collation
        $this->postJson('/api/registry/students', ['code' => strtolower($thai['code']), 'first_name' => 'ซ้ำ'])->assertUnprocessable();
        $this->postJson('/api/registry/students', ['code' => '  '.$thai['code'].' ', 'first_name' => 'ซ้ำ'])->assertUnprocessable();
        $this->postJson('/api/registry/students', ['code' => 'REG-X', 'first_name' => 'x', 'sex' => 'Z'])->assertUnprocessable();
        $this->postJson('/api/registry/students', ['code' => 'REG-Y', 'first_name' => 'x', 'classroom_id' => 999])->assertUnprocessable();
        $this->assertSame(1, (int) DB::table('students')->where('code', 'DEMO-01')->value('version'));
        $this->assertSame('นักเรียนตัวอย่าง 01', DB::table('students')->where('code', 'DEMO-01')->value('name'));
    }

    // ---------- identifiers ----------

    public function test_thai_id_checksum_and_g_code(): void
    {
        $s = $this->student();
        $url = '/api/registry/identifiers';
        $valid = $this->thaiId('110170200001');
        $bad = substr($valid, 0, 12).(((int) $valid[12] + 1) % 10);
        $this->assertTrue(Identity::thaiIdValid($valid));
        $this->assertFalse(Identity::thaiIdValid($bad));
        $base = ['person_type' => 'student', 'person_id' => $s['id']];
        $this->postJson($url, $base + ['kind' => 'thai_id', 'value' => $bad])->assertUnprocessable()->assertJsonFragment(['message' => 'เลขประจำตัวประชาชนไม่ถูกต้อง (ตัวเลขตรวจสอบไม่ตรง)']);
        $this->postJson($url, $base + ['kind' => 'thai_id', 'value' => '12345'])->assertUnprocessable()->assertJsonFragment(['message' => 'เลขประจำตัวประชาชนต้องมี 13 หลัก']);
        $this->postJson($url, $base + ['kind' => 'thai_id', 'value' => ''])->assertUnprocessable();
        $this->postJson($url, $base + ['kind' => 'thai_id', 'value' => substr($valid, 0, 1).'-'.substr($valid, 1, 4).' '.substr($valid, 5)])->assertCreated()->assertJsonPath('last4', substr($valid, -4));
        $this->postJson($url, $base + ['kind' => 'thai_id', 'value' => $valid])->assertUnprocessable(); // same number again
        $this->postJson($url, $base + ['kind' => 'g_code', 'value' => 'G12345'])->assertUnprocessable();
        $this->postJson($url, $base + ['kind' => 'g_code', 'value' => 'g123456789012'])->assertCreated()->assertJsonPath('last4', '9012');
        $this->postJson($url, $base + ['kind' => 'passport', 'value' => 'x00'])->assertUnprocessable();
        $this->postJson($url, $base + ['kind' => 'passport', 'value' => 'X0000001'])->assertCreated();
        $this->postJson($url, $base + ['kind' => 'nonsense', 'value' => 'abc'])->assertUnprocessable();
        $this->postJson($url, ['person_type' => 'student', 'person_id' => 99999, 'kind' => 'other', 'value' => 'ABC123'])->assertNotFound();
        $row = DB::table('reg_identifiers')->where('kind', 'thai_id')->first();
        $this->assertSame(64, strlen($row->value_hash));
        $this->assertStringNotContainsString($valid, (string) $row->value_enc);
        $this->assertSame($valid, Identity::decrypt($row->value_enc));
        $this->assertNotSame(hash('sha256', $valid), $row->value_hash, 'hash must be keyed');
    }

    public function test_listing_masks_identifiers_and_reveal_is_audited(): void
    {
        $s = $this->student();
        $id = $this->thaiId('310150000017');
        $row = $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $s['id'], 'kind' => 'thai_id', 'value' => $id])->assertCreated()->json();
        $this->assertStringNotContainsString($id, json_encode($row));
        $list = $this->getJson('/api/registry/students?per_page=200')->assertOk();
        $this->assertStringNotContainsString($id, $list->getContent());
        $mine = collect($list->json('data'))->firstWhere('id', $s['id']);
        $this->assertSame(substr($id, -4), $mine['identifiers'][0]['last4']);
        $this->assertArrayNotHasKey('value', $mine['identifiers'][0]);
        $this->assertStringNotContainsString($id, $this->getJson('/api/registry/students/'.$s['id'])->assertOk()->getContent());

        $this->assertSame(0, DB::table('audit_events')->where('action', 'registry.identifier.revealed')->count());
        $this->postJson('/api/registry/identifiers/'.$row['id'].'/reveal')->assertOk()->assertJsonPath('value', $id);
        $audit = DB::table('audit_events')->where('action', 'registry.identifier.revealed')->get();
        $this->assertCount(1, $audit);
        $this->assertSame((string) $row['id'], (string) $audit[0]->entity_id);
        $this->assertStringNotContainsString($id, (string) $audit[0]->detail);
        $this->assertSame(0, DB::table('audit_events')->where('detail', 'like', '%'.$id.'%')->count());

        $this->as('principal')->postJson('/api/registry/identifiers/'.$row['id'].'/reveal')->assertOk();
        $this->as('teacher')->postJson('/api/registry/identifiers/'.$row['id'].'/reveal')->assertForbidden();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'registry.identifier.revealed')->count());
    }

    // ---------- teacher scope ----------

    public function test_teacher_sees_only_homeroom_without_identity_data(): void
    {
        $own = DB::table('students')->where('classroom_id', 1)->value('id');
        $other = DB::table('students')->where('classroom_id', 2)->value('id');
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $own, 'kind' => 'passport', 'value' => 'X0000009'])->assertCreated();
        $this->postJson('/api/registry/students/'.$own.'/documents', ['file' => $this->pdf(), 'kind' => 'passport'], ['Accept' => 'application/json'])->assertCreated();

        $this->as('teacher');
        $list = $this->getJson('/api/registry/students?per_page=200')->assertOk();
        $this->assertSame(8, $list->json('meta.total'));
        $this->assertSame([1], array_values(array_unique(array_column($list->json('data'), 'classroom_id'))));
        $this->assertFalse($list->json('can_edit'));
        $this->assertArrayNotHasKey('identifiers', $list->json('data.0'));
        $this->assertStringNotContainsString('X0000009', $list->getContent());
        $this->assertSame([['id' => 1, 'name' => 'ป.4/1']], $this->getJson('/api/registry/classrooms')->json('data'));
        $this->getJson('/api/registry/students?classroom_id=2')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/registry/students?pending_documents=1')->assertOk()->assertJsonPath('meta.total', 8);

        $detail = $this->getJson('/api/registry/students/'.$own)->assertOk();
        $this->assertArrayNotHasKey('identifiers', $detail->json());
        $this->assertArrayNotHasKey('documents', $detail->json());
        $this->assertFalse($detail->json('can_see_identity'));
        $this->getJson('/api/registry/students/'.$other)->assertNotFound();

        $this->postJson('/api/registry/students', ['code' => 'T-1', 'first_name' => 'x'])->assertForbidden();
        $this->putJson('/api/registry/students/'.$own, ['version' => 1, 'nickname' => 'x'])->assertForbidden();
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $own, 'kind' => 'other', 'value' => 'ABCDE'])->assertForbidden();
        $this->getJson('/api/registry/import/batches')->assertForbidden();
        $doc = DB::table('reg_documents')->value('id');
        $this->get('/api/registry/documents/'.$doc.'/download')->assertForbidden();
        $this->getJson('/api/registry/export/class-list?classroom_id=2')->assertNotFound();
        $this->get('/api/registry/export/class-list?classroom_id=1')->assertOk();

        // a teacher with no homeroom sees nobody; other roles are refused
        // (homeroom = users.classroom_id OR classrooms.homeroom_teacher_id, so both links are removed)
        DB::table('users')->where('role', 'teacher')->update(['classroom_id' => null, 'teacher_id' => null]);
        $this->as('teacher')->getJson('/api/registry/students')->assertOk()->assertJsonPath('meta.total', 0);
        DB::table('users')->where('role', 'teacher')->update(['role' => 'editor']);
        $this->actingAs(User::where('role', 'editor')->first())->getJson('/api/registry/students')->assertForbidden();
    }

    // ---------- guardians and pickup restriction ----------

    public function test_pickup_restriction_is_persisted_and_shown(): void
    {
        $s = $this->student();
        $url = '/api/registry/students/'.$s['id'].'/guardians';
        $mother = $this->postJson($url, ['guardian' => ['prefix' => 'นาง', 'first_name' => 'จันทร์เพ็ญ', 'last_name' => 'ศรีต้นธาร', 'phone' => '0XX-XXX-1201'], 'relationship' => 'mother', 'is_primary' => true, 'is_emergency_contact' => true])->assertCreated()->json();
        $this->assertSame('นางจันทร์เพ็ญ ศรีต้นธาร', $mother['guardians'][0]['display_name']);
        $this->assertSame('0XX-XXX-1201', $mother['guardians'][0]['phone']);
        $this->assertNotSame('0XX-XXX-1201', DB::table('reg_guardians')->value('phone_enc'));
        $this->postJson($url, ['guardian' => ['first_name' => 'อนุชา', 'last_name' => 'ศรีต้นธาร'], 'relationship' => 'father', 'can_pick_up' => false])->assertUnprocessable();
        $res = $this->postJson($url, ['guardian' => ['prefix' => 'นาย', 'first_name' => 'อนุชา', 'last_name' => 'ศรีต้นธาร'], 'relationship' => 'father', 'can_pick_up' => false,
            'pickup_note' => 'ห้ามรับนักเรียนกลับบ้านตามที่ผู้ปกครองหลักแจ้ง ติดต่อครูประจำชั้นก่อน'])->assertCreated();
        $this->assertCount(2, $res->json('guardians'));
        $this->assertSame('อนุชา', $res->json('guardians.1.first_name'));
        $this->assertFalse($res->json('guardians.1.can_pick_up'));
        $this->assertCount(1, $res->json('pickup_restrictions'));
        $this->assertSame(0, DB::table('reg_student_guardians')->where('guardian_id', $res->json('guardians.1.guardian_id'))->value('can_pick_up'));

        $this->assertTrue(collect($this->getJson('/api/registry/students?per_page=200')->json('data'))->firstWhere('id', $s['id'])['pickup_restricted']);
        $this->assertFalse(collect($this->getJson('/api/registry/students?per_page=200')->json('data'))->firstWhere('id', DB::table('students')->where('code', 'DEMO-01')->value('id'))['pickup_restricted']);
        $this->as('teacher')->getJson('/api/registry/students/'.$s['id'])->assertOk()->assertJsonPath('pickup_restrictions.0.display_name', 'นายอนุชา ศรีต้นธาร');

        // siblings share one guardian, one primary per student, restriction can be lifted with a version check
        $this->as('admin');
        $sibling = $this->student();
        $gid = $res->json('guardians.0.guardian_id');
        $this->postJson('/api/registry/students/'.$sibling['id'].'/guardians', ['guardian_id' => $gid, 'relationship' => 'mother', 'is_primary' => true])->assertCreated();
        $this->postJson('/api/registry/students/'.$sibling['id'].'/guardians', ['guardian_id' => $gid])->assertUnprocessable();
        $this->assertSame(1, DB::table('reg_student_guardians')->where('student_id', $s['id'])->where('is_primary', true)->count());
        $link = $res->json('guardians.1');
        $second = $this->putJson('/api/registry/student-guardians/'.$link['link_id'], ['version' => $link['link_version'], 'is_primary' => true])->assertOk();
        $this->assertSame(1, DB::table('reg_student_guardians')->where('student_id', $s['id'])->where('is_primary', true)->count());
        $this->assertFalse(DB::table('reg_student_guardians')->where('id', $mother['guardians'][0]['link_id'])->value('is_primary') == true);
        $this->putJson('/api/registry/student-guardians/'.$link['link_id'], ['version' => $link['link_version'], 'can_pick_up' => true])->assertStatus(409);
        $newVersion = collect($second->json('guardians'))->firstWhere('link_id', $link['link_id'])['link_version'];
        $lifted = $this->putJson('/api/registry/student-guardians/'.$link['link_id'], ['version' => $newVersion, 'can_pick_up' => true])->assertOk();
        $this->assertSame([], $lifted->json('pickup_restrictions'));
        $this->assertNull(DB::table('reg_student_guardians')->where('id', $link['link_id'])->value('pickup_note'));
        $this->putJson('/api/registry/guardians/'.$gid, ['version' => 1, 'phone' => '0XX-XXX-9999'])->assertOk();
        $this->assertSame('0XX-XXX-9999', $this->getJson('/api/registry/students/'.$sibling['id'])->json('guardians.0.phone'));
        $this->deleteJson('/api/registry/student-guardians/'.$link['link_id'], ['version' => 1])->assertStatus(409);
    }

    // ---------- documents ----------

    public function test_documents_are_private_audited_and_quota_limited(): void
    {
        $s = $this->student();
        $url = '/api/registry/students/'.$s['id'].'/documents';
        $doc = $this->post($url, ['file' => $this->pdf(), 'kind' => 'birth_certificate', 'label' => 'สูติบัตร'], ['Accept' => 'application/json'])->assertCreated()->json();
        $row = DB::table('reg_documents')->find($doc['id']);
        $this->assertStringStartsWith('registry/1/'.$s['id'].'/', $row->path);
        $this->assertSame('application/pdf', $row->mime);
        $this->assertSame(64, strlen($row->sha256));
        $this->assertArrayNotHasKey('path', $doc);
        Storage::disk('local')->assertExists($row->path);
        $this->assertFileDoesNotExist(public_path($row->path));
        // Laravel's signed local-disk route answers 403 without a signature; either way the file is not served
        $this->assertContains($this->get('/storage/'.$row->path)->getStatusCode(), [403, 404]);
        $this->assertContains($this->get('/storage/'.preg_replace('~^registry/~', '', $row->path))->getStatusCode(), [403, 404]);
        $this->get('/'.$row->path)->assertNotFound();
        $this->assertStringNotContainsString($row->path, $this->getJson('/api/registry/students/'.$s['id'])->getContent());

        $this->assertSame(0, DB::table('audit_events')->where('action', 'registry.document.downloaded')->count());
        $dl = $this->get('/api/registry/documents/'.$doc['id'].'/download')->assertOk();
        $this->assertStringContainsString('attachment', $dl->headers->get('Content-Disposition'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'registry.document.downloaded')->count());
        $this->as('principal')->get('/api/registry/documents/'.$doc['id'].'/download')->assertOk();
        $this->as('teacher')->get('/api/registry/documents/'.$doc['id'].'/download')->assertForbidden();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'registry.document.downloaded')->count());
        $this->as('admin');

        // types: png ok, text and disguised files refused, kind validated
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::PNG)), 'kind' => 'photo'], ['Accept' => 'application/json'])->assertCreated();
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('note.txt', 'hello'), 'kind' => 'other'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo 1;'), 'kind' => 'other'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['file' => $this->pdf(), 'kind' => 'bogus'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(2, DB::table('reg_documents')->count());

        // size limit and shared school quota
        config(['saan.storage.max_upload_mb' => 1]);
        $this->post($url, ['file' => $this->pdf(1200), 'kind' => 'other'], ['Accept' => 'application/json'])->assertUnprocessable();
        config(['saan.storage.max_upload_mb' => 10, 'saan.storage.quota_mb' => 1]);
        $this->post($url, ['file' => $this->pdf(600), 'kind' => 'other'], ['Accept' => 'application/json'])->assertCreated();
        $full = $this->post($url, ['file' => $this->pdf(600), 'kind' => 'other'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertStringContainsString('พื้นที่ไฟล์', $full->json('message'));
        $this->assertSame(3, DB::table('reg_documents')->count());
        config(['saan.storage.quota_mb' => 4096]);

        // review and delete use the version check
        $this->putJson('/api/registry/documents/'.$doc['id'], ['version' => 1, 'review_state' => 'accepted'])->assertOk()->assertJsonPath('review_state', 'accepted');
        $this->putJson('/api/registry/documents/'.$doc['id'], ['version' => 1, 'review_state' => 'rejected'])->assertStatus(409)->assertJsonMissingPath('current.path');
        $this->deleteJson('/api/registry/documents/'.$doc['id'], ['version' => 1])->assertStatus(409);
        Storage::disk('local')->assertExists($row->path);
        $this->deleteJson('/api/registry/documents/'.$doc['id'], ['version' => 2])->assertOk();
        Storage::disk('local')->assertMissing($row->path);
        $this->assertNull(DB::table('reg_documents')->find($doc['id']));
    }

    public function test_other_school_data_is_not_reachable(): void
    {
        $mine = $this->student();
        $theirs = $this->schoolTwoStudent();
        $gid = DB::table('reg_guardians')->insertGetId(['school_id' => 2, 'display_name' => 'ผู้ปกครองโรงเรียนอื่น', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $link = DB::table('reg_student_guardians')->insertGetId(['school_id' => 2, 'student_id' => $theirs, 'guardian_id' => $gid, 'relationship' => 'mother', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $ident = DB::table('reg_identifiers')->insertGetId(['school_id' => 2, 'person_type' => 'student', 'person_id' => $theirs, 'kind' => 'passport', 'value_enc' => Identity::encrypt('OTHER0001'),
            'value_hash' => Identity::hash('OTHER0001'), 'last4' => '0001', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $path = 'registry/2/'.$theirs.'/x.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        $doc = DB::table('reg_documents')->insertGetId(['school_id' => 2, 'student_id' => $theirs, 'kind' => 'other', 'label' => 'x', 'path' => $path, 'mime' => 'application/pdf', 'size' => 8,
            'sha256' => str_repeat('a', 64), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $batch = DB::table('reg_import_batches')->insertGetId(['school_id' => 2, 'kind' => 'students', 'filename' => 'x.csv', 'status' => 'preview', 'created_at' => now(), 'updated_at' => now()]);

        $codes = array_column($this->getJson('/api/registry/students?per_page=200')->json('data'), 'code');
        $this->assertNotContains('SCH2-01', $codes);
        $this->getJson('/api/registry/students/'.$theirs)->assertNotFound();
        $this->putJson('/api/registry/students/'.$theirs, ['version' => 1, 'nickname' => 'x'])->assertNotFound();
        $this->putJson('/api/registry/guardians/'.$gid, ['version' => 1, 'first_name' => 'x'])->assertNotFound();
        $this->putJson('/api/registry/student-guardians/'.$link, ['version' => 1, 'relationship' => 'father'])->assertNotFound();
        $this->deleteJson('/api/registry/student-guardians/'.$link, ['version' => 1])->assertNotFound();
        $this->postJson('/api/registry/identifiers/'.$ident.'/reveal')->assertNotFound();
        $this->putJson('/api/registry/identifiers/'.$ident, ['version' => 1, 'verification_state' => 'verified'])->assertNotFound();
        $this->get('/api/registry/documents/'.$doc.'/download')->assertNotFound();
        $this->putJson('/api/registry/documents/'.$doc, ['version' => 1, 'review_state' => 'accepted'])->assertNotFound();
        $this->deleteJson('/api/registry/documents/'.$doc, ['version' => 1])->assertNotFound();
        $this->postJson('/api/registry/students/'.$theirs.'/documents', ['file' => $this->pdf(), 'kind' => 'other'], ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson('/api/registry/students/'.$theirs.'/guardians', ['guardian_id' => $gid])->assertNotFound();
        $this->postJson('/api/registry/students/'.$mine['id'].'/guardians', ['guardian_id' => $gid])->assertNotFound();
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $theirs, 'kind' => 'other', 'value' => 'ABCDE'])->assertNotFound();
        $this->getJson('/api/registry/import/batches/'.$batch)->assertNotFound();
        $this->postJson('/api/registry/import/batches/'.$batch.'/commit')->assertNotFound();
        $this->assertSame([], $this->getJson('/api/registry/import/batches')->json('data'));
        Storage::disk('local')->assertExists($path);
        $this->assertSame('OTHER0001', Identity::decrypt(DB::table('reg_identifiers')->find($ident)->value_enc));

        // same code and same number in another school do not collide
        $this->postJson('/api/registry/students', ['code' => 'SCH2-01', 'first_name' => 'ซ้ำข้ามโรงเรียน'])->assertCreated();
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $mine['id'], 'kind' => 'passport', 'value' => 'OTHER0001'])->assertCreated();
    }

    // ---------- optimistic locking ----------

    public function test_version_conflict_returns_409_with_current_row(): void
    {
        $s = $this->student();
        $ok = $this->putJson('/api/registry/students/'.$s['id'], ['version' => $s['version'], 'nickname' => 'แก้ม', 'first_name' => 'ใหม่'])->assertOk();
        $this->assertSame(2, $ok->json('student.version'));
        $this->assertSame('ใหม่ ตัวอย่าง', $ok->json('student.name'));
        $this->putJson('/api/registry/students/'.$s['id'], ['version' => $s['version'], 'nickname' => 'สอง'])->assertStatus(409)->assertJsonPath('current.nickname', 'แก้ม')->assertJsonPath('current.version', 2);
        $this->assertSame('แก้ม', DB::table('students')->find($s['id'])->nickname);
        $this->putJson('/api/registry/students/'.$s['id'], ['nickname' => 'ไม่มีเวอร์ชัน'])->assertUnprocessable();

        $ident = $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $s['id'], 'kind' => 'passport', 'value' => 'X0000077'])->assertCreated()->json();
        $this->putJson('/api/registry/identifiers/'.$ident['id'], ['version' => 1, 'verification_state' => 'verified'])->assertOk()->assertJsonPath('version', 2);
        $conflict = $this->putJson('/api/registry/identifiers/'.$ident['id'], ['version' => 1, 'verification_state' => 'rejected'])->assertStatus(409);
        $this->assertStringNotContainsString('X0000077', $conflict->getContent());
        $this->assertArrayNotHasKey('value_enc', $conflict->json('current'));
        $this->assertArrayNotHasKey('value_hash', $conflict->json('current'));
    }

    // ---------- CSV import ----------

    public function test_import_preview_commit_and_rollback(): void
    {
        $before = DB::table('students')->where('code', 'DEMO-01')->first();
        $p = $this->upload('students', 'students.csv')->assertCreated()->json();
        $this->assertSame('preview', $p['batch']['status']);
        $this->assertSame(9, $p['batch']['summary']['rows']);
        $this->assertSame(8, $p['batch']['summary']['update']);
        $this->assertSame(1, $p['batch']['summary']['create']);
        $this->assertSame(0, $p['batch']['summary']['error']);
        $this->assertSame(['person_id', 'portrait'], $p['batch']['summary']['ignored_columns'], 'only the mock columns without a registry field are ignored');
        // identity_pending_reason of the mock becomes a pending identifier with its note
        $this->assertSame('ย้ายเข้ากลางปี ยังไม่มีเอกสาร ไม่ปฏิเสธสิทธิ์เข้าเรียน', collect($p['rows'])->firstWhere('data.code', 'DEMO-08')['data']['pending_reason']);
        $this->assertSame('create', collect($p['rows'])->firstWhere('data.code', 'REG-901')['action']);
        $this->assertSame($before->name, DB::table('students')->where('code', 'DEMO-01')->value('name'), 'preview must not write');
        $this->assertNull(DB::table('students')->where('code', 'REG-901')->first());

        $c = $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/commit')->assertOk()->json();
        $this->assertSame('committed', $c['batch']['status']);
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/commit')->assertUnprocessable();
        $s1 = DB::table('students')->where('code', 'DEMO-01')->first();
        $this->assertSame('Souksavanh Keomany', $s1->name);
        $this->assertSame('foreign', $s1->nationality_status);
        $this->assertSame(1, (int) $s1->needs_interpreter);
        $this->assertSame('DEMO-S001', $s1->external_ref);
        $this->assertSame('2026-05-16', substr($s1->admitted_on, 0, 10));
        $this->assertSame('undetermined', DB::table('students')->where('code', 'DEMO-08')->value('nationality_status'));
        $this->assertSame('stateless', DB::table('students')->where('code', 'DEMO-10')->value('nationality_status'));
        $this->assertSame('transfer_in', DB::table('students')->where('code', 'DEMO-08')->value('admission_type'));
        $pend = DB::table('reg_identifiers')->where('person_type', 'student')->where('kind', 'pending')->get();
        $this->assertEqualsCanonicalizing([(int) DB::table('students')->where('code', 'DEMO-08')->value('id'), (int) DB::table('students')->where('code', 'DEMO-10')->value('id')], $pend->pluck('person_id')->map(fn ($v) => (int) $v)->all());
        $this->assertStringContainsString('ไม่สร้างเลข G เอง', $pend->firstWhere('person_id', DB::table('students')->where('code', 'DEMO-10')->value('id'))->note);
        $new = DB::table('students')->where('code', 'REG-901')->first();
        $this->assertNull($new->classroom_id);
        $this->assertSame(1, (int) $new->version);

        // guardians then links (siblings S004 and S012 share two guardians; one father may not pick up)
        $g = $this->upload('guardians', 'guardians.csv')->assertCreated()->json();
        $this->assertSame(14, $g['batch']['summary']['create']);
        $this->assertSame(['person_id', 'contact_language', 'verification_state', 'portrait', 'sex', 'birth_year_be'], $g['batch']['summary']['ignored_columns'], 'unused columns are reported, not fatal');
        $this->postJson('/api/registry/import/batches/'.$g['batch']['id'].'/commit')->assertOk();
        $l = $this->upload('student_guardians', 'student_guardians.csv')->assertCreated()->json();
        $this->assertSame(16, $l['batch']['summary']['create']);
        $this->postJson('/api/registry/import/batches/'.$l['batch']['id'].'/commit')->assertOk();
        $s4 = DB::table('students')->where('code', 'DEMO-04')->value('id');
        $detail = $this->getJson('/api/registry/students/'.$s4)->assertOk();
        $this->assertCount(2, $detail->json('guardians'));
        $this->assertSame('นายอนุชา แก้วประเสริฐ', $detail->json('pickup_restrictions.0.display_name'));
        $this->assertNotEmpty($detail->json('pickup_restrictions.0.note'));
        $this->assertTrue($detail->json('guardians.0.is_primary'));
        $this->assertSame(1, DB::table('reg_guardians')->where('display_name', 'นายอนุชา แก้วประเสริฐ')->count());
        $again = $this->upload('student_guardians', 'student_guardians.csv')->assertCreated()->json();
        $this->assertSame(16, $again['batch']['summary']['skip']);
        $this->postJson('/api/registry/import/batches/'.$again['batch']['id'].'/commit')->assertUnprocessable();

        // roll back in reverse order: links, guardians, students
        $this->postJson('/api/registry/import/batches/'.$g['batch']['id'].'/rollback')->assertStatus(409)->assertJsonStructure(['blocking' => [['row_no', 'entity_id', 'reason']]]);
        $this->postJson('/api/registry/import/batches/'.$l['batch']['id'].'/rollback')->assertOk()->assertJsonPath('batch.status', 'rolled_back');
        $this->assertSame(0, DB::table('reg_student_guardians')->count());
        $this->postJson('/api/registry/import/batches/'.$g['batch']['id'].'/rollback')->assertOk();
        $this->assertSame(0, DB::table('reg_guardians')->count());
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/rollback')->assertOk()->assertJsonPath('batch.status', 'rolled_back');
        $this->assertNull(DB::table('students')->where('code', 'REG-901')->first());
        $restored = DB::table('students')->where('code', 'DEMO-01')->first();
        $this->assertSame($before->name, $restored->name);
        $this->assertSame($before->nationality_status, $restored->nationality_status);
        $this->assertNull($restored->external_ref);
        $this->assertSame(0, DB::table('reg_identifiers')->where('kind', 'pending')->count(), 'pending identifiers created by the import are removed with it');
        $this->assertSame((int) $restored->version, 3, 'import +1, rollback +1');
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/rollback')->assertUnprocessable();
        $this->assertSame(3, DB::table('audit_events')->whereIn('action', ['registry.import.committed'])->count());
    }

    public function test_rollback_is_blocked_after_a_row_was_edited(): void
    {
        $p = $this->upload('students', 'students.csv')->assertCreated()->json();
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/commit')->assertOk();
        $edited = DB::table('students')->where('code', 'DEMO-02')->first();
        $created = DB::table('students')->where('code', 'REG-901')->first();
        $this->putJson('/api/registry/students/'.$edited->id, ['version' => $edited->version, 'nickname' => 'แก้หลังนำเข้า'])->assertOk();
        $this->putJson('/api/registry/students/'.$created->id, ['version' => 1, 'nickname' => 'แก้หลังนำเข้า'])->assertOk();

        $res = $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/rollback')->assertStatus(409);
        $blocking = collect($res->json('blocking'));
        $this->assertCount(2, $blocking);
        $this->assertEqualsCanonicalizing([(int) $edited->id, (int) $created->id], $blocking->pluck('entity_id')->all());
        $this->assertSame('ข้อมูลถูกแก้ไขแล้วหลังนำเข้า', $blocking[0]['reason']);
        // nothing was touched and the batch stays committed
        $this->assertSame('committed', DB::table('reg_import_batches')->find($p['batch']['id'])->status);
        $this->assertNotNull(DB::table('students')->where('code', 'REG-901')->first());
        $this->assertSame('Souksavanh Keomany', DB::table('students')->where('code', 'DEMO-01')->value('name'));
        $this->assertSame('แก้หลังนำเข้า', DB::table('students')->where('code', 'DEMO-02')->value('nickname'));

        // a created student that gained related data also blocks the rollback
        $other = $this->upload('students', 'students_thai_headers.csv')->assertCreated()->json();
        $this->postJson('/api/registry/import/batches/'.$other['batch']['id'].'/commit')->assertOk();
        $sid = DB::table('students')->where('code', 'REG-902')->value('id');
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $sid, 'kind' => 'pending'])->assertCreated();
        $dep = $this->postJson('/api/registry/import/batches/'.$other['batch']['id'].'/rollback')->assertStatus(409);
        $this->assertSame([(int) $sid], $dep->json('blocking.*.entity_id'));
        $this->assertStringContainsString('ข้อมูลที่เกี่ยวข้อง', $dep->json('blocking.0.reason'));
    }

    public function test_rollback_is_blocked_by_data_of_other_modules(): void
    {
        $p = $this->upload('students', 'students.csv')->assertCreated()->json();
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/commit')->assertOk();
        $sid = (int) DB::table('students')->where('code', 'REG-901')->value('id');
        DB::table('bhv_records')->insert(['school_id' => 1, 'term_id' => 1, 'student_id' => $sid, 'occurred_on' => '2026-11-05', 'points' => 5, 'recorded_by' => 1,
            'status' => 'active', 'guardian_visible' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $res = $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/rollback')->assertStatus(409);
        $this->assertContains($sid, $res->json('blocking.*.entity_id'));
        $this->assertNotNull(DB::table('students')->where('id', $sid)->first());
        DB::table('bhv_records')->where('student_id', $sid)->delete();
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/rollback')->assertOk();
        $this->assertNull(DB::table('students')->where('id', $sid)->first());
    }

    public function test_rollback_is_blocked_by_activities_and_constructionism_rows_declared_in_module_json(): void
    {
        $p = $this->upload('students', 'students.csv')->assertCreated()->json();
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/commit')->assertOk();
        $sid = (int) DB::table('students')->where('code', 'REG-901')->value('id');
        DB::table('act_voters')->insert(['school_id' => 1, 'election_id' => 1, 'student_id' => $sid, 'classroom_id' => 1, 'voted_at' => now()]);
        $res = $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/rollback')->assertStatus(409);
        $this->assertContains($sid, $res->json('blocking.*.entity_id'));
        $this->assertNotNull(DB::table('students')->where('id', $sid)->first());
        DB::table('act_voters')->where('student_id', $sid)->delete();
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/rollback')->assertOk();
    }

    public function test_long_guardian_names_are_truncated_and_replay_keeps_no_phone_in_cache(): void
    {
        config(['cache.default' => 'database']); // the production store: the cache table is inspected below
        $this->as('admin');
        $sid = (int) DB::table('students')->where('school_id', 1)->value('id');
        $body = ['guardian' => ['prefix' => str_repeat('ก', 30), 'first_name' => str_repeat('ข', 120), 'last_name' => str_repeat('ค', 120), 'phone' => '0812345678'], 'relationship' => 'mother'];
        $h = ['Idempotency-Key' => 'reg-guardian-1'];
        $first = $this->postJson("/api/registry/students/$sid/guardians", $body, $h)->assertCreated();
        $this->assertLessThanOrEqual(240, mb_strlen((string) DB::table('reg_guardians')->orderByDesc('id')->value('display_name')));
        $replay = $this->postJson("/api/registry/students/$sid/guardians", $body, $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('reg_guardians')->count());
        $this->assertSame($first->json('guardians.0.guardian_id'), $replay->json('guardians.0.guardian_id'));
        $this->assertGreaterThan(0, DB::table('cache')->count());
        foreach (DB::table('cache')->get() as $row) $this->assertStringNotContainsString('0812345678', (string) $row->value);
    }

    public function test_import_row_errors_thai_headers_and_bom(): void
    {
        $e = $this->upload('students', 'students_errors.csv')->assertCreated()->json();
        $s = $e['batch']['summary'];
        $this->assertSame(6, $s['rows']);
        $this->assertSame(1, $s['create']);
        $this->assertSame(5, $s['error']);
        $byCode = collect($e['rows'])->keyBy(fn ($r) => $r['row_no']);
        $this->assertStringContainsString('เพศไม่ถูกต้อง', $byCode[3]['errors'][0]);
        $this->assertStringContainsString('ไม่พบห้องเรียน', $byCode[4]['errors'][0]);
        $this->assertContains('ไม่มีรหัสนักเรียน', $byCode[5]['errors']);
        $this->assertStringContainsString('ซ้ำ', $byCode[6]['errors'][0]);
        $this->assertStringContainsString('วันเกิดไม่ถูกต้อง', $byCode[7]['errors'][0]);
        $c = $this->postJson('/api/registry/import/batches/'.$e['batch']['id'].'/commit')->assertOk()->json();
        $this->assertSame(1, $c['batch']['summary']['committed']['create']);
        $this->assertNotNull(DB::table('students')->where('code', 'BAD-01')->first());
        $this->assertNull(DB::table('students')->where('code', 'BAD-02')->first());

        $t = $this->upload('students', 'students_thai_headers.csv')->assertCreated()->json();
        $this->assertSame(3, $t['batch']['summary']['create']);
        $this->assertSame(0, $t['batch']['summary']['error']);
        $this->postJson('/api/registry/import/batches/'.$t['batch']['id'].'/commit')->assertOk();
        $r2 = DB::table('students')->where('code', 'REG-902')->first();
        $this->assertSame('M', $r2->sex);
        $this->assertSame('2016-03-15', substr($r2->birth_date, 0, 10));
        $this->assertSame('สมชาย', $r2->first_name);
        $this->assertSame('ทดสอบดี', $r2->last_name);
        $this->assertSame('เด็กชาย', $r2->prefix);
        $this->assertSame('foreign', DB::table('students')->where('code', 'REG-903')->value('nationality_status'));
        $this->assertSame(1, (int) DB::table('students')->where('code', 'REG-903')->value('needs_interpreter'));
        $this->assertSame('stateless', DB::table('students')->where('code', 'REG-904')->value('nationality_status'));
        $this->assertNull(DB::table('students')->where('code', 'REG-904')->value('classroom_id'));

        $this->upload('students', 'x.csv', "id,name\n1,x\n")->assertUnprocessable();
        $this->upload('students', 'x.csv', "student_code,display_name\n\xFF\xFE,x\n")->assertUnprocessable();
        $this->upload('students', 'x.csv', "student_code,display_name\n")->assertUnprocessable();
        $this->upload('nonsense', 'x.csv', "student_code,display_name\nA,b\n")->assertUnprocessable();
        // blank cells never wipe existing values on update
        $keep = $this->upload('students', 'x.csv', "student_code,nickname,sex\nDEMO-03,,\n")->assertCreated()->json();
        $this->assertSame(1, $keep['batch']['summary']['skip']);
    }

    public function test_class_list_export_has_no_identifiers_and_is_formula_safe(): void
    {
        $s = $this->student(['first_name' => '=SUM(1+1)', 'classroom_id' => 3]);
        $id = $this->thaiId('510150000035');
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $s['id'], 'kind' => 'thai_id', 'value' => $id])->assertCreated();
        $res = $this->get('/api/registry/export/class-list?classroom_id=3')->assertOk();
        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('DEMO-17', $body);
        $this->assertStringContainsString("'=SUM(1+1)", $body);
        $this->assertStringNotContainsString($id, $body);
        $this->assertStringNotContainsString(substr($id, -4), $body);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'registry.class_list.exported')->count());
        $this->getJson('/api/registry/export/class-list')->assertUnprocessable();
    }

    public function test_module_pages_are_listed_by_role(): void
    {
        $this->assertContains('registry', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->get('/work')->assertOk()->assertSee('ทะเบียนนักเรียน')->assertSee('นำเข้าข้อมูลนักเรียน')->assertSee('assets/modules/registry/registry.js', false);
        $this->as('teacher')->get('/work')->assertOk()->assertSee('ทะเบียนนักเรียน')->assertDontSee('นำเข้าข้อมูลนักเรียน');
    }
    // ---------- review fixes ----------

    public function test_import_never_keeps_guardian_phone_in_clear_text(): void
    {
        $csv = "id,display_name,phone\nG-PH1,นางสมศรี ทดสอบ,081-234-5678\n";
        $p = $this->upload('guardians', 'g.csv', $csv)->assertCreated();
        $this->assertStringNotContainsString('081-234-5678', $p->getContent());
        $this->assertStringNotContainsString('0812345678', $p->getContent());
        $this->assertSame('5678', $p->json('rows.0.data.phone_last4'));
        $this->assertArrayNotHasKey('phone_enc', $p->json('rows.0.data'));
        $raw = DB::table('reg_import_rows')->get();
        foreach ($raw as $r) { $this->assertStringNotContainsString('081-234-5678', $r->data); $this->assertStringNotContainsString('5678-', $r->data); }
        $c = $this->postJson('/api/registry/import/batches/'.$p->json('batch.id').'/commit')->assertOk();
        $this->assertStringNotContainsString('081-234-5678', $c->getContent());
        foreach (DB::table('reg_import_rows')->get() as $r) { $this->assertStringNotContainsString('081-234-5678', $r->data.$r->before.$r->after); }
        $g = DB::table('reg_guardians')->where('ref_code', 'G-PH1')->first();
        $this->assertSame('081-234-5678', Identity::decrypt($g->phone_enc));
        $this->assertStringNotContainsString('081-234-5678', (string) $g->phone_enc);
        // same phone again: the plain value is compared in memory, so nothing to update
        $again = $this->upload('guardians', 'g.csv', $csv)->assertCreated();
        $this->assertSame(1, $again->json('batch.summary.skip'));
        $changed = $this->upload('guardians', 'g.csv', "id,display_name,phone\nG-PH1,นางสมศรี ทดสอบ,089-999-0000\n")->assertCreated();
        $this->assertSame(['phone'], $changed->json('rows.0.data._diff'));
        $this->assertStringNotContainsString('089-999-0000', $changed->getContent());
    }

    public function test_blank_guardian_last_name_keeps_existing_value(): void
    {
        $a = $this->upload('guardians', 'g.csv', "id,first_name,last_name\nG-LN1,สมหมาย,ใจดี\n")->assertCreated()->json();
        $this->postJson('/api/registry/import/batches/'.$a['batch']['id'].'/commit')->assertOk();
        $b = $this->upload('guardians', 'g.csv', "id,first_name,last_name,occupation\nG-LN1,สมหมาย,,ครู\n")->assertCreated()->json();
        $this->assertSame('update', $b['rows'][0]['action']);
        $this->postJson('/api/registry/import/batches/'.$b['batch']['id'].'/commit')->assertOk();
        $g = DB::table('reg_guardians')->where('ref_code', 'G-LN1')->first();
        $this->assertSame('ใจดี', $g->last_name);
        $this->assertSame('ครู', $g->occupation);
    }

    public function test_clearing_last_name_updates_name_and_legacy_rows_keep_theirs(): void
    {
        $s = $this->student(['first_name' => 'สมชาย', 'last_name' => 'ใจดี']);
        $r = $this->putJson('/api/registry/students/'.$s['id'], ['version' => $s['version'], 'last_name' => null])->assertOk();
        $this->assertNull($r->json('student.last_name'));
        $this->assertSame('สมชาย', $r->json('student.name'));
        $this->assertSame('สมชาย', DB::table('students')->find($s['id'])->name);

        // a seeded (legacy) row has no first_name: editing only the last name must not lose the stored name
        $legacy = DB::table('students')->where('code', 'DEMO-02')->first();
        $this->assertNull($legacy->first_name);
        $r2 = $this->putJson('/api/registry/students/'.$legacy->id, ['version' => $legacy->version, 'last_name' => 'นามสกุลใหม่'])->assertOk();
        $this->assertSame($legacy->name, $r2->json('student.name'));
        $this->assertSame('นามสกุลใหม่', $r2->json('student.last_name'));
    }

    public function test_legacy_rows_without_code_key_are_matched_case_insensitively_in_code(): void
    {
        $legacy = DB::table('students')->where('code', 'DEMO-01')->first();
        $this->assertNull($legacy->code_key, 'seeded rows are inserted without code_key');
        $this->postJson('/api/registry/students', ['code' => 'demo-01', 'first_name' => 'ซ้ำ'])->assertUnprocessable();
        $this->postJson('/api/registry/students', ['code' => ' Demo-01 ', 'first_name' => 'ซ้ำ'])->assertUnprocessable();

        $p = $this->upload('students', 's.csv', "student_code,nickname\ndemo-01,ชื่อเล่นใหม่\n")->assertCreated()->json();
        $this->assertSame('update', $p['rows'][0]['action']);
        $this->assertSame((int) $legacy->id, $p['rows'][0]['entity_id']);
        $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/commit')->assertOk();
        $after = DB::table('students')->find($legacy->id);
        $this->assertSame('ชื่อเล่นใหม่', $after->nickname);
        $this->assertSame('demo-01', $after->code_key, 'the import fills the normalised key of a legacy row');

        $other = DB::table('students')->where('code', 'DEMO-03')->first();
        $this->putJson('/api/registry/students/'.$other->id, ['version' => $other->version, 'nickname' => 'x'])->assertOk();
        $this->assertSame('demo-03', DB::table('students')->find($other->id)->code_key);
    }

    public function test_chained_batches_roll_back_in_reverse_order(): void
    {
        $orig = DB::table('students')->where('code', 'DEMO-03')->value('nickname');
        $a = $this->upload('students', 's.csv', "student_code,nickname\nDEMO-03,AAA\n")->assertCreated()->json();
        $this->postJson('/api/registry/import/batches/'.$a['batch']['id'].'/commit')->assertOk();
        $b = $this->upload('students', 's.csv', "student_code,nickname\nDEMO-03,BBB\n")->assertCreated()->json();
        $this->postJson('/api/registry/import/batches/'.$b['batch']['id'].'/commit')->assertOk();
        // A cannot go first: batch B changed the same row after it
        $this->postJson('/api/registry/import/batches/'.$a['batch']['id'].'/rollback')->assertStatus(409)->assertJsonCount(1, 'blocking');
        $this->assertSame('BBB', DB::table('students')->where('code', 'DEMO-03')->value('nickname'));
        $this->postJson('/api/registry/import/batches/'.$b['batch']['id'].'/rollback')->assertOk();
        $this->assertSame('AAA', DB::table('students')->where('code', 'DEMO-03')->value('nickname'));
        $this->postJson('/api/registry/import/batches/'.$a['batch']['id'].'/rollback')->assertOk()->assertJsonPath('batch.status', 'rolled_back');
        $this->assertSame($orig, DB::table('students')->where('code', 'DEMO-03')->value('nickname'));
        // a blocked rollback leaves the batch committed and writes nothing
        $c = $this->upload('students', 's.csv', "student_code,first_name,nickname\nCHN-01,ทดสอบ,N1\n")->assertCreated()->json();
        $this->postJson('/api/registry/import/batches/'.$c['batch']['id'].'/commit')->assertOk();
        $id = DB::table('students')->where('code', 'CHN-01')->value('id');
        $this->putJson('/api/registry/students/'.$id, ['version' => 1, 'nickname' => 'N2'])->assertOk();
        $this->postJson('/api/registry/import/batches/'.$c['batch']['id'].'/rollback')->assertStatus(409);
        $this->assertSame('committed', DB::table('reg_import_batches')->find($c['batch']['id'])->status);
    }

    public function test_commit_after_a_concurrent_edit_returns_409(): void
    {
        $p = $this->upload('students', 's.csv', "student_code,nickname\nDEMO-04,จากไฟล์\n")->assertCreated()->json();
        $row = DB::table('students')->where('code', 'DEMO-04')->first();
        // simulate a concurrent writer that bumps the row between the commit's read and its versioned update
        $bumped = false;
        DB::beforeExecuting(function ($sql) use (&$bumped, $row) {
            $plain = str_replace(['"', '`'], '', $sql);
            if ($bumped || !str_starts_with($plain, 'update students set') || !str_contains($plain, 'nickname')) return;
            $bumped = true;
            DB::table('students')->where('id', $row->id)->update(['nickname' => 'แก้ระหว่างรอ', 'version' => DB::raw('version + 1')]);
        });
        $res = $this->postJson('/api/registry/import/batches/'.$p['batch']['id'].'/commit')->assertStatus(409);
        $this->assertStringContainsString('DEMO-04', $res->json('message'));
        $this->assertSame('preview', DB::table('reg_import_batches')->find($p['batch']['id'])->status, 'the failed commit is rolled back as a whole');
        // the simulated writer shares the transaction here, so it is undone too; the import value is never applied
        $this->assertSame($row->nickname, DB::table('students')->find($row->id)->nickname);
        $this->assertSame((int) $row->version, (int) DB::table('students')->find($row->id)->version);
    }

    public function test_identity_responses_are_not_cacheable(): void
    {
        $s = $this->student();
        $i = $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $s['id'], 'kind' => 'passport', 'value' => 'X0000999', 'issuer' => 'lao'])->assertCreated();
        $this->assertSame('LAO', $i->json('issuer'));
        $rev = $this->postJson('/api/registry/identifiers/'.$i->json('id').'/reveal')->assertOk();
        $this->assertStringContainsString('no-store', $rev->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $this->getJson('/api/registry/students/'.$s['id'])->assertOk()->headers->get('Cache-Control'));
    }

    public function test_passport_issuer_and_expiry_are_kept_and_scope_the_uniqueness(): void
    {
        $a = $this->student(); $b = $this->student();
        $lao = $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $a['id'], 'kind' => 'passport', 'value' => 'P1234567', 'issuer' => 'LAO', 'expires_on' => '2028-01-15'])->assertCreated();
        $this->assertSame('2028-01-15', substr((string) $lao->json('expires_on'), 0, 10));
        // same number from another country is a different passport
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $b['id'], 'kind' => 'passport', 'value' => 'P1234567', 'issuer' => 'MMR'])->assertCreated();
        // same country and number is a duplicate
        $c = $this->student();
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $c['id'], 'kind' => 'passport', 'value' => 'p1234567', 'issuer' => 'lao'])->assertUnprocessable();
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $c['id'], 'kind' => 'passport', 'value' => 'P1234567', 'expires_on' => 'not-a-date'])->assertUnprocessable();
        $list = $this->getJson('/api/registry/students/'.$a['id'])->assertOk();
        $this->assertSame('LAO', $list->json('identifiers.0.issuer'));
    }

    public function test_migrations_are_safe_to_run_over_existing_columns(): void
    {
        foreach (['issuer', 'expires_on'] as $c) $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('reg_identifiers', $c));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('reg_import_rows', 'after'));
        foreach (['first_name', 'code_key', 'status', 'version', 'external_ref'] as $c) $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('students', $c));
        // re-running the additive migration must not fail or duplicate anything
        (require base_path('modules/registry/database/migrations/2026_11_06_120003_registry_review_additions.php'))->up();
        $this->assertSame(1, count(array_filter(\Illuminate\Support\Facades\Schema::getIndexes('students'), fn ($i) => $i['name'] === 'students_school_codekey')));
    }

    // ---------- student-area review fixes ----------

    public function test_teacher_cannot_read_students_who_left_the_room(): void
    {
        $own = DB::table('students')->where('classroom_id', 1)->value('id');
        $this->postJson('/api/registry/students/'.$own.'/guardians', ['guardian' => ['first_name' => 'ผู้ปกครอง', 'phone' => '0800000000'], 'relationship' => 'mother'])->assertCreated();
        $v = $this->getJson('/api/registry/students/'.$own)->json('student.version');
        $this->putJson('/api/registry/students/'.$own, ['version' => $v, 'status' => 'graduated'])->assertOk();
        $this->as('teacher');
        $this->getJson('/api/registry/students/'.$own)->assertNotFound();
        $this->assertNotContains($own, array_column($this->getJson('/api/registry/students?per_page=200&status=graduated')->assertOk()->json('data'), 'id'));
        $this->getJson('/api/registry/students?per_page=200&status=graduated')->assertJsonPath('meta.total', 0);
        $this->as('admin');
        $this->getJson('/api/registry/students/'.$own)->assertOk()->assertJsonPath('student.status', 'graduated');
    }

    public function test_student_detail_read_is_audited(): void
    {
        $own = DB::table('students')->where('classroom_id', 1)->value('id');
        $this->as('teacher');
        $this->getJson('/api/registry/students/'.$own)->assertOk();
        $row = DB::table('audit_events')->where('action', 'registry.student.viewed')->where('entity_id', $own)->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('0800', (string) $row->detail);
    }

    public function test_guardian_can_be_erased_only_when_unlinked(): void
    {
        $sid = DB::table('students')->where('classroom_id', 1)->value('id');
        $d = $this->postJson('/api/registry/students/'.$sid.'/guardians', ['guardian' => ['first_name' => 'ลบได้', 'phone' => '0811111111'], 'relationship' => 'father'])->assertCreated()->json();
        $g = $d['guardians'][0];
        $this->postJson('/api/registry/identifiers', ['person_type' => 'guardian', 'person_id' => $g['guardian_id'], 'kind' => 'passport', 'value' => 'GUARD0001'])->assertCreated();
        $this->deleteJson('/api/registry/guardians/'.$g['guardian_id'], ['version' => $g['guardian_version']])->assertUnprocessable(); // still linked
        $this->deleteJson('/api/registry/student-guardians/'.$g['link_id'], ['version' => $g['link_version']])->assertOk();
        $this->assertContains($g['guardian_id'], array_column($this->getJson('/api/registry/guardians/unlinked')->assertOk()->json('data'), 'id'));
        $this->deleteJson('/api/registry/guardians/'.$g['guardian_id'], ['version' => $g['guardian_version']])->assertOk();
        $this->assertSame(0, DB::table('reg_guardians')->where('id', $g['guardian_id'])->count());
        $this->assertSame(0, DB::table('reg_identifiers')->where('person_type', 'guardian')->where('person_id', $g['guardian_id'])->count());
        $this->as('teacher');
        $this->deleteJson('/api/registry/guardians/'.$g['guardian_id'], ['version' => 1])->assertForbidden();
    }

    public function test_deleting_a_guardian_with_a_stale_version_does_not_leak_phone_ciphertext_in_the_409(): void
    {
        $sid = DB::table('students')->where('classroom_id', 1)->value('id');
        $g = $this->postJson('/api/registry/students/'.$sid.'/guardians', ['guardian' => ['first_name' => 'เวอร์ชัน', 'phone' => '0822222222'], 'relationship' => 'father'])->assertCreated()->json('guardians.0');
        $this->deleteJson('/api/registry/student-guardians/'.$g['link_id'], ['version' => $g['link_version']])->assertOk();
        DB::table('reg_guardians')->where('id', $g['guardian_id'])->update(['version' => $g['guardian_version'] + 1]);
        $res = $this->deleteJson('/api/registry/guardians/'.$g['guardian_id'], ['version' => $g['guardian_version']])->assertStatus(409);
        $res->assertJsonMissingPath('current.phone_enc');
        $this->assertStringNotContainsString((string) DB::table('reg_guardians')->where('id', $g['guardian_id'])->value('phone_enc'), $res->getContent());
        $this->assertSame(1, DB::table('reg_guardians')->where('id', $g['guardian_id'])->count());
    }

    public function test_import_switching_pickup_off_closes_the_portal_links_like_the_screen_does(): void
    {
        $sid = (int) DB::table('students')->where('classroom_id', 1)->value('id');
        $code = (string) DB::table('students')->where('id', $sid)->value('code');
        $gb = $this->upload('guardians', 'g.csv', "id,display_name\nG-PU1,นางสมพร ทดสอบ\n")->assertCreated()->json('batch.id');
        $this->postJson('/api/registry/import/batches/'.$gb.'/commit')->assertOk();
        $gid = (int) DB::table('reg_guardians')->where('ref_code', 'G-PU1')->value('id');
        $l = $this->upload('student_guardians', 'l.csv', "student_id,guardian_id,relationship,can_pick_up\n$code,G-PU1,mother,True\n")->assertCreated();
        $this->postJson('/api/registry/import/batches/'.$l->json('batch.id').'/commit')->assertOk();
        DB::table('gp_links')->insert(['school_id' => 1, 'guardian_id' => $gid, 'student_id' => $sid, 'token_hash' => hash('sha256', 'pu1'), 'purpose' => 'invite', 'expires_at' => now(), 'used_at' => now(), 'created_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $off = $this->upload('student_guardians', 'l.csv', "student_id,guardian_id,relationship,can_pick_up,pickup_note\n$code,G-PU1,mother,False,ห้ามรับ\n")->assertCreated();
        $this->postJson('/api/registry/import/batches/'.$off->json('batch.id').'/commit')->assertOk();
        $this->assertFalse((bool) DB::table('reg_student_guardians')->where('student_id', $sid)->where('guardian_id', $gid)->value('can_pick_up'));
        $this->assertNotNull(DB::table('gp_links')->where('student_id', $sid)->where('guardian_id', $gid)->value('revoked_at'));
    }

    public function test_import_staging_rows_are_purged_after_the_windows(): void
    {
        $now = now()->toDateTimeString();
        $old = now()->subDays(40)->toDateTimeString();
        $stale = now()->subDays(10)->toDateTimeString();
        $mk = fn (string $status, string $at) => (int) DB::table('reg_import_batches')->insertGetId(['school_id' => 1, 'kind' => 'students', 'filename' => 'x.csv', 'status' => $status, 'created_at' => $at, 'updated_at' => $at]);
        $row = fn (int $b) => DB::table('reg_import_rows')->insert(['batch_id' => $b, 'row_no' => 1, 'data' => '{"first_name":"A"}', 'before' => '{"a":1}', 'after' => '{"a":2}', 'action' => 'create']);
        $freshPreview = $mk('preview', $now); $stalePreview = $mk('preview', $stale); $oldCommitted = $mk('committed', $old); $newCommitted = $mk('committed', $now);
        foreach ([$freshPreview, $stalePreview, $oldCommitted, $newCommitted] as $b) $row($b);
        $dry = \Saan\Modules\Registry\ImportService::purge(7, 30, true);
        $this->assertSame(2, $dry['rows_wiped']);
        $this->assertSame(4, DB::table('reg_import_rows')->count());
        \Saan\Modules\Registry\ImportService::purge(7, 30);
        $this->assertSame(0, DB::table('reg_import_rows')->where('batch_id', $stalePreview)->count());
        $this->assertSame('expired', DB::table('reg_import_batches')->where('id', $stalePreview)->value('status'));
        $this->assertSame('archived', DB::table('reg_import_batches')->where('id', $oldCommitted)->value('status'));
        $this->assertNull(DB::table('reg_import_rows')->where('batch_id', $oldCommitted)->value('data'));
        $this->assertNotNull(DB::table('reg_import_rows')->where('batch_id', $freshPreview)->value('data'));
        $this->assertNotNull(DB::table('reg_import_rows')->where('batch_id', $newCommitted)->value('data'));
        $this->postJson('/api/registry/import/batches/'.$oldCommitted.'/rollback')->assertUnprocessable();
        $this->artisan('saan:registry-prune', ['--dry-run' => true])->assertSuccessful();
    }

    public function test_identifier_dedupe_survives_app_key_rotation(): void
    {
        $sid = DB::table('students')->where('classroom_id', 1)->value('id');
        $other = DB::table('students')->where('classroom_id', 1)->where('id', '!=', $sid)->value('id');
        $id = $this->thaiId('110170000000');
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $sid, 'kind' => 'thai_id', 'value' => $id])->assertCreated();
        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $other, 'kind' => 'thai_id', 'value' => $id])->assertUnprocessable();
        // with a dedicated hash key rotation does not matter at all
        config(['saan.hash_key' => 'dedicated-secret']);
        $this->assertSame(Identity::hash('abc'), Identity::hash('abc', 'dedicated-secret'));
        // ... but hashes made earlier with the app key (and the previous one) must still be found after the dedicated key is introduced
        $this->assertSame(Identity::hash('abc', 'dedicated-secret'), Identity::hashes('abc')[0]);
        $this->assertContains(Identity::hash('abc', config('app.key')), Identity::hashes('abc'));
        $this->assertContains(Identity::hash('abc', $old), Identity::hashes('abc'));
        $this->postJson('/api/registry/identifiers', ['person_type' => 'student', 'person_id' => $other, 'kind' => 'thai_id', 'value' => $id])->assertUnprocessable();
    }
}