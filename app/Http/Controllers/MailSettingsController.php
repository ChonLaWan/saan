<?php
namespace App\Http\Controllers;
use App\Domain\SchoolMail;
use Illuminate\Http\Request;
class MailSettingsController extends Controller
{
    public function read(SchoolMail $mail){return response()->json($mail->read());}
    public function save(Request $r,SchoolMail $mail)
    {
        $mail->authorize();$data=$r->validate(['version'=>'required|integer|min:0','settings'=>'required|array:provider,host,port,encryption,username,password,from_address,from_name,reply_to,clear_password','settings.provider'=>'required|in:env,disabled,gmail,smtp,local','settings.host'=>['present','nullable','string','max:253','regex:/^[A-Za-z0-9.:-]*$/D'],'settings.port'=>'required|integer|between:1,65535','settings.encryption'=>'required|in:tls,ssl','settings.username'=>'present|nullable|string|max:254','settings.password'=>'sometimes|nullable|string|max:512','settings.from_address'=>'present|nullable|email|max:254','settings.from_name'=>'present|nullable|string|max:140','settings.reply_to'=>'present|nullable|email|max:254','settings.clear_password'=>'sometimes|boolean']);
        $s=$data['settings'];foreach(['host','username','password','from_address','from_name','reply_to']as$k)$s[$k]=$s[$k]??'';foreach($s as$v)if(is_string($v))abort_if(strpbrk($v,"\r\n\0")!==false,422,'ข้อมูลเมลมีตัวอักษรที่ใช้ไม่ได้');if(in_array($s['provider'],['smtp','local'],true))abort_if($s['host']==='',422,'กรอก SMTP host');
        return response()->json($mail->save($s,(int)$data['version']));
    }
    public function reset(Request $r,SchoolMail $mail){$mail->authorize();$d=$r->validate(['version'=>'required|integer|min:1','current_password'=>'required|string|current_password']);return response()->json($mail->reset((int)$d['version']));}
    public function test(Request $r,SchoolMail $mail){$mail->authorize();$d=$r->validate(['recipient'=>'required|email|max:254','version'=>'required|integer|min:0','request_id'=>'required|uuid']);$result=$mail->test($d['recipient'],(int)$d['version'],$d['request_id']);return response()->json($result,$result['accepted']?200:422);}
}
