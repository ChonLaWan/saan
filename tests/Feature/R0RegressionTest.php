<?php

namespace Tests\Feature;

use App\Domain\Cms\DemoLibrary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class R0RegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();config(['school.demo'=>true]);$this->seed();
    }

    public function test_ops003_operations_screen_uses_server_today_and_real_term(): void
    {
        $js=file_get_contents(public_path('assets/atelier.js'));
        $this->assertDoesNotMatchRegularExpression("/(dutyDate|careDate)='\\d{4}-\\d{2}-\\d{2}'/",$js);
        $this->assertStringNotContainsString('l.weekday===4&&',$js);
        $this->assertStringContainsString('dutyDate=boot.today',$js);
        $term=DB::table('academic_terms')->where('id',config('school.term_id'))->first();
        $html=$this->actingAs(User::where('role','admin')->first())->get('/operations')->assertOk()->getContent();
        $this->assertStringContainsString('ปีการศึกษา '.$term->year.' <span>/ ภาคเรียนที่ '.$term->term.'</span>',$html);
        $this->assertStringContainsString('"today":"'.now()->toDateString().'"',$html);
        $this->assertStringNotContainsString('ภาคเรียนสาธิต 1',$html);
    }

    public function test_n2_n3_demo_library_path_and_version_have_single_source(): void
    {
        $this->assertSame('demo-library/7/'.DemoLibrary::VERSION,DemoLibrary::prefixFor(7));
        foreach (['app/Domain/InstallationContent.php','app/Support/SchoolNavigation.php'] as $file) {
            $source=file_get_contents(base_path($file));
            $this->assertStringNotContainsString(DemoLibrary::VERSION,$source,$file);
            $this->assertStringNotContainsString('demo-library/1/',$source,$file);
        }
    }

    public function test_n4_staff_entered_names_are_escaped_in_operations_templates(): void
    {
        $js=file_get_contents(public_path('assets/atelier.js'));
        $this->assertStringNotContainsString('<td>${classroom(s.classroom_id)}</td>',$js);
        $this->assertStringNotContainsString('<td>${s.code}</td>',$js);
        $this->assertStringContainsString('${esc(classroom(s.classroom_id))}',$js);
        $this->assertDoesNotMatchRegularExpression('/\$\{(teacher|classroom)\([^)]*\)\}/',$js,'teacher()/classroom() names must go through esc()');
    }

    public function test_ops012_development_plan_links_only_to_files_that_exist(): void
    {
        $html=$this->actingAs(User::where('role','admin')->first())->get('/development-plan')->assertOk()->getContent();
        preg_match_all('~href="'.preg_quote(url('/'),'~').'(/(?:design|cms-release|student-registry-design|implementation)/[^"#]+)~',$html,$m);
        foreach(array_unique($m[1]) as $path)$this->assertFileExists(public_path(ltrim($path,'/')),$path.' is linked but not shipped');
    }

    public function test_ops009_demo_library_predicate_is_shared_by_menu_and_coverage(): void
    {
        config(['school.demo'=>false,'school.local_only'=>false,'school.starter_data'=>'demo']);
        $catalog=storage_path('app/private/'.DemoLibrary::prefixFor(1).'/catalog.json');$created=!is_file($catalog);
        if($created){@mkdir(dirname($catalog),0700,true);file_put_contents($catalog,'{"files":{}}');}
        try{
            $this->assertTrue(DemoLibrary::available());
            $paths=collect(\App\Support\SchoolNavigation::groups('admin'))->flatMap(fn($g)=>$g['items'])->pluck('path');
            $this->assertTrue($paths->contains('/demo-library'));
            $coverage=collect(\App\Support\WebsiteCoverage::navigation())->firstWhere('path','/demo-library');
            $this->assertNotNull($coverage);$this->assertNotSame('unknown',$coverage['kind']);
        }finally{if($created)@unlink($catalog);}
    }

    public function test_r005_one_shared_escape_helper_and_server_today(): void
    {
        foreach (glob(public_path('assets/{,modules/*/}*.js'), GLOB_BRACE) as $file) {
            if (basename($file) === 'saan-core.js') continue;
            $this->assertDoesNotMatchRegularExpression('/esc\s*=\s*s\s*=>\s*String\(/', file_get_contents($file), basename($file).' must use SaanCore.esc');
        }
        $html=$this->actingAs(User::where('role','admin')->first())->get('/cms')->assertOk()->getContent();
        $this->assertStringContainsString('assets/saan-core.js',$html);
        $this->assertStringContainsString('<meta name="saan-today" content="'.now('Asia/Bangkok')->toDateString().'">',$html);
        // saan-core runs while the page parses; every screen script is deferred, so it is always available first
        $this->assertMatchesRegularExpression('~<script src="[^"]*assets/saan-core\.js[^"]*"></script>~',$html);
    }
}
