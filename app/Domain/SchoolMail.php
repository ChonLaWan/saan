<?php
namespace App\Domain;

use Illuminate\Support\Facades\{Cache,Crypt,DB,Schema};
use Symfony\Component\Mailer\Transport\{Dsn,NullTransport};
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

class SchoolMail
{
    /** RT-03: MailManager applies global from/reply_to right after creating the transport, so every school mail gets them. */
    public static function applyGlobalAddresses(array $settings): void
    {
        config(['mail.from'=>['address'=>$settings['from_address'],'name'=>($settings['from_name']??'')?:config('app.name')],
            'mail.reply_to'=>!empty($settings['reply_to'])?['address'=>$settings['reply_to'],'name'=>null]:null]);
    }
    public static function defaults():array{return['provider'=>'env','host'=>'','port'=>587,'encryption'=>'tls','username'=>'','password'=>'','from_address'=>'','from_name'=>'','reply_to'=>''];}
    public function localTesting():bool{return (bool)config('school.local_only')&&in_array(parse_url(config('app.url'),PHP_URL_HOST),['localhost','127.0.0.1','::1'],true);}
    public function authorize():void{app(SchoolOperations::class)->role(['admin']);}
    private function row(){return DB::table('school_mail_settings')->where('school_id',config('school.school_id'))->first();}
    private function decode($row):array
    {
        if(!$row)return self::defaults();
        try{return array_replace(self::defaults(),json_decode(Crypt::decryptString($row->encrypted_payload),true,512,JSON_THROW_ON_ERROR));}
        catch(\Throwable){abort(422,'ถอดรหัสการตั้งค่าเมลไม่ได้ ตรวจ APP_KEY จาก backup หรือตั้งค่าชุดใหม่ผ่านผู้ดูแล');}
    }
    private function readable($row):bool
    {
        if(!$row)return true;
        try{json_decode(Crypt::decryptString($row->encrypted_payload),true,512,JSON_THROW_ON_ERROR);return true;}catch(\Throwable){return false;}
    }
    public function read():array
    {
        $this->authorize();$row=$this->row();
        // RT-02: an unreadable payload (APP_KEY changed or data damaged) is reported, never shown or silently replaced.
        if(!$this->readable($row)){$settings=self::defaults();unset($settings['password']);return['settings'=>$settings,'has_password'=>false,'needs_reset'=>true,'version'=>$row->version,'local_testing'=>$this->localTesting(),'last_test_at'=>$row->last_test_at,'last_test_status'=>$row->last_test_status];}
        $settings=$this->decode($row);$has=$settings['password']!=='';unset($settings['password']);
        return['settings'=>$settings,'has_password'=>$has,'needs_reset'=>false,'version'=>$row?->version??0,'local_testing'=>$this->localTesting(),'last_test_at'=>$row?->last_test_at,'last_test_status'=>$row?->last_test_status];
    }
    /** RT-02: explicit, re-authenticated reset of unreadable mail settings back to "use server .env". */
    public function reset(int $version):array
    {
        $this->authorize();return DB::transaction(function()use($version){
            $row=DB::table('school_mail_settings')->where('school_id',config('school.school_id'))->lockForUpdate()->first()??abort(404);
            abort_if((int)$row->version!==$version,409,'มีการแก้การตั้งค่าเมลแล้ว กรุณาโหลดใหม่');
            abort_if($this->readable($row),422,'การตั้งค่าเมลยังอ่านได้ตามปกติ แก้ไขผ่านแบบฟอร์มแทนการรีเซ็ต');
            DB::table('school_mail_settings')->where('id',$row->id)->update(['encrypted_payload'=>Crypt::encryptString(json_encode(self::defaults())),'version'=>$version+1,'last_test_status'=>null,'last_test_at'=>null,'updated_at'=>now()]);
            app(SchoolOperations::class)->audit('mail.settings.reset','school_mail_settings',$row->id,['reason'=>'unreadable_payload','version'=>$version+1]);app('mail.manager')->purge('school');return$this->read();
        });
    }
    public function save(array $data,int $version):array
    {
        $this->authorize();return DB::transaction(function()use($data,$version){
            DB::table('school_mail_settings')->insertOrIgnore(['school_id'=>config('school.school_id'),'encrypted_payload'=>Crypt::encryptString(json_encode(self::defaults())),'version'=>0,'created_at'=>now(),'updated_at'=>now()]);
            $row=DB::table('school_mail_settings')->where('school_id',config('school.school_id'))->lockForUpdate()->first();abort_if((int)$row->version!==$version,409,'มีการแก้การตั้งค่าเมลแล้ว กรุณาโหลดใหม่ก่อนบันทึก');$old=$this->decode($row);
            $next=array_replace($old,$data);$newPassword=$data['password']??'';
            if($newPassword==='')$next['password']=$old['password'];if($data['clear_password']??false)$next['password']='';unset($next['clear_password']);
            if($next['provider']==='local'){$next['password']='';$next['username']='';}
            if($next['provider']==='gmail'){$next['host']='smtp.gmail.com';$next['port']=$next['encryption']==='ssl'?465:587;$next['password']=str_replace(' ','',$next['password']);abort_unless(preg_match('/^[A-Za-z0-9]{16}$/D',$next['password']),422,'Gmail ต้องใช้ App Password 16 ตัว ไม่ใช่รหัสผ่านบัญชี Google');abort_unless(filter_var($next['username'],FILTER_VALIDATE_EMAIL),422,'กรอกอีเมล Gmail หรือ Google Workspace ที่สร้าง App Password');$next['from_address']=$next['username'];}
            $targetChanged=$old['provider']!==$next['provider']||$old['host']!==$next['host']||$old['username']!==$next['username'];if(in_array($next['provider'],['gmail','smtp'],true)&&$targetChanged&&$old['password']!==''&&$newPassword==='')abort(422,'เปลี่ยนผู้ให้บริการ host หรือบัญชี ต้องกรอกรหัสผ่านใหม่ เพื่อไม่ส่งรหัสเดิมไป server อื่น');
            if($next['provider']==='smtp')abort_if($next['username']!==''&&$next['password']==='',422,'กรอกรหัสผ่าน SMTP หรือปิดการยืนยันตัวตนด้วยการเว้น username ตาม server ที่อนุญาต');
            if($next['provider']==='local')abort_unless($this->localTesting()&&in_array($next['host'],['127.0.0.1','localhost','::1'],true)&&$next['username']===''&&$next['password']==='',422,'เมลจำลองใช้ได้เฉพาะ loopback ไม่มีบัญชี/รหัสผ่าน');
            if(in_array($next['provider'],['gmail','smtp','local'],true))abort_unless(filter_var($next['from_address'],FILTER_VALIDATE_EMAIL),422,'กรอกอีเมลผู้ส่ง');
            DB::table('school_mail_settings')->where('id',$row->id)->update(['encrypted_payload'=>Crypt::encryptString(json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'version'=>$version+1,'last_test_status'=>null,'last_test_at'=>null,'updated_at'=>now()]);
            app(SchoolOperations::class)->audit('mail.settings.updated','school_mail_settings',$row->id,['provider'=>$next['provider'],'version'=>$version+1]);app('mail.manager')->purge('school');return$this->read();
        });
    }
    public function transport():\Symfony\Component\Mailer\Transport\TransportInterface
    {
        $row=Schema::hasTable('school_mail_settings')?$this->row():null;$settings=$this->decode($row);
        if($settings['provider']==='env'){$fallback=config('mail.school_fallback','log');return app('mail.manager')->mailer($fallback==='school'?'log':$fallback)->getSymfonyTransport();}
        if($settings['provider']==='disabled')return new NullTransport;
        abort_unless(in_array($settings['provider'],['gmail','smtp','local'],true),422);
        if($settings['provider']==='local')abort_unless($this->localTesting()&&in_array($settings['host'],['localhost','127.0.0.1','::1'],true),422,'SMTP จำลองไม่เปิดใช้บนเว็บไซต์สาธารณะ');
        self::applyGlobalAddresses($settings);
        $local=$settings['provider']==='local';$options=['timeout'=>10,'require_tls'=>!$local,'auto_tls'=>!$local,'verify_peer'=>true];
        return(new EsmtpTransportFactory)->create(new Dsn($settings['encryption']==='ssl'&&!$local?'smtps':'smtp',$settings['host'],$settings['username']?:null,$settings['password']?:null,(int)$settings['port'],$options));
    }
    public function test(string $recipient,int $version,string $requestId):array
    {
        $this->authorize();$row=$this->row();abort_unless($row&&(int)$row->version===$version,409,'บันทึกและโหลดการตั้งค่าล่าสุดก่อนส่งทดสอบ');$settings=$this->decode($row);abort_unless(in_array($settings['provider'],['gmail','smtp','local'],true),422,'เลือก Gmail หรือ SMTP และบันทึกก่อนส่งทดสอบ');
        return Cache::lock('school-mail-test:'.config('school.school_id'),30)->block(1,function()use($recipient,$row,$settings,$requestId){
            abort_unless(Cache::add('school-mail-once:'.config('school.school_id').':'.$requestId,true,600),409,'คำขอทดสอบนี้ส่งไปแล้ว กรุณาตรวจผลก่อนส่งใหม่');
            try{app('mail.manager')->purge('school');app('mail.manager')->mailer('school')->raw('อีเมลทดสอบจาก '.config('saan.display').' หากคุณได้รับข้อความนี้ SMTP ยอมรับการส่งจากเว็บไซต์แล้ว ไม่มีข้อมูลนักเรียนหรือรหัสผ่านในข้อความนี้',function($mail)use($recipient,$settings){$mail->to($recipient)->from($settings['from_address'],$settings['from_name']?:config('app.name'))->subject(config('saan.display').' — ทดสอบการส่งอีเมล');if($settings['reply_to'])$mail->replyTo($settings['reply_to']);});$status='accepted';}
            catch(\Throwable){$status='failed';}
            DB::table('school_mail_settings')->where('id',$row->id)->where('version',$row->version)->update(['last_test_at'=>now(),'last_test_status'=>$status]);app(SchoolOperations::class)->audit('mail.test.'.$status,'school_mail_settings',$row->id,['provider'=>$settings['provider']]);
            return['accepted'=>$status==='accepted','message'=>$status==='accepted'?'SMTP ยอมรับข้อความทดสอบแล้ว โปรดตรวจกล่องจดหมายและ Spam ของผู้รับ':'ส่งทดสอบไม่สำเร็จ ตรวจ host/port/TLS, App Password, การอนุญาตบัญชี และ firewall ของ hosting ระบบไม่แสดงรหัสผ่านหรือคำตอบดิบของ SMTP'];
        });
    }
}
