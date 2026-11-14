<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use Saan\Modules\LearningLinks\Links;
use Saan\Modules\LearningLinks\UrlRule;
use Tests\TestCase;

/** learning-links module (M22). All data is fictional. */
class LearningLinksModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;
    private int $term;
    private array $room = [];
    private User $ta;
    private User $tb;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->term = DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2569, 'term' => 1, 'starts_on' => '2026-05-15', 'ends_on' => '2026-09-30']);
        $ta = DB::table('teachers')->insertGetId(['school_id' => 1, 'name' => 'ครู ก (สมมติ)', 'subject' => 'ทดสอบ']);
        $tb = DB::table('teachers')->insertGetId(['school_id' => 1, 'name' => 'ครู ข (สมมติ)', 'subject' => 'ทดสอบ']);
        $this->room['a'] = DB::table('classrooms')->insertGetId(['school_id' => 1, 'name' => 'ม.1/1', 'homeroom_teacher_id' => $ta]);
        $this->room['b'] = DB::table('classrooms')->insertGetId(['school_id' => 1, 'name' => 'ม.1/2', 'homeroom_teacher_id' => $tb]);
        $this->ta = $this->mk('teacher', $ta);
        $this->tb = $this->mk('teacher', $tb);
        $this->admin = $this->mk('admin', null);
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

    private function body(array $o = []): array
    {
        return $o + ['title' => 'ห้องเรียนออนไลน์ (ทดสอบ)', 'url' => 'https://classroom.example.org/c/abc', 'kind' => 'lms', 'classroom_id' => $this->room['a']];
    }

    public function test_url_validation_accepts_only_plain_https(): void
    {
        $this->as($this->ta);
        foreach (['javascript:alert(1)', 'http://example.org/x', 'data:text/html,x', 'https://user:pw@example.org/', 'https://localhost/x', 'https://10.0.0.5/x',
            'https://192.168.1.2/', 'https://example.org:8443/', "https://example.org/a b", 'https://example.org/<x>', 'ftp://example.org/', '//example.org/x', 'https://intranet/', 'https://127.1/', 'https://0x7f.0.0.1/', 'https://0177.0.0.1/', 'https://10.1/', ''] as $bad) {
            $this->postJson('/api/learning/links', $this->body(['url' => $bad]))->assertUnprocessable();
        }
        $this->assertSame('https://khan.example.org/Path?Q=1', UrlRule::check('HTTPS://Khan.Example.org/Path?Q=1'));
        $this->postJson('/api/learning/links', $this->body(['url' => 'HTTPS://Classroom.Example.org/c/abc']))->assertCreated()->assertJsonPath('url', 'https://classroom.example.org/c/abc');
        $this->assertSame(1, DB::table('lrn_links')->count());
        $this->postJson('/api/learning/links', $this->body(['kind' => 'nope', 'url' => 'https://a.example.org/']))->assertUnprocessable();
    }

    public function test_duplicates_conflict_and_versions_are_enforced(): void
    {
        $this->as($this->ta);
        $id = $this->postJson('/api/learning/links', $this->body())->assertCreated()->json('id');
        $this->postJson('/api/learning/links', $this->body(['title' => 'ซ้ำ', 'url' => 'https://CLASSROOM.example.org/c/abc/']))->assertConflict();
        $v = DB::table('lrn_links')->where('id', $id)->value('version');
        $this->putJson('/api/learning/links/'.$id, $this->body(['title' => 'ชื่อใหม่', 'version' => $v]))->assertOk()->assertJsonPath('version', $v + 1);
        $this->putJson('/api/learning/links/'.$id, $this->body(['title' => 'เก่า', 'version' => $v]))->assertConflict();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'learning.link.create')->count());
        $this->deleteJson('/api/learning/links/'.$id, ['version' => $v])->assertConflict();
        $this->deleteJson('/api/learning/links/'.$id, ['version' => $v + 1])->assertNoContent();
        $this->assertSame(0, DB::table('lrn_links')->count());
    }

    public function test_urls_differing_only_in_path_case_are_distinct_and_partial_update_keeps_fields(): void
    {
        $this->as($this->ta);
        $this->assertNotSame(UrlRule::hash(UrlRule::check('https://www.youtube.com/watch?v=AbCdEf')), UrlRule::hash(UrlRule::check('https://www.youtube.com/watch?v=abcdef')));
        $a = $this->postJson('/api/learning/links', $this->body(['url' => 'https://www.youtube.com/watch?v=AbCdEf', 'kind' => 'video', 'sort' => 7]))->assertCreated()->json();
        $this->postJson('/api/learning/links', $this->body(['url' => 'https://www.youtube.com/watch?v=abcdef', 'kind' => 'video']))->assertCreated();
        $this->postJson('/api/learning/links', $this->body(['url' => 'https://WWW.youtube.com/watch?v=AbCdEf']))->assertConflict();
        // update without kind / sort keeps the stored values
        $this->putJson('/api/learning/links/'.$a['id'], ['title' => 'ใหม่', 'url' => 'https://www.youtube.com/watch?v=AbCdEf', 'version' => $a['version']])
            ->assertOk()->assertJsonPath('kind', 'video')->assertJsonPath('sort', 7);
    }

    public function test_classroom_links_default_to_current_term_and_code_is_homeroom_only(): void
    {
        $this->as($this->ta);
        $r = $this->postJson('/api/learning/links', $this->body())->assertCreated();
        $this->assertSame(TermScope::currentId(), $r->json('term_id'));
        $this->assertNotNull($r->json('term_id'));
        // a subject teacher of room a (lesson only) may not issue or revoke the code
        $period = DB::table('bell_periods')->insertGetId(['school_id' => 1, 'number' => 1, 'starts_at' => '08:30', 'ends_at' => '09:20']);
        DB::table('lessons')->insert(['school_id' => 1, 'term_id' => $this->term, 'classroom_id' => $this->room['a'], 'teacher_id' => $this->tb->teacher_id, 'period_id' => $period,
            'weekday' => 1, 'room' => 'ห้อง 101', 'subject' => 'ทดสอบ', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($this->tb);
        $this->assertTrue(TeacherScope::canClassroom($this->room['a'], $this->tb));
        {
            $this->postJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertForbidden();
            $this->deleteJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertForbidden();
            $this->getJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertOk();
            $opt = collect($this->getJson('/api/learning/options')->json('classrooms'))->keyBy('id');
            $this->assertFalse($opt[$this->room['a']]['can_manage_code']);
            $this->assertTrue($opt[$this->room['b']]['can_manage_code']);
        }
        $this->as($this->ta)->postJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertCreated();
        // moving to another term hides the link on the public page and in the staff list
        $t2 = DB::table('academic_terms')->insertGetId(['school_id' => 1, 'year' => 2569, 'term' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31']);
        \App\Support\Saan\Settings::put('academic.current_term_id', $t2);
        $this->assertSame($t2, TermScope::currentId());
        $this->assertCount(0, Links::publicLinks($this->room['a']));
        $this->assertCount(0, $this->getJson('/api/learning/links')->json('data'));
        $this->assertCount(1, $this->getJson('/api/learning/links?all_terms=1')->json('data'));
        // explicit all-terms link stays visible
        $this->postJson('/api/learning/links', $this->body(['url' => 'https://every.example.org/', 'all_terms' => true]))->assertCreated()->assertJsonPath('term_id', null);
        $this->assertCount(1, Links::publicLinks($this->room['a']));
    }

    public function test_teacher_scope_and_school_wide_links(): void
    {
        $this->as($this->ta)->postJson('/api/learning/links', $this->body(['classroom_id' => $this->room['b']]))->assertForbidden();
        $this->postJson('/api/learning/links', $this->body(['classroom_id' => null]))->assertForbidden();
        $mine = $this->postJson('/api/learning/links', $this->body())->assertCreated()->json('id');
        $this->as($this->tb);
        $other = $this->postJson('/api/learning/links', $this->body(['classroom_id' => $this->room['b']]))->assertCreated()->json('id');
        $ids = collect($this->getJson('/api/learning/links')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$other], $ids);
        $this->putJson('/api/learning/links/'.$mine, $this->body(['version' => 1]))->assertForbidden();
        $this->deleteJson('/api/learning/links/'.$mine, ['version' => 1])->assertForbidden();
        $this->getJson('/api/learning/links?classroom_id='.$this->room['a'])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertForbidden();
        $this->as($this->admin);
        $wide = $this->postJson('/api/learning/links', ['title' => 'ทั้งโรงเรียน', 'url' => 'https://school.example.org/', 'kind' => 'doc'])->assertCreated()->json('id');
        $this->assertCount(3, $this->getJson('/api/learning/links')->json('data'));
        // a teacher sees the school-wide link read-only
        $this->as($this->ta);
        $seen = collect($this->getJson('/api/learning/links')->json('data'))->keyBy('id');
        $this->assertTrue($seen[$wide]['classroom_id'] === null && $seen[$wide]['can_edit'] === false && $seen[$mine]['can_edit'] === true);
        $this->putJson('/api/learning/links/'.$wide, ['title' => 'x', 'url' => 'https://x.example.org/', 'version' => 1])->assertForbidden();
        // students/other roles cannot use the staff api
        $this->as($this->mk('editor', null))->getJson('/api/learning/links')->assertForbidden();
    }

    public function test_course_links_follow_the_course_teacher(): void
    {
        if (!Schema::hasTable('asm_courses')) $this->markTestSkipped('assessment module tables are not installed');
        $course = DB::table('asm_courses')->insertGetId(['school_id' => 1, 'term_id' => $this->term, 'subject_id' => 1, 'classroom_id' => $this->room['b'],
            'teacher_id' => $this->ta->teacher_id, 'policy' => '{}', 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($this->ta);
        $r = $this->postJson('/api/learning/links', ['title' => 'วิชา', 'url' => 'https://khan.example.org/c', 'kind' => 'video', 'course_id' => $course])->assertCreated();
        $this->assertSame($this->room['b'], $r->json('classroom_id'));
        $this->assertSame($this->term, $r->json('term_id'));
        $this->as($this->tb)->postJson('/api/learning/links', ['title' => 'วิชา', 'url' => 'https://khan.example.org/d', 'course_id' => $course])->assertCreated(); // homeroom teacher of that class
        $this->as($this->mk('teacher', DB::table('teachers')->insertGetId(['school_id' => 1, 'name' => 'ครู ค', 'subject' => 'x'])))
            ->postJson('/api/learning/links', ['title' => 'วิชา', 'url' => 'https://khan.example.org/e', 'course_id' => $course])->assertForbidden();
    }

    public function test_access_code_unlocks_titles_and_links_only(): void
    {
        $this->as($this->ta)->postJson('/api/learning/links', $this->body(['title' => 'ลิงก์ของห้อง ก']))->assertCreated();
        $this->as($this->tb)->postJson('/api/learning/links', $this->body(['title' => 'ลิงก์ของห้อง ข', 'url' => 'https://b.example.org/', 'classroom_id' => $this->room['b']]))->assertCreated();
        DB::table('students')->insert(['school_id' => 1, 'classroom_id' => $this->room['a'], 'code' => '90001', 'name' => 'เด็กสมมติ หนึ่ง']);
        $this->as($this->ta);
        $this->getJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertOk()->assertJsonPath('exists', false);
        $res = $this->postJson('/api/learning/classrooms/'.$this->room['a'].'/access', ['days' => 30])->assertCreated();
        $code = $res->json('code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);
        $this->assertNull(DB::table('lrn_access')->where('code_hash', $code)->first()); // only a hash is stored
        $this->assertStringNotContainsString($code, json_encode(DB::table('lrn_access')->first()));
        $this->assertStringNotContainsString($code, json_encode(DB::table('audit_events')->get()));
        $this->getJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertOk()->assertJsonPath('active', true)->assertJsonMissingPath('code');

        $this->app['auth']->forgetGuards();
        $page = '/learn/'.$this->room['a'];
        $this->get($page)->assertOk()->assertSee('รหัสห้องเรียน')->assertDontSee('ลิงก์ของห้อง ก');
        $this->post($page, ['code' => 'WRONGCODE'])->assertStatus(422)->assertDontSee('ลิงก์ของห้อง ก');
        $this->post('/learn/9999', ['code' => $code])->assertStatus(422);
        $this->post('/learn/'.$this->room['b'], ['code' => $code])->assertStatus(422)->assertDontSee('ลิงก์ของห้อง ข'); // code is per classroom
        $ok = $this->post($page, ['code' => strtolower(substr($code, 0, 4).'-'.substr($code, 4))])->assertOk();
        $ok->assertSee('ลิงก์ของห้อง ก')->assertSee('https://classroom.example.org/c/abc', false)->assertDontSee('ลิงก์ของห้อง ข')
            ->assertDontSee('เด็กสมมติ')->assertDontSee('90001')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get($page)->assertOk()->assertSee('ลิงก์ของห้อง ก'); // session grant
        // regenerating invalidates the grant and the old code
        $this->as($this->ta)->postJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->get($page)->assertOk()->assertDontSee('ลิงก์ของห้อง ก');
        $this->post($page, ['code' => $code])->assertStatus(422);
    }

    public function test_expired_and_revoked_codes_are_rejected(): void
    {
        $this->as($this->ta)->postJson('/api/learning/links', $this->body())->assertCreated();
        $code = $this->postJson('/api/learning/classrooms/'.$this->room['a'].'/access')->json('code');
        $this->postJson('/api/learning/classrooms/'.$this->room['a'].'/access', ['days' => 400])->assertUnprocessable();
        DB::table('lrn_access')->update(['expires_at' => now()->subMinute()]);
        $this->app['auth']->forgetGuards();
        $this->post('/learn/'.$this->room['a'], ['code' => $code])->assertStatus(422);
        $this->as($this->ta)->deleteJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertNoContent();
        $this->assertSame(0, DB::table('lrn_access')->count());
        $this->deleteJson('/api/learning/classrooms/'.$this->room['a'].'/access')->assertNotFound();
    }

    public function test_sso_hint_only_for_registered_sso_clients(): void
    {
        if (!Schema::hasTable('api_clients') || !app(\App\Support\Modules\ModuleRegistry::class)->active('api-gateway')) $this->markTestSkipped('api-gateway is not active');
        $mkClient = fn (string $name, array $scopes, bool $active = true) => DB::table('api_clients')->insertGetId(['school_id' => 1, 'name' => $name, 'name_key' => mb_strtolower($name), 'client_id' => 'cid-'.uniqid(),
            'secret_hash' => 'x', 'scopes' => json_encode($scopes), 'redirect_uris' => json_encode(['https://classroom.example.org/callback']), 'is_active' => $active, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sso = $mkClient('Fictional App', ['sso:student']);
        $plain = $mkClient('Plain App', ['classrooms:read']);
        $off = $mkClient('Disabled App', ['sso:student'], false);
        $this->as($this->ta);
        $this->postJson('/api/learning/links', $this->body(['api_client_id' => $plain]))->assertUnprocessable();
        $this->postJson('/api/learning/links', $this->body(['api_client_id' => $off]))->assertUnprocessable();
        $this->postJson('/api/learning/links', $this->body(['api_client_id' => $sso]))->assertCreated()->assertJsonPath('sso', true);
        // an https link to some other host does not get the badge even when it is tied to the SSO client
        $this->postJson('/api/learning/links', $this->body(['api_client_id' => $sso, 'title' => 'ลิงก์ปลอม (ทดสอบ)', 'url' => 'https://other-host.example.net/c/abc']))->assertCreated()->assertJsonPath('sso', false);
        $code = $this->postJson('/api/learning/classrooms/'.$this->room['a'].'/access')->json('code');
        $this->app['auth']->forgetGuards();
        $this->post('/learn/'.$this->room['a'], ['code' => $code])->assertOk()->assertSee('เข้าสู่ระบบด้วยบัญชีโรงเรียน');
    }

    public function test_scoping_by_school_and_module_listing(): void
    {
        DB::table('lrn_links')->insert(['school_id' => 2, 'classroom_id' => $this->room['a'], 'scope_key' => 'c'.$this->room['a'].'|k0|t0', 'title' => 'โรงเรียนอื่น', 'url' => 'https://o.example.org/', 'url_hash' => sha1('https://o.example.org'),
            'kind' => 'app', 'sort' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($this->admin)->getJson('/api/learning/links')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, Links::q('lrn_links')->count());
        $this->assertContains('learning-links', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->get('/work')->assertOk()->assertSee('assets/modules/learning-links/learning.js', false)->assertSee('ลิงก์การเรียนรู้');
        $this->as($this->mk('editor', null))->get('/work')->assertOk()->assertDontSee('data-work-key="learning"', false);
        $this->as($this->ta)->get('/work')->assertOk()->assertSee('data-work-key="learning"', false);
        $opts = $this->as($this->ta)->getJson('/api/learning/options')->assertOk()->json();
        $this->assertSame([$this->room['a']], array_column($opts['classrooms'], 'id'));
        $this->assertFalse($opts['can_school_wide']);
    }
}
