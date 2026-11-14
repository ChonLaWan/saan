<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Domain\AccountPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateSchoolAdmin extends Command
{
    protected $signature = 'school:admin';
    protected $description = 'Create the first non-demo administrator with an interactive hidden password';

    public function handle(): int
    {
        if (!$this->input->isInteractive()) {
            $this->error('ใช้คำสั่งนี้ใน terminal แบบ interactive เพื่อไม่ส่งรหัสผ่านผ่าน command arguments');
            return self::FAILURE;
        }
        if (User::where('school_id', config('school.school_id'))->where('role','admin')->where('is_demo',false)->where('is_active',true)->exists()) {
            $this->error('มีผู้ดูแลระบบแล้ว เพิ่มผู้ใช้อื่นได้จากหน้าจัดการบัญชี');
            return self::FAILURE;
        }
        $data = ['name' => $this->ask('ชื่อผู้ดูแลระบบ'), 'email' => mb_strtolower(trim((string)$this->ask('อีเมล'))),
            'password' => $this->secret('รหัสผ่าน (อย่างน้อย 12 ตัว มีตัวอักษรและตัวเลข)'),
            'password_confirmation' => $this->secret('ยืนยันรหัสผ่าน')];
        $validator = Validator::make($data, ['name'=>'required|string|max:140','email'=>'required|email|max:254|unique:users,email',
            'password'=>AccountPassword::rules()]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }
        return Cache::lock('school-bootstrap-admin:'.config('school.school_id'), 15)->block(5, function () use ($data) {
            return DB::transaction(function () use ($data) {
                if (User::where('school_id',config('school.school_id'))->where('role','admin')->where('is_demo',false)->where('is_active',true)->exists()) {
                    $this->error('มีผู้สร้างผู้ดูแลระบบแล้ว'); return self::FAILURE;
                }
                $user = new User;
                foreach (['name','email','password'] as $field) $user->{$field}=$data[$field];
                $user->school_id=config('school.school_id');$user->role='admin';$user->is_demo=false;$user->is_active=true;$user->save();
                \App\Domain\Cms\ContentTypes::ensureCategories((int)$user->school_id);
                DB::table('audit_events')->insert(['school_id'=>$user->school_id,'actor_id'=>$user->id,'action'=>'account.bootstrapped','entity'=>'users','entity_id'=>$user->id,'detail'=>'{}','created_at'=>now()]);
                $this->info('[OK] สร้างผู้ดูแลระบบแล้ว เข้าระบบด้วยอีเมลและรหัสผ่านที่กำหนด'); return self::SUCCESS;
            });
        });
    }
}
