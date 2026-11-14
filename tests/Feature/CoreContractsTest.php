<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\TermScope;
use App\Support\Saan\VersionConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        // MariaDB commits implicitly on DDL, so probe tables are created before seeding and a real transaction is reopened afterwards
        Schema::create('core_probe', function ($t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name'); $t->json('meta')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('core_probe2', function ($t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name'); $t->string('api_token')->nullable();
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create('core_probe3', function ($t) { $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name'); });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('START TRANSACTION');
            $this->beforeApplicationDestroyed(function () {
                foreach (['core_probe', 'core_probe2', 'core_probe3'] as $t) Schema::dropIfExists($t);
            });
        }
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->first();
    }

    public function test_settings_round_trip_and_school_scoping(): void
    {
        $this->assertSame('x', Settings::get('a.b', 'x'));
        Settings::put('a.b', ['n' => 1, 't' => 'ไทย']);
        $this->assertSame(['n' => 1, 't' => 'ไทย'], Settings::get('a.b'));
        Settings::put('a.b', 5);
        $this->assertSame(5, Settings::get('a.b'));
        $row = DB::table('saan_settings')->first();
        $this->assertSame(2, (int) $row->version);
        $this->assertSame(1, DB::table('saan_settings')->count());
        config(['school.school_id' => 99]);
        $this->assertNull(Settings::get('a.b'));
        Settings::put('a.b', 'other');
        $this->assertSame('other', Settings::get('a.b'));
        config(['school.school_id' => 1]);
        $this->assertSame(5, Settings::get('a.b'));
        $this->assertSame(2, DB::table('saan_settings')->count());
    }

    public function test_term_scope_fallback_chain(): void
    {
        $school = 55; // a school with no seeded terms (seeded rows are referenced by foreign keys)
        config(['school.school_id' => $school]);
        config(['school.term_id' => 9999]);
        $this->assertNull(TermScope::currentId());
        $this->assertNull(TermScope::current());
        try { TermScope::apply(DB::table('academic_terms')); $this->fail('expected DomainException'); }
        catch (\DomainException $e) { $this->assertSame('ยังไม่ได้ตั้งภาคเรียน', $e->getMessage()); }
        $old = DB::table('academic_terms')->insertGetId(['school_id' => $school, 'year' => 2560, 'term' => 1, 'starts_on' => '2017-05-15', 'ends_on' => '2017-09-30']);
        $new = DB::table('academic_terms')->insertGetId(['school_id' => $school, 'year' => 2561, 'term' => 2, 'starts_on' => '2018-11-01', 'ends_on' => '2019-03-01']);
        $this->assertSame($new, TermScope::currentId());
        config(['school.term_id' => $old]);
        $this->assertSame($old, TermScope::currentId());
        Settings::put('academic.current_term_id', $new);
        $this->assertSame($new, TermScope::currentId());
        Settings::put('academic.current_term_id', 123456);
        $this->assertSame($old, TermScope::currentId());
        Settings::put('academic.current_term_id', $new);
        $this->assertSame(2561, TermScope::current()->year);
        $this->assertSame(1, TermScope::apply(DB::table('academic_terms'), 'id')->count());
        $this->assertSame(1, TermScope::apply(DB::table('academic_terms'), 'id', $old)->count());
    }

    public function test_mutation_update_success_conflict_not_found_and_audit(): void
    {
        $this->actingAs($this->admin());
        $row = Mutation::insert('core_probe', ['name' => 'a', 'meta' => ['k' => 1]], 'probe.created', ['why' => 'test']);
        $this->assertSame(1, (int) $row->version);
        $this->assertSame((int) config('school.school_id'), (int) $row->school_id);
        $fresh = Mutation::update('core_probe', $row->id, 1, ['name' => 'b'], 'probe.updated', ['f' => 'name']);
        $this->assertSame('b', $fresh->name);
        $this->assertSame(2, (int) $fresh->version);
        $audit = DB::table('audit_events')->where('action', 'probe.updated')->first();
        $this->assertSame('core_probe', $audit->entity);
        $this->assertSame((int) $row->id, (int) $audit->entity_id);
        $this->assertSame($this->admin()->id, (int) $audit->actor_id);
        $this->assertSame(['f' => 'name'], json_decode($audit->detail, true));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'probe.created')->count());
        try { Mutation::update('core_probe', $row->id, 1, ['name' => 'stale'], 'probe.updated'); $this->fail('expected conflict'); }
        catch (VersionConflict $e) { $this->assertSame('b', $e->current['name']); }
        $this->assertSame('b', DB::table('core_probe')->first()->name);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'probe.updated')->count());
        try { Mutation::update('core_probe', 9999, 1, ['name' => 'x'], 'probe.updated'); $this->fail('expected 404'); }
        catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        $other = DB::table('core_probe')->insertGetId(['school_id' => 77, 'name' => 'o', 'version' => 1]);
        try { Mutation::update('core_probe', $other, 1, ['name' => 'x'], 'probe.updated'); $this->fail('expected 404'); }
        catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $this->assertSame('o', DB::table('core_probe')->find($other)->name); }
    }

    public function test_version_conflict_renders_as_409_json(): void
    {
        $this->actingAs($this->admin());
        $row = Mutation::insert('core_probe', ['name' => 'a'], 'probe.created');
        Route::middleware('web')->put('/__probe/{id}', fn (Request $r, int $id) => Mutation::update('core_probe', $id, (int) $r->input('version'), ['name' => 'z'], 'probe.updated'));
        $this->putJson('/__probe/'.$row->id, ['version' => 5])->assertStatus(409)
            ->assertJsonPath('message', 'ข้อมูลถูกแก้ไขโดยผู้อื่นแล้ว กรุณาโหลดใหม่')->assertJsonPath('current.name', 'a');
    }

    public function test_mutation_delete_checks_version_and_without_user_skips_audit(): void
    {
        $this->actingAs($this->admin());
        $row = Mutation::insert('core_probe', ['name' => 'a'], 'probe.created');
        $this->expectConflict(fn () => Mutation::delete('core_probe', $row->id, 7, 'probe.deleted'));
        $this->assertSame(1, DB::table('core_probe')->count());
        Mutation::delete('core_probe', $row->id, 1, 'probe.deleted');
        $this->assertSame(0, DB::table('core_probe')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'probe.deleted')->count());
        auth()->logout();
        $again = Mutation::insert('core_probe', ['name' => 'n'], 'probe.created');
        $before = DB::table('audit_events')->count();
        Mutation::update('core_probe', $again->id, 1, ['name' => 'm'], 'probe.updated');
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    private function expectConflict(\Closure $fn): void
    {
        try { $fn(); $this->fail('expected VersionConflict'); } catch (VersionConflict) { $this->assertTrue(true); }
    }

    public function test_idempotency_replays_per_key_and_runs_without_key(): void
    {
        Cache::flush();
        $this->actingAs($this->admin());
        $calls = 0;
        $fn = function () use (&$calls) { $calls++; return response()->json(['n' => $calls], 201); };
        $req = fn (?string $key) => tap(Request::create('/x', 'POST'), function ($r) use ($key) {
            if ($key !== null) $r->headers->set('Idempotency-Key', $key);
            $r->setUserResolver(fn () => $this->admin());
        });
        $a = Idempotency::run($req('k1'), $fn);
        $b = Idempotency::run($req('k1'), $fn);
        $this->assertSame(1, $calls);
        $this->assertSame(201, $b->getStatusCode());
        $this->assertSame(['n' => 1], json_decode($b->getContent(), true));
        $this->assertSame('true', $b->headers->get('Idempotent-Replayed'));
        Idempotency::run($req('k2'), $fn);
        $this->assertSame(2, $calls);
        Idempotency::run($req(null), $fn);
        Idempotency::run($req(null), $fn);
        $this->assertSame(4, $calls);
        Idempotency::run($req(str_repeat('a', 81)), $fn);
        Idempotency::run($req(str_repeat('a', 81)), $fn);
        $this->assertSame(6, $calls);
        $this->assertSame(1, json_decode(Idempotency::run($req('plain'), fn () => 1)->getContent(), true));
        $this->assertSame(1, json_decode(Idempotency::run($req('plain'), fn () => 2)->getContent(), true));
    }

    public function test_idempotency_is_per_user_and_concrete_path_and_skips_errors(): void
    {
        Cache::flush();
        $calls = 0;
        $fn = function () use (&$calls) { $calls++; return response()->json(['n' => $calls]); };
        $admin = $this->admin();
        $teacher = User::where('role', 'teacher')->first();
        $req = fn (User $u, string $path) => tap(Request::create($path, 'PUT'), function ($r) use ($u) {
            $r->headers->set('Idempotency-Key', 'same');
            $r->setUserResolver(fn () => $u);
        });
        Idempotency::run($req($admin, '/api/p/1'), $fn);
        Idempotency::run($req($teacher, '/api/p/1'), $fn);
        $this->assertSame(2, $calls);
        Idempotency::run($req($admin, '/api/p/2'), $fn);
        $this->assertSame(3, $calls);
        Idempotency::run($req($admin, '/api/p/2'), $fn);
        $this->assertSame(3, $calls);
        $err = function () use (&$calls) { $calls++; return response()->json(['message' => 'bad'], 422); };
        Idempotency::run($req($admin, '/api/p/3'), $err);
        Idempotency::run($req($admin, '/api/p/3'), $err);
        $this->assertSame(5, $calls);
    }

    public function test_idempotency_replays_model_rows_with_serializing_store(): void
    {
        config(['cache.stores.idemfile' => ['driver' => 'file', 'path' => storage_path('framework/cache/idem-test')]]);
        config(['cache.default' => 'idemfile']);
        Cache::purge('idemfile');
        Cache::flush();
        $this->actingAs($this->admin());
        $req = tap(Request::create('/api/p', 'POST'), function ($r) {
            $r->headers->set('Idempotency-Key', 'row-'.uniqid());
            $r->setUserResolver(fn () => $this->admin());
        });
        $calls = 0;
        $fn = function () use (&$calls) { $calls++; return Mutation::insert('core_probe', ['name' => 'ไทย'], 'probe.created'); };
        $a = Idempotency::run($req, $fn);
        $b = Idempotency::run($req, $fn);
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table('core_probe')->count());
        $this->assertSame('ไทย', json_decode($b->getContent(), true)['name']);
        $this->assertSame(json_decode($a->getContent(), true), json_decode($b->getContent(), true));
        Cache::flush();
    }

    public function test_settings_put_rethrows_non_duplicate_failures(): void
    {
        // no DDL here: MariaDB cannot roll a dropped table back. Fail the insert itself with a non-duplicate error.
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) {
            if (str_contains($query, 'insert into') && str_contains($query, 'saan_settings')) throw new \Illuminate\Database\QueryException($connection->getName(), $query, $bindings, new \Exception('forced non-duplicate failure'));
        });
        $this->expectException(\Illuminate\Database\QueryException::class);
        Settings::put('x', 1);
    }

    public function test_version_conflict_hides_secret_columns_and_insert_without_version_column(): void
    {
        $this->actingAs($this->admin());
        $row = Mutation::insert('core_probe2', ['name' => 'a', 'api_token' => 'secret'], 'probe2.created');
        try { Mutation::update('core_probe2', $row->id, 9, ['name' => 'b'], 'probe2.updated'); $this->fail('expected conflict'); }
        catch (VersionConflict $e) { $this->assertArrayNotHasKey('api_token', $e->current); $this->assertSame('a', $e->current['name']); }
        $this->assertSame('n', Mutation::insert('core_probe3', ['name' => 'n'], 'probe3.created')->name);
    }

    public function test_role_middleware_returns_thai_403(): void
    {
        Route::middleware(['web', 'auth', 'role:admin,principal'])->get('/__role', fn () => 'ok');
        $this->actingAs(User::where('role', 'teacher')->first())->get('/__role')->assertForbidden()->assertSee('บัญชีนี้ไม่มีสิทธิ์ใช้งานส่วนนี้');
        $this->actingAs($this->admin())->get('/__role')->assertOk();
    }

    private function fixtureRegistry(): void
    {
        $registry = new class extends ModuleRegistry {
            public function all(): array
            {
                // Real modules keep their manifests but drop /work entries so the fixture is measured alone.
                $base = array_map(fn ($m) => array_diff_key($m, ['work_menu' => 1, 'work_assets' => 1]), parent::all());
                $base['zz-fixture'] = [
                    'id' => 'zz-fixture', 'name' => 'Fixture', 'version' => '0.1.0', 'readiness' => 'beta', 'active' => true, 'reason' => 'ok',
                    'requires' => ['core' => '>=0.6.0', 'modules' => []], 'path' => sys_get_temp_dir(),
                    'work_menu' => [
                        ['key' => 'fixture', 'label' => 'หน้างานทดสอบ', 'path' => '/work#fixture', 'icon' => 'check', 'roles' => ['admin', 'teacher']],
                        ['key' => 'bad', 'label' => 'ห้ามแสดง', 'path' => '/work#Bad Path', 'icon' => 'check', 'roles' => ['admin', 'teacher']],
                        ['key' => 'adm', 'label' => 'เฉพาะแอดมิน', 'path' => '/work#adm/x', 'icon' => 'zzz', 'roles' => ['admin']],
                    ],
                    'work_assets' => ['assets/modules/zz-fixture/none.js', '../evil.js', 'assets/saan-work.js'],
                ];
                return $base;
            }
        };
        $this->app->instance(ModuleRegistry::class, $registry);
    }

    public function test_work_menu_and_assets_validation(): void
    {
        $this->fixtureRegistry();
        $reg = app(ModuleRegistry::class);
        $teacher = $reg->workMenu('teacher');
        $this->assertSame(['/work#fixture'], array_column($teacher, 'path'));
        $admin = $reg->workMenu('admin');
        $this->assertSame(['/work#fixture', '/work#adm/x'], array_column($admin, 'path'));
        $this->assertSame('web', $admin[1]['icon']);
        $this->assertSame([], $reg->workMenu('editor'));
        $this->assertSame([], $reg->workAssets());
        $dir = public_path('assets/modules/zz-fixture');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/page.js', '// fixture');
        try {
            $this->app->instance(ModuleRegistry::class, $this->fixtureWith(['work_assets' => ['assets/modules/zz-fixture/page.js', '../evil.js']]));
            $this->assertSame(['assets/modules/zz-fixture/page.js'], app(ModuleRegistry::class)->workAssets());
            $this->actingAs(User::where('role', 'teacher')->first())->get('/work')->assertOk()->assertSee('assets/modules/zz-fixture/page.js', false);
        } finally {
            @unlink($dir.'/page.js');
            @rmdir($dir);
        }
    }

    public function test_work_menu_drops_item_whose_key_differs_from_path(): void
    {
        $this->app->instance(ModuleRegistry::class, $this->fixtureWith(['work_menu' => [
            ['key' => 'plans', 'label' => 'ผิดคีย์', 'path' => '/work#plan-list', 'roles' => ['admin']],
            ['label' => 'ไม่ระบุคีย์', 'path' => '/work#plan-list/sub', 'roles' => ['admin']],
        ]]));
        $menu = app(ModuleRegistry::class)->workMenu('admin');
        $this->assertSame(['plan-list'], array_column($menu, 'key'));
    }

    public function test_work_js_boot_order_and_empty_state(): void
    {
        $work = file_get_contents(public_path('assets/saan-work.js'));
        $this->assertStringContainsString("document.readyState === 'complete'", $work);
        $this->assertStringNotContainsString("readyState === 'loading'", $work);
        $this->assertStringContainsString('if (!items.length) return;', $work);
    }

    private function fixtureWith(array $over): ModuleRegistry
    {
        return new class($over) extends ModuleRegistry {
            public function __construct(private array $over) {}
            public function all(): array
            {
                return ['zz-fixture' => $this->over + [
                    'id' => 'zz-fixture', 'name' => 'Fixture', 'version' => '0.1.0', 'readiness' => 'beta', 'active' => true, 'reason' => 'ok',
                    'requires' => ['core' => '>=0.6.0', 'modules' => []], 'path' => sys_get_temp_dir(),
                ]];
            }
        };
    }

    public function test_work_shell_renders_for_teacher_redirects_guest_and_lists_module_menu(): void
    {
        $this->get('/work')->assertRedirect('/login');
        $teacher = User::where('role', 'teacher')->first();
        $this->app->instance(ModuleRegistry::class, $this->fixtureWith([]));
        $this->actingAs($teacher)->get('/work')->assertOk()->assertSee('id="work-view"', false)->assertSee('ยังไม่มีโมดูลที่เปิดหน้างาน')
            ->assertSee('name="csrf-token"', false)->assertSee('name="school-base"', false)->assertSee('assets/saan-core.js', false);
        $this->fixtureRegistry();
        $this->get('/work')->assertOk()->assertSee('หน้างานทดสอบ')->assertDontSee('ห้ามแสดง')->assertDontSee('เฉพาะแอดมิน')
            ->assertSee('assets/saan-work.js', false)->assertSee('/work#fixture', false);
        $this->assertTrue(collect(\App\Support\SchoolNavigation::groups('teacher'))->flatMap(fn ($g) => $g['items'])->contains(fn ($i) => $i['path'] === '/work' && $i['label'] === 'งานโรงเรียน'));
        $this->assertFalse(collect(\App\Support\SchoolNavigation::groups('editor'))->flatMap(fn ($g) => $g['items'])->contains('path', '/work'));
    }

    public function test_core_js_keeps_old_api_and_adds_new(): void
    {
        $js = file_get_contents(public_path('assets/saan-core.js'));
        foreach (['esc(value)', 'today()', 'routeGuard()', 'api, date, uid', 'X-CSRF-TOKEN', 'school-base', 'th-TH-u-nu-latn', 'randomUUID', 'err.current'] as $needle) $this->assertStringContainsString($needle, $js);
        $work = file_get_contents(public_path('assets/saan-work.js'));
        foreach (['window.SaanWork', 'register(key', 'go(hash)', 'current()', 'controller.abort()', 'epoch'] as $needle) $this->assertStringContainsString($needle, $work);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $work);
    }
}