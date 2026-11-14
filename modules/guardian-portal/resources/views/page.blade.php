<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="school-base" content="{{ url('/') }}">
<meta name="saan-today" content="{{ now('Asia/Bangkok')->toDateString() }}">
<title>พอร์ทัลผู้ปกครอง</title>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/modules/guardian-portal/guardian.css') }}">
<script src="{{ \App\Support\WebsiteView::asset('assets/saan-core.js') }}"></script>
@if($mode === 'app')<script defer src="{{ \App\Support\WebsiteView::asset('assets/modules/guardian-portal/portal-views.js') }}"></script><script defer src="{{ \App\Support\WebsiteView::asset('assets/modules/guardian-portal/guardian.js') }}"></script>@endif
</head>
<body class="gp">
<header class="gp-top"><div class="gp-wrap"><strong>พอร์ทัลผู้ปกครอง</strong>
@if($mode === 'app')<form method="post" action="{{ url('/guardian/logout') }}">@csrf<button type="submit" class="gp-btn gp-quiet">ออกจากระบบ</button></form>@endif
</div></header>
<main class="gp-wrap" id="main">
@if($mode === 'app')
    <div id="gp-root" aria-live="polite" aria-busy="true"><p class="gp-muted">กำลังโหลดข้อมูล</p></div>
@elseif($mode === 'invalid')
    <section class="gp-card" role="alert"><h1>ลิงก์นี้ใช้ไม่ได้</h1>
    <p>ลิงก์อาจหมดอายุ ถูกใช้ไปแล้ว หรือถูกยกเลิก กรุณาติดต่อครูประจำชั้นเพื่อขอลิงก์ใหม่</p></section>
@elseif($mode === 'open')
    <section class="gp-card"><h1>เปิดดูข้อมูลบุตรหลาน</h1>
    <p>ลิงก์นี้ใช้เปิดได้ครั้งเดียว กดปุ่มด้านล่างเพื่อเข้าดูข้อมูล แล้วระบบจะจำอุปกรณ์นี้ไว้ชั่วคราว</p>
    <form method="post" action="{{ url('/guardian/l/'.$token) }}">@csrf<button type="submit" class="gp-btn gp-primary">เปิดดูข้อมูล</button></form></section>
@elseif($mode === 'invite')
    <section class="gp-card"><h1>ตั้งบัญชีผู้ปกครอง</h1>
    <p>ตั้งอีเมลและรหัสผ่านเพื่อเข้าดูข้อมูลบุตรหลานได้โดยไม่ต้องขอลิงก์ใหม่ ลิงก์นี้ใช้ได้ครั้งเดียว</p>
    <form method="post" action="{{ url('/guardian/l/'.$token) }}" class="gp-form" autocomplete="off">@csrf
    @if(!empty($username_only))<p>บัญชีนี้ใช้ชื่อผู้ใช้ที่ครูออกให้ กรอกชื่อผู้ใช้และรหัสผ่านเดิมเพื่อเพิ่มนักเรียนคนนี้</p>
    <label for="gp-email">ชื่อผู้ใช้</label><input id="gp-email" name="email" type="text" required maxlength="190" autocomplete="username" value="{{ old('email') }}">
    <label for="gp-pw">รหัสผ่านเดิม</label><input id="gp-pw" name="password" type="password" required maxlength="200" autocomplete="current-password">
    @else
    <label for="gp-email">อีเมล</label><input id="gp-email" name="email" type="email" required maxlength="190" autocomplete="email" value="{{ old('email') }}">
    <label for="gp-pw">รหัสผ่าน (อย่างน้อย 10 ตัวอักษร)</label><input id="gp-pw" name="password" type="password" required minlength="10" maxlength="200" autocomplete="new-password">
    <label for="gp-pw2">ยืนยันรหัสผ่าน</label><input id="gp-pw2" name="password_confirmation" type="password" minlength="10" maxlength="200" autocomplete="new-password">
    @endif
    @if($errors->any())<ul class="gp-error" role="alert">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    <button type="submit" class="gp-btn gp-primary">บันทึกและเข้าใช้งาน</button></form></section>
