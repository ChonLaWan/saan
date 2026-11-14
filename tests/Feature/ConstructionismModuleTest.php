<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** constructionism module (M17). All data is fictional. */
class ConstructionismModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;
    private int $term;
    private array $room = [];
    private array $stu = [];
    private array $tid = [];
    private User $ta;
    private User $tb;
    private User $admin;
    private string $diskRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskRoot = storage_path('framework/testing/pbl-'.getmypid().'-'.uniqid());
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::forgetDisk('local');
        $this->term = DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2569, 'term' => 1, 'starts_on' => '2026-05-15', 'ends_on' => '2026-09-30']);
        $this->tid['a'] = DB::table('teachers')->insertGetId(['school_id' => 1, 'name' => 'ครู ก (สมมติ)', 'subject' => 'ทดสอบ']);
        $this->tid['b'] = DB::table('teachers')->insertGetId(['school_id' => 1, 'name' => 'ครู ข (สมมติ)', 'subject' => 'ทดสอบ']);
        $this->room['a'] = DB::table('classrooms')->insertGetId(['school_id' => 1, 'name' => 'ม.1/1', 'homeroom_teacher_id' => $this->tid['a']]);
        $this->room['b'] = DB::table('classrooms')->insertGetId(['school_id' => 1, 'name' => 'ม.1/2', 'homeroom_teacher_id' => $this->tid['b']]);
        foreach ([['a1', 'a', '90001'], ['a2', 'a', '90002'], ['b1', 'b', '90003']] as [$k, $room, $code]) {
            $this->stu[$k] = DB::table('students')->insertGetId(['school_id' => 1, 'classroom_id' => $this->room[$room], 'code' => $code, 'name' => 'เด็กสมมติ '.$k]);
        }
        $this->ta = $this->mk('teacher', $this->tid['a']);
        $this->tb = $this->mk('teacher', $this->tid['b']);
        $this->admin = $this->mk('admin', null);
    }

    protected function tearDown(): void
    {
        if ($this->diskRoot !== '') File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function mk(string $role, ?int $teacher): User
    {
        $this->n++;
        return User::forceCreate(['name' => 'Fictional '.$role.$this->n, 'email' => $role.$this->n.'@school.test', 'password' => 'Original-Password-2026', 'role' => $role,
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1, 'teacher_id' => $teacher]);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u);
    }

    private function project(string $room = 'a', string $title = 'โครงงานหุ่นยนต์ (ทดสอบ)'): array
    {
        return $this->postJson('/api/pbl/projects', ['title' => $title, 'classroom_id' => $this->room[$room], 'driving_question' => 'เราจะทำให้ห้องเรียนประหยัดไฟได้อย่างไร'])->assertCreated()->json();
    }

    public function test_project_lifecycle_versions_and_title_uniqueness(): void
    {
        $this->as($this->ta);
        $d = $this->project();
        $p = $d['project'];
        $this->assertSame($this->tid['a'], $p['advisor_teacher_id']);
        $this->assertSame('planning', $p['status']);
        $this->postJson('/api/pbl/projects', ['title' => '  โครงงานหุ่นยนต์   (ทดสอบ) ', 'classroom_id' => $this->room['a']])->assertConflict();
        $this->project('a', 'โครงงานอื่น');
        $up = $this->putJson('/api/pbl/projects/'.$p['id'], ['title' => 'ชื่อใหม่', 'status' => 'active', 'version' => $p['version']])->assertOk()->json('project');
        $this->assertSame(2, $up['version']);
        $this->putJson('/api/pbl/projects/'.$p['id'], ['title' => 'x', 'status' => 'active', 'version' => $p['version']])->assertConflict()->assertJsonPath('current.title', 'ชื่อใหม่');
        $this->putJson('/api/pbl/projects/'.$p['id'], ['title' => 'ชื่อใหม่', 'status' => 'bogus', 'version' => 2])->assertUnprocessable();
        $this->putJson('/api/pbl/projects/'.$p['id'], ['title' => 'โครงงานอื่น', 'status' => 'active', 'version' => 2])->assertConflict();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'pbl.project.update')->count());
        $this->getJson('/api/pbl/projects')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_teacher_scope_and_advisor_access(): void
    {
        $this->as($this->ta);
        $p = $this->project()['project'];
        $this->postJson('/api/pbl/projects', ['title' => 'ห้องอื่น', 'classroom_id' => $this->room['b']])->assertForbidden();
        $this->as($this->tb);
        $this->getJson('/api/pbl/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/pbl/projects/'.$p['id'])->assertForbidden();
        $this->putJson('/api/pbl/projects/'.$p['id'], ['title' => 'x', 'status' => 'done', 'version' => 1])->assertForbidden();
        $this->postJson('/api/pbl/projects/'.$p['id'].'/logs', ['date' => '2026-06-01', 'entry' => 'x'])->assertForbidden();
        $this->get('/pbl/projects/'.$p['id'].'/print')->assertForbidden();
        $this->deleteJson('/api/pbl/projects/'.$p['id'], ['version' => 1])->assertForbidden();
        // naming teacher B as advisor grants access to that one project
        $this->as($this->ta)->putJson('/api/pbl/projects/'.$p['id'], ['title' => $p['title'], 'status' => 'planning', 'advisor_teacher_id' => $this->tid['b'], 'version' => 1])->assertOk();
        $this->as($this->tb);
        $this->getJson('/api/pbl/projects/'.$p['id'])->assertOk();
        $this->getJson('/api/pbl/projects')->assertOk()->assertJsonCount(1, 'data');
        // roles outside staff are refused
        $this->as($this->mk('editor', null))->getJson('/api/pbl/projects')->assertForbidden();
        $this->as($this->admin)->getJson('/api/pbl/projects/'.$p['id'])->assertOk();
    }

    public function test_members_journal_and_cascade_delete(): void
    {
        $this->as($this->ta);
        $p = $this->project()['project'];
        $this->postJson('/api/pbl/projects/'.$p['id'].'/members', ['student_id' => $this->stu['b1']])->assertUnprocessable(); // other classroom
        $this->postJson('/api/pbl/projects/'.$p['id'].'/members', ['student_id' => 999999])->assertUnprocessable();
        $d = $this->postJson('/api/pbl/projects/'.$p['id'].'/members', ['student_id' => $this->stu['a1'], 'role' => 'ผู้ประสานงาน'])->assertCreated()->json();
        $this->assertSame('ผู้ประสานงาน', $d['members'][0]['role']);
        $this->assertCount(1, $d['candidates']);
        $this->postJson('/api/pbl/projects/'.$p['id'].'/members', ['student_id' => $this->stu['a1']])->assertConflict();
        $m = $d['members'][0];
        $this->putJson('/api/pbl/members/'.$m['id'], ['role' => 'ช่างประกอบ', 'version' => $m['version']])->assertOk()->assertJsonPath('members.0.role', 'ช่างประกอบ');
        $this->putJson('/api/pbl/members/'.$m['id'], ['role' => 'x', 'version' => $m['version']])->assertConflict();

        $this->postJson('/api/pbl/projects/'.$p['id'].'/logs', ['date' => '2099-01-01', 'entry' => 'อนาคต'])->assertUnprocessable();
        $this->postJson('/api/pbl/projects/'.$p['id'].'/logs', ['date' => '2026-02-30', 'entry' => 'x'])->assertUnprocessable();
        $l = $this->postJson('/api/pbl/projects/'.$p['id'].'/logs', ['date' => now()->toDateString(), 'entry' => 'ทีมออกแบบวงจร <b>x</b>', 'by_teacher' => false])->assertCreated()->json('logs.0');
        $this->assertFalse($l['by_teacher']);
        $this->assertSame($this->ta->name, $l['by_name']);
        $this->putJson('/api/pbl/logs/'.$l['id'], ['date' => $l['date'], 'entry' => 'แก้ไข', 'version' => 1])->assertOk();
        $this->deleteJson('/api/pbl/logs/'.$l['id'], ['version' => 1])->assertConflict();

        $this->postJson('/api/pbl/projects/'.$p['id'].'/artifacts', ['title' => 'ไฟล์', 'file' => UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\nx")])->assertCreated();
        $this->assertCount(1, Storage::disk('local')->allFiles('constructionism'));
        $this->deleteJson('/api/pbl/projects/'.$p['id'], ['version' => 99])->assertConflict();
        $this->assertCount(1, Storage::disk('local')->allFiles('constructionism'));
        $this->deleteJson('/api/pbl/projects/'.$p['id'], ['version' => 1])->assertNoContent();
        foreach (['con_projects', 'con_members', 'con_logs', 'con_artifacts', 'con_reflections'] as $t) $this->assertSame(0, DB::table($t)->count(), $t);
        $this->assertSame([], Storage::disk('local')->allFiles('constructionism'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'pbl.project.cascade')->count());
    }

    public function test_artifacts_are_private_validated_and_never_expose_paths(): void
    {
        $this->as($this->ta);
        $p = $this->project()['project'];
        $url = '/api/pbl/projects/'.$p['id'].'/artifacts';
        foreach (['evil.php', 'page.html', 'pic.svg', 'noext', 'x.php.txt.exe'] as $bad) {
            $this->postJson($url, ['title' => 'x', 'file' => UploadedFile::fake()->createWithContent($bad, 'data')])->assertUnprocessable();
        }
        $this->postJson($url, ['title' => 'ทั้งสอง', 'url' => 'https://example.org/x', 'file' => UploadedFile::fake()->createWithContent('a.txt', 'data')])->assertUnprocessable();
        $this->postJson($url, ['title' => 'ไม่มีเลย'])->assertUnprocessable();
        $this->postJson($url, ['title' => 'x', 'url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->postJson($url, ['title' => 'x', 'url' => 'http://example.org/'])->assertUnprocessable();
        foreach (['https://127.1/', 'https://0x7f.0.0.1/', 'https://0177.0.0.1/', 'https://10.1/', 'https://localhost/'] as $bad) {
            $this->postJson($url, ['title' => 'x', 'url' => $bad])->assertUnprocessable();
        }
        $res = $this->postJson($url, ['title' => 'ชิ้นงานลับ', 'kind' => 'doc', 'file' => UploadedFile::fake()->createWithContent('รายงาน ../ลับ.PDF', "%PDF-1.4\nfictional-marker")])->assertCreated();
        $a = $res->json('artifacts.0');
        $this->assertTrue($a['has_file']);
        $this->assertStringNotContainsString('constructionism/', $res->getContent());
        $this->assertStringNotContainsString('path', $res->getContent());
        $row = DB::table('con_artifacts')->first();
        $this->assertMatchesRegularExpression('~^constructionism/1/p'.$p['id'].'/[A-Za-z0-9]{40}\.pdf$~', $row->path);
        $this->assertSame(hash('sha256', "%PDF-1.4\nfictional-marker"), $row->sha256);
        $this->assertFileExists($this->diskRoot.'/'.$row->path);
        $this->assertStringStartsNotWith(public_path(), $this->diskRoot);
        $link = $this->postJson($url, ['title' => 'เว็บ', 'kind' => 'code', 'url' => 'https://project.example.org/demo'])->assertCreated();
        $this->assertSame('https://project.example.org/demo', $link->json('artifacts.1.url'));
        // a stale update must not leak the stored path in the 409 body
        $conflict = $this->putJson('/api/pbl/artifacts/'.$a['id'], ['title' => 'ใหม่', 'version' => 99])->assertConflict();
        $this->assertStringNotContainsString('constructionism/', $conflict->getContent());
        $this->assertArrayNotHasKey('path', $conflict->json('current'));
        // download: owner teacher yes, other teacher and anonymous no
        $dl = $this->get('/api/pbl/artifacts/'.$a['id'].'/file')->assertOk();
        $this->assertStringContainsString('attachment', (string) $dl->headers->get('content-disposition'));
        $this->assertStringContainsString(rawurlencode('ลับ.PDF'), (string) $dl->headers->get('content-disposition')); // original name via filename*
        $this->assertSame('nosniff', $dl->headers->get('x-content-type-options'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'pbl.artifact.download')->count());
        $this->as($this->tb)->get('/api/pbl/artifacts/'.$a['id'].'/file')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get('/api/pbl/artifacts/'.$a['id'].'/file')->assertRedirect();
        foreach (['/storage/'.$row->path, '/constructionism/'.basename($row->path), '/storage/app/private/'.$row->path] as $guess) $this->assertContains($this->get($guess)->getStatusCode(), [403, 404]);
        // delete removes the file
        $this->as($this->ta)->deleteJson('/api/pbl/artifacts/'.$a['id'], ['version' => 1])->assertOk();
        $this->assertFileDoesNotExist($this->diskRoot.'/'.$row->path);
    }

    public function test_artifact_size_limit(): void
    {
        config(['saan.storage.max_upload_mb' => 1]);
        $this->as($this->ta);
        $p = $this->project()['project'];
        $this->postJson('/api/pbl/projects/'.$p['id'].'/artifacts', ['title' => 'ใหญ่', 'file' => UploadedFile::fake()->createWithContent('big.zip', str_repeat('a', 1048577))])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles('constructionism'));
    }

    public function test_rubric_defaults_edit_and_reflection_scores(): void
    {
        $this->as($this->ta);
        $p = $this->project()['project'];
        $r = $this->getJson('/api/pbl/rubric')->assertOk()->json();
        $this->assertFalse($r['custom']);
        $this->assertGreaterThanOrEqual(3, count($r['criteria']));
        $key = $r['criteria'][0]['key'];
        $this->postJson('/api/pbl/projects/'.$p['id'].'/members', ['student_id' => $this->stu['a1']])->assertCreated();
        $rf = '/api/pbl/projects/'.$p['id'].'/reflections';
        $this->postJson($rf, ['student_id' => $this->stu['a2'], 'text' => 'x'])->assertUnprocessable(); // not a member
        $this->postJson($rf, ['student_id' => $this->stu['a1']])->assertUnprocessable(); // nothing to save
        foreach ([5, 0, 'x', 2.5, -1] as $bad) $this->postJson($rf, ['student_id' => $this->stu['a1'], 'rubric' => [$key => $bad]])->assertUnprocessable();
        $this->postJson($rf, ['student_id' => $this->stu['a1'], 'rubric' => ['nope' => 3]])->assertUnprocessable();
        $d = $this->postJson($rf, ['student_id' => $this->stu['a1'], 'text' => 'เรียนรู้จากการลองผิดลองถูก', 'rubric' => [$key => 4]])->assertCreated()->json();
        $this->assertSame([$key => 4], $d['reflections'][0]['rubric']);
        $this->postJson($rf, ['student_id' => $this->stu['a1'], 'text' => 'ซ้ำ'])->assertConflict();
        $refl = $d['reflections'][0];
        $this->putJson('/api/pbl/reflections/'.$refl['id'], ['text' => 'ปรับ', 'rubric' => [$key => 3], 'version' => $refl['version']])->assertOk()->assertJsonPath('reflections.0.rubric.'.$key, 3);
        $this->putJson('/api/pbl/reflections/'.$refl['id'], ['text' => 'เก่า', 'version' => $refl['version']])->assertConflict();
        $this->deleteJson('/api/pbl/members/'.$d['members'][0]['id'], ['version' => 1])->assertUnprocessable(); // has a reflection

        // only admin / principal edit criteria
        $this->putJson('/api/pbl/rubric', ['criteria' => [['key' => 'a', 'label' => 'ก']], 'revision' => $r['revision']])->assertForbidden();
        $this->as($this->admin);
        $new = [['key' => $key, 'label' => 'ชื่อใหม่ของเกณฑ์แรก', 'levels' => ['ต้องช่วย', 'พอใช้', 'ดี', 'เก่ง']], ['key' => 'safety', 'label' => 'ความปลอดภัยในการทำงาน']];
        $this->putJson('/api/pbl/rubric', ['criteria' => $new, 'revision' => 'stale'])->assertConflict();
        $this->putJson('/api/pbl/rubric', ['criteria' => [['key' => 'Bad Key', 'label' => 'x']], 'revision' => $r['revision']])->assertUnprocessable();
        $this->putJson('/api/pbl/rubric', ['criteria' => [['key' => 'a', 'label' => 'ซ้ำ'], ['key' => 'b', 'label' => ' ซ้ำ ']], 'revision' => $r['revision']])->assertUnprocessable();
        $this->putJson('/api/pbl/rubric', ['criteria' => [['key' => 'x', 'label' => 'ไม่มีเกณฑ์เดิม']], 'revision' => $r['revision']])->assertUnprocessable(); // drops a scored criterion
        $saved = $this->putJson('/api/pbl/rubric', ['criteria' => $new, 'revision' => $r['revision']])->assertOk()->json();
        $this->assertTrue($saved['custom']);
        $this->assertSame(\App\Support\Saan\Settings::get('constructionism.rubric')['criteria'][1]['key'], 'safety');
        $this->putJson('/api/pbl/rubric', ['criteria' => $new, 'revision' => $r['revision']])->assertConflict(); // old revision
        // new criterion is scoreable, removed defaults are not
        $this->as($this->ta);
        $this->putJson('/api/pbl/reflections/'.$refl['id'], ['rubric' => ['safety' => 2], 'version' => 2])->assertOk()->assertJsonPath('reflections.0.text', 'ปรับ'); // partial update keeps the text
        $this->putJson('/api/pbl/reflections/'.$refl['id'], ['text' => 'ข้อความใหม่', 'version' => 3])->assertOk()->assertJsonPath('reflections.0.rubric.safety', 2); // and keeps the scores
        $this->putJson('/api/pbl/reflections/'.$refl['id'], ['rubric' => ['safety' => 2], 'version' => 4])->assertOk();
        $this->putJson('/api/pbl/reflections/'.$refl['id'], ['rubric' => ['teamwork' => 2], 'version' => 5])->assertUnprocessable();
        $this->assertSame('ชื่อใหม่ของเกณฑ์แรก', $this->getJson('/api/pbl/options')->json('rubric.criteria.0.label'));
    }

    public function test_print_summary_is_escaped_and_complete(): void
    {
        $this->as($this->ta);
        $p = $this->project('a', 'โครงงาน <script>alert(1)</script>')['project'];
        $this->postJson('/api/pbl/projects/'.$p['id'].'/members', ['student_id' => $this->stu['a1'], 'role' => 'หัวหน้า'])->assertCreated();
        $this->postJson('/api/pbl/projects/'.$p['id'].'/logs', ['date' => now()->toDateString(), 'entry' => 'บันทึก <img src=x onerror=alert(1)>'])->assertCreated();
        $this->postJson('/api/pbl/projects/'.$p['id'].'/artifacts', ['title' => 'ลิงก์งาน', 'url' => 'https://project.example.org/demo'])->assertCreated();
        $this->postJson('/api/pbl/projects/'.$p['id'].'/reflections', ['student_id' => $this->stu['a1'], 'text' => 'ภูมิใจมาก', 'rubric' => ['inquiry' => 3]])->assertCreated();
        $html = $this->get('/pbl/projects/'.$p['id'].'/print')->assertOk()->assertSee('เด็กสมมติ a1')->assertSee('หัวหน้า')->assertSee('ภูมิใจมาก')->assertSee('https://project.example.org/demo')
            ->assertSee('3 (ดี)')->assertSee('เราจะทำให้ห้องเรียนประหยัดไฟได้อย่างไร')->assertSee('ปีการศึกษา 2569')->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)->assertHeader('X-Robots-Tag', 'noindex')->getContent();
        $this->assertStringNotContainsString('constructionism/', $html);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'pbl.print')->count());
    }

    public function test_school_scoping_and_term_rules(): void
    {
        $this->as($this->ta);
        $p = $this->project()['project'];
        // rows of another school are invisible and cannot be changed
        DB::table('con_projects')->insert(['school_id' => 2, 'term_id' => $this->term, 'classroom_id' => $this->room['a'], 'title' => 'โรงเรียนอื่น', 'title_key' => 'โรงเรียนอื่น', 'status' => 'planning', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $foreign = (int) DB::table('con_projects')->where('school_id', 2)->value('id');
        $this->as($this->admin);
        $this->getJson('/api/pbl/projects')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/pbl/projects/'.$foreign)->assertNotFound();
        $this->putJson('/api/pbl/projects/'.$foreign, ['title' => 'x', 'status' => 'done', 'version' => 1])->assertNotFound();
        $this->deleteJson('/api/pbl/projects/'.$foreign, ['version' => 1])->assertNotFound();
        // a past term is read-only for teachers, editable by admin
        $newTerm = DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2569, 'term' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31']);
        \App\Support\Saan\Settings::put('academic.current_term_id', $newTerm);
        $this->as($this->ta);
        $this->getJson('/api/pbl/projects/'.$p['id'])->assertOk()->assertJsonPath('project.can_write', false);
        $this->postJson('/api/pbl/projects/'.$p['id'].'/logs', ['date' => now()->toDateString(), 'entry' => 'x'])->assertForbidden();
        $this->getJson('/api/pbl/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/pbl/projects?term_id='.$this->term)->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->admin)->postJson('/api/pbl/projects/'.$p['id'].'/logs', ['date' => now()->toDateString(), 'entry' => 'ผู้ดูแลแก้ย้อนหลัง'])->assertCreated();
        // a new project in the new term may reuse a title
        $this->as($this->ta);
        $this->project();
        $this->assertSame(2, DB::table('con_projects')->where('school_id', 1)->count());
        // module registration and menu
        $this->assertContains('constructionism', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->get('/work')->assertOk()->assertSee('assets/modules/constructionism/pbl.js', false)->assertSee('โครงงาน Constructionism');
        $this->as($this->mk('editor', null))->get('/work')->assertOk()->assertDontSee('data-work-key="pbl"', false);
    }

    public function test_a_colleague_with_classroom_scope_cannot_take_over_the_advisor_role_and_then_delete(): void
    {
        $this->as($this->ta);
        $p = $this->project()['project'];
        DB::table('users')->where('id', $this->tb->id)->update(['classroom_id' => $this->room['a']]); // tb now has classroom scope of room a, but is neither advisor nor creator
        $this->as($this->tb->fresh());
        $this->putJson('/api/pbl/projects/'.$p['id'], ['title' => $p['title'], 'status' => 'planning', 'advisor_teacher_id' => $this->tid['b'], 'version' => 1])->assertForbidden();
        $this->assertSame($this->tid['a'], (int) DB::table('con_projects')->where('id', $p['id'])->value('advisor_teacher_id'));
        $this->putJson('/api/pbl/projects/'.$p['id'], ['title' => 'ชื่อใหม่', 'status' => 'planning', 'advisor_teacher_id' => $this->tid['a'], 'version' => 1])->assertOk(); // unchanged advisor is not a transfer
        $this->deleteJson('/api/pbl/projects/'.$p['id'], ['version' => 2])->assertForbidden();
        $this->assertSame(1, DB::table('con_projects')->where('id', $p['id'])->count());
    }

    public function test_an_old_term_project_does_not_list_the_current_cohort_as_candidates(): void
    {
        $this->as($this->ta);
        $p = $this->project()['project'];
        $this->getJson('/api/pbl/projects/'.$p['id'])->assertOk()->assertJsonCount(2, 'candidates');
        $newTerm = DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2569, 'term' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31']);
        \App\Support\Saan\Settings::put('academic.current_term_id', $newTerm);
        $this->getJson('/api/pbl/projects/'.$p['id'])->assertOk()->assertJsonCount(0, 'candidates');
        $this->as($this->admin)->getJson('/api/pbl/projects/'.$p['id'])->assertOk()->assertJsonCount(0, 'candidates');
    }
}