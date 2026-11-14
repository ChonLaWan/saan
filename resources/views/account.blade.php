<x-account-layout title="บัญชีของฉัน">
<div class="account-columns"><section class="account-card glass"><p class="account-kicker">เข้าสู่ระบบในชื่อ</p><h2>{{ auth()->user()->name }}</h2><p>{{ auth()->user()->email }}</p><span class="tag">{{ \App\Domain\SchoolAccounts::ROLES[auth()->user()->role] ?? auth()->user()->role }}</span><p class="account-help">สิทธิ์ในระบบกำหนดโดยผู้ดูแลโรงเรียน</p></section>
<section class="account-card glass"><h2>เปลี่ยนรหัสผ่าน</h2>
@if(auth()->user()->is_demo)<p>บัญชีนี้เป็นบัญชีสาธิต ใช้สำหรับทดลองขั้นตอนทำงาน</p>
@else
<p class="account-help">ใช้ตัวอักษรและตัวเลขรวมกันอย่างน้อย 12 ตัว เปลี่ยนแล้วอุปกรณ์อื่นต้องเข้าสู่ระบบใหม่</p>
<form class="account-form" method="post" action="{{ url('/account/password') }}">@csrf @method('put')
<label>รหัสผ่านปัจจุบัน<input name="current_password" type="password" autocomplete="current-password" required></label>
<label>รหัสผ่านใหม่<input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
<label>ยืนยันรหัสผ่านใหม่<input name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
<button class="primary">บันทึกรหัสผ่านใหม่</button></form>
@endif
</section></div>
@if(!auth()->user()->is_demo)
<div class="account-columns">
<section class="account-card glass" id="mfa"><h2>ยืนยันสองขั้นตอน (MFA)</h2>
@if(session('mfa_recovery_codes'))<div class="account-alert" role="status"><strong>รหัสสำรอง (แสดงครั้งเดียว)</strong><p>ใช้แทนรหัส 6 หลักเมื่อโทรศัพท์หาย รหัสละหนึ่งครั้ง</p><ol>@foreach(session('mfa_recovery_codes') as $code)<li><code>{{ $code }}</code></li>@endforeach</ol></div>@endif
@if(auth()->user()->mfa_enabled_at)
<p>เปิดใช้งานตั้งแต่ {{ \Carbon\CarbonImmutable::parse(auth()->user()->mfa_enabled_at)->setTimezone('Asia/Bangkok')->format('Y-m-d H:i') }}</p>
<form class="account-form" method="post" action="{{ url('/account/mfa/disable') }}">@csrf<label>รหัสผ่านปัจจุบัน<input name="current_password" type="password" autocomplete="current-password" required></label><button>ปิดการยืนยันสองขั้นตอน</button></form>
@elseif(session('mfa_setup'))
@php($mfaSecret=session('mfa_setup'))
<ol class="account-help"><li>เปิดแอป Authenticator (เช่น Google Authenticator, Microsoft Authenticator, 2FAS)</li><li>เลือกเพิ่มบัญชีด้วยการพิมพ์คีย์ แล้วกรอกคีย์ด้านล่าง ชนิดตามเวลา</li><li>กรอกรหัส 6 หลักที่แอปแสดง</li></ol>
<p><code class="mfa-secret">{{ trim(chunk_split($mfaSecret,4,' ')) }}</code></p>
<p class="account-help">ลิงก์สำหรับแอปที่รองรับ: <code>{{ \App\Domain\Totp::uri($mfaSecret,auth()->user()->email,config('saan.name_latin')) }}</code></p>
<form class="account-form" method="post" action="{{ url('/account/mfa/confirm') }}">@csrf<label>รหัส 6 หลักจากแอป<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required></label>@error('code')<p class="account-alert error" role="alert">{{ $message }}</p>@enderror<button class="primary">ยืนยันและเปิดใช้งาน</button></form>
@else
<p class="account-help">แนะนำสำหรับผู้ดูแลระบบ: นอกจากรหัสผ่านแล้ว ต้องกรอกรหัสจากแอปในโทรศัพท์ทุกครั้งที่เข้าสู่ระบบ</p>
<form method="post" action="{{ url('/account/mfa/start') }}">@csrf<button class="primary">เริ่มตั้งค่า</button></form>
@endif
</section>
<section class="account-card glass" id="sessions"><h2>อุปกรณ์ที่เข้าสู่ระบบ</h2>
@php($deviceSessions=\App\Http\Controllers\AccountSecurityController::sessions(request()))
@if($deviceSessions)<ul class="account-sessions">@foreach($deviceSessions as $ds)<li><strong>{{ $ds['current']?'อุปกรณ์นี้':'อุปกรณ์อื่น' }}</strong> · {{ $ds['ip'] }} · ใช้ล่าสุด {{ $ds['last_activity'] }}<br><small>{{ $ds['device'] }}</small></li>@endforeach</ul>@else<p class="account-help">ระบบนี้ไม่ได้เก็บ session ในฐานข้อมูล จึงแสดงรายการอุปกรณ์ไม่ได้ แต่ยังออกจากระบบทุกอุปกรณ์อื่นได้</p>@endif
<form class="account-form" method="post" action="{{ url('/account/sessions/revoke') }}">@csrf<label>รหัสผ่านปัจจุบัน<input name="current_password" type="password" autocomplete="current-password" required></label><button>ออกจากระบบทุกอุปกรณ์อื่น</button></form>
</section></div>
@endif
</x-account-layout>
