<?php

namespace Tests\Feature;

use App\Domain\Cms\SiteSettings;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\StorageQuota;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Personnel\Support as PerSupport;
use Tests\TestCase;

/** Core follow-ups: school-wide StorageQuota over declared storage_tables, personnel photo backup migration, directory_source. */
class CoreFollowupTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $roots = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->first());
    }

    protected function tearDown(): void
    {
        foreach (['local', 'public'] as $disk) Storage::purge($disk);
        foreach ($this->roots as $dir) if (is_dir($dir)) (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
        \Illuminate\Support\Carbon::setTestNow();
        app(ModuleRegistry::class)->flush();
        parent::tearDown();
    }

    private function ownDisks(): void
    {
        foreach (['local', 'public'] as $disk) {
            $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saan-followup-'.$disk.'-'.bin2hex(random_bytes(6));
            mkdir($dir, 0700, true); $this->roots[] = $dir;
            config(['filesystems.disks.'.$disk.'.root' => $dir]);
            Storage::purge($disk);
        }
    }

    // ---------------------------------------------------------------- (1) StorageQuota

    public function test_modules_declare_their_storage_tables(): void
    {
        $declared = collect(StorageQuota::declared())->keyBy('table');
        foreach (['reg_documents' => 'size', 'doc_files' => 'size', 'per_files' => 'size', 'leave_requests' => 'attachment_bytes'] as $table => $col) {
            $this->assertSame($col, $declared[$table]['bytes'] ?? null, $table);
            $this->assertTrue(Schema::hasTable($table) && Schema::hasColumn($table, $col), $table.' exists');
        }
        $this->assertSame(['status' => ['active', 'archived']], $declared['per_files']['where']);
    }

    public function test_used_bytes_sums_media_and_every_declared_table_for_this_school_only(): void
    {
        $this->ddl(fn () => Schema::create('zz_quota_files', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('size'); $t->string('status', 20); }));
        $registry = new class extends ModuleRegistry {
            public function all(): array
            {
                return ['a' => ['id' => 'a', 'storage_tables' => [
                    ['table' => 'zz_quota_files', 'bytes' => 'size', 'where' => ['status' => ['active', 'archived']]],
                    ['table' => 'zz_missing_table', 'bytes' => 'size'],
                    ['table' => 'bad name; drop', 'bytes' => 'size'],
                    ['table' => 'zz_quota_files', 'bytes' => 'no_such_column'],
                ]]];
            }
        };
        $this->app->instance(ModuleRegistry::class, $registry);
        $base = StorageQuota::usedBytes(1);
        DB::table('zz_quota_files')->insert([
            ['school_id' => 1, 'size' => 1000, 'status' => 'active'], ['school_id' => 1, 'size' => 500, 'status' => 'archived'],
            ['school_id' => 1, 'size' => 9999, 'status' => 'deleted'], ['school_id' => 2, 'size' => 7777, 'status' => 'active'],
        ]);
        $this->assertSame($base + 1500, StorageQuota::usedBytes(1));
        $this->assertSame($base + 1500, StorageQuota::usedBytes()); // defaults to config('school.school_id')
        $this->assertSame(StorageQuota::usedBytes(1), StorageQuota::summary()['used_bytes']);

        // assertRoom($bytes) and the older assertRoom($school, $bytes) agree; only the NEW bytes are passed
        config(['saan.storage.quota_mb' => 1]);
        $room = 1048576 - StorageQuota::usedBytes(1);
        StorageQuota::assertRoom($room);
        StorageQuota::assertRoom(1, $room);
        foreach ([fn () => StorageQuota::assertRoom($room + 1), fn () => StorageQuota::assertRoom(1, $room + 1)] as $over) {
            try { $over(); $this->fail('over quota must be refused'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        }
        config(['saan.storage.quota_mb' => 0]);
        StorageQuota::assertRoom(PHP_INT_MAX >> 4); // 0 = unlimited
    }

    public function test_real_uploads_of_other_modules_count_against_the_school_total_once(): void
    {
        $now = now();
        $before = StorageQuota::usedBytes(1);
        $teacher = (int) DB::table('teachers')->where('school_id', 1)->value('id');
        $folder = DB::table('per_folders')->insertGetId(['school_id' => 1, 'code' => 'zz', 'label' => 'ทดสอบ', 'label_key' => 'zz', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('per_files')->insert(['school_id' => 1, 'teacher_id' => $teacher, 'folder_id' => $folder, 'fiscal_or_school_year' => 2569, 'original_name' => 'a.pdf', 'path' => 'personnel/1/a', 'mime' => 'application/pdf',
            'size' => 4000, 'sha256' => str_repeat('a', 64), 'dedupe_key' => str_repeat('a', 64), 'status' => 'active', 'review_status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
        $this->assertSame($before + 4000, StorageQuota::usedBytes(1), 'per_files counted once');
        DB::table('per_files')->update(['status' => 'deleted']);
        $this->assertSame($before, StorageQuota::usedBytes(1), 'deleted files are not counted');
    }

    public function test_each_module_upload_is_refused_when_other_modules_files_fill_the_school_quota(): void
    {
        $this->ownDisks();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-02 08:00:00', 'Asia/Bangkok'));
        config(['saan.storage.quota_mb' => 1, 'saan.storage.max_upload_mb' => 10]);
        $now = now();
        $mk = fn (string $n, int $teacher) => User::forceCreate(['name' => 'Fictional '.$n, 'email' => $n.'@school.test', 'password' => 'Original-Password-2026', 'role' => 'teacher',
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1, 'teacher_id' => $teacher]);
        $tids = DB::table('teachers')->where('school_id', 1)->orderBy('id')->pluck('id')->all();
        $ta = $mk('qa', (int) $tids[0]); $tb = $mk('qb', (int) $tids[1]);
        $admin = User::where('role', 'admin')->first();
        $as = function (User $u) { $this->app['auth']->forgetGuards(); return $this->actingAs($u); };
        $pdf = fn (string $n) => \Illuminate\Http\UploadedFile::fake()->createWithContent($n, "%PDF-1.4\n% fictional\n".str_repeat('a', 100000)."\n%%EOF");
        $accept = ['Accept' => 'application/json'];

        // objects to upload to (created before the quota is filled)
        $doc = $as($admin)->postJson('/api/documents', ['kind' => 'incoming', 'doc_no' => 'ศธ 04001/999', 'doc_date' => \Saan\Modules\Documents\Documents::today(),
            'from_org' => 'หน่วยงานสมมติ', 'subject' => 'ทดสอบโควตา'])->assertCreated()->json();
        $student = $as($admin)->postJson('/api/registry/students', ['code' => 'QT-1', 'first_name' => 'ทดสอบ', 'last_name' => 'โควตา', 'prefix' => 'ด.ช.', 'sex' => 'M', 'classroom_id' => 1])->assertCreated()->json('student');
        $leave = fn (User $u, string $day) => $as($u)->postJson('/api/leave/requests', ['type' => 'personal', 'starts_on' => $day, 'ends_on' => $day, 'reason' => 'ธุระส่วนตัว (ข้อมูลทดสอบ)'])->assertCreated()->json();
        $l1 = $leave($ta, '2026-11-09'); $l2 = $leave($tb, '2026-11-10');

        // fill: a personnel file + a leave attachment leave only 60000 bytes of room, so a 100 KB upload cannot fit
        $base = StorageQuota::usedBytes(1);
        $filler = 1048576 - $base - 60000;
        $this->assertGreaterThan(100000, $filler);
        DB::table('leave_requests')->where('id', $l1['id'])->update(['attachment_bytes' => 100000]);
        $folder = DB::table('per_folders')->insertGetId(['school_id' => 1, 'code' => 'zq', 'label' => 'โควตา', 'label_key' => 'zq', 'created_at' => $now, 'updated_at' => $now]);
        $fillId = DB::table('per_files')->insertGetId(['school_id' => 1, 'teacher_id' => $tids[0], 'folder_id' => $folder, 'fiscal_or_school_year' => 2569, 'original_name' => 'fill.pdf', 'path' => 'personnel/1/fill',
            'mime' => 'application/pdf', 'size' => $filler - 100000, 'sha256' => str_repeat('b', 64), 'dedupe_key' => str_repeat('b', 64), 'status' => 'active', 'review_status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
        $this->assertSame(1048576 - 60000, StorageQuota::usedBytes(1));

        $as($admin)->post('/api/documents/'.$doc['id'].'/files', ['file' => $pdf('a.pdf')], $accept)->assertUnprocessable();
        $as($admin)->post('/api/registry/students/'.$student['id'].'/documents', ['file' => $pdf('b.pdf'), 'kind' => 'other'], $accept)->assertUnprocessable();
        $as($tb)->post('/api/leave/requests/'.$l2['id'].'/attachment', ['version' => $l2['version'], 'file' => $pdf('c.pdf')], $accept)->assertUnprocessable();
        $this->assertSame(0, DB::table('doc_files')->count());
        $this->assertSame(0, DB::table('reg_documents')->count());

        // free the personnel file: the very same uploads now fit, proving the refusal came from the other module's bytes
        DB::table('per_files')->where('id', $fillId)->update(['status' => 'deleted']);
        DB::table('leave_requests')->where('id', $l1['id'])->update(['attachment_bytes' => 0]);
        $as($admin)->post('/api/documents/'.$doc['id'].'/files', ['file' => $pdf('a.pdf')], $accept)->assertCreated();
        $as($admin)->post('/api/registry/students/'.$student['id'].'/documents', ['file' => $pdf('b.pdf'), 'kind' => 'other'], $accept)->assertCreated();
        $as($tb)->post('/api/leave/requests/'.$l2['id'].'/attachment', ['version' => $l2['version'], 'file' => $pdf('c.pdf')], $accept)->assertOk();
    }

    public function test_declared_table_without_school_id_is_not_counted(): void
    {
        $this->ddl(fn () => Schema::create('zz_noschool_files', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('size'); }));
        DB::table('zz_noschool_files')->insert(['size' => 5000]);
        $registry = new class extends ModuleRegistry { public function all(): array { return ['a' => ['id' => 'a', 'storage_tables' => [['table' => 'zz_noschool_files', 'bytes' => 'size']]]]; } };
        $this->app->instance(ModuleRegistry::class, $registry);
        $this->assertSame((int) DB::table('cms_media')->where('school_id', 1)->sum('bytes'), StorageQuota::usedBytes(1));
    }

    // ---------------------------------------------------------------- (2) personnel photo backup

    public function test_photo_migration_copies_public_photos_to_the_private_disk_and_is_safe_when_empty(): void
    {
        $this->ownDisks();
        $migration = require base_path('modules/personnel/database/migrations/2026_11_09_100001_personnel_photos_to_private_disk.php');
        $migration->up(); // no profiles, no files: nothing happens
        $this->assertSame([], Storage::disk('local')->allFiles());

        $now = now(); $bytes = "\xFF\xD8fictional-jpeg";
        $t = DB::table('teachers')->where('school_id', 1)->orderBy('id')->pluck('id')->all();
        $paths = ['personnel/1/'.str_repeat('a', 32).'.jpg', 'personnel/1/'.str_repeat('b', 32).'.jpg', 'personnel/1/'.str_repeat('c', 32).'.jpg'];
        foreach ([[$t[0], $paths[0]], [$t[1], $paths[1]], [$t[2], $paths[2]], [$t[3], '../../.env'], [$t[4], null]] as [$teacher, $path]) {
            DB::table('per_profiles')->insert(['school_id' => 1, 'teacher_id' => $teacher, 'photo_path' => $path, 'created_at' => $now, 'updated_at' => $now]);
        }
        Storage::disk('public')->put($paths[0], $bytes);   // only on public: must be copied
        Storage::disk('public')->put($paths[1], 'public-copy');
        Storage::disk('local')->put($paths[1], 'private-original'); // private copy exists: never overwritten
        // $paths[2]: file is gone everywhere: skipped, row kept
        $migration->up(); $migration->up();
        $this->assertSame($bytes, Storage::disk('local')->get($paths[0]));
        $this->assertSame('private-original', Storage::disk('local')->get($paths[1]));
        $this->assertFalse(Storage::disk('local')->exists($paths[2]));
        $this->assertSame(2, count(Storage::disk('local')->allFiles()));
        $this->assertSame($paths[2], DB::table('per_profiles')->where('teacher_id', $t[2])->value('photo_path'));
    }

    // ---------------------------------------------------------------- (3) updater isolation
    // asserted inside UpdaterTest itself (temp base path, real folders untouched); here only the sanity check that nothing leaked

    public function test_no_throwaway_updater_module_exists_in_the_real_folders(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('modules/zz-update-test'));
        $this->assertDirectoryDoesNotExist(public_path('assets/modules/zz-update-test'));
    }

    // ---------------------------------------------------------------- (4) G3 directory_source

    private function setSource(?string $source, bool $publish = true): void
    {
        $svc = app(SiteSettings::class); $cur = $svc->read(1);
        $s = $cur['draft'];
        if ($source === null) unset($s['directory_source']); else $s['directory_source'] = $source;
        $svc->save($s, $cur['version'], $publish, 'profile');
    }

    private function profile(int $teacherId, array $extra): void
    {
        $now = now();
        DB::table('per_profiles')->insert(['school_id' => 1, 'teacher_id' => $teacherId, 'created_at' => $now, 'updated_at' => $now] + $extra);
    }

    public function test_default_is_manual_and_the_public_page_keeps_using_person_posts(): void
    {
        $this->assertSame('manual', app(SiteSettings::class)->read(1)['published']['directory_source']);
        $teacher = DB::table('teachers')->where('school_id', 1)->orderBy('id')->first();
        $this->profile((int) $teacher->id, ['work_group' => 'กลุ่ม-ทดสอบ-ส่วนตัว', 'position' => 'ตำแหน่งทดสอบ']);
        $this->app['auth']->forgetGuards();
        $this->get('/portal/people')->assertOk()->assertDontSee('personnel-directory', false)->assertDontSee('กลุ่ม-ทดสอบ-ส่วนตัว');
    }

    public function test_personnel_source_renders_the_register_grouped_by_work_group_without_private_fields(): void
    {
        $teachers = DB::table('teachers')->where('school_id', 1)->orderBy('id')->get();
        $this->profile((int) $teachers[0]->id, ['work_group' => 'กลุ่มสาระภาษาไทย-ทดสอบ', 'position' => 'ครูชำนาญการ-ทดสอบ', 'academic_standing' => 'SECRET-STANDING', 'education' => json_encode([['degree' => 'SECRET-DEGREE']]), 'start_date' => '2010-01-01']);
        $this->profile((int) $teachers[1]->id, ['work_group' => 'กลุ่มสาระภาษาไทย-ทดสอบ', 'position' => '<b>ครู</b>']);
        $this->profile((int) $teachers[2]->id, ['work_group' => 'กลุ่มซ่อน-ทดสอบ', 'position' => 'HIDDEN-POS', 'show_in_directory' => false]);

        $this->setSource('personnel', false); // draft only: the public page must not change yet
        $this->app['auth']->forgetGuards();
        $this->get('/portal/people')->assertOk()->assertDontSee('personnel-directory', false);

        $this->actingAs(User::where('role', 'admin')->first());
        $this->setSource('personnel');
        $this->app['auth']->forgetGuards();
        $html = $this->get('/portal/people')->assertOk()->assertSee('personnel-directory', false)->assertSee('ครูชำนาญการ-ทดสอบ')->getContent();
        $this->assertSame(1, substr_count($html, '<h3>กลุ่มสาระภาษาไทย-ทดสอบ</h3>'), 'one group heading for the work_group');
        $this->assertStringContainsString(PerSupport::teacherName($teachers[0]), $html);
        $this->assertStringNotContainsString('<b>ครู</b>', $html); // escaped
        // a teacher with no profile row is never published
        foreach ($teachers->slice(3) as $t) $this->assertStringNotContainsString(PerSupport::teacherName($t), $html, 'unprofiled teacher must be absent');
        foreach (['SECRET-STANDING', 'SECRET-DEGREE', '2010-01-01', 'HIDDEN-POS', 'กลุ่มซ่อน-ทดสอบ', 'photo_path', 'academic_standing'] as $leak) $this->assertStringNotContainsString($leak, $html, $leak);
    }

    public function test_personnel_source_is_forced_to_manual_when_the_module_is_inactive(): void
    {
        $teacher = DB::table('teachers')->where('school_id', 1)->orderBy('id')->first();
        $this->profile((int) $teacher->id, ['work_group' => 'กลุ่ม-ทดสอบ-ปิดโมดูล']);
        $this->setSource('personnel');
        config(['saan.modules.disable' => 'personnel']);
        app(ModuleRegistry::class)->flush();
        $this->assertFalse(app(ModuleRegistry::class)->active('personnel'));
        $this->app['auth']->forgetGuards();
        $this->get('/portal/people')->assertOk()->assertDontSee('personnel-directory', false)->assertDontSee('กลุ่ม-ทดสอบ-ปิดโมดูล');
    }

    public function test_admin_state_lists_the_available_directory_sources(): void
    {
        $this->getJson('/api/site/state')->assertOk()->assertJsonPath('directory_sources', ['manual', 'personnel']);
        config(['saan.modules.disable' => 'personnel']);
        app(ModuleRegistry::class)->flush();
        $this->getJson('/api/site/state')->assertOk()->assertJsonPath('directory_sources', ['manual']);
    }

    public function test_directory_source_is_validated_and_survives_a_save_that_omits_it(): void
    {
        $cur = app(SiteSettings::class)->read(1);
        $payload = fn (array $extra, int $version) => ['version' => $version, 'intent' => 'draft', 'settings' => array_intersect_key($cur['draft'], array_flip([
            'badge', 'intro_heading', 'intro_text', 'mission', 'email', 'office_hours', 'levels', 'footer_text', 'statistics', 'navigation', 'social_links', 'homepage_sections', 'default_seo_description'])) + $extra];
        $this->putJson('/api/site/settings', $payload(['directory_source' => 'ldap'], $cur['version']))->assertUnprocessable();
        $ok = $this->putJson('/api/site/settings', $payload(['directory_source' => 'personnel'], $cur['version']))->assertOk();
        $this->assertSame('personnel', $ok->json('draft.directory_source'));
        $again = $this->putJson('/api/site/settings', $payload([], (int) $ok->json('version')))->assertOk();
        $this->assertSame('personnel', $again->json('draft.directory_source'), 'a save that does not send the key keeps the choice');
    }
}
