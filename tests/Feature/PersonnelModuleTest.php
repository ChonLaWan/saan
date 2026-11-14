<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Personnel\Console\RetentionCommand;
use Saan\Modules\Personnel\Support;
use Tests\TestCase;

class PersonnelModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $diskRoot = '';
    private string $publicRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        // own disk roots: other test classes call Storage::fake() and would wipe a shared directory mid-test
        foreach (['diskRoot' => 'local', 'publicRoot' => 'public'] as $prop => $disk) {
            $this->$prop = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saan-personnel-'.$disk.'-'.bin2hex(random_bytes(6));
            mkdir($this->$prop, 0700, true);
            config(['filesystems.disks.'.$disk.'.root' => $this->$prop]);
            Storage::purge($disk);
        }
        $this->as('teacher');
    }

    protected function tearDown(): void
    {
        Storage::purge('local'); Storage::purge('public');
        foreach ([$this->diskRoot, $this->publicRoot] as $dir) if ($dir !== '' && is_dir($dir)) (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
        parent::tearDown();
    }

    private function as(string $role): static { return $this->actingAs(User::where('role', $role)->first()); }

    /** A second teacher account linked to teachers.id = 2. */
    private function asTeacherTwo(): static
    {
        $u = User::where('email', 'teacher2@demo.school.test')->first();
        if (!$u) {
            $row = (array) DB::table('users')->where('role', 'teacher')->first();
            unset($row['id']);
            DB::table('users')->insert(array_merge($row, ['email' => 'teacher2@demo.school.test', 'name' => 'ครูทดสอบสอง', 'teacher_id' => 2]));
            $u = User::where('email', 'teacher2@demo.school.test')->first();
        }
        return $this->actingAs($u);
    }

    private function pdf(string $seed = 'a', int $kb = 0, string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% fictional ".$seed."\n".str_repeat('0', $kb * 1024)."\n%%EOF");
    }

    private function office(string $type, string $seed = 'x'): UploadedFile
    {
        $entry = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$type];
        $tmp = tempnam(sys_get_temp_dir(), 'per');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString($entry, '<x>'.$seed.'</x>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp); unlink($tmp);
        return UploadedFile::fake()->createWithContent('plan.'.$type, $bytes);
    }

    private function folderId(string $code): int
    {
        return (int) collect($this->getJson('/api/personnel/folders')->assertOk()->json('data'))->firstWhere('code', $code)['id'];
    }

    private function store(UploadedFile $file, string $code = 'lesson-plan', int $year = 2569)
    {
        return $this->post('/api/personnel/files', ['folder_id' => $this->folderId($code), 'year' => $year, 'file' => $file], ['Accept' => 'application/json']);
    }

    private function profile(int $teacherId, array $over = [])
    {
        return $this->putJson('/api/personnel/staff/'.$teacherId, $over + ['position' => 'ครู', 'department' => 'คณิตศาสตร์']);
    }

    // ---------- folders ----------

    public function test_thirteen_default_folders_are_seeded_and_editable(): void
    {
        $list = $this->getJson('/api/personnel/folders')->assertOk()->json('data');
        $this->assertCount(13, $list);
        $this->assertContains('แผนการจัดการเรียนรู้', array_column($list, 'label'));
        $this->assertContains('PA ข้อตกลงในการพัฒนางาน', array_column($list, 'label'));
        $this->assertSame(13, DB::table('per_folders')->where('school_id', 1)->count());

        $this->as('admin');
        $plan = collect($list)->firstWhere('code', 'lesson-plan');
        $this->putJson('/api/personnel/folders/'.$plan['id'], ['version' => $plan['version'], 'max_file_mb' => 80, 'retention_years' => 4])->assertOk()->assertJsonPath('max_file_mb', 80)->assertJsonPath('version', 2);
        $this->putJson('/api/personnel/folders/'.$plan['id'], ['version' => 1, 'max_file_mb' => 90])->assertStatus(409)->assertJsonPath('current.max_file_mb', 80);
        // label uniqueness ignores case and repeated spaces
        $this->postJson('/api/personnel/folders', ['label' => '  แผนการจัดการเรียนรู้ '])->assertStatus(409);
        $this->postJson('/api/personnel/folders', ['label' => 'Robotics  Club'])->assertCreated();
        $this->postJson('/api/personnel/folders', ['label' => 'robotics club'])->assertStatus(409);
        $this->putJson('/api/personnel/folders/'.$plan['id'], ['version' => 2, 'label' => 'ROBOTICS CLUB'])->assertStatus(409);
        // teachers cannot edit folders
        $this->as('teacher')->postJson('/api/personnel/folders', ['label' => 'ใหม่'])->assertForbidden();
    }

    public function test_folder_with_files_cannot_be_deleted(): void
    {
        $this->store($this->pdf('a'))->assertCreated();
        $this->as('admin');
        $f = collect($this->getJson('/api/personnel/folders')->json('data'))->firstWhere('code', 'lesson-plan');
        $this->deleteJson('/api/personnel/folders/'.$f['id'], ['version' => $f['version']])->assertStatus(409);
        $empty = collect($this->getJson('/api/personnel/folders')->json('data'))->firstWhere('code', 'other');
        $this->deleteJson('/api/personnel/folders/'.$empty['id'], ['version' => $empty['version']])->assertOk();
    }

    public function test_unique_violation_becomes_409_not_500(): void
    {
        $this->getJson('/api/personnel/folders')->assertOk();
        $dup = fn () => DB::table('per_folders')->insert(['school_id' => 1, 'code' => 'zz', 'label' => 'zz', 'label_key' => 'plan-key', 'version' => 1]);
        $dup();
        try { Support::unique($dup, 'ซ้ำ'); $this->fail('expected 409'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
    }

    // ---------- upload ----------

    public function test_upload_is_private_and_never_under_public_storage(): void
    {
        $res = $this->store($this->pdf('private', 1))->assertCreated();
        $id = $res->json('id');
        $res->assertJsonMissingPath('path')->assertJsonMissingPath('sha256');
        $row = DB::table('per_files')->find($id);
        $this->assertStringStartsWith('personnel/1/1/', $row->path);
        $this->assertTrue(Storage::disk('local')->exists($row->path));
        $this->assertFalse(Storage::disk('public')->exists($row->path));
        $this->assertFileExists($this->diskRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $row->path));
        $this->assertFileDoesNotExist(public_path('storage/'.$row->path));
        $this->assertSame(1, (int) DB::table('audit_events')->where('action', 'personnel.file.uploaded')->count());
        $this->assertSame(hash('sha256', Storage::disk('local')->get($row->path)), $row->sha256);

        $dl = $this->get('/api/personnel/files/'.$id.'/download')->assertOk();
        $this->assertSame('application/pdf', $dl->headers->get('Content-Type'));
        $this->assertSame('nosniff', $dl->headers->get('X-Content-Type-Options'));
        $this->assertSame("sandbox; default-src 'none'", $dl->headers->get('Content-Security-Policy'));
        // an anonymous visitor reaches neither the API nor the bytes
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/personnel/files/'.$id.'/download')->assertUnauthorized();
    }

    public function test_all_supported_types_accepted_and_disguised_files_refused(): void
    {
        $this->store($this->office('docx', '1'))->assertCreated()->assertJsonPath('mime', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->store($this->office('xlsx', '2'))->assertCreated();
        $this->store($this->office('pptx', '3'))->assertCreated();
        $this->store(UploadedFile::fake()->createWithContent('a.jpg', "\xFF\xD8\xFF\xE0".str_repeat('1', 64)))->assertCreated()->assertJsonPath('mime', 'image/jpeg');
        $this->store(UploadedFile::fake()->createWithContent('a.png', "\x89PNG\r\n\x1A\n".str_repeat('2', 64)))->assertCreated();
        $this->store(UploadedFile::fake()->createWithContent('a.mp4', "\0\0\0\x18ftypmp42".str_repeat('3', 64)))->assertCreated()->assertJsonPath('mime', 'video/mp4');

        // extension says pdf, bytes say otherwise
        $this->store(UploadedFile::fake()->createWithContent('evil.pdf', "MZ\x90\x00 not a pdf"))->assertStatus(422);
        $this->store(UploadedFile::fake()->createWithContent('note.txt', 'plain text'))->assertStatus(422);
        $this->store(UploadedFile::fake()->createWithContent('page.php', '<?php echo 1;'))->assertStatus(422);
        // a zip that is not an office document
        $tmp = tempnam(sys_get_temp_dir(), 'per'); $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::OVERWRITE); $z->addFromString('a.txt', 'x'); $z->close();
        $this->store(UploadedFile::fake()->createWithContent('a.docx', (string) file_get_contents($tmp)))->assertStatus(422); unlink($tmp);
        $this->store(UploadedFile::fake()->createWithContent('empty.pdf', ''))->assertStatus(422);
        $this->assertSame(6, DB::table('per_files')->count());
        $this->assertCount(6, Storage::disk('local')->allFiles('personnel'));
    }

    public function test_folder_max_size_is_enforced_with_a_helpful_message(): void
    {
        $this->as('admin');
        $f = collect($this->getJson('/api/personnel/folders')->json('data'))->firstWhere('code', 'lesson-plan');
        $this->putJson('/api/personnel/folders/'.$f['id'], ['version' => $f['version'], 'max_file_mb' => 1])->assertOk();
        $this->as('teacher');
        $res = $this->store($this->pdf('big', 1100))->assertStatus(422);
        $this->assertStringContainsString('1 MB', $res->json('message'));
        $this->assertStringContainsString('แบ่งเป็นหลายไฟล์', $res->json('message'));
        $this->store($this->pdf('ok', 900))->assertCreated();
        // another folder keeps its own limit
        $this->store($this->pdf('big2', 1100), 'student-work')->assertCreated();
        $this->assertSame(1, DB::table('per_files')->where('folder_id', $f['id'])->count());
    }

    public function test_duplicate_sha_is_detected_per_teacher_only(): void
    {
        $first = $this->store($this->pdf('same'), 'lesson-plan')->assertCreated()->json();
        $this->store($this->pdf('same', 0, 'renamed.pdf'), 'student-work', 2570)->assertStatus(409);
        $this->store($this->pdf('same'), 'lesson-plan')->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'อยู่ในแฟ้มของคุณแล้ว'));
        $this->assertSame(1, DB::table('per_files')->count());
        // another teacher may store the very same bytes
        $this->asTeacherTwo()->store($this->pdf('same'))->assertCreated();
        // after deleting, the same bytes can come back
        $this->as('teacher')->deleteJson('/api/personnel/files/'.$first['id'], ['version' => $first['version']])->assertOk();
        $this->store($this->pdf('same'))->assertCreated();
        // the database guards the race too
        $row = DB::table('per_files')->where('teacher_id', 1)->where('status', 'active')->first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('per_files')->insert(['school_id' => 1, 'teacher_id' => 1, 'folder_id' => $row->folder_id, 'fiscal_or_school_year' => 2569, 'original_name' => 'x.pdf', 'path' => 'x', 'mime' => 'application/pdf',
            'size' => 1, 'sha256' => $row->sha256, 'dedupe_key' => $row->sha256]);
    }

    public function test_per_teacher_total_quota_and_usage_bar(): void
    {
        Settings::put('personnel.teacher_quota_mb', 1);
        $this->store($this->pdf('q1', 600))->assertCreated();
        $res = $this->store($this->pdf('q2', 600))->assertStatus(422);
        $this->assertStringContainsString('พื้นที่แฟ้มสะสมงานของคุณเต็ม', $res->json('message'));
        // the quota is per teacher: teacher two still has room
        $this->asTeacherTwo()->store($this->pdf('q3', 600))->assertCreated();

        $this->as('teacher');
        $me = $this->getJson('/api/personnel/me')->assertOk()->json();
        $this->assertSame(1048576, $me['usage']['quota_bytes']);
        $this->assertGreaterThan(500000, $me['usage']['used_bytes']);
        $this->assertGreaterThan(50, $me['usage']['percent']);
        $this->assertCount(13, $me['usage']['folders']);
        // deleting releases the room
        $file = $this->getJson('/api/personnel/files')->json('data.0');
        $this->deleteJson('/api/personnel/files/'.$file['id'], ['version' => $file['version']])->assertOk();
        $this->assertSame(0, $this->getJson('/api/personnel/me')->json('usage.used_bytes'));
        $this->store($this->pdf('q4', 600))->assertCreated();
        // 0 means unlimited
        Settings::put('personnel.teacher_quota_mb', 0);
        $this->store($this->pdf('q5', 600))->assertCreated();
    }

    public function test_per_folder_teacher_quota_and_school_quota(): void
    {
        $this->as('admin');
        $f = collect($this->getJson('/api/personnel/folders')->json('data'))->firstWhere('code', 'innovation-media');
        $this->putJson('/api/personnel/folders/'.$f['id'], ['version' => $f['version'], 'quota_mb_per_teacher' => 1])->assertOk();
        $this->as('teacher');
        $this->store($this->pdf('m1', 700), 'innovation-media')->assertCreated();
        $this->store($this->pdf('m2', 700), 'innovation-media')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'เต็ม'));
        $this->store($this->pdf('m3', 700), 'student-work')->assertCreated();
        // the school-wide cap (StorageQuota) counts portfolio bytes too
        config(['saan.storage.quota_mb' => 1]);
        $this->store($this->pdf('m4', 400), 'student-work')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'พื้นที่ไฟล์ของโรงเรียนเต็ม'));
    }

    public function test_year_and_folder_are_validated(): void
    {
        $fid = $this->folderId('lesson-plan');
        $this->post('/api/personnel/files', ['folder_id' => $fid, 'year' => 1999, 'file' => $this->pdf('y')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/personnel/files', ['folder_id' => 99999, 'year' => 2569, 'file' => $this->pdf('y')], ['Accept' => 'application/json'])->assertNotFound();
        $this->post('/api/personnel/files', ['folder_id' => $fid, 'year' => 2569], ['Accept' => 'application/json'])->assertStatus(422);
        // original name is stored sanitised, never as a path
        $res = $this->store($this->pdf('n', 0, '../../etc/แผน 1.pdf'))->assertCreated();
        $this->assertSame('แผน 1.pdf', $res->json('original_name'));
    }

    // ---------- roles and scoping ----------

    public function test_teacher_sees_only_own_files_and_admin_sees_all(): void
    {
        $mine = $this->store($this->pdf('mine'))->assertCreated()->json();
        $this->asTeacherTwo();
        $theirs = $this->store($this->pdf('theirs'))->assertCreated()->json();
        $this->assertSame(2, $theirs['teacher_id']);

        $this->getJson('/api/personnel/files')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $theirs['id']);
        $this->getJson('/api/personnel/files?teacher_id=1')->assertNotFound();
        $this->get('/api/personnel/files/'.$mine['id'].'/download')->assertNotFound();
        $this->deleteJson('/api/personnel/files/'.$mine['id'], ['version' => 1])->assertNotFound();
        $this->getJson('/api/personnel/teachers/1/zip?year=2569')->assertNotFound();
        $this->getJson('/api/personnel/me?teacher_id=1')->assertOk()->assertJsonPath('teacher.id', 2); // ignored for non-reviewers
        $this->getJson('/api/personnel/staff')->assertForbidden();
        $this->putJson('/api/personnel/files/'.$theirs['id'].'/review', ['version' => 1, 'review_status' => 'approved'])->assertForbidden();
        $this->getJson('/api/personnel/files?status=deleted')->assertNotFound();

        $this->as('admin');
        $this->getJson('/api/personnel/files')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/personnel/files?teacher_id=2')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/personnel/files/'.$mine['id'].'/download')->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'personnel.file.downloaded')->count()); // a reviewer's access to someone else's file is audited
        $this->as('principal')->getJson('/api/personnel/files')->assertOk()->assertJsonPath('meta.total', 2);
        // admin cannot upload: no teacher record
        $this->as('admin')->store($this->pdf('adm'))->assertForbidden();
    }

    public function test_other_roles_and_unlinked_teachers_are_refused(): void
    {
        $row = (array) DB::table('users')->where('role', 'teacher')->first();
        unset($row['id']);
        DB::table('users')->insert(array_merge($row, ['email' => 'editor@demo.school.test', 'role' => 'editor', 'teacher_id' => null]));
        $this->actingAs(User::where('role', 'editor')->first());
        $this->getJson('/api/personnel/me')->assertForbidden();
        $this->getJson('/api/personnel/files')->assertForbidden();
        $this->getJson('/api/personnel/staff')->assertForbidden();

        DB::table('users')->where('role', 'teacher')->update(['teacher_id' => null]);
        $this->as('teacher');
        $this->getJson('/api/personnel/files')->assertForbidden();
        $this->store($this->pdf('u'))->assertForbidden();
        $this->getJson('/api/personnel/me')->assertOk()->assertJsonPath('teacher', null);
    }

    public function test_school_scoping_hides_rows_of_another_school(): void
    {
        $this->store($this->pdf('s1'))->assertCreated();
        $now = now();
        DB::table('per_folders')->insert(['school_id' => 2, 'code' => 'other', 'label' => 'x', 'label_key' => 'x', 'max_file_mb' => 5, 'sort' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $fid = (int) DB::table('per_folders')->where('school_id', 2)->value('id');
        $foreign = DB::table('per_files')->insertGetId(['school_id' => 2, 'teacher_id' => 1, 'folder_id' => $fid, 'fiscal_or_school_year' => 2569, 'original_name' => 'foreign.pdf', 'path' => 'personnel/2/1/f.pdf',
            'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64), 'dedupe_key' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now]);
        Storage::disk('local')->put('personnel/2/1/f.pdf', '%PDF-foreign');
        DB::table('teachers')->insert(['id' => 90, 'school_id' => 2, 'name' => 'ครูโรงเรียนอื่น', 'subject' => 'x']);
        DB::table('per_profiles')->insert(['school_id' => 2, 'teacher_id' => 90, 'position' => 'ครู', 'show_in_directory' => true, 'directory_order' => 0, 'version' => 1]);

        $this->as('admin');
        $this->getJson('/api/personnel/files')->assertJsonPath('meta.total', 1);
        $this->get('/api/personnel/files/'.$foreign.'/download')->assertNotFound();
        $this->putJson('/api/personnel/files/'.$foreign.'/review', ['version' => 1, 'review_status' => 'approved'])->assertNotFound();
        $this->getJson('/api/personnel/staff/90')->assertNotFound();
        $this->getJson('/api/personnel/folders')->assertJsonCount(13, 'data');
        $names = array_column($this->getJson('/api/personnel/directory')->json('data'), 'name');
        $this->assertNotContains('ครูโรงเรียนอื่น', $names);
    }

    // ---------- review ----------

    public function test_review_flow_with_notes_versions_and_conflicts(): void
    {
        $file = $this->store($this->pdf('pa'), 'pa-agreement')->assertCreated()->json();
        $this->assertSame('pending', $file['review_status']);

        $this->as('admin');
        $this->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 1, 'review_status' => 'revise'])->assertStatus(422); // a note is required
        $this->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 1, 'review_status' => 'bogus'])->assertStatus(422);
        $this->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 1, 'review_status' => 'revise', 'review_note' => 'โปรดแนบตัวชี้วัดข้อ 2'])
            ->assertOk()->assertJsonPath('review_status', 'revise')->assertJsonPath('version', 2)->assertJsonPath('reviewed_by', fn ($n) => $n !== null);
        // a stale version is a 409 with the current row, never a silent overwrite, and never leaks the path
        $this->as('principal')->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 1, 'review_status' => 'approved'])
            ->assertStatus(409)->assertJsonPath('current.review_status', 'revise')->assertJsonMissingPath('current.path')->assertJsonMissingPath('current.sha256');

        // the teacher sees the comment
        $seen = $this->as('teacher')->getJson('/api/personnel/files')->assertOk()->json('data.0');
        $this->assertSame('โปรดแนบตัวชี้วัดข้อ 2', $seen['review_note']);
        $this->assertSame('revise', $seen['review_status']);
        $this->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 2, 'review_status' => 'approved'])->assertForbidden();

        $this->as('principal')->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 2, 'review_status' => 'approved', 'review_note' => 'ผ่าน'])->assertOk()->assertJsonPath('review_status', 'approved');
        $this->assertSame(2, DB::table('audit_events')->where('action', 'personnel.file.reviewed')->count());
        // approved files cannot be deleted by the owner
        $this->as('teacher')->deleteJson('/api/personnel/files/'.$file['id'], ['version' => 3])->assertStatus(422);
        // reset to pending clears the reviewer
        $this->as('admin')->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 3, 'review_status' => 'pending'])->assertOk()->assertJsonPath('reviewed_by', null);
        $this->getJson('/api/personnel/files?review_status=pending')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/personnel/staff')->assertOk()->assertJsonPath('data.0.pending_review', 1);
    }

    public function test_a_reviewer_cannot_review_own_portfolio(): void
    {
        $principal = User::where('role', 'principal')->first();
        $this->assertSame(6, (int) $principal->teacher_id);
        $this->actingAs($principal);
        $file = $this->store($this->pdf('own-principal'))->assertCreated()->json();
        $this->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 1, 'review_status' => 'approved'])->assertForbidden();
        $this->as('admin')->putJson('/api/personnel/files/'.$file['id'].'/review', ['version' => 1, 'review_status' => 'approved'])->assertOk();
    }

    // ---------- zip ----------

    public function test_zip_of_a_teacher_year_is_streamed_and_complete(): void
    {
        $a = $this->store($this->pdf('zip-a', 2, 'แผน.pdf'), 'lesson-plan', 2569)->assertCreated()->json();
        $this->store($this->pdf('zip-b', 1, 'แผน.pdf'), 'lesson-plan', 2569)->assertCreated(); // same name, different bytes
        $this->store($this->office('docx'), 'pa-agreement', 2569)->assertCreated();
        $this->store($this->pdf('other-year'), 'lesson-plan', 2568)->assertCreated();
        $before = glob(public_path('*.zip'));

        $this->as('admin');
        $res = $this->get('/api/personnel/teachers/1/zip?year=2569')->assertOk();
        $this->assertSame('application/zip', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('portfolio-1-2569.zip', (string) $res->headers->get('Content-Disposition'));
        $bytes = $res->streamedContent();
        $tmp = tempnam(sys_get_temp_dir(), 'zipcheck'); file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'archive opens');
        $this->assertSame(3, $zip->numFiles);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        $this->assertContains('แผนการจัดการเรียนรู้/แผน.pdf', $names);
        $this->assertContains('แผนการจัดการเรียนรู้/แผน-'.($a['id'] + 1).'.pdf', $names);
        $this->assertSame(file_get_contents($this->diskRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, DB::table('per_files')->find($a['id'])->path)), $zip->getFromName('แผนการจัดการเรียนรู้/แผน.pdf'));
        $this->assertSame(0, $zip->statName($names[0])['comp_method']);
        $zip->close(); unlink($tmp);
        $this->assertSame($before, glob(public_path('*.zip')), 'no zip left in public');

        $this->getJson('/api/personnel/teachers/1/zip?year=2571')->assertNotFound();
        $this->getJson('/api/personnel/teachers/1/zip')->assertStatus(422);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'personnel.zip.downloaded')->count());
        // the owner may fetch the own archive
        $this->as('teacher')->get('/api/personnel/teachers/1/zip?year=2569&folder_id='.$this->folderId('pa-agreement'))->assertOk();
    }

    // ---------- retention ----------

    private function registerRetention(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new RetentionCommand());
    }

    public function test_retention_dry_run_then_apply_archives_without_deleting(): void
    {
        $this->registerRetention();
        $old = $this->store($this->pdf('old', 3), 'lesson-plan')->assertCreated()->json();        // retention 5 years
        $new = $this->store($this->pdf('new', 3), 'lesson-plan')->assertCreated()->json();
        $keep = $this->store($this->pdf('keep', 3), 'pa-agreement')->assertCreated()->json();    // no retention: never archived
        $plan = $this->folderId('lesson-plan');
        $be = (int) now()->year + 543;
        DB::table('per_files')->whereIn('id', [$old['id'], $keep['id']])->update(['created_at' => now()->subYears(7), 'fiscal_or_school_year' => $be - 7]);
        DB::table('per_files')->where('id', $new['id'])->update(['created_at' => now()->subYears(4), 'fiscal_or_school_year' => $be - 4]);
        $oldRow = DB::table('per_files')->find($old['id']);
        $bytes = (int) $oldRow->size;

        $this->artisan('saan:personnel-retention')->assertExitCode(2); // neither flag
        $this->artisan('saan:personnel-retention', ['--dry-run' => true, '--apply' => true])->assertExitCode(2);
        $this->artisan('saan:personnel-retention', ['--dry-run' => true])->expectsOutputToContain('1 ไฟล์ '.$bytes.' bytes')->assertExitCode(0);
        $this->assertSame('active', DB::table('per_files')->find($old['id'])->status);
        $this->assertTrue(Storage::disk('local')->exists($oldRow->path));

        $this->artisan('saan:personnel-retention', ['--apply' => true])->expectsOutputToContain('1 ไฟล์ '.$bytes.' bytes')->assertExitCode(0);
        $arch = DB::table('per_files')->find($old['id']);
        $this->assertSame('archived', $arch->status);
        $this->assertNotNull($arch->archived_at);
        $this->assertSame(2, (int) $arch->version);
        $this->assertStringStartsWith('personnel/1/_archive/1/'.$plan.'/', $arch->path);
        $this->assertTrue(Storage::disk('local')->exists($arch->path));
        $this->assertFalse(Storage::disk('local')->exists($oldRow->path));
        $this->assertSame('active', DB::table('per_files')->find($new['id'])->status);
        $this->assertSame('active', DB::table('per_files')->find($keep['id'])->status);
        $this->assertSame(3, DB::table('per_files')->count()); // rows kept

        // idempotent
        $this->artisan('saan:personnel-retention', ['--apply' => true])->expectsOutputToContain('0 ไฟล์ 0 bytes')->assertExitCode(0);
        // archived bytes leave the teacher's quota but stay downloadable and visible
        $this->as('teacher');
        $usage = $this->getJson('/api/personnel/me')->json('usage.used_bytes');
        $this->assertSame((int) DB::table('per_files')->where('status', 'active')->sum('size'), $usage);
        $this->getJson('/api/personnel/files?status=archived')->assertJsonPath('meta.total', 1);
        $this->get('/api/personnel/files/'.$old['id'].'/download')->assertOk();
        $this->assertCount(3, Storage::disk('local')->allFiles('personnel'));
    }

    public function test_retention_reports_a_missing_source_file_and_does_not_lie(): void
    {
        $this->registerRetention();
        $old = $this->store($this->pdf('gone'))->assertCreated()->json();
        $row = DB::table('per_files')->find($old['id']);
        DB::table('per_files')->where('id', $old['id'])->update(['created_at' => now()->subYears(9), 'fiscal_or_school_year' => (int) now()->year + 543 - 9]);
        Storage::disk('local')->delete($row->path);
        $this->artisan('saan:personnel-retention', ['--apply' => true])->assertExitCode(1);
        $this->assertSame('active', DB::table('per_files')->find($old['id'])->status);
    }

    public function test_retention_uses_the_document_year_not_the_upload_time(): void
    {
        $this->registerRetention();
        $hist = $this->store($this->pdf('hist'), 'lesson-plan', 2560)->assertCreated()->json();   // uploaded today, document year 2560 (5-year folder)
        $fresh = $this->store($this->pdf('fresh'), 'lesson-plan', (int) now()->year + 543)->assertCreated()->json();
        $this->artisan('saan:personnel-retention', ['--apply' => true])->assertExitCode(0); // inside the one-month grace period
        $this->assertSame('active', DB::table('per_files')->find($hist['id'])->status);
        DB::table('per_files')->whereIn('id', [$hist['id'], $fresh['id']])->update(['created_at' => now()->subMonths(2)]);
        $this->artisan('saan:personnel-retention', ['--dry-run' => true])->expectsOutputToContain('1 ไฟล์')->assertExitCode(0);
        $this->artisan('saan:personnel-retention', ['--apply' => true])->assertExitCode(0);
        $this->assertSame('archived', DB::table('per_files')->find($hist['id'])->status);
        $this->assertSame('active', DB::table('per_files')->find($fresh['id'])->status);

        // the zip of that year still carries archived files
        $this->as('admin');
        $zipBytes = $this->get('/api/personnel/teachers/1/zip?year=2560')->assertOk()->streamedContent();
        $tmp = tempnam(sys_get_temp_dir(), 'zipcheck'); file_put_contents($tmp, $zipBytes);
        $zip = new \ZipArchive(); $this->assertTrue($zip->open($tmp) === true); $this->assertSame(1, $zip->numFiles); $zip->close(); unlink($tmp);
    }

    public function test_upload_replay_with_same_idempotency_key_stores_one_file(): void
    {
        $h = ['Accept' => 'application/json', 'Idempotency-Key' => 'per-replay-key-0001'];
        $data = fn () => ['folder_id' => $this->folderId('lesson-plan'), 'year' => 2569, 'file' => $this->pdf('idem')];
        $a = $this->post('/api/personnel/files', $data(), $h)->assertCreated()->json();
        $b = $this->post('/api/personnel/files', $data(), $h);
        $this->assertSame($a['id'], $b->json('id'));
        $this->assertSame(1, DB::table('per_files')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('personnel'));
    }

    public function test_profile_insert_race_is_409_not_500(): void
    {
        $this->as('admin');
        DB::table('per_profiles')->insert(['school_id' => 1, 'teacher_id' => 2, 'position' => 'เดิม', 'show_in_directory' => true, 'directory_order' => 0, 'version' => 1]);
        $this->putJson('/api/personnel/staff/2', ['position' => 'ใหม่'])->assertStatus(422); // existing row: version required
        DB::table('per_profiles')->where('teacher_id', 2)->delete();
        // the row appears between the existence check and the insert
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\QueryExecuted::class, function ($q) {
            static $done = false;
            if (!$done && str_contains(str_replace(['"', '`'], '', $q->sql), 'from per_profiles') && str_contains($q->sql, 'limit 1')) {
                $done = true;
                DB::table('per_profiles')->insert(['school_id' => 1, 'teacher_id' => 2, 'position' => 'แทรก', 'show_in_directory' => true, 'directory_order' => 0, 'version' => 1]);
            }
        });
        $this->putJson('/api/personnel/staff/2', ['position' => 'ใหม่'])->assertStatus(409);
    }

    public function test_folders_are_not_reseeded_after_an_admin_deleted_them_all(): void
    {
        $this->as('admin');
        foreach ($this->getJson('/api/personnel/folders')->json('data') as $f) $this->deleteJson('/api/personnel/folders/'.$f['id'], ['version' => $f['version']])->assertOk();
        $this->getJson('/api/personnel/folders')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, DB::table('per_folders')->where('school_id', 1)->count());
    }

    // ---------- profiles, photo and the public directory ----------

    public function test_profile_crud_with_versions(): void
    {
        $this->as('admin');
        $res = $this->profile(2, ['position' => 'ครูชำนาญการ', 'academic_standing' => 'ชำนาญการ', 'work_group' => 'กลุ่มงานวิชาการ', 'start_date' => '2015-05-16',
            'education' => [['level' => 'ปริญญาตรี', 'degree' => 'ค.บ.', 'major' => 'คณิตศาสตร์', 'institution' => 'มหาวิทยาลัยสมมติ', 'year' => 2558]]])->assertOk();
        $res->assertJsonPath('version', 1)->assertJsonPath('education.0.degree', 'ค.บ.');
        $this->profile(2, ['position' => 'แก้', 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->profile(2, ['position' => 'ซ้ำ', 'version' => 1])->assertStatus(409)->assertJsonPath('current.position', 'แก้')->assertJsonMissingPath('current.photo_path');
        $this->profile(2, ['position' => 'ไม่มี version'])->assertStatus(422);
        $this->profile(999)->assertNotFound();
        $this->profile(2, ['version' => 2, 'start_date' => '2999-01-01'])->assertStatus(422);
        $this->assertSame(1, DB::table('per_profiles')->where('teacher_id', 2)->count());
        $this->getJson('/api/personnel/staff?q=แก้')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/personnel/staff/2')->assertOk()->assertJsonPath('profile.position', 'แก้')->assertJsonPath('usage.teacher_id', 2);
        $this->as('teacher')->putJson('/api/personnel/staff/2', ['position' => 'x'])->assertForbidden();
    }

    private function png(int $w = 200, int $h = 120): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 90));
        ob_start(); imagepng($im); $bytes = (string) ob_get_clean();
        return UploadedFile::fake()->createWithContent('face.png', $bytes);
    }

        public function test_photo_is_served_by_an_app_route_from_the_private_disk_only(): void
    {
        $this->as('admin');
        $this->post('/api/personnel/staff/2/photo', ['version' => 1, 'photo' => $this->png()], ['Accept' => 'application/json'])->assertStatus(422); // no profile yet
        $p = $this->profile(2)->assertOk()->json();
        $res = $this->post('/api/personnel/staff/2/photo', ['version' => $p['version'], 'photo' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $url = $res->json('photo_url');
        $this->assertNotNull($url);
        $path = DB::table('per_profiles')->where('teacher_id', 2)->value('photo_path');
        $this->assertStringStartsWith('personnel/1/', $path);
        $this->assertStringEndsWith('/portal/staff-photo/'.basename($path), $url);
        // nothing is published on the public disk (no public/storage symlink is needed); the derivative is on the private disk
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertTrue(Storage::disk('local')->exists($path));
        $info = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([IMAGETYPE_JPEG, 480, 480], [$info[2], $info[0], $info[1]]);
        $route = '/portal/staff-photo/'.basename($path);
        $img = $this->get($route)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('image/jpeg', $img->headers->get('Content-Type'));
        // a restore never needs a public copy: the route still serves from the private disk
        $this->assertNotNull($this->getJson('/api/personnel/staff/2')->json('profile.photo_url'));
        // unknown or malformed names are 404
        $this->get('/portal/staff-photo/'.str_repeat('a', 32).'.jpg')->assertNotFound();
        $this->get('/portal/staff-photo/..%2F..%2F.env')->assertNotFound();

        // anonymous visitors: visible while listed in the directory, 404 once hidden or the teacher is deactivated; admin always allowed
        // staff variant: never cached by a shared cache
        $this->assertStringContainsString('no-store', (string) $this->get($route)->headers->get('Cache-Control'));
        auth()->logout(); $this->app['auth']->forgetGuards();
        // the public derivative opts out of the blanket no-store (the marker header itself is stripped by SchoolBoundary)
        $pubImg = $this->get($route)->assertOk();
        $this->assertStringContainsString('public', (string) $pubImg->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', (string) $pubImg->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', (string) $pubImg->headers->get('Cache-Control'));
        $this->as('admin');
        $this->profile(2, ['version' => $this->getJson('/api/personnel/staff/2')->json('profile.version'), 'show_in_directory' => false])->assertOk();
        $this->get($route)->assertOk();
        auth()->logout(); $this->app['auth']->forgetGuards();
        $this->get($route)->assertNotFound();
        $this->as('admin');
        $this->profile(2, ['version' => $this->getJson('/api/personnel/staff/2')->json('profile.version'), 'show_in_directory' => true])->assertOk();
        DB::table('teachers')->where('id', 2)->update(['is_active' => false]);
        auth()->logout(); $this->app['auth']->forgetGuards();
        $this->get($route)->assertNotFound();
        $this->as('admin');
        DB::table('teachers')->where('id', 2)->update(['is_active' => true]);

        // PHP source dressed as an image, and non-images, are refused
        $v = $this->getJson('/api/personnel/staff/2')->json('profile.version');
        $this->post('/api/personnel/staff/2/photo', ['version' => $v, 'photo' => UploadedFile::fake()->createWithContent('x.png', '<?php system($_GET[0]);')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/personnel/staff/2/photo', ['version' => $v, 'photo' => $this->pdf('p')], ['Accept' => 'application/json'])->assertStatus(422);
        // a replacement removes the previous derivative
        $this->post('/api/personnel/staff/2/photo', ['version' => $v, 'photo' => $this->png(90, 200)], ['Accept' => 'application/json'])->assertOk();
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertCount(1, Storage::disk('local')->allFiles('personnel'));
        $this->assertCount(0, Storage::disk('public')->allFiles('personnel'));
        $v = $this->getJson('/api/personnel/staff/2')->json('profile.version');
        $this->deleteJson('/api/personnel/staff/2/photo', ['version' => $v])->assertOk()->assertJsonPath('photo_url', null);
        $this->assertCount(0, Storage::disk('local')->allFiles('personnel'));
    }
public function test_oversized_photo_is_422_not_a_memory_fatal(): void
    {
        $this->as('admin');
        $p = $this->profile(2)->assertOk()->json();
        // header-only PNG claiming 7000 x 5000 (35 MP): rejected from the header before GD allocates anything
        $ihdr = pack('N', 7000).pack('N', 5000)."\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
        $this->post('/api/personnel/staff/2/photo', ['version' => $p['version'], 'photo' => UploadedFile::fake()->createWithContent('big.png', $png)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '4000 x 4000'));
    }

    public function test_directory_api_exposes_only_public_safe_fields(): void
    {
        $this->as('admin');
        $this->profile(1, ['position' => 'ครูชำนาญการพิเศษ', 'academic_standing' => 'SECRET-STANDING', 'work_group' => 'กลุ่มสาระภาษาไทย', 'start_date' => '2010-01-01',
            'education' => [['degree' => 'SECRET-DEGREE']], 'directory_order' => 2])->assertOk();
        $this->profile(2, ['position' => 'ครู', 'department' => 'คณิตศาสตร์', 'directory_order' => 1])->assertOk();
        $this->profile(3, ['position' => 'ซ่อน', 'show_in_directory' => false])->assertOk();
        $this->profile(4, ['position' => 'ลาออก'])->assertOk();
        DB::table('teachers')->where('id', 4)->update(['is_active' => false]);
        $p = $this->profile(2, ['version' => 1, 'position' => 'ครู'])->json();
        $this->post('/api/personnel/staff/2/photo', ['version' => $p['version'], 'photo' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $this->store($this->pdf('priv'))->assertForbidden();
        $this->as('teacher')->store($this->pdf('priv'))->assertCreated();

        auth()->logout(); $this->app['auth']->forgetGuards();
        $res = $this->getJson('/api/personnel/directory')->assertOk();
        $rows = $res->json('data');
        // only teachers with a profile row and show_in_directory are listed: no profile, hidden and inactive ones are not
        $this->assertCount(2, $rows);
        $this->assertSame(array_column($rows, 'order'), [1, 2]);
        foreach ($rows as $r) $this->assertSame(['department', 'name', 'order', 'photo_url', 'position', 'work_group'], collect($r)->keys()->sort()->values()->all());
        $byName = collect($rows)->keyBy('name');
        $this->assertNotNull($byName[Support::teacherName(DB::table('teachers')->find(2))]['photo_url']);
        $this->assertNull($byName[Support::teacherName(DB::table('teachers')->find(1))]['photo_url']);
        $noProfile = DB::table('teachers')->where('school_id', 1)->where('is_active', true)->whereNotIn('id', [1, 2, 3])->first();
        $this->assertArrayNotHasKey(Support::teacherName($noProfile), $byName->all(), 'a teacher without a profile row is not published');
        $this->assertNotContains(Support::teacherName(DB::table('teachers')->find(3)), array_column($rows, 'name'));
        $body = $res->getContent();
        foreach (['SECRET-STANDING', 'SECRET-DEGREE', 'start_date', '2010-01-01', 'education', 'teacher_id', 'email', 'sha256', 'size', 'doc.pdf', 'ซ่อน', 'ลาออก', 'photo_path', 'academic_standing'] as $leak)
            $this->assertStringNotContainsString($leak, $body, $leak);
        // nothing at all is published on the public disk
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_directory_partial_renders_and_escapes(): void
    {
        $this->as('admin');
        $this->profile(1, ['position' => '<script>alert(1)</script>', 'work_group' => 'ภาษาไทย'])->assertOk();
        $html = view('personnel::directory')->render();
        $this->assertStringContainsString('personnel-directory', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('ภาษาไทย', $html);
        $this->assertStringContainsString('ยังไม่มีข้อมูลบุคลากร', view('personnel::directory', ['staff' => []])->render());
        // grouped by work group, one heading per group
        $this->assertStringContainsString('<h3>ภาษาไทย</h3>', $html);
        $this->assertStringNotContainsString('<h3>บุคลากร</h3>', $html); // teachers without a profile are not listed
    }

    public function test_directory_partial_is_empty_when_the_module_is_switched_off(): void
    {
        $this->as('admin');
        $this->profile(1, ['position' => 'ครู'])->assertOk();
        $reg = \Mockery::mock(\App\Support\Modules\ModuleRegistry::class)->makePartial();
        $reg->shouldReceive('active')->with('personnel')->andReturn(false);
        $this->app->instance(\App\Support\Modules\ModuleRegistry::class, $reg);
        $this->assertStringContainsString('ยังไม่มีข้อมูลบุคลากร', view('personnel::directory')->render());
    }

    // ---------- summary, settings, menu ----------

    public function test_summary_and_settings_are_for_reviewers(): void
    {
        $this->store($this->pdf('sum', 5))->assertCreated();
        $this->getJson('/api/personnel/summary')->assertForbidden();
        $this->putJson('/api/personnel/settings', ['teacher_quota_mb' => 5])->assertForbidden();
        $this->as('admin');
        $this->putJson('/api/personnel/settings', ['teacher_quota_mb' => 7])->assertOk()->assertJsonPath('teacher_quota_mb', 7);
        $this->putJson('/api/personnel/settings', ['teacher_quota_mb' => -1])->assertStatus(422);
        $s = $this->getJson('/api/personnel/summary')->assertOk()->assertJsonPath('pending_review', 1)->assertJsonPath('teacher_quota_mb', 7)->json();
        $this->assertGreaterThan(5000, $s['school']['personnel_bytes']);
        $this->assertSame(1, array_sum(array_column($s['folders'], 'active_files')));
    }

    public function test_work_menu_by_role_and_private_storage_declared(): void
    {
        $reg = $this->app->make(\App\Support\Modules\ModuleRegistry::class);
        $keys = fn (string $role) => array_column(array_filter($reg->workMenu($role), fn ($m) => $m['module'] === 'personnel'), 'key');
        $this->assertSame(['portfolio', 'personnel'], $keys('admin'));
        $this->assertSame(['portfolio', 'personnel'], $keys('principal'));
        $this->assertSame(['portfolio'], $keys('teacher'));
        $this->assertSame([], $keys('editor'));
        $this->assertSame(['personnel'], $reg->get('personnel')['private_storage']);
        $this->assertContains('assets/modules/personnel/personnel.js', $reg->workAssets());
    }
}
