<?php

namespace App\Http\Controllers;

use App\Domain\AccountPassword;
use App\Domain\PasswordLinks;
use App\Domain\SchoolAccounts;
use App\Models\User;
use Illuminate\Http\Request;

class PasswordLinkController extends Controller
{
    public function __construct(private PasswordLinks $links) {}

    public function forgotPage() { return auth()->check() ? redirect('/') : view('forgot-password'); }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:254'], ['email.required' => 'กรุณากรอกอีเมล', 'email.email' => 'กรุณาตรวจสอบรูปแบบอีเมล']);
        $this->links->request($data['email'], (string) $request->ip());
        return back()->with('status', PasswordLinks::GENERIC);
    }

    public function resetPage(Request $request, string $token)
    {
        return view('reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:200', 'email' => 'required|email|max:254', 'password' => AccountPassword::rules()], [
            'password.required' => 'กรุณากรอกรหัสผ่านใหม่', 'password.confirmed' => 'รหัสผ่านทั้งสองช่องไม่ตรงกัน', 'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร',
            'password.letters' => 'รหัสผ่านต้องมีตัวอักษร', 'password.numbers' => 'รหัสผ่านต้องมีตัวเลข',
        ]);
        $this->links->reset($data['email'], $data['token'], $data['password']);
        return redirect('/login')->with('status', 'ตั้งรหัสผ่านแล้ว เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย');
    }

    /** Admin: send an invitation / new-password link to an existing account. */
    public function send(Request $request, int $id)
    {
        app(SchoolAccounts::class)->authorize();
        $user = User::where('school_id', config('school.school_id'))->findOrFail($id);
        $this->links->send($user, 'invite');
        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('status', 'ส่งลิงก์ตั้งรหัสผ่านไปที่ '.$user->email.' แล้ว');
    }
}
