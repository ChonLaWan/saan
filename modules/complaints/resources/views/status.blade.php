@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">ร้องเรียนและแจ้งเรื่อง</p><h1>ตรวจสอบสถานะเรื่อง</h1>
<p>กรอกเลขรับเรื่องและรหัสติดตามที่ได้รับเมื่อส่งเรื่อง ระบบแสดงเฉพาะสถานะ วันที่ และคำตอบของโรงเรียน</p></section>
<section class="cmp-page">
    @if($result)
        <div class="cmp-result" role="status">
            <h2>เรื่อง {{ $result['receipt_no'] }}</h2>
            <dl>
                <dt>ประเภท</dt><dd>{{ $result['category_label'] }}</dd>
                <dt>สถานะ</dt><dd><strong>{{ $result['status_label'] }}</strong></dd>
                <dt>วันที่รับเรื่อง</dt><dd>{{ \Carbon\CarbonImmutable::parse($result['received_at'])->locale('th')->translatedFormat('j F') }} {{ \Carbon\CarbonImmutable::parse($result['received_at'])->year + 543 }}</dd>
                <dt>ปรับปรุงสถานะล่าสุด</dt><dd>{{ \Carbon\CarbonImmutable::parse($result['updated_at'])->locale('th')->translatedFormat('j F') }} {{ \Carbon\CarbonImmutable::parse($result['updated_at'])->year + 543 }}</dd>
            </dl>
            <h3>คำตอบจากโรงเรียน</h3>
            @if($result['public_reply'])<div class="cmp-reply">{!! nl2br(e($result['public_reply'])) !!}</div>@else<p>ยังไม่มีคำตอบ โรงเรียนจะแจ้งที่หน้านี้เมื่อมีความคืบหน้า</p>@endif
        </div>
    @endif
    @if($error)<div class="cmp-error" role="alert"><p>{{ $error }}</p></div>@endif
    <form method="post" action="{{ url('/portal/complaints/status') }}" class="cmp-form" autocomplete="off">
        @csrf
        <label class="cmp-field"><span>เลขรับเรื่อง (เช่น C69-ABCD2345)</span><input type="text" name="receipt_no" maxlength="24" required autocapitalize="characters" spellcheck="false"></label>
        <label class="cmp-field"><span>รหัสติดตาม</span><input type="text" name="tracking_code" maxlength="40" required autocapitalize="characters" spellcheck="false" autocomplete="off"></label>
        <button type="submit" class="cmp-submit">ตรวจสอบสถานะ</button>
    </form>
    <p><a class="text-link" href="{{ url('/portal/complaints') }}">ส่งเรื่องใหม่</a></p>
</section>
<style>
.cmp-page{max-width:720px;margin:0 auto 48px;padding:0 16px}.cmp-error{border:1px solid #b3261e;border-radius:10px;padding:6px 14px;margin:0 0 14px;background:#fff1f0;color:#601410}
.cmp-result{border:1px solid var(--line,#bac8b4);border-radius:12px;padding:12px 16px;margin:0 0 16px;background:var(--surface-raised,#fffdf4)}.cmp-result h2{margin:0 0 8px;font-size:1.2rem}.cmp-result h3{font-size:1rem;margin:12px 0 4px}
.cmp-result dl{display:grid;grid-template-columns:max-content 1fr;gap:4px 16px;margin:0}.cmp-result dt{font-weight:600}.cmp-result dd{margin:0}.cmp-reply{padding:10px 12px;border-left:4px solid var(--brand,#285747);background:var(--surface,#fffef7)}
.cmp-field{display:grid;gap:4px;margin:8px 0}.cmp-field input{width:100%;min-height:44px;padding:8px 12px;font-size:16px;letter-spacing:.06em;text-transform:uppercase;box-sizing:border-box}
.cmp-submit{min-height:48px;padding:0 28px;font-size:1rem;font-weight:600;border:0;border-radius:10px;background:var(--brand,#285747);color:var(--on-brand,#fff);cursor:pointer;width:100%}
@media (min-width:640px){.cmp-submit{width:auto}}
</style>
@endsection
