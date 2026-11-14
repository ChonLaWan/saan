<x-account-layout title="ระบบงานนี้ยังไม่เปิดใช้งาน">
<section class="account-card glass module-unavailable">
    <h2>{{ $module['name'] ?? 'โมดูลนี้' }}</h2>
    <p>ส่วนนี้ยังเป็นรุ่นทดลองที่ยังไม่ผ่านการตรวจรับ จึงปิดไว้เป็นค่าเริ่มต้นในระบบที่ใช้งานจริง เพื่อไม่ให้ข้อมูลโรงเรียนเสียหาย</p>
    <p>สถานะ: {{ $module['reason'] ?? 'ปิดอยู่' }} · รุ่น {{ $module['version'] ?? '-' }}</p>
    @if(auth()->user()->role==='admin')
        <p>ผู้ดูแลระบบเปิดเพื่อทดลองได้โดยเพิ่ม <code>SAAN_ENABLE_MODULES={{ $module['id'] ?? '' }}</code> ในไฟล์ <code>.env</code> แล้วล้าง config cache ควรทดลองในเครื่องสำรองก่อน</p>
    @endif
    <p><a href="{{ url('/account') }}">ไปที่บัญชีของฉัน</a>@if(in_array(auth()->user()->role,['admin','principal','editor'],true)) · <a href="{{ url('/cms') }}">จัดการเว็บไซต์โรงเรียน</a>@endif</p>
</section>
</x-account-layout>
