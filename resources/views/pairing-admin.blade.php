<x-account-layout title="เชื่อมเว็บไซต์หน้าบ้าน">
<p class="account-intro">จับคู่ระบบภายในนี้กับเว็บไซต์หน้าบ้าน (public) ด้วยรหัสใช้ครั้งเดียว กุญแจลับของระบบภายในไม่ถูกส่งออกไปจากเครื่องนี้</p>
@if($flash)<div class="account-alert" role="status">{{ $flash }}</div>@endif
@if(!$active)
<section class="account-card glass"><h2>ไม่ต้องจับคู่</h2><p>โหมดการติดตั้งปัจจุบันคือ <b>{{ $edition }}</b> ใช้ได้เฉพาะระบบภายใน (SAAN_EDITION=internal)</p></section>
@else
<section class="account-card glass"><h2>สถานะ</h2>
<dl>
<dt>จับคู่ล่าสุด</dt><dd>{{ $info['paired_at'] ?: 'ยังไม่เคยจับคู่' }}</dd>
<dt>ที่อยู่รับข้อมูลของหน้าบ้าน</dt><dd>{{ $info['push_url'] ?: 'ยังไม่ได้ตั้ง' }}</dd>
<dt>กุญแจ inbox เดิมที่รอรับซองให้หมด</dt><dd>{{ $info['retired'] }}</dd>
<dt>ซองที่ส่งต่อไม่ได้ (quarantine)</dt><dd>{{ $info['quarantine'] }}</dd>
</dl>
</section>
@if($code)
<section class="account-card glass"><h2>รหัสจับคู่ (แสดงครั้งนี้ครั้งเดียว)</h2>
<p><code style="font-size:1.2rem">{{ $code }}</code></p>
<p class="account-help">หมดอายุ {{ $expires }} (15 นาที) ใช้ได้ครั้งเดียว ฝั่งหน้าบ้านรัน: <code>php artisan saan:pair {{ url('/') }} {{ $code }}</code> หรือเปิดหน้า /_saan/setup บนหน้าบ้านถ้าไม่มี SSH เมื่อจับคู่สำเร็จ ระบบจะส่งข้อมูลหน้าเว็บชุดเต็มให้เอง</p>
</section>
@endif
<section class="account-card glass"><h2>สร้างรหัสจับคู่</h2>
<form method="post" action="{{ url('/admin/pairing/code') }}">@csrf
<label>ที่อยู่เว็บไซต์หน้าบ้าน <input type="url" name="public_url" value="{{ old('public_url') }}" placeholder="https://www.example.ac.th" required></label>
<button type="submit">สร้างรหัสใหม่</button></form>
<p class="account-help">รหัสใหม่จะยกเลิกรหัสเดิมที่ยังไม่ได้ใช้ หมุนกุญแจด้วย <code>php artisan saan:keys-rotate</code> แล้วจับคู่ใหม่</p>
</section>
@endif
</x-account-layout>