@elseif($mode === 'forgot')
    <section class="gp-card"><h1>ลืมรหัสผ่าน</h1>
    <p>กรอกชื่อผู้ใช้หรืออีเมลของบัญชีผู้ปกครอง ถ้าบัญชีมีอีเมลที่ยืนยันแล้ว ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้ (ใช้ได้ 60 นาที)</p>
    <p class="gp-muted"><strong>ไม่มีอีเมลที่ยืนยันไว้:</strong> กรุณาแจ้งครูประจำชั้นหรือผู้ดูแลระบบของโรงเรียนเพื่อตั้งรหัสผ่านใหม่ให้</p>
    @if(session('status'))<p class="gp-msg" role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ url('/guardian/forgot') }}" class="gp-form">@csrf
    <label for="gp-login">ชื่อผู้ใช้หรืออีเมล</label><input id="gp-login" name="login" type="text" required maxlength="190" autocomplete="username" value="{{ old('login') }}">
    @if($errors->any())<ul class="gp-error" role="alert">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    <button type="submit" class="gp-btn gp-primary">ขอลิงก์ตั้งรหัสผ่านใหม่</button></form>
    <p><a href="{{ url('/guardian') }}">กลับไปหน้าเข้าสู่ระบบ</a></p></section>
@elseif($mode === 'reset')
    <section class="gp-card"><h1>ตั้งรหัสผ่านใหม่</h1>
    <form method="post" action="{{ url('/guardian/reset') }}" class="gp-form" autocomplete="off">@csrf
    <input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="i" value="{{ $who }}">
    <label for="gp-pw">รหัสผ่านใหม่</label><input id="gp-pw" name="password" type="password" required maxlength="200" autocomplete="new-password">
    <label for="gp-pw2">ยืนยันรหัสผ่านใหม่</label><input id="gp-pw2" name="password_confirmation" type="password" required maxlength="200" autocomplete="new-password">
    <p class="gp-muted">ใช้ตัวเลขล้วนอย่างน้อย 6 หลัก หรือรหัสผ่านตามความยาวที่โรงเรียนกำหนด</p>
    @if($errors->any())<ul class="gp-error" role="alert">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    <button type="submit" class="gp-btn gp-primary">บันทึกรหัสผ่านใหม่</button></form></section>
@elseif($mode === 'verify')
    <section class="gp-card"><h1>ยืนยันอีเมล</h1>
    <p>กดปุ่มเพื่อยืนยันว่าอีเมลนี้ใช้กับบัญชีพอร์ทัลของคุณ</p>
    <form method="post" action="{{ url('/guardian/verify/'.$token) }}">@csrf<button type="submit" class="gp-btn gp-primary">ยืนยันอีเมล</button></form></section>
@elseif($mode === 'verified')
    <section class="gp-card" role="status"><h1>ยืนยันอีเมลแล้ว</h1><p>ถ้าลืมรหัสผ่าน คุณขอลิงก์ตั้งรหัสผ่านใหม่ทางอีเมลนี้ได้เอง</p><p><a href="{{ url('/guardian') }}">ไปหน้าพอร์ทัล</a></p></section>
@else
    <section class="gp-card"><h1>เข้าดูข้อมูลบุตรหลาน</h1>
    <p>เปิดลิงก์ที่ครูประจำชั้นให้ไว้ หรือเข้าสู่ระบบด้วยบัญชีผู้ปกครองที่ตั้งไว้แล้ว (อีเมลหรือชื่อผู้ใช้ที่ครูออกให้) ระบบไม่เปิดดูด้วยรหัสนักเรียน</p>
    @if(session('status'))<p class="gp-msg" role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ url('/guardian/login') }}" class="gp-form">@csrf
    <label for="gp-email">อีเมลหรือชื่อผู้ใช้</label><input id="gp-email" name="email" type="text" required maxlength="190" autocomplete="username" autocapitalize="none" value="{{ old('email') }}">
    <label for="gp-pw">รหัสผ่าน</label><input id="gp-pw" name="password" type="password" required maxlength="200" autocomplete="current-password">
    @if($errors->any())<ul class="gp-error" role="alert">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    <button type="submit" class="gp-btn gp-primary">เข้าสู่ระบบ</button></form>
    <p class="gp-muted"><a href="{{ url('/guardian/forgot') }}">ลืมรหัสผ่าน</a> · ยังไม่มีบัญชีให้ติดต่อครูประจำชั้นเพื่อขอลิงก์หรือรหัสผ่านใหม่</p></section>
@endif
</main>
</body>
</html>
