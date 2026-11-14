<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>ตั้งรหัสผ่าน — {{ config('saan.display') }}</title><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/atelier.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/accounts.css') }}"><x-appearance-assets/></head>
<body class="login-body"><main class="login-sheet glass account-login">
<x-saan-brand /><h1>ตั้งรหัสผ่าน</h1><p>อย่างน้อย 12 ตัว มีทั้งตัวอักษรและตัวเลข หลังตั้งรหัสผ่าน อุปกรณ์อื่นที่เคยเข้าสู่ระบบจะต้องเข้าใหม่</p>
@if($errors->any())<div class="account-alert error" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ url('/reset-password') }}" class="account-form">@csrf<input type="hidden" name="token" value="{{ $token }}">
<label>อีเมล<input type="email" name="email" autocomplete="username" value="{{ old('email',$email) }}" maxlength="254" required></label>
<label>รหัสผ่านใหม่<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
<label>ยืนยันรหัสผ่านใหม่<input type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required></label>
<button class="primary" type="submit">บันทึกรหัสผ่าน</button></form>
<a class="account-back" href="{{ url('/login') }}"><x-ui-icon name="arrow-left"/> กลับไปหน้าเข้าสู่ระบบ</a>
</main></body></html>
