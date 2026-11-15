<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>ลืมรหัสผ่าน — {{ config('saan.display') }}</title><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/atelier.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/accounts.css') }}"><x-appearance-assets/></head>
<body class="login-body"><main class="login-sheet glass account-login">
<x-login-identity /><h1>ลืมรหัสผ่าน</h1><p>กรอกอีเมลของบัญชี ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้ ลิงก์ใช้ได้ 60 นาที</p>
@if(session('status'))<div class="account-alert" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="account-alert error" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ url('/forgot-password') }}" class="account-form">@csrf
<label>อีเมล<input type="email" name="email" autocomplete="username" value="{{ old('email') }}" maxlength="254" required autofocus></label>
<button class="primary" type="submit">ส่งลิงก์ตั้งรหัสผ่าน</button></form>
<a class="account-back" href="{{ url('/login') }}"><x-ui-icon name="arrow-left"/> กลับไปหน้าเข้าสู่ระบบ</a>
</main></body></html>
