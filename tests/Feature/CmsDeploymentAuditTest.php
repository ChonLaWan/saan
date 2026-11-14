<?php
namespace Tests\Feature;
use App\Domain\Cms\SiteSettings;
use App\Domain\CmsPublishing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CmsDeploymentAuditTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();config(['school.demo'=>true]);$this->seed();Storage::fake('local');$this->actingAs(User::where('role','admin')->first());}
    public function test_deployment_health_is_admin_only_and_contains_no_key():void
    {
        $this->getJson('/api/site/deployment')->assertOk()->assertJsonStructure(['checks'=>[['key','label','ok','detail']],'technical_checks_passed','technical_checks_total'])->assertDontSee(config('app.key'),false);$this->actingAs(User::where('role','principal')->first());$this->getJson('/api/site/deployment')->assertForbidden();$this->artisan('school:doctor',['--json'=>true])->assertFailed();
    }
    public function test_cli_recovery_invalidates_sessions_and_records_the_operator_channel():void
    {
        $u=User::forceCreate(['name'=>'Recovery demo','email'=>'recovery@testing.example','password'=>'Old-Fictional-Password-2026','school_id'=>1,'role'=>'editor','is_active'=>true,'is_demo'=>false]);DB::table('sessions')->insert(['id'=>'test-recovery-session','user_id'=>$u->id,'payload'=>'fixture','last_activity'=>time()]);
        $this->artisan('school:recover-account',['email'=>$u->email])->expectsQuestion('รหัสผ่านใหม่','New-Fictional-Password-2026')->expectsQuestion('ยืนยันรหัสผ่านใหม่','New-Fictional-Password-2026')->assertSuccessful();$u->refresh();$this->assertTrue(Hash::check('New-Fictional-Password-2026',$u->password));$this->assertSame(2,$u->auth_version);$this->assertDatabaseMissing('sessions',['user_id'=>$u->id]);$this->assertDatabaseHas('audit_events',['entity_id'=>$u->id,'action'=>'account.recovered_by_server_operator']);
    }
    public function test_recovery_does_not_accept_demo_accounts():void {$this->artisan('school:recover-account',['email'=>'admin@demo.school.test'])->assertFailed();}
    public function test_redirect_cycle_through_query_fallback_is_rejected():void
    {
        $r=fn($a,$b)=>$this->postJson('/api/site/redirects',['source_path'=>$a,'target_path'=>$b,'status_code'=>301,'is_active'=>true]);$r('/a','/b?x=1')->assertCreated();$r('/b','/a')->assertUnprocessable();$r('/%2Fevil.example','/portal')->assertUnprocessable();$r('/bad%00path','/portal')->assertUnprocessable();
    }
    public function test_transparent_png_is_flattened_to_white_jpeg_without_changing_source():void
    {
        $image=imagecreatetruecolor(64,64);imagealphablending($image,false);imagesavealpha($image,true);imagefill($image,0,0,imagecolorallocatealpha($image,0,0,0,127));ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);$id=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('transparent.png',$bytes)])->assertCreated()->json('id');$options=['width'=>64,'ratio'=>'1:1','format'=>'jpeg','quality'=>90,'focal_x'=>50,'focal_y'=>50];$id2=$this->postJson('/api/site/media/'.$id.'/variant',$options)->assertCreated()->json('id');$path=DB::table('cms_media')->find($id2)->path;$jpeg=imagecreatefromstring(Storage::disk('local')->get($path));$rgb=imagecolorat($jpeg,32,32);imagedestroy($jpeg);$this->assertGreaterThan(245,($rgb>>16)&255);$this->assertGreaterThan(245,($rgb>>8)&255);$this->assertGreaterThan(245,$rgb&255);
    }
    public function test_background_control_rejects_a_low_contrast_light_mode_background():void
    {
        $s=app(CmsPublishing::class)->defaults();$s['background']='#000000';$this->putJson('/api/cms/theme',['version'=>0,'intent'=>'publish','fields'=>\App\Http\Controllers\CmsController::THEME_FIELDS,'settings'=>$s])->assertUnprocessable();$s['background']='#e8f1fa';$this->putJson('/api/cms/theme',['version'=>0,'intent'=>'publish','fields'=>\App\Http\Controllers\CmsController::THEME_FIELDS,'settings'=>$s])->assertOk();
    }
}
