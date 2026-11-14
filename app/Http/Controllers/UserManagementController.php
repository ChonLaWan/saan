<?php

namespace App\Http\Controllers;

use App\Domain\SchoolAccounts;
use App\Domain\SchoolOperations;
use App\Domain\AccountPassword;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function __construct(private SchoolAccounts $accounts, private SchoolOperations $ops) {}

    public function index(Request $request)
    {
        $this->accounts->authorize();
        return view('users', [
            'users' => $this->ops->query('users')->when(!$request->boolean('inactive'),fn($q)=>$q->where('is_active',true))->orderBy('is_demo')->orderBy('name')->get(['id','name','email','role','is_active','is_demo','teacher_id','classroom_id','version']),
            'roles' => SchoolAccounts::ROLES,
            'teachers' => $this->ops->query('teachers')->orderBy('name')->get(),
            'classrooms' => $this->ops->query('classrooms')->orderBy('name')->get(),
        ]);
    }

    public function save(Request $request, ?int $id = null)
    {
        $this->accounts->authorize();
        if ($id) $this->ops->record('users', $id);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:140',
            'email' => ['required','email','max:254',Rule::unique('users')->ignore($id)],
            'role' => ['required',Rule::in(array_keys(SchoolAccounts::ROLES))],
            'is_active' => 'required|boolean',
            'teacher_id' => 'nullable|integer|min:1|required_if:role,teacher',
            'classroom_id' => 'nullable|integer|min:1|required_if:role,teacher',
            'version' => $id ? 'required|integer|min:1' : 'prohibited',
            'invite' => $id ? 'prohibited' : 'sometimes|boolean',
            'password' => $id || $request->boolean('invite') ? 'prohibited' : AccountPassword::rules(),
        ], [
            'name.required' => 'กรุณากรอกชื่อ', 'email.required' => 'กรุณากรอกอีเมล', 'email.email' => 'กรุณาตรวจสอบรูปแบบอีเมล',
            'email.unique' => 'อีเมลนี้มีบัญชีแล้ว', 'password.required' => 'กรุณากำหนดรหัสผ่านเริ่มต้น',
            'password.confirmed' => 'รหัสผ่านทั้งสองช่องไม่ตรงกัน', 'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร',
            'password.letters' => 'รหัสผ่านต้องมีตัวอักษร', 'password.numbers' => 'รหัสผ่านต้องมีตัวเลข',
            'teacher_id.required_if' => 'กรุณาเลือกครูเจ้าของบัญชี', 'classroom_id.required_if' => 'กรุณาเลือกห้องเรียนที่รับผิดชอบ',
        ]);
        $invite = !$id && $request->boolean('invite');unset($data['invite']);
        if ($invite) $data['password'] = \Illuminate\Support\Str::password(32);
        $user = $this->accounts->save($data, $id);
        if ($invite) app(\App\Domain\PasswordLinks::class)->send($user, 'invite');
        if ($id === auth()->id()) $request->session()->put('auth_version', $user->auth_version);
        if ($request->expectsJson()) return response()->json(['id' => $user->id, 'version' => $user->version], $id ? 200 : 201);
        return redirect('/admin/users')->with('status', $id ? 'บันทึกบัญชีแล้ว ผู้ใช้ต้องเข้าสู่ระบบใหม่' : ($invite ? 'เพิ่มบัญชีและส่งลิงก์ตั้งรหัสผ่านไปที่ '.$user->email.' แล้ว' : 'เพิ่มบัญชีแล้ว'));
    }
}
