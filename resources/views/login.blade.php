<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>เข้าสู่ระบบ — {{ config('saan.display') }}</title><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/atelier.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/accounts.css') }}"><x-appearance-assets/></head>
<body class="login-body"><main class="login-sheet glass account-login">
<x-login-identity /><p class="eyebrow">{{ config('saan.tagline') }}</p><h1>เรื่องราวของโรงเรียน<br>เริ่มที่นี่</h1><p>เข้าสู่ระบบเพื่อเขียนข่าวและดูแลเว็บไซต์ของโรงเรียน</p>
@if(session('status'))<div class="account-alert" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="account-alert error" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ url('/login') }}" class="account-form">@csrf
<label>อีเมล<input type="email" name="email" autocomplete="username" value="{{ old('email') }}" maxlength="254" required autofocus></label>
<label>รหัสผ่าน<input type="password" name="password" autocomplete="current-password" required></label>
<button class="primary" type="submit">เข้าสู่ระบบ <span aria-hidden="true"><x-ui-icon name="arrow-right"/></span></button></form>
<a class="account-back" href="{{ url('/forgot-password') }}">ลืมรหัสผ่าน</a>
<a class="account-back" href="{{ \App\Support\Saan\Edition::publicUrl() ?? url('/portal') }}"><x-ui-icon name="arrow-left"/> กลับหน้าเว็บไซต์โรงเรียน</a>
@if(config('school.demo') && in_array(request()->ip(),['127.0.0.1','::1']))
<details class="demo-accounts"><summary>ทดลองด้วยข้อมูลสาธิตในเครื่อง</summary><p>ข้อมูลสมมติสำหรับทดลองขั้นตอนทำงาน</p>
<form method="post" action="{{ url('/demo-login') }}">@csrf
<button name="role" value="admin">เข้าในบทบาทผู้ดูแลโรงเรียน <span>จัดตาราง / เวร / เว็บไซต์</span></button>
<button name="role" value="teacher">เข้าในบทบาทครู <span>เช็คชื่อ / คะแนน / ยื่นลา</span></button>
<button name="role" value="principal">เข้าในบทบาทผู้อำนวยการ <span>พิจารณาการลา / ตรวจข้อมูล</span></button></form></details>@endif
</main></body></html>