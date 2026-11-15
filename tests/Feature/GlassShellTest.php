<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WorkAreas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Saan Glass: the visual layer of the staff side. Looks are measured in a real browser by tests/browser; these tests keep the pieces that the
 * browser audits depend on from drifting apart (icon family, area map, which pages load the layer).
 */
class GlassShellTest extends TestCase
{
    use RefreshDatabase;

    private function read(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_the_icon_family_is_one_file_and_every_glyph_used_by_the_shell_exists_in_it(): void
    {
        $icons = json_decode($this->read('public/assets/ui-icons.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(50, count($icons));
        // the browser copy is generated from the same file: same names, same paths
        preg_match('/Object\.freeze\((\{.*?\})\);/s', $this->read('public/assets/ui-icons.js'), $m);
        $this->assertSame($icons, json_decode($m[1], true, 512, JSON_THROW_ON_ERROR));
        $ref = new \ReflectionClass(WorkAreas::class);
        foreach ($ref->getReflectionConstant('MODULES')->getValue() as $key => [$area, $glyph]) {
            $this->assertArrayHasKey($area, WorkAreas::AREAS, "area of $key");
            $this->assertArrayHasKey($glyph, $icons, "glyph of $key");
        }
        foreach ($ref->getReflectionConstant('NAV')->getValue() as $key => $glyph) $this->assertArrayHasKey($glyph, $icons, "glyph of navigation entry $key");
    }

    public function test_every_work_module_of_the_install_belongs_to_a_known_area_and_the_hub_gets_the_groups(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        Storage::fake('local');
        $this->actingAs(User::where('role', 'admin')->first());
        $html = $this->get('/work')->assertOk()->getContent();
        preg_match('~<script type="application/json" id="work-boot">(.*?)</script>~s', $html, $m);
        $boot = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(array_keys(WorkAreas::AREAS), array_keys($boot['areas']), 'the hub lists the areas in the server order');
        $this->assertNotEmpty($boot['menu']);
        foreach ($boot['menu'] as $item) {
            $this->assertArrayHasKey($item['area'], WorkAreas::AREAS);
            $this->assertNotSame('', $item['glyph']);
        }
        $this->assertContains('academic', array_column($boot['menu'], 'area'));
        // the strip of module links and the sidebar use the same family (inline SVG), not the old per-page path lists
        $this->assertStringContainsString('class="work-tabs-track"', $html);
        $this->assertStringContainsString('data-ui-icon="', $html);
        $this->assertStringNotContainsString("'grid'=>'M3 3h7v7H3z", $html);
    }

    public function test_the_glass_layer_loads_last_on_staff_and_sign_in_pages_and_never_on_the_public_website(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        Storage::fake('local');
        $login = $this->get('/login')->assertOk()->getContent();
        $this->assertGreaterThan(strpos($login, 'accessibility.css'), strpos($login, 'saan-glass.css'), 'the layer comes after accessibility.css');
        $this->assertStringContainsString('class="sg-login-id"', $login);
        $this->actingAs(User::where('role', 'admin')->first());
        foreach (['/cms', '/work', '/account', '/admin/users', '/admin/modules', '/whats-new', '/operations'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('assets/saan-glass.css', $html, $path);
            $this->assertGreaterThan(strpos($html, 'school-navigation.css'), strpos($html, 'saan-glass.css'), $path);
        }
        $this->get('/portal')->assertOk()->assertDontSee('saan-glass.css', false);
    }

    public function test_the_flattening_overrides_are_gone_and_the_real_accessibility_rules_stay(): void
    {
        $css = $this->read('public/assets/accessibility.css');
        // the rules that made every .glass an opaque box with a 2px border, removed the transitions and forced opaque controls
        foreach (['.cms-app .glass,', '.school-layout .glass{', '.workspace button:not(.lesson)', '.cms-app button:not(.primary),.cms-app .quiet-button{background-color'] as $old) {
            $this->assertStringNotContainsString($old, $css);
        }
        // what protected the reader stays: high-contrast mode, forced colours, 44px controls, the focus ring of the public pages
        $this->assertStringContainsString('html[data-contrast=high] .glass', $css);
        $this->assertStringContainsString('@media(forced-colors:active)', $css);
        $this->assertMatchesRegularExpression('/\.presentation-header \.site-menu-toggle[^{]*\{width:44px!important;height:44px!important/', $css);
        $glass = $this->read('public/assets/saan-glass.css');
        foreach (['@supports not', 'prefers-reduced-transparency', 'forced-colors:active', '@media print', 'html[data-appearance=dark]', 'prefers-reduced-motion'] as $needle) {
            $this->assertStringContainsString($needle, $glass);
        }
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $glass);
    }
}
