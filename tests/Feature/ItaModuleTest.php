<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItaModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();config(['school.demo'=>true]);$this->seed();$this->actingAs(User::where('role','admin')->first());
    }

    private function pdf(): int
    {
        return $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('ita.pdf',"%PDF-1.4\n% ita marker\n%%EOF")])->assertCreated()->json('id');
    }

    public function test_year_lifecycle_copy_publish_and_public_page(): void
    {
        $y=$this->postJson('/api/ita/years',['fiscal_year'=>2568])->assertCreated()->json();
        $this->assertCount(6,$y['topics']);$this->assertSame('draft',$y['year']['status']);
        $this->postJson('/api/ita/years',['fiscal_year'=>2568])->assertConflict();
        $pdf=$this->pdf();
        $topics=[['code'=>'O1','title'=>'โครงสร้างหน่วยงาน','items'=>[['title'=>'แผนผังโครงสร้าง','url'=>null,'media_id'=>$pdf,'note'=>''],['title'=>'ข้อมูลผู้บริหาร','url'=>'https://example.org/admin','media_id'=>null,'note'=>'ปรับปรุงทุกภาคเรียน']]],['code'=>'O2','title'=>'ช่องทางติดต่อ','items'=>[]]];
        $saved=$this->putJson('/api/ita/years/'.$y['year']['id'],['version'=>$y['year']['version'],'title'=>'ITA 2568 ทดสอบ','topics'=>$topics])->assertOk()->json();
        $this->assertSame('แผนผังโครงสร้าง',$saved['topics'][0]['items'][0]['title']);
        $this->putJson('/api/ita/years/'.$y['year']['id'],['version'=>$y['year']['version'],'title'=>'stale','topics'=>[]])->assertConflict();
        $this->putJson('/api/ita/years/'.$y['year']['id'],['version'=>$saved['year']['version'],'title'=>'bad','topics'=>[['code'=>'','title'=>'x','items'=>[['title'=>'js','url'=>'javascript:alert(1)','media_id'=>null,'note'=>'']]]]])->assertUnprocessable();
        // draft is private, file too
        $this->app['auth']->forgetGuards();
        $this->get('/portal/ita')->assertOk()->assertSee('ยังไม่มีข้อมูลที่เผยแพร่');$this->get('/portal/ita/2568')->assertNotFound();$this->get('/media/'.$pdf)->assertNotFound();
        $this->get('/portal/ita/99999999999999999999')->assertNotFound();$this->get('/portal/ita/1234567890')->assertNotFound(); // longer than 9 digits never reaches the integer cast
        $this->actingAs(User::where('role','admin')->first());
        $pub=$this->postJson('/api/ita/years/'.$y['year']['id'].'/status',['version'=>$saved['year']['version'],'action'=>'publish'])->assertOk()->json();
        $this->assertSame('published',$pub['year']['status']);
        $this->deleteJson('/api/site/media/'.$pdf,['version'=>1])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->get('/portal/ita')->assertOk()->assertSee('ITA 2568 ทดสอบ')->assertSee('แผนผังโครงสร้าง')->assertSee('/media/'.$pdf,false)->assertSee('O1');
        $this->get('/media/'.$pdf)->assertOk();
        // copying a year keeps structure but not evidence
        $this->actingAs(User::where('role','admin')->first());
        $copy=$this->postJson('/api/ita/years',['fiscal_year'=>2569,'copy_from'=>$y['year']['id']])->assertCreated()->json();
        $this->assertSame('O1',$copy['topics'][0]['code']);$this->assertNull($copy['topics'][0]['items'][0]['media_id']);$this->assertNull($copy['topics'][0]['items'][1]['url']);
        $this->getJson('/api/ita/years')->assertOk()->assertJsonCount(2,'data');
    }

    public function test_teacher_cannot_manage_ita_and_module_is_listed(): void
    {
        $this->actingAs(User::where('role','teacher')->first())->getJson('/api/ita/years')->assertForbidden();
        $this->assertContains('web-ita',array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(),'id'));
        $this->actingAs(User::where('role','admin')->first())->get('/cms')->assertOk()->assertSee('assets/modules/web-ita/ita-admin.js',false)->assertSee('ITA เปิดเผยข้อมูลสาธารณะ');
    }

    public function test_public_page_prefixes_internal_links_with_the_install_folder_and_keeps_external_ones(): void
    {
        $y=$this->postJson('/api/ita/years',['fiscal_year'=>2568])->assertCreated()->json();
        $topics=[['code'=>'O1','title'=>'t','items'=>[['title'=>'ข่าวภายใน','url'=>'/portal/news','media_id'=>null,'note'=>''],['title'=>'ลิงก์ภายนอก','url'=>'https://example.org/ext','media_id'=>null,'note'=>'']]]];
        $s=$this->putJson('/api/ita/years/'.$y['year']['id'],['version'=>$y['year']['version'],'title'=>'sub','topics'=>$topics])->assertOk()->json();
        $this->postJson('/api/ita/years/'.$y['year']['id'].'/status',['version'=>$s['year']['version'],'action'=>'publish'])->assertOk();
        $this->app['auth']->forgetGuards();
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost/some-folder');
        try { $this->get('http://localhost/portal/ita/2568')->assertOk()->assertSee('href="http://localhost/some-folder/portal/news"',false)->assertSee('href="https://example.org/ext"',false)->assertDontSee('href="/portal/news"',false); }
        finally { \Illuminate\Support\Facades\URL::forceRootUrl(null); }
    }
}
