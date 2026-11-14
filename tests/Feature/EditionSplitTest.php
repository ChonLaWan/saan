<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Edition;
use App\Support\Saan\Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * S1: SAAN_EDITION (combined | internal | public), the route split, EditionGate, per-edition cookie/cache names, module edition
 * validation and the public projection declarations. Every edition test boots a fresh application with the env set, because
 * routes are registered once per boot.
 */
class EditionSplitTest extends TestCase
{
    /** @var array<string,array{getenv:string|false,env:mixed,server:mixed,had_env:bool,had_server:bool}> env values this test replaced (restored in tearDown) */
    private array $envSet = [];

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // always, even when the teardown throws: a leaked DB_DATABASE would run every later test of the suite on this test's temporary database
            foreach ($this->envSet as $k => $old) {
                if ($old['getenv'] === false) putenv($k); else putenv("$k={$old['getenv']}");
                if ($old['had_env']) $_ENV[$k] = $old['env']; else unset($_ENV[$k]);
                if ($old['had_server']) $_SERVER[$k] = $old['server']; else unset($_SERVER[$k]);
            }
            $this->envSet = [];
        }
    }

    private function bootEdition(string $edition, array $env = []): void
    {
        foreach (['SAAN_EDITION' => $edition] + $env as $k => $v) {
            // remember the value from before the first change (phpunit.xml fixes SAAN_EDITION=combined) so tearDown restores it exactly
            $this->envSet[$k] ??= ['getenv' => getenv($k), 'env' => $_ENV[$k] ?? null, 'server' => $_SERVER[$k] ?? null, 'had_env' => array_key_exists($k, $_ENV), 'had_server' => array_key_exists($k, $_SERVER)];
            putenv("$k=$v"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        $this->refreshApplication();
        $this->assertSame($edition, Edition::current());
    }

    private function freshDatabase(): void
    {
        config(['school.demo' => true]);
        $this->artisan('migrate:fresh')->run();
        $this->seed();
    }

    /** @return list<array{key:string,m:string,uri:string,mw:list<string>,action:string,name:?string}> */
    private function routes(): array
    {
        $out = [];
        foreach ($this->app['router']->getRoutes()->getRoutes() as $r) {
            $uri = '/'.ltrim($r->uri(), '/');
            if (str_contains($uri, 'fallbackPlaceholder')) continue;
            $out[] = ['key' => $r->methods()[0].' '.$uri, 'm' => $r->methods()[0], 'uri' => $uri, 'mw' => array_values(array_filter($r->gatherMiddleware(), 'is_string')),
                'action' => $r->getActionName(), 'name' => $r->getName()];
        }
        return $out;
    }

    private function keys(array $routes): array
    {
        return array_column($routes, 'key');
    }

    private function hasUri(array $routes, string $uri): bool
    {
        return (bool) array_filter($routes, fn ($r) => $r['uri'] === $uri);
    }

    private function authed(array $route): bool
    {
        return (bool) array_filter($route['mw'], fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:'));
    }

    // ---------------------------------------------------------------- Edition helper

    public function test_edition_helper_normalizes_and_rejects_unknown_values(): void
    {
        $this->assertSame('combined', Edition::normalize(null));
        $this->assertSame('combined', Edition::normalize(''));
        $this->assertSame('public', Edition::normalize(' Public '));
        $this->assertSame('internal', Edition::normalize('INTERNAL'));
        $this->expectException(\InvalidArgumentException::class);
        Edition::normalize('both');
    }

    public function test_edition_surfaces_matrix(): void
    {
        $expect = [
            'combined' => ['site' => true, 'staff' => true, 'guardian' => true, 'student' => true, 'api' => true, 'inbox' => true],
            'internal' => ['site' => false, 'staff' => true, 'guardian' => true, 'student' => true, 'api' => true, 'inbox' => true],
            'public' => ['site' => true, 'staff' => false, 'guardian' => false, 'student' => false, 'api' => false, 'inbox' => true],
        ];
        foreach ($expect as $edition => $surfaces) foreach ($surfaces as $surface => $serves) {
            $this->assertSame($serves, Edition::servesIn($edition, $surface), "$edition/$surface");
        }
        config(['saan.edition' => 'public']);
        $this->assertTrue(Edition::isPublic());
        $this->assertFalse(Edition::isInternal());
        $this->assertTrue(Edition::is('public'));
        $this->assertTrue(Edition::serves('site'));
        $this->assertFalse(Edition::serves('staff'));
        $this->expectException(\InvalidArgumentException::class);
        Edition::serves('nothing');
    }

    public function test_cookie_and_cache_suffix_helper(): void
    {
        $this->assertSame('', Edition::suffix('combined'));
        $this->assertSame('_public', Edition::suffix('public'));
        $this->assertSame('_internal', Edition::suffix('internal'));
    }

    public function test_blocked_paths_in_public_edition(): void
    {
        foreach (['/login', '/login/mfa', '/LOGIN', '//login', '/logout', '/forgot-password', '/reset-password/abc', '/cms', '/cms/x', '/work', '/operations', '/install', '/install/step',
            '/admin/users', '/account', '/guardian', '/guardian/api/children', '/student', '/student/api/me', '/student/forgot', '/api/student-portal/context', '/oauth/token', '/api/v1/students', '/api/state', '/api/site/content', '/api/cms/state', '/demo-login',
            '/theme-preview', '/content-preview/3', '/hero-preview/3', '/%6cogin', '/api/personnel/directory/x',
            // allowlist: prefixes that exist in the internal edition and were missing from the old block list, and anything unknown
            '/activities', '/documents/print', '/print/pp/student/1', '/timetable/print/class/1', '/exam-checker/exams/1/slips', '/maintenance', '/pbl', '/zz-unknown', '/robots.txt/x', '/media-x', '/portalx'] as $p) {
            $this->assertTrue(Edition::blockedInPublic($p), $p);
        }
        foreach (['/', '/portal', '/portal/news/x', '/portal/ita', '/portal/surveys/abc-def', '/portal/certificates/verify', '/learn/3', '/media/4', '/sitemap.xml', '/up',
            '/api/personnel/directory', '/api/personnel/directory/', '/portal/staff-photo/'.str_repeat('a', 32).'.jpg', '/portal/login-help'] as $p) {
            $this->assertFalse(Edition::blockedInPublic($p), $p);
        }
    }

    public function test_admin_url_helper_accepts_only_clean_http_urls(): void
    {
        config(['saan.internal_url' => '']);
        $this->assertNull(Edition::adminUrl());
        config(['saan.internal_url' => 'https://app.school.ac.th/']);
        $this->assertSame('https://app.school.ac.th', Edition::adminUrl());
        config(['saan.internal_url' => 'http://localhost:8080']);
        $this->assertSame('http://localhost:8080', Edition::adminUrl());
        foreach (['javascript:alert(1)', 'app.school.ac.th', 'https://user:pw@app.school.ac.th', 'https://a b.example', 'https://app.school.ac.th/"onmouseover="x', '//app.school.ac.th'] as $bad) {
            config(['saan.internal_url' => $bad]);
            $this->assertNull(Edition::adminUrl(), $bad);
        }
    }

    // ---------------------------------------------------------------- route lists per edition

    /** Public edition on a temporary database whose published module list names every module (what a synced public install has). */
    private function bootPublicWithAllModules(): void
    {
        $this->bootPublicWithSettings([]);
    }

    public function test_public_edition_registers_no_internal_surface(): void
    {
        $this->bootPublicWithAllModules();
        $routes = $this->routes();
        $this->assertNotEmpty($routes);
        foreach ($routes as $r) {
            $this->assertFalse(Edition::blockedInPublic($r['uri']), 'internal route registered in public edition: '.$r['key']);
            $this->assertFalse($this->authed($r), 'public route behind auth: '.$r['key']);
            foreach ($r['mw'] as $m) $this->assertFalse(str_starts_with($m, 'role:'), 'role middleware in public edition: '.$r['key']);
        }
        $this->assertFalse(Route::has('login'));
        foreach (['/', '/portal', '/portal/directory', '/portal/contact', '/portal/search', '/sitemap.xml', '/media/{id}', '/portal/{section}', '/portal/{section}/{slug}',
            '/portal/ita', '/portal/ita/{fiscalYear}', '/portal/notices/{slug}', '/portal/surveys/{slug}', '/portal/surveys/{slug}/submit', '/portal/certificates/verify',
            '/portal/certificates/{no}', '/learn/{id}', '/api/personnel/directory', '/portal/staff-photo/{file}'] as $uri) {
            $this->assertTrue($this->hasUri($routes, $uri), "missing public route $uri");
        }
        foreach ($routes as $r) {
            $this->assertDoesNotMatchRegularExpression('~^/(guardian|student|api/student-portal|oauth|api/v1|api/api-gateway|api/site|api/cms|api/ita|api/notices|api/surveys|api/learning|api/state)~', $r['uri'], $r['key']);
        }
        $front = array_values(array_filter($routes, fn ($r) => $r['key'] === 'GET /'))[0];
        $this->assertStringEndsWith('SchoolWebsiteController@home', $front['action']);
        // the fallback still answers 404
        $this->assertTrue((bool) array_filter($this->app['router']->getRoutes()->getRoutes(), fn ($r) => $r->isFallback));
    }

    public function test_internal_edition_has_no_public_pages_except_authenticated_previews(): void
    {
        $this->bootEdition('internal');
        $routes = $this->routes();
        foreach (['/login', '/login/mfa', '/forgot-password', '/logout', '/cms', '/work', '/operations', '/account', '/admin/users', '/api/site/state', '/api/state',
            '/theme-preview', '/content-preview/{id}', '/hero-preview/{id}', '/guardian', '/student', '/oauth/token', '/oauth/authorize', '/api/v1/students', '/api/ita/years',
            '/api/notices/lists', '/api/surveys', '/api/learning/links', '/api/personnel/staff', '/api/guardian-portal/context'] as $uri) {
            $this->assertTrue($this->hasUri($routes, $uri), "missing internal route $uri");
        }
        foreach ($routes as $r) {
            if (preg_match('~^/(portal|learn|sitemap\.xml)~', $r['uri'])) {
                $this->assertSame('/portal/staff-photo/{file}', $r['uri'], 'public page registered in internal edition: '.$r['key']);
                $this->assertTrue($this->authed($r), 'staff photo must need a sign-in in the internal edition');
            }
            $this->assertNotSame('/api/personnel/directory', $r['uri']);
            $this->assertFalse(str_contains($r['action'], 'SchoolWebsiteController@') && !in_array($r['uri'], ['/theme-preview', '/content-preview/{id}', '/hero-preview/{id}'], true), $r['key']);
        }
        foreach (['/theme-preview', '/content-preview/{id}', '/hero-preview/{id}'] as $uri) {
            $r = array_values(array_filter($routes, fn ($x) => $x['uri'] === $uri))[0];
            $this->assertTrue($this->authed($r), "$uri must require staff auth");
        }
        $media = array_values(array_filter($routes, fn ($r) => $r['uri'] === '/media/{id}'));
        $this->assertCount(1, $media);
        $this->assertTrue($this->authed($media[0]));
        $front = array_values(array_filter($routes, fn ($r) => $r['key'] === 'GET /'))[0];
        $this->assertSame('Closure', $front['action']);
    }

    public function test_group_hub_routes_follow_the_edition_split(): void
    {
        // public edition: the hub pages and the counting download are there (inside /portal), the staff screens and APIs are not
        $this->bootPublicWithAllModules();
        $routes = $this->routes();
        foreach (['/portal/groups', '/portal/groups/{slug}', '/portal/groups/{slug}/docs/{cat}', '/portal/groups/{slug}/news', '/portal/d/{id}'] as $uri) $this->assertTrue($this->hasUri($routes, $uri), "missing public route $uri");
        foreach ($routes as $r) {
            $this->assertDoesNotMatchRegularExpression('~^/(api/cms/hubs|hub-preview)~', $r['uri'], $r['key']);
            if (!str_contains($r['action'], 'WebCms')) continue;
            $this->assertFalse(Edition::blockedInPublic($r['uri']), $r['key']);
            $this->assertFalse($this->authed($r), $r['key']);
            $this->assertSame('GET', $r['m'], 'the public hub surface is read only: '.$r['key']);
        }
        $dl = array_values(array_filter($routes, fn ($r) => $r['uri'] === '/portal/d/{id}'))[0];
        $this->assertNotEmpty(array_filter($dl['mw'], fn ($m) => str_starts_with($m, 'throttle:')), 'the counting route is rate limited');
        $this->assertTrue(Edition::blockedInPublic('/api/cms/hubs'));
        $this->assertTrue(Edition::blockedInPublic('/hub-preview/1'));
        $this->assertFalse(Edition::blockedInPublic('/portal/d/1'));
        // internal edition: staff screens only, all behind sign-in and a role check, no public hub page
        $this->bootEdition('internal');
        $routes = $this->routes();
        foreach (['/api/cms/hubs', '/api/cms/hubs/{id}', '/api/cms/hubs/{id}/documents', '/api/cms/hubs/documents/{did}/file', '/hub-preview/{id}'] as $uri) {
            $r = array_values(array_filter($routes, fn ($x) => $x['uri'] === $uri));
            $this->assertNotEmpty($r, "missing internal route $uri");
            foreach ($r as $x) {
                $this->assertTrue($this->authed($x), $x['key']);
                $this->assertNotEmpty(array_filter($x['mw'], fn ($m) => str_starts_with($m, 'role:')), 'hub routes check the role: '.$x['key']);
            }
        }
        foreach (['/portal/groups', '/portal/groups/{slug}', '/portal/d/{id}'] as $uri) $this->assertFalse($this->hasUri($routes, $uri), "public hub route in the internal edition: $uri");
    }

    public function test_every_internal_route_is_blocked_in_public_or_explicitly_shared(): void
    {
        // independent of the public test above: that one uses blockedInPublic() as its own check
        $this->bootEdition('internal');
        foreach ($this->routes() as $r) {
            $seg = Edition::firstSegment($r['uri']);
            $this->assertTrue(Edition::blockedInPublic($r['uri']) || in_array($seg, Edition::SHARED_SEGMENTS, true) || in_array(strtolower(trim($r['uri'], '/')), Edition::PUBLIC_API_ALLOW, true),
                'internal route would be reachable in the public edition: '.$r['key']);
        }
        // and the other way: every public-edition route sits inside the allowlist
        $this->bootEdition('public');
        foreach ($this->routes() as $r) {
            $this->assertTrue(in_array(Edition::firstSegment($r['uri']), array_merge(Edition::PUBLIC_ALLOWED, ['']), true) || in_array(strtolower(trim($r['uri'], '/')), Edition::PUBLIC_API_ALLOW, true), $r['key']);
        }
    }

    public function test_combined_edition_is_the_union_of_both_without_duplicates(): void
    {
        $this->bootEdition('combined');
        $combined = $this->routes();
        $keys = $this->keys($combined);
        $this->assertSame(array_values(array_unique($keys)), $keys, 'a route is registered twice: '.implode(', ', array_diff_assoc($keys, array_unique($keys))));
        foreach (['/login', '/cms', '/work', '/portal', '/portal/ita', '/portal/notices/{slug}', '/portal/surveys/{slug}', '/learn/{id}', '/guardian', '/student', '/oauth/token', '/api/v1/students',
            '/api/personnel/directory', '/portal/staff-photo/{file}', '/media/{id}', '/theme-preview', '/sitemap.xml'] as $uri) {
            $this->assertTrue($this->hasUri($combined, $uri), "combined lost $uri");
        }
        $front = array_values(array_filter($combined, fn ($r) => $r['key'] === 'GET /'))[0];
        $this->assertStringEndsWith('SchoolWebsiteController@entry', $front['action'], 'combined keeps the old front door');
        $media = array_values(array_filter($combined, fn ($r) => $r['uri'] === '/media/{id}'));
        $this->assertFalse($this->authed($media[0]), 'combined keeps guest media');
        $this->assertTrue(Route::has('login'));

        $this->bootPublicWithAllModules();
        $public = $this->keys($this->routes());
        $this->bootEdition('internal');
        $internal = $this->keys($this->routes());
        $this->assertSame([], array_values(array_diff($public, $keys)), 'public routes missing from combined');
        $this->assertSame([], array_values(array_diff($internal, $keys)), 'internal routes missing from combined');
        $this->assertSame([], array_values(array_diff($keys, $public, $internal)), 'combined has routes that no edition owns');
        // shared by design: the front door, the media/photo files (guest in public, behind sign-in in internal) and the framework health check
        $overlap = array_values(array_intersect($public, $internal));
        sort($overlap);
        // plus the token-guarded finish step of an update, which each edition needs for its own app root
        $this->assertSame(['GET /', 'GET /media/{id}', 'GET /portal/staff-photo/{file}', 'GET /up', 'POST /_saan/update-finish'], $overlap, 'public and internal overlap');
    }

    // ---------------------------------------------------------------- EditionGate

    public function test_edition_gate_answers_404_in_public_edition_even_for_a_route_registered_by_mistake(): void
    {
        $this->bootEdition('public');
        Route::middleware('web')->get('/cms', fn () => 'leaked');
        Route::middleware('web')->get('/api/state', fn () => 'leaked');
        $this->get('/cms')->assertNotFound();
        $this->get('/api/state')->assertNotFound();
        foreach (['/login', '/logout', '/forgot-password', '/work', '/operations', '/install', '/guardian', '/student', '/student/forgot', '/oauth/authorize', '/api/v1/students', '/api/site/state', '/admin/users', '/LOGIN', '//login'] as $p) {
            $this->get($p)->assertNotFound();
        }
        $this->post('/login', ['email' => 'a@example.test', 'password' => 'x'])->assertNotFound();
        $this->post('/oauth/token')->assertNotFound();
        $this->post('/demo-login')->assertNotFound();
        $this->getJson('/api/state')->assertNotFound();
        $this->get('/up')->assertOk();
    }

    public function test_edition_gate_leaves_other_editions_alone(): void
    {
        $this->bootEdition('internal');
        $this->get('/login')->assertOk();
        $this->bootEdition('combined');
        $this->get('/login')->assertOk();
    }

    public function test_internal_edition_front_door_and_public_pages(): void
    {
        $this->bootEdition('internal');
        $this->freshDatabase();
        $this->get('/')->assertRedirect('/login');
        foreach (['/portal', '/portal/news', '/portal/directory', '/portal/contact', '/portal/search', '/sitemap.xml', '/portal/ita', '/learn/1', '/portal/surveys/abc-def', '/api/personnel/directory'] as $p) {
            $this->get($p)->assertNotFound();
        }
        $this->get('/theme-preview')->assertRedirect();
        $this->get('/media/1')->assertRedirect();
        $this->actingAs(User::where('role', 'admin')->first());
        $this->get('/')->assertRedirect('/work');
    }

    // ---------------------------------------------------------------- admin link

    public function test_public_site_admin_link_follows_internal_url(): void
    {
        $this->bootEdition('public', ['SAAN_INTERNAL_URL' => 'https://app.school.ac.th']);
        $this->freshDatabase();
        $html = $this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('class="site-admin-link" href="https://app.school.ac.th"', $html);
        $this->assertStringContainsString('ผู้ดูแลระบบ', $html);

        config(['saan.internal_url' => '']);
        $html = $this->get('/portal')->assertOk()->getContent();
        $this->assertStringNotContainsString('site-admin-link', $html);

        config(['saan.internal_url' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('site-admin-link', $this->get('/portal')->assertOk()->getContent());
    }

    public function test_combined_site_has_no_admin_link_by_default(): void
    {
        $this->bootEdition('combined');
        $this->freshDatabase();
        $this->assertStringNotContainsString('site-admin-link', $this->get('/portal')->assertOk()->getContent());
    }

    // ---------------------------------------------------------------- cookie and cache names

    public function test_session_cookie_and_cache_prefix_differ_per_edition(): void
    {
        $cookies = [];
        $prefixes = [];
        foreach (['combined', 'internal', 'public'] as $e) {
            $this->bootEdition($e);
            $cookies[$e] = config('session.cookie');
            $prefixes[$e] = config('cache.prefix');
        }
        $this->assertCount(3, array_unique($cookies), json_encode($cookies));
        $this->assertCount(3, array_unique($prefixes), json_encode($prefixes));
        $this->assertStringEndsWith('_internal', $cookies['internal']);
        $this->assertStringEndsWith('_public', $cookies['public']);
        $this->assertSame($cookies['combined'].'_public', $cookies['public']);
        $this->assertStringEndsNotWith('_public', $cookies['combined']);
        $this->assertStringEndsNotWith('_internal', $cookies['combined']);
    }

    public function test_the_cookie_a_response_sets_carries_the_edition_name(): void
    {
        $this->bootEdition('public');
        $this->freshDatabase();
        $names = array_map(fn ($c) => $c->getName(), $this->get('/portal')->assertOk()->headers->getCookies());
        $this->assertContains(config('session.cookie'), $names);
        $this->assertStringEndsWith('_public', config('session.cookie'));
    }

    // ---------------------------------------------------------------- module edition

    public function test_modules_declare_valid_editions(): void
    {
        $this->bootEdition('combined');
        $registry = app(ModuleRegistry::class);
        $modules = $registry->all();
        $this->assertNotEmpty($modules);
        foreach ($modules as $id => $m) {
            $this->assertContains($m['edition'], ModuleRegistry::EDITIONS, $id);
            if (is_file($m['path'].'/routes/public.php')) $this->assertContains($m['edition'], ['public', 'both'], "$id ships routes/public.php");
        }
        foreach (['web-ita', 'web-notices', 'surveys', 'learning-links', 'personnel', 'web-social', 'web-cms'] as $id) $this->assertSame('both', $modules[$id]['edition'], $id);
        foreach (['guardian-portal', 'api-gateway'] as $id) $this->assertSame('internal', $modules[$id]['edition'], $id);
        $this->assertSame('internal', $modules['finance']['edition'], 'default for a module without public routes');
        foreach (['web-ita', 'web-notices', 'surveys', 'learning-links', 'personnel'] as $id) $this->assertFileExists($modules[$id]['path'].'/routes/public.php');
    }

    private function validateModuleJson(array $override): void
    {
        $base = ['id' => 'zz-x', 'name' => 'x', 'version' => '1.0.0', 'readiness' => 'beta', 'requires' => ['core' => '>=0.1.0', 'modules' => []]];
        $registry = new ModuleRegistry();
        $m = new \ReflectionMethod($registry, 'validate');
        $m->invoke($registry, $override + $base, 'modules/zz-x/module.json');
    }

    public function test_module_registry_validates_edition_and_projection_shape(): void
    {
        $this->validateModuleJson([]);
        foreach (['internal', 'public', 'both'] as $e) $this->validateModuleJson(['edition' => $e]);
        $this->validateModuleJson(['public_projection' => [['table' => 'posts', 'columns' => ['id' => 'keep']]]]);
        foreach ([['edition' => 'combined'], ['edition' => ''], ['edition' => 7], ['edition' => null],
            ['public_projection' => 'posts'], ['public_projection' => ['table' => 'posts']], ['public_projection' => ['posts']]] as $bad) {
            try {
                $this->validateModuleJson($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('module.json', $e->getMessage());
            }
        }
    }

        /** Writes a throw-away module under a temp modules root (never under the real modules/ folder). */
    private function tempModules(array $files): string
    {
        $root = sys_get_temp_dir().'/saan-modules-'.bin2hex(random_bytes(6));
        foreach ($files as $rel => $content) {
            if (!is_dir(dirname("$root/$rel"))) mkdir(dirname("$root/$rel"), 0777, true);
            file_put_contents("$root/$rel", is_array($content) ? json_encode($content) : $content);
        }
        return $root;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $p) {
            if (in_array(basename($p), ['.', '..'], true)) continue;
            is_dir($p) ? $this->removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function probeJson(array $extra = []): array
    {
        return $extra + ['id' => 'zz-edition-probe', 'name' => 'probe', 'version' => '1.0.0', 'readiness' => 'beta', 'requires' => ['core' => '>=0.1.0', 'modules' => []]];
    }

    public function test_module_edition_must_match_the_route_files_it_ships(): void
    {
        $dirs = [];
        try {
            // internal + routes/public.php: refused
            $dirs[] = $root = $this->tempModules(['zz-edition-probe/module.json' => $this->probeJson(['edition' => 'internal']), 'zz-edition-probe/routes/public.php' => "<?php\n"]);
            try { (new ModuleRegistry($root))->all(); $this->fail('internal module with routes/public.php was accepted'); }
            catch (RuntimeException $e) { $this->assertStringContainsString('routes/public.php', $e->getMessage()); }
            // public + routes/web.php: refused (no edition would ever load that file)
            $dirs[] = $root = $this->tempModules(['zz-edition-probe/module.json' => $this->probeJson(['edition' => 'public']), 'zz-edition-probe/routes/public.php' => "<?php\n", 'zz-edition-probe/routes/web.php' => "<?php\n"]);
            try { (new ModuleRegistry($root))->all(); $this->fail('public module with routes/web.php was accepted'); }
            catch (RuntimeException $e) { $this->assertStringContainsString('routes/web.php', $e->getMessage()); }
            // public with only public.php, and the defaults
            $dirs[] = $root = $this->tempModules(['zz-edition-probe/module.json' => $this->probeJson(['edition' => 'public']), 'zz-edition-probe/routes/public.php' => "<?php\n"]);
            $this->assertSame('public', (new ModuleRegistry($root))->all()['zz-edition-probe']['edition']);
            $dirs[] = $root = $this->tempModules(['zz-edition-probe/module.json' => $this->probeJson(), 'zz-edition-probe/routes/web.php' => "<?php\n"]);
            $this->assertSame('internal', (new ModuleRegistry($root))->all()['zz-edition-probe']['edition']);
            $dirs[] = $root = $this->tempModules(['zz-edition-probe/module.json' => $this->probeJson(), 'zz-edition-probe/routes/public.php' => "<?php\n"]);
            $this->assertSame('both', (new ModuleRegistry($root))->all()['zz-edition-probe']['edition']);
        } finally {
            foreach ($dirs as $d) $this->removeDir($d);
        }
        // the real modules folder was never touched
        $this->assertDirectoryDoesNotExist(base_path('modules/zz-edition-probe'));
    }

    /** A fully migrated database of the given edition, built once per test process and cloned per test (migrate:fresh takes minutes on the full schema). */
    private function editionTemplate(string $edition): string
    {
        return $this->templateDatabase('edition-'.$edition, function (string $db) use ($edition): void {
            $this->bootEdition($edition, ['DB_DATABASE' => $db]);
            $this->artisan('migrate:fresh')->run();
        });
    }

    /** Boots the public edition on a temporary database (a clone of the migrated template) so that module switches exist before routes are registered. */
    private function bootPublicWithSettings(array $settings, ?array $published = null): string
    {
        $db = $this->tempDatabase($this->editionTemplate('public'));
        $this->bootEdition('public', ['DB_DATABASE' => $db]);
        // S2: the public edition serves only the modules named in pub_active_modules (null here = all of them, [] = none)
        foreach ($published ?? array_keys(app(ModuleRegistry::class)->all()) as $id) \Illuminate\Support\Facades\DB::table('pub_active_modules')->insert(['module_id' => $id]);
        foreach ($settings as $k => $v) {
            \Illuminate\Support\Facades\DB::table('saan_settings')->insert(['school_id' => (int) config('school.school_id'), 'key' => $k, 'value' => json_encode($v), 'version' => 1]);
        }
        $this->refreshApplication();
        $this->assertSame('public', Edition::current());
        return $db;
    }

    public function test_module_switched_off_on_the_internal_side_has_no_public_route(): void
    {
        $this->bootPublicWithSettings(['modules.disabled' => ['surveys', 'learning-links']]);
        $routes = $this->routes();
        $this->assertTrue($this->hasUri($routes, '/portal/ita'), 'an enabled module keeps its public routes');
        $this->assertFalse($this->hasUri($routes, '/portal/surveys/{slug}/submit'));
        $this->assertFalse($this->hasUri($routes, '/portal/certificates/verify'));
        $this->assertFalse($this->hasUri($routes, '/learn/{id}'));
        $this->assertFalse(app(ModuleRegistry::class)->active('surveys'));
    }

    public function test_public_edition_uses_the_published_active_module_list_as_allowlist(): void
    {
        $this->bootPublicWithSettings([], ['web-cms', 'web-ita']);
        $routes = $this->routes();
        $this->assertTrue($this->hasUri($routes, '/portal/ita'));
        foreach (['/portal/notices/{slug}', '/portal/surveys/{slug}', '/learn/{id}', '/api/personnel/directory'] as $uri) $this->assertFalse($this->hasUri($routes, $uri), $uri);
        $reg = app(ModuleRegistry::class);
        $this->assertTrue($reg->active('web-ita'));
        $this->assertFalse($reg->active('web-notices'));
        $this->assertStringContainsString('รายชื่อโมดูลที่เผยแพร่', $reg->get('web-notices')['reason']);
    }

    public function test_public_edition_with_an_empty_published_module_list_registers_no_module_routes(): void
    {
        $this->bootPublicWithSettings([], []); // S2: deny by default, nothing published yet (or the school switched everything off)
        $routes = $this->routes();
        foreach (['/portal/ita', '/portal/ita/{fiscalYear}', '/portal/notices/{slug}', '/portal/surveys/{slug}', '/portal/certificates/verify', '/learn/{id}', '/api/personnel/directory', '/portal/staff-photo/{file}'] as $uri) {
            $this->assertFalse($this->hasUri($routes, $uri), "module route $uri registered with an empty published list");
        }
        foreach (['/', '/portal', '/portal/search', '/media/{id}', '/_saan/push'] as $uri) $this->assertTrue($this->hasUri($routes, $uri), "core public route $uri missing");
        $reg = app(ModuleRegistry::class);
        foreach (array_keys($reg->all()) as $id) $this->assertFalse($reg->active($id), $id);
        $this->assertSame([], ModuleRegistry::publishedModules());
        // no table at all (a public database that was never migrated) is the same: nothing is served
        $this->bootEdition('public', ['DB_DATABASE' => $this->tempDatabase()]);
        $this->assertSame([], ModuleRegistry::publishedModules());
        $this->assertFalse(app(ModuleRegistry::class)->active('web-ita'));
    }

    public function test_the_published_module_list_does_not_limit_the_internal_edition(): void
    {
        $tpl = $this->editionTemplate('internal');
        $this->bootEdition('internal', ['DB_DATABASE' => $this->tempDatabase($tpl)]);
        \Illuminate\Support\Facades\DB::table('pub_active_modules')->insert(['module_id' => 'web-cms']);
        $this->refreshApplication();
        $this->assertTrue(app(ModuleRegistry::class)->active('web-ita'));
    }

    // ---------------------------------------------------------------- projection declarations

    public function test_forbidden_tables_list_is_complete(): void
    {
        $list = Projection::forbiddenTables();
        foreach (['users', 'password_reset_tokens', 'password_invite_tokens', 'sessions', 'students', 'reg_*', 'hlth_*', 'bhv_*', 'attendances', 'att_*', 'asm_*', 'grade_entries', 'fin_*', 'doc_*', 'per_files',
            'ld_*', 'leave_requests', 'duties', 'gp_*', 'api_*', 'exm_*', 'mnt_*', 'prj_*', 'act_*', 'con_*', 'srv_responses', 'srv_roster', 'srv_pending', 'audit_events',
            'saan_settings', 'system_updates', 'jobs', 'cache'] as $t) $this->assertContains($t, $list, $t);
        foreach (['users', 'reg_guardians', 'hlth_profiles', 'fin_invoices', 'doc_files', 'per_files', 'ld_leave_types', 'gp_links', 'api_tokens', 'srv_responses', 'saan_settings', 'cache'] as $t) {
            $this->assertTrue(Projection::isForbiddenTable($t), $t);
        }
        foreach (['posts', 'cms_media', 'per_profiles', 'srv_surveys', 'lrn_links', 'pub_certificates', 'pub_notice_rows'] as $t) $this->assertFalse(Projection::isForbiddenTable($t), $t);
        // raw notice names and the machinery of the split itself can never be declared
        foreach (['notice_rows', 'saan_inbox_quarantine', 'saan_inbox_done', 'saan_pairing_codes', 'pub_outbox', 'pub_sent_rows', 'pub_sync_state', 'pub_inbox', 'pub_inbox_nonces'] as $t) $this->assertTrue(Projection::isForbiddenTable($t), $t);
    }

    public function test_declared_projection_is_valid_against_the_real_schema_and_never_touches_forbidden_data(): void
    {
        $this->bootEdition('combined');
        $this->artisan('migrate:fresh')->run();
        $declared = Projection::declared(true);
        $tables = array_column($declared, 'table');
        $this->assertSame(array_values(array_unique($tables)), $tables);
        foreach (['posts', 'cms_categories', 'cms_media', 'cms_site_settings', 'cms_themes', 'ita_years', 'ita_topics', 'ita_items', 'notice_lists', 'pub_notice_rows', 'srv_surveys',
            'pub_certificates', 'per_profiles', 'teachers', 'lrn_links', 'lrn_access', 'pub_learning_rooms', 'academic_terms', 'saan_settings', 'social_feeds'] as $t) $this->assertContains($t, $tables, "undeclared $t");
        $byTable = array_column($declared, null, 'table');
        foreach ($declared as $e) {
            if ($e['table'] === 'saan_settings') {
                // the single forbidden table with an exception: pinned to whitelisted keys (asserted below)
                $this->assertSame('core', $e['source']);
            } else {
                $this->assertFalse(Projection::isForbiddenTable($e['table']), $e['table']);
            }
            foreach ($e['columns'] as $col => $rule) {
                if ($rule === 'keep') $this->assertNotContains($col, Projection::neverKeepColumns(), $e['table'].'.'.$col);
                $this->assertDoesNotMatchRegularExpression('/password|secret|api_key|remember_token/', $col.($rule === 'keep' ? '' : 'x'));
            }
        }
        // the explicit examples from the plan
        $posts = $byTable['posts']['columns'];
        $this->assertSame('null', $posts['draft_payload']);
        $this->assertStringStartsWith('const:', $posts['author_id']);
        $this->assertSame('null', $posts['scheduled_by']);
        $this->assertArrayNotHasKey('users', $byTable);
        $this->assertSame([['column' => 'status', 'op' => '=', 'value' => 'published'], ['column' => 'deleted_at', 'op' => 'null', 'value' => null]], $byTable['posts']['where']);
        $this->assertSame('published_media', $byTable['cms_media']['filter']);
        $this->assertStringStartsWith('const:', $byTable['cms_media']['columns']['uploaded_by']);
        // notice rows travel only as the derived, already masked output of NoticeRepository::mask (never per-column rules that ignore the list settings)
        $this->assertArrayNotHasKey('notice_rows', $byTable);
        $this->assertSame('notice_masked_rows', $byTable['pub_notice_rows']['derived']);
        $this->assertSame(['id', 'school_id', 'list_id', 'order_no', 'cells', 'full_name_until'], array_keys($byTable['pub_notice_rows']['columns'])); // F9: full cells carry their expiry
        // learning links: only what Links::publicLinks can show, plus the classroom names and the current term it reads
        $this->assertSame('learning_public_links', $byTable['lrn_links']['filter']);
        $this->assertSame('learning_rooms', $byTable['pub_learning_rooms']['derived']);
        $this->assertSame(['id', 'school_id', 'name'], array_keys($byTable['pub_learning_rooms']['columns']));
        $this->assertSame('current_term', $byTable['academic_terms']['filter']);
        $this->assertSame('null', $byTable['lrn_links']['columns']['api_client_id']);
        // module switches are the only saan_settings keys that leave the internal side
        $settings = $byTable['saan_settings'];
        $this->assertSame('core', $settings['source']);
        $this->assertSame([['column' => 'key', 'op' => 'in', 'value' => ['modules.enabled', 'modules.disabled']]], $settings['where']);
        $this->assertSame([], array_diff($settings['where'][0]['value'], array_keys(Projection::SETTINGS_KEY_WHITELIST)));
        $this->assertSame('certificate_verify', $byTable['pub_certificates']['derived']);
        $this->assertSame('mask_name', $byTable['pub_certificates']['columns']['holder_name']);
        $this->assertSame('open', $byTable['srv_surveys']['where'][0]['value']);
        $this->assertSame('null', $byTable['per_profiles']['columns']['education']);
        $this->assertSame('core', $byTable['posts']['source']);
        $this->assertSame('web-ita', $byTable['ita_years']['source']);
        // a column outside the list is never exported
        $this->assertArrayNotHasKey('password', $byTable['teachers']['columns']);
        $this->assertArrayNotHasKey('answers', $byTable['srv_surveys']['columns']);
    }

    /** Adds a rule for every NOT NULL column without a default (the schema check demands them), so a test entry fails only for its own flaw. */
    private function fill(array $entry): array
    {
        if (!is_array($entry['columns'] ?? null) || $entry['columns'] === [] || array_is_list($entry['columns']) || !Schema::hasTable($entry['table'])) return $entry;
        foreach (Schema::getColumns($entry['table']) as $col) {
            if ($col['nullable'] || $col['default'] !== null || array_key_exists($col['name'], $entry['columns'])) continue;
            $never = in_array($col['name'], Projection::neverKeepColumns(), true) || preg_match('/(password|passwd|secret|api_key|(^|_)token$)/', $col['name']);
            $entry['columns'][$col['name']] = $never ? 'const:1' : 'keep';
        }
        return $entry;
    }

    public function test_projection_requires_a_rule_for_every_not_null_column_without_default(): void
    {
        $this->bootEdition('combined');
        $this->artisan('migrate:fresh')->run();
        try {
            Projection::validate([['table' => 'posts', 'columns' => ['id' => 'keep']]], 't', true);
            $this->fail('a NOT NULL column without a rule was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('NOT NULL', $e->getMessage());
        }
        // the same entry is fine once the missing columns are listed, and a derived entry is exempt (its extractor fills the pub_* table)
        $this->assertCount(1, Projection::validate([$this->fill(['table' => 'posts', 'columns' => ['id' => 'keep']])], 't', true));
        $this->assertCount(1, Projection::validate([['table' => 'pub_x', 'derived' => 'notice_masked_rows', 'columns' => ['id' => 'keep']]], 't', true));
        // a column with a database default may be left out (saan_settings.version)
        $this->assertCount(1, Projection::validate([['table' => 'saan_settings', 'where' => ['key' => 'modules.enabled'], 'columns' => ['id' => 'keep', 'school_id' => 'keep', 'key' => 'keep']]], 't', true));
    }

    public function test_projection_validation_rejects_bad_declarations(): void
    {
        $this->bootEdition('combined');
        $this->artisan('migrate:fresh')->run();
        $ok = $this->fill(['table' => 'posts', 'columns' => ['id' => 'keep']]);
        $this->assertCount(1, Projection::validate([$ok], 't', true));
        $bad = [
            'unknown table' => ['table' => 'no_such_table', 'columns' => ['id' => 'keep']],
            'unknown column' => ['table' => 'posts', 'columns' => ['no_such_column' => 'keep']],
            'unknown where column' => ['table' => 'posts', 'where' => ['no_such_column' => 'x'], 'columns' => ['id' => 'keep']],
            'forbidden users' => ['table' => 'users', 'columns' => ['name' => 'keep']],
            'forbidden prefix' => ['table' => 'fin_invoices', 'columns' => ['id' => 'keep']],
            'forbidden srv_responses' => ['table' => 'srv_responses', 'columns' => ['id' => 'keep']],
            'forbidden per_files' => ['table' => 'per_files', 'columns' => ['id' => 'keep']],
            'saan_settings key not whitelisted' => ['table' => 'saan_settings', 'where' => ['key' => 'auth.secret'], 'columns' => ['key' => 'keep']],
            'saan_settings one key not whitelisted' => ['table' => 'saan_settings', 'where' => ['key' => ['op' => 'in', 'value' => ['modules.enabled', 'auth.secret']]], 'columns' => ['key' => 'keep']],
            'saan_settings without a key filter' => ['table' => 'saan_settings', 'columns' => ['key' => 'keep']],
            'keep author' => ['table' => 'posts', 'columns' => ['author_id' => 'keep']],
            'keep draft payload' => ['table' => 'posts', 'columns' => ['draft_payload' => 'keep']],
            'keep password-like column' => ['table' => 'posts', 'columns' => ['user_password' => 'keep']],
            'null on a NOT NULL column' => ['table' => 'posts', 'columns' => ['title' => 'null']],
            'bad rule' => ['table' => 'posts', 'columns' => ['id' => 'copy']],
            'rule not string' => ['table' => 'posts', 'columns' => ['id' => true]],
            'no columns' => ['table' => 'posts', 'columns' => []],
            'columns as list' => ['table' => 'posts', 'columns' => ['id']],
            'bad table name' => ['table' => 'posts; drop', 'columns' => ['id' => 'keep']],
            'unknown filter' => ['table' => 'posts', 'filter' => 'everything', 'columns' => ['id' => 'keep']],
            'unknown derived' => ['table' => 'pub_x', 'derived' => 'dump', 'columns' => ['id' => 'keep']],
            'derived into a real table' => ['table' => 'posts', 'derived' => 'certificate_verify', 'columns' => ['id' => 'keep']],
            'bad op' => ['table' => 'posts', 'where' => ['status' => ['op' => 'like', 'value' => 'x']], 'columns' => ['id' => 'keep']],
            'bad token' => ['table' => 'posts', 'where' => ['status' => '@sql'], 'columns' => ['id' => 'keep']],
            'empty in' => ['table' => 'posts', 'where' => ['status' => ['op' => 'in', 'value' => []]], 'columns' => ['id' => 'keep']],
            'where as list' => ['table' => 'posts', 'where' => ['status'], 'columns' => ['id' => 'keep']],
        ];
        foreach ($bad as $why => $entry) {
            try {
                Projection::validate([$this->fill($entry)], 't', true);
                $this->fail("accepted: $why");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('projection[t', $e->getMessage(), $why);
            }
        }
        // structure-only mode accepts unknown tables but still rejects forbidden ones and bad shapes
        $this->assertCount(1, Projection::validate([['table' => 'no_such_table', 'columns' => ['id' => 'keep']]], 't', false));
        $this->expectException(RuntimeException::class);
        Projection::validate([['table' => 'users', 'columns' => ['name' => 'keep']]], 't', false);
    }

    public function test_projection_rejects_a_table_declared_twice(): void
    {
        $this->bootEdition('combined');
        $this->artisan('migrate:fresh')->run();
        config(['saan_projection' => array_merge(config('saan_projection'), [$this->fill(['table' => 'ita_years', 'columns' => ['id' => 'keep']])])]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ประกาศซ้ำ');
        Projection::declared(true);
    }

        // ---------------------------------------------------------------- gate marker, headers, per-edition names

    public function test_route_cache_marker_refuses_a_cache_built_for_another_edition(): void
    {
        foreach (['combined', 'internal', 'public'] as $e) {
            $this->bootEdition($e);
            $this->assertTrue(Route::has('saan.edition.'.$e), $e);
            foreach (array_diff(['combined', 'internal', 'public'], [$e]) as $other) $this->assertFalse(Route::has('saan.edition.'.$other), "$e has the marker of $other");
            \App\Http\Middleware\EditionGate::assertRoutesMatchEdition(true); // cache built for this edition: fine
            \App\Http\Middleware\EditionGate::assertRoutesMatchEdition(false);
        }
        // a cache built under 'combined' and then served with SAAN_EDITION=public must be refused
        $this->bootEdition('combined');
        config(['saan.edition' => 'public']);
        try {
            \App\Http\Middleware\EditionGate::assertRoutesMatchEdition(true);
            $this->fail('stale route cache accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('route cache', $e->getMessage());
        }
        \App\Http\Middleware\EditionGate::assertRoutesMatchEdition(false); // no cache: routes were just registered for the current edition
    }

    public function test_internal_edition_is_marked_noindex_and_the_others_are_not(): void
    {
        $this->bootEdition('internal');
        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->bootEdition('combined');
        $this->get('/login')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->bootEdition('public');
        $this->get('/up')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_queue_redis_and_file_cache_names_carry_the_edition(): void
    {
        $seen = [];
        foreach (['combined' => '', 'internal' => '_internal', 'public' => '_public'] as $e => $sfx) {
            $this->bootEdition($e);
            $this->assertSame('default'.$sfx, config('queue.connections.redis.queue'), $e);
            $this->assertSame('default'.$sfx, config('queue.connections.database.queue'), $e);
            $this->assertStringEndsWith('framework/cache/data'.$sfx, str_replace('\\', '/', (string) config('cache.stores.file.path')), $e);
            $redis = (string) config('database.redis.options.prefix');
            $seen[$redis] = true;
            if ($sfx !== '') $this->assertStringContainsString(ltrim($sfx, '_').'-', $redis, $e);
        }
        $this->assertCount(3, $seen, 'redis prefix must differ per edition');
    }

    public function test_public_url_helper_and_doctor_session_domain_warning(): void
    {
        config(['saan.public_url' => '']);
        $this->assertNull(Edition::publicUrl());
        config(['saan.public_url' => 'https://www.school.ac.th/']);
        $this->assertSame('https://www.school.ac.th', Edition::publicUrl());
        config(['saan.public_url' => 'javascript:alert(1)']);
        $this->assertNull(Edition::publicUrl());
        $level = function () {
            $report = (new \App\Domain\Cms\DeploymentHealth())->inspect();
            $checks = $report['checks'] ?? $report;
            return array_values(array_filter($checks, fn ($c) => ($c['key'] ?? '') === 'session_domain'))[0]['level'];
        };
        config(['session.domain' => null]);
        $this->assertSame('ok', $level());
        config(['session.domain' => '.school.ac.th']);
        $this->assertSame('warn', $level());
        config(['session.domain' => 'app.school.ac.th']);
        $this->assertSame('ok', $level());
    }

    public function test_installer_refuses_to_answer_in_the_public_edition(): void
    {
        // public/install/index.php is served by the web server directly, so only its own guard can protect it
        $src = (string) file_get_contents(base_path('public/install/index.php'));
        $this->assertStringContainsString("getenv('SAAN_EDITION')", $src);
        $this->assertStringContainsString("if(\$edition==='public'&&(\$setup->installed()||\$setup->legacy())){http_response_code(404)", $src);
        $this->assertStringContainsString("<?php if(\$edition!=='public'):?><a class=\"button\" href=\"<?= e(\$base) ?>/login\"", $src);
    }
public function test_projection_only_lists_active_modules(): void
    {
        $this->bootEdition('combined');
        $this->artisan('migrate:fresh')->run();
        config(['saan.modules.disable' => 'web-social']);
        $this->app->make(ModuleRegistry::class)->flush();
        $tables = array_column(Projection::declared(true), 'table');
        $this->assertNotContains('social_feeds', $tables);
        $this->assertContains('ita_years', $tables);
    }
}
