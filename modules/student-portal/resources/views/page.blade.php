<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="school-base" content="{{ url('/') }}">
<meta name="saan-today" content="{{ now('Asia/Bangkok')->toDateString() }}">
<title>พอร์ทัลนักเรียน</title>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/modules/guardian-portal/guardian.css') }}">
<script src="{{ \App\Support\WebsiteView::asset('assets/saan-core.js') }}"></script>
@if($mode === 'app')<script defer src="{{ \App\Support\WebsiteView::asset('assets/modules/guardian-portal/portal-views.js') }}"></script><script defer src="{{ \App\Support\WebsiteView::asset('assets/modules/student-portal/student.js') }}"></script>@endif
</head>
<body class="gp">
<header class="gp-top"><div class="gp-wrap"><strong>พอร์ทัลนักเรียน</strong>
@if($mode === 'app')<form method="post" action="{{ url('/student/logout') }}">@csrf<button type="submit" class="gp-btn gp-quiet">ออกจากระบบ</button></form>@endif
</div></header>
<main class="gp-wrap" id="main">
@if($mode === 'app')
    <div id="gp-root" aria-live="polite" aria-busy="true"><p class="gp-muted">กำลังโหลดข้อมูล</p></div>
@elseif($mode === 'invalid')
    <section class="gp-card" role="alert"><h1>ลิงก์นี้ใช้ไม่ได้</h1>
    <p>ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว กรุณาขอใหม่ หรือแจ้งครูประจำชั้น</p></section>
@elseif($mode === 'forgot')
    <section class="gp-card"><h1>ลืมรหัสผ่าน</h1>
    <p>กรอกรหัสนักเรียน ถ้าบัญชีมีอีเมลที่ยืนยันแล้ว ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ไปที่อีเมลนั้น (ใช้ได้ 60 นาที)</p>
    <p class="gp-muted"><strong>ไม่มีอีเมลที่ยืนยันไว้:</strong> ให้แจ้งครูประจำชั้นหรือผู้ดูแลระบบของโรงเรียนเพื่อตั้งรหัสผ่านใหม่ให้</p>
    @if(session('status'))<p class="gp-msg" role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ url('/student/forgot') }}" class="gp-form">@csrf
    <label for="sp-code">รหัสนักเรียน</label><input id="sp-code" name="code" type="text" required maxlength="40" autocomplete="username" autocapitalize="none" value="{{ old('code') }}">
    @if($errors->any())<ul class="gp-error" role="alert">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    <button type="submit" class="gp-btn gp-primary">ขอลิงก์ตั้งรหัสผ่านใหม่</button></form>
    <p><a href="{{ url('/student') }}">กลับไปหน้าเข้าสู่ระบบ</a></p></section>
@elseif($mode === 'reset')
    <section class="gp-card"><h1>ตั้งรหัสผ่านใหม่</h1>
    <form method="post" action="{{ url('/student/reset') }}" class="gp-form" autocomplete="off">@csrf
    <input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="i" value="{{ $who }}">
    <label for="sp-pw">รหัสผ่านใหม่</label><input id="sp-pw" name="password" type="password" required maxlength="200" autocomplete="new-password">
    <label for="sp-pw2">ยืนยันรหัสผ่านใหม่</label><input id="sp-pw2" name="password_confirmation" type="password" required maxlength="200" autocomplete="new-password">
    <p class="gp-muted">ใช้ตัวเลขล้วนอย่างน้อย 6 หลัก หรือรหัสผ่านตามความยาวที่โรงเรียนกำหนด</p>
    @if($errors->any())<ul class="gp-error" role="alert">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    <button type="submit" class="gp-btn gp-primary">บันทึกรหัสผ่านใหม่</button></form></section>
@elseif($mode === 'verify')
    <section class="gp-card"><h1>ยืนยันอีเมล</h1>
    <p>กดปุ่มเพื่อยืนยันว่าอีเมลนี้ใช้กับบัญชีนักเรียนของคุณ</p>
    <form method="post" action="{{ url('/student/verify/'.$token) }}">@csrf<button type="submit" class="gp-btn gp-primary">ยืนยันอีเมล</button></form></section>
@elseif($mode === 'verified')
    <section class="gp-card" role="status"><h1>ยืนยันอีเมลแล้ว</h1><p>ถ้าลืมรหัสผ่าน คุณขอลิงก์ตั้งรหัสผ่านใหม่ทางอีเมลนี้ได้เอง</p><p><a href="{{ url('/student') }}">ไปหน้าพอร์ทัล</a></p></section>
@else
    <section class="gp-card"><h1>เข้าสู่ระบบนักเรียน</h1>
    <p>ใช้รหัสนักเรียนและรหัสผ่านที่ครูประจำชั้นให้ ไม่ต้องใช้อีเมล</p>
    @if(session('status'))<p class="gp-msg" role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ url('/student/login') }}" class="gp-form">@csrf
    <label for="sp-code">รหัสนักเรียน</label><input id="sp-code" name="code" type="text" required maxlength="40" autocomplete="username" autocapitalize="none" value="{{ old('code') }}">
    <label for="sp-pw">รหัสผ่าน</label><input id="sp-pw" name="secret" type="password" required maxlength="200" autocomplete="current-password">
    @if($errors->any())<ul class="gp-error" role="alert">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    <button type="submit" class="gp-btn gp-primary">เข้าสู่ระบบ</button></form>
    <p class="gp-muted"><a href="{{ url('/student/forgot') }}">ลืมรหัสผ่าน</a> · ถ้าเข้าไม่ได้ให้แจ้งครูประจำชั้น</p></section>
@endif
</main>
</body>
</html>
