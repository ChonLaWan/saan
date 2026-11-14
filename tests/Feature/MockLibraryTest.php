<?php
namespace Tests\Feature;

use App\Domain\Cms\{ContentTypes,DemoLibrary};
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Tests\TestCase;

class MockLibraryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void
    {
        parent::setUp();config(['school.demo'=>true,'school.local_only'=>true]);$this->seed();Storage::fake('local');$this->actingAs(User::where('role','admin')->first());
    }
    private function video(int$school=1,string$mime='video/mp4'):int
    {
        // Minimal file header for MIME/access tests. Real demo MP4 decoding is checked by ffprobe and the browser.
        $bytes="\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";$path='cms/'.$school.'/sample.mp4';Storage::disk('local')->put($path,$bytes);
        return DB::table('cms_media')->insertGetId(['school_id'=>$school,'uploaded_by'=>auth()->id(),'path'=>$path,'mime'=>$mime,'bytes'=>strlen($bytes),'width'=>0,'height'=>0,'alt'=>'วีดิทัศน์ทดสอบ','original_name'=>'sample.mp4','folder'=>'ทดสอบ','created_at'=>now(),'updated_at'=>now()]);
    }
    private function payload(int$id,string$intent='publish',string$type='video'):array
    {
        return['title'=>'วีดิทัศน์ทดสอบสมมติ','body'=>'ข้อความอธิบายภาพ','excerpt'=>'ทดสอบ','content_type'=>$type,'category'=>ContentTypes::TYPES[$type]['category'],'is_pinned'=>false,'intent'=>$intent,'metadata'=>['video_media_id'=>$id]];
    }
    private function catalog(array$files=[]):void
    {
        $catalog=['modules'=>[],'documents'=>[],'staff'=>[],'files'=>$files];Storage::disk('local')->put(app(DemoLibrary::class)->prefix().'/catalog.json',json_encode($catalog));
    }
    public function test_local_video_is_private_until_published_and_private_again_after_unpublish():void
    {
        $id=$this->video();$payload=$this->payload($id,'draft');$post=$this->postJson('/api/site/content',$payload)->assertCreated()->json();
        $this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertNotFound();
        $this->actingAs(User::where('role','admin')->first());$post=$this->putJson('/api/site/content/'.$post['id'],$this->payload($id)+['version'=>$post['version']])->assertOk()->json();
        $this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertOk()->assertHeader('Content-Type','video/mp4');
        $this->get('/portal/videos/'.$post['slug'])->assertOk()->assertSee('<video',false)->assertSee('/media/'.$id,false)->assertDontSee('<iframe',false);
        $this->actingAs(User::where('role','admin')->first());$this->postJson('/api/site/content/'.$post['id'].'/action',['version'=>$post['version'],'action'=>'unpublish'])->assertOk();
        $this->app['auth']->forgetGuards();$this->get('/media/'.$id)->assertNotFound();
    }
    public function test_wrong_mime_wrong_content_type_and_foreign_school_video_are_rejected():void
    {
        $this->postJson('/api/site/content',$this->payload($this->video(1,'application/pdf')))->assertUnprocessable();
        $id=$this->video();$this->postJson('/api/site/content',$this->payload($id,'publish','article'))->assertUnprocessable();
        $foreign=$this->video(2);$this->postJson('/api/site/content',$this->payload($foreign))->assertNotFound();$this->get('/media/'.$foreign)->assertNotFound();
    }
    public function test_upload_requires_mp4_extension_and_mp4_mime_and_filters_video_from_documents():void
    {
        $bytes="\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
        $id=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('sample.mp4',$bytes)])->assertCreated()->json('id');
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('sample.mp4',"%PDF-1.4\nexample")])->assertUnprocessable();
        $this->getJson('/api/site/media?kind=video')->assertOk()->assertJsonPath('data.0.id',$id);
        $this->assertNotContains($id,array_column($this->getJson('/api/site/media?kind=document')->json('data'),'id'));
    }
    public function test_mock_library_requires_staff_scope_local_mode_and_authenticated_account():void
    {
        $this->catalog();$this->get('/demo-library')->assertOk()->assertHeader('X-Robots-Tag','noindex, nofollow');
        $this->actingAs(User::where('role','teacher')->first());$this->get('/demo-library')->assertForbidden();
        $this->app['auth']->forgetGuards();$this->get('/demo-library')->assertRedirect('/login');$this->get('/demo-library/files/M03-json')->assertRedirect('/login');
        $admin=User::forceCreate(['name'=>'ผู้ดูแลทดสอบ','email'=>'real-admin@testing.example','password'=>'test-only-password','school_id'=>1,'role'=>'admin','is_demo'=>false,'is_active'=>true]);$this->actingAs($admin);config(['school.demo'=>false]);$this->get('/demo-library')->assertNotFound();config(['school.demo'=>true,'school.local_only'=>false]);$this->get('/demo-library')->assertNotFound();
    }
    public function test_library_downloads_are_allowlisted_and_checked_against_manifest_hash():void
    {
        $bytes='{"fictional":true}';$disk=Storage::disk('local');$prefix=app(DemoLibrary::class)->prefix();$disk->put($prefix.'/datasets/M03.json',$bytes);
        $this->catalog(['M03-json'=>['path'=>'datasets/M03.json','sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes),'mime'=>'application/json'],'bad-path'=>['path'=>'../../.env','sha256'=>str_repeat('0',64),'mime'=>'text/plain']]);
        $this->get('/demo-library/files/M03-json')->assertOk()->assertHeader('Content-Type','application/json');$this->get('/demo-library/files/unlisted')->assertNotFound();$this->get('/demo-library/files/bad-path')->assertNotFound();
        $disk->put($prefix.'/datasets/M03.json','changed');$this->get('/demo-library/files/M03-json')->assertStatus(409);
    }
    public function test_importer_refuses_disabled_demo_and_tampered_files_before_creating_rows():void
    {
        config(['school.demo'=>false]);$this->artisan('cms:mock-library',['directory'=>sys_get_temp_dir()])->assertFailed();config(['school.demo'=>true]);
        $root=storage_path('framework/testing/mock-package');@mkdir($root.'/datasets',0777,true);file_put_contents($root.'/datasets/M01.json','changed');$catalog=['version'=>DemoLibrary::VERSION,'fictional'=>true,'modules'=>array_fill(0,24,[]),'files'=>['M01-json'=>['path'=>'datasets/M01.json','sha256'=>str_repeat('0',64),'bytes'=>7]]];file_put_contents($root.'/catalog.json',json_encode($catalog));$before=DB::table('posts')->count();
        try{$this->artisan('cms:mock-library',['directory'=>$root])->assertFailed();$this->assertSame($before,DB::table('posts')->count());$this->assertSame(0,DB::table('cms_media')->count());}
        finally{unlink($root.'/datasets/M01.json');unlink($root.'/catalog.json');rmdir($root.'/datasets');rmdir($root);}
    }
}
