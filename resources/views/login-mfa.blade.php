<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>ยืนยันสองขั้นตอน — {{ config('saan.display') }}</title><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/atelier.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/accounts.css') }}"><x-appearance-assets/></head>
<body class="login-body"><main class="login-sheet glass account-login">
<x-saan-brand /><h1>ยืนยันสองขั้นตอน</h1><p>เปิดแอป Authenticator ในโทรศัพท์ แล้วกรอกรหัส 6 หลัก หรือใช้รหัสสำรองที่เก็บไว้</p>
@if($errors->any())<div class="account-alert error" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ url('/login/mfa') }}" class="account-form">@csrf
<label>รหัสยืนยัน<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" required autofocus></label>
<button class="primary" type="submit">ยืนยัน</button></form>
<a class="account-back" href="{{ url('/login') }}"><x-ui-icon name="arrow-left"/> เริ่มเข้าสู่ระบบใหม่</a>
</main></body></html>
