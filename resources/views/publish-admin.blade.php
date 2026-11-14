<x-account-layout title="ส่งข้อมูลไปหน้าเว็บ">
<p class="account-intro">ระบบภายในส่งเฉพาะเนื้อหาที่เผยแพร่แล้วไปยังเว็บไซต์สาธารณะ ข้อมูลที่ลงลายเซ็นไว้ ฝั่งหน้าเว็บไม่มีทางย้อนเข้ามายังระบบนี้</p>
@if($flash)<div class="account-alert" role="status">{{ $flash }}</div>@endif
@if(!$active)
<section class="account-card glass"><h2>ไม่ต้องส่งข้อมูล</h2><p>โหมดการติดตั้งปัจจุบันคือ <b>{{ $edition }}</b> ใช้ฐานข้อมูลเดียวกับหน้าเว็บ หรือเป็นฝั่งรับ จึงไม่มีการส่งข้อมูล</p></section>
@else
<section class="account-card glass"><h2>สถานะ</h2>
<dl>
<dt>การตั้งค่าปลายทาง</dt><dd>{{ $status['configured'] ? 'ตั้งค่าแล้ว (จับคู่หรือ SAAN_PUBLIC_PUSH_URL)' : 'ยังไม่ได้ตั้งที่อยู่หน้าบ้าน (จับคู่ที่หน้า เชื่อมเว็บไซต์หน้าบ้าน หรือตั้ง SAAN_PUBLIC_PUSH_URL ในไฟล์ .env)' }}</dd>
<dt>ส่งสำเร็จล่าสุด</dt><dd>{{ $status['last_sync'] ?: 'ยังไม่เคยส่ง' }}</dd>
<dt>ชุดข้อมูลที่รอส่ง</dt><dd>{{ $status['queue'] }}</dd>
<dt>ตารางที่มีการเปลี่ยนแปลงรอตรวจ</dt><dd>{{ $status['markers'] }}</dd>
<dt>ชุดข้อมูลที่ส่งไม่สำเร็จซ้ำหลายครั้ง</dt><dd>{{ $status['stuck'] }}@if($status['stuck']) (ระบบสร้างข้อมูลของตารางนั้นใหม่ให้เองไม่เกินชั่วโมงละครั้ง)@endif</dd>
</dl>
@if($publicKey)<p class="account-help">จับคู่กับหน้าเว็บด้วยรหัสใช้ครั้งเดียวที่หน้า <a href="{{ url('/admin/pairing') }}">เชื่อมเว็บไซต์หน้าบ้าน</a> (ไม่ต้องคัดลอกกุญแจเอง) กุญแจสาธารณะ push ปัจจุบัน: <code>{{ $publicKey }}</code></p>@endif
</section>
<section class="account-card glass"><h2>ข้อผิดพลาดล่าสุด</h2>
@if(count($status['errors']))
<ul>@foreach($status['errors'] as $e)<li>ชุด #{{ $e->id }} ({{ $e->stream }} ลำดับ {{ $e->seq }}) ลองแล้ว {{ $e->attempts }} ครั้ง: {{ $e->last_error }}@if($e->available_at) (ลองใหม่หลัง {{ $e->available_at }})@endif</li>@endforeach</ul>
@else<p>ไม่มีข้อผิดพลาด</p>@endif
@if(count($status['skipped']))
<h3>ไฟล์ที่ข้ามไป (แถวข้อมูลยังส่งตามปกติ)</h3>
<ul>@foreach($status['skipped'] as $s)<li>{{ $s }}</li>@endforeach</ul>
@endif
</section>
<section class="account-card glass"><h2>ส่งข้อมูลทั้งหมดใหม่</h2>
<p class="account-help">ใช้เมื่อสร้างหน้าเว็บใหม่ หรือหลังเหตุที่หน้าเว็บถูกเจาะ ระบบจะสร้างข้อมูลสาธารณะทั้งชุดจากระบบภายในแล้วแทนที่ของเดิมบนหน้าเว็บ</p>
<form method="post" action="{{ url('/admin/publish/full') }}" onsubmit="return confirm('ส่งข้อมูลทั้งหมดใหม่ไปยังหน้าเว็บ?')">@csrf<button type="submit">ส่งข้อมูลทั้งหมดใหม่</button></form>
</section>
@endif
</x-account-layout>
