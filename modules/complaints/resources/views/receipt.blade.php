@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">ร้องเรียนและแจ้งเรื่อง</p><h1>รับเรื่องแล้ว</h1></section>
<section class="cmp-page cmp-receipt">
    <p role="status">โรงเรียนได้รับเรื่องของท่านแล้ว กรุณาจดเลขรับเรื่องและรหัสติดตามด้านล่างไว้ ระบบแสดงให้ท่านเห็นเพียงครั้งนี้ครั้งเดียวและไม่เก็บรหัสไว้ที่ใด หากทำหาย จะตรวจสถานะเรื่องไม่ได้</p>
    <dl class="cmp-codes">
        <dt>เลขรับเรื่อง</dt><dd><code>{{ $receipt }}</code></dd>
        <dt>รหัสติดตาม</dt><dd><code>{{ $code }}</code></dd>
    </dl>
    @if($anonymous)<p>ท่านส่งเรื่องโดยไม่ระบุชื่อ โรงเรียนจะไม่สามารถติดต่อท่านได้ ท่านติดตามผลได้ที่หน้าตรวจสอบสถานะเท่านั้น</p>@endif
    <p><button type="button" class="cmp-submit" onclick="window.print()">พิมพ์หน้านี้</button></p>
    <p><a class="text-link" href="{{ url('/portal/complaints/status') }}">ไปหน้าตรวจสอบสถานะ</a> · <a class="text-link" href="{{ url('/') }}">กลับหน้าแรก</a></p>
</section>
<style>
.cmp-page{max-width:720px;margin:0 auto 48px;padding:0 16px}.cmp-codes{display:grid;grid-template-columns:max-content 1fr;gap:8px 16px;padding:14px 16px;border:1px solid var(--line,#bac8b4);border-radius:12px;background:var(--surface-raised,#fffdf4)}
.cmp-codes dt{font-weight:600}.cmp-codes dd{margin:0}.cmp-codes code{font-size:1.35rem;letter-spacing:.08em;user-select:all}
.cmp-submit{min-height:48px;padding:0 28px;font-size:1rem;font-weight:600;border:0;border-radius:10px;background:var(--brand,#285747);color:var(--on-brand,#fff);cursor:pointer}
@media print{header,footer,nav,.cmp-submit{display:none!important}}
</style>
@endsection
