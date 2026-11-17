<?php
namespace App\Console\Commands;
use App\Domain\AccountPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RecoverSchoolAccount extends Command
{
    protected $signature='school:recover-account {email}';
    protected $description='Recover an existing non-demo account through a hidden interactive password';
    public function handle():int
    {
        if(!$this->input->isInteractive()){$this->error('Use an interactive terminal; passwords are never accepted as command arguments');return self::FAILURE;}
        $user=User::where('school_id',config('school.school_id'))->where('email',mb_strtolower(trim($this->argument('email'))))->where('is_demo',false)->where('is_active',true)->first();if(!$user){$this->error('No active account in this installation');return self::FAILURE;}
        $d=['password'=>$this->secret('รหัสผ่านใหม่'),'password_confirmation'=>$this->secret('ยืนยันรหัสผ่านใหม่')];$v=Validator::make($d,['password'=>AccountPassword::rules()]);if($v->fails()){foreach($v->errors()->all()as$e)$this->error($e);return self::FAILURE;}
        DB::transaction(function()use($user,$d){$fresh=User::whereKey($user->id)->where('is_active',true)->where('is_demo',false)->lockForUpdate()->firstOrFail();$fresh->password=$d['password'];if(array_key_exists('must_change_password',$fresh->getAttributes()))$fresh->must_change_password=false;$fresh->auth_version++;$fresh->version++;$fresh->remember_token=null;$fresh->save();DB::table('sessions')->where('user_id',$fresh->id)->delete();DB::table('audit_events')->insert(['school_id'=>$fresh->school_id,'actor_id'=>$fresh->id,'action'=>'account.recovered_by_server_operator','entity'=>'users','entity_id'=>$fresh->id,'detail'=>json_encode(['channel'=>'interactive-cli']),'created_at'=>now()]);});$this->info('[OK] Password changed and prior sessions invalidated');return self::SUCCESS;
    }
}
