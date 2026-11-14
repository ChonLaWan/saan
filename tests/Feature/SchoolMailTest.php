<?php
namespace Tests\Feature;
use App\Domain\SchoolMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt,DB};
use Tests\TestCase;
class SchoolMailTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed();$this->actingAs(User::where('role','admin')->first());}
    private function payload(array $extra=[]):array{return['version'=>0,'settings'=>array_replace(SchoolMail::defaults(),['provider'=>'gmail','host'=>'','port'=>587,'username'=>'sender@school.example','password'=>'abcd efgh ijkl mnop','from_address'=>'sender@school.example','from_name'=>'โรงเรียนตัวอย่าง'],$extra)];}
    public function test_gmail_credentials_are_encrypted_redacted_and_not_part_of_website_state():void
    {
        $response=$this->putJson('/api/site/mail',$this->payload())->assertOk()->assertJsonPath('has_password',true)->assertJsonPath('settings.host','smtp.gmail.com')->assertJsonPath('settings.port',587);$response->assertDontSee('abcdefghijklmnop')->assertJsonMissingPath('settings.password');
        $row=DB::table('school_mail_settings')->first();$this->assertStringNotContainsString('abcdefghijklmnop',$row->encrypted_payload);$this->assertSame('abcdefghijklmnop',json_decode(Crypt::decryptString($row->encrypted_payload),true)['password']);
        $this->getJson('/api/site/state')->assertOk()->assertDontSee('sender@school.example')->assertDontSee('abcdefghijklmnop');$this->assertStringNotContainsString('abcdefghijklmnop',DB::table('audit_events')->get()->toJson());
    }
    public function test_password_omission_retains_secret_and_stale_version_is_rejected():void
    {
        $this->putJson('/api/site/mail',$this->payload())->assertOk();$next=$this->payload(['password'=>'']);$next['version']=1;$this->putJson('/api/site/mail',$next)->assertOk()->assertJsonPath('version',2)->assertJsonPath('has_password',true);$this->putJson('/api/site/mail',$next)->assertStatus(409);
    }
    public function test_changing_authentication_target_requires_new_secret():void
    {
        $this->putJson('/api/site/mail',$this->payload())->assertOk();$next=$this->payload(['username'=>'other@school.example','password'=>'']);$next['version']=1;$this->putJson('/api/site/mail',$next)->assertStatus(422);$this->getJson('/api/site/mail')->assertJsonPath('settings.username','sender@school.example');
    }
    public function test_admin_only_and_school_scope_boundaries():void
    {
        $this->actingAs(User::where('role','teacher')->first());$this->getJson('/api/site/mail')->assertForbidden();$this->putJson('/api/site/mail',$this->payload())->assertForbidden();$this->actingAs(User::forceCreate(['school_id'=>2,'name'=>'Other','email'=>'other-admin@school.example','password'=>'Synthetic-Only-2026','role'=>'admin','is_demo'=>false,'is_active'=>true]));$this->getJson('/api/site/mail')->assertUnauthorized();
    }
    public function test_header_injection_invalid_gmail_password_and_insecure_remote_local_transport_are_rejected():void
    {
        $this->putJson('/api/site/mail',$this->payload(['from_name'=>"Marker\r\nBcc:marker@school.example"]))->assertStatus(422);$this->putJson('/api/site/mail',$this->payload(['password'=>'not-a-google-app-password']))->assertStatus(422);
        config(['school.local_only'=>false]);$this->putJson('/api/site/mail',$this->payload(['provider'=>'local','host'=>'127.0.0.1','username'=>'','password'=>'']))->assertStatus(422);$this->assertDatabaseCount('school_mail_settings',0);
    }
    public function test_gmail_ssl_preset_and_reply_to_are_validated():void
    {
        $this->putJson('/api/site/mail',$this->payload(['encryption'=>'ssl']))->assertOk()->assertJsonPath('settings.port',465);$bad=$this->payload(['reply_to'=>'invalid']);$bad['version']=1;$this->putJson('/api/site/mail',$bad)->assertStatus(422);
    }
    public function test_explicit_clear_password_and_disabled_mail_do_not_claim_delivery():void
    {
        $this->putJson('/api/site/mail',$this->payload())->assertOk();$next=$this->payload(['provider'=>'disabled','password'=>'','clear_password'=>true]);$next['version']=1;$this->putJson('/api/site/mail',$next)->assertOk()->assertJsonPath('has_password',false);$this->postJson('/api/site/mail/test',['recipient'=>'recipient@school.example','version'=>2,'request_id'=>'a44ab6cc-baa1-44aa-8538-a70e3262f7a0'])->assertStatus(422);
    }
}
