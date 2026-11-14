@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">ช่องทางร้องเรียนและแจ้งเรื่อง</p><h1>ร้องเรียนและแจ้งเรื่อง</h1>
<p>ช่องทางนี้รับเรื่องร้องเรียนทั่วไป เรื่องทุจริตและประพฤติมิชอบ ข้อเสนอแนะ และคำขอข้อมูลข่าวสาร ส่งโดยไม่ระบุชื่อได้</p></section>
<section class="cmp-page">
    <div class="cmp-note">
        <p><strong>ก่อนส่ง</strong> เมื่อส่งเรื่องแล้วระบบจะออกเลขรับเรื่องและรหัสติดตามให้ท่านเพียงครั้งเดียว กรุณาจดไว้ เว็บไซต์นี้ไม่เก็บเนื้อหาเรื่อง ชื่อ หรือไฟล์แนบของท่านไว้ในระบบหน้าบ้าน ข้อมูลถูกเข้ารหัสส่งให้เจ้าหน้าที่ที่รับผิดชอบเท่านั้น</p>
        <p><a class="text-link" href="{{ url('/portal/complaints/status') }}">ตรวจสอบสถานะเรื่องที่ส่งไว้แล้ว</a>@foreach($reports as $rp) · <a class="text-link" href="{{ url('/portal/complaints/reports/'.$rp['fiscal_year']) }}">รายงานสรุปปีงบประมาณ {{ $rp['fiscal_year'] }}</a>@endforeach</p>
    </div>
    @if(!empty($error))<div class="cmp-error" role="alert"><strong>ส่งเรื่องไม่สำเร็จ</strong><p>{{ $error }}</p></div>@endif
    <form method="post" action="{{ url('/portal/complaints') }}" class="cmp-form" enctype="multipart/form-data" autocomplete="off">
        @csrf
        <input type="hidden" name="form_nonce" value="{{ $formNonce }}">
        <fieldset class="cmp-q"><legend>ประเภทเรื่อง</legend>
            @foreach($categories as $key => $label)
                <label class="cmp-opt"><input type="radio" name="category" value="{{ $key }}" required @checked(isset($old['category']) && $old['category'] !== '' ? $old['category'] === $key : $loop->first)><span>{{ $label }}</span></label>
            @endforeach
        </fieldset>
        <fieldset class="cmp-q"><legend>ผู้แจ้ง</legend>
            <label class="cmp-opt"><input type="checkbox" name="anonymous" value="1" id="cmp-anon" @checked(!empty($old['anonymous']))><span>ส่งโดยไม่ระบุชื่อ (ไม่ต้องกรอกชื่อและช่องทางติดต่อ)</span></label>
            <div id="cmp-contact">
                <label class="cmp-field"><span>ชื่อ-สกุล</span><input type="text" name="name" maxlength="100" autocomplete="off" value="{{ $old['name'] ?? '' }}"></label>
                <label class="cmp-field"><span>อีเมล</span><input type="email" name="email" maxlength="254" inputmode="email" autocomplete="off" value="{{ $old['email'] ?? '' }}"></label>
                <label class="cmp-field"><span>โทรศัพท์</span><input type="tel" name="phone" maxlength="30" inputmode="tel" autocomplete="off" value="{{ $old['phone'] ?? '' }}"></label>
                <label class="cmp-opt"><input type="checkbox" name="consent" value="1" @checked(!empty($old['consent']))><span>ยินยอมให้โรงเรียนใช้ข้อมูลติดต่อนี้เพื่อดำเนินการและตอบเรื่องนี้</span></label>
                <small>ระบุอีเมลหรือโทรศัพท์อย่างน้อยหนึ่งช่องทาง</small>
            </div>
        </fieldset>
        <fieldset class="cmp-q"><legend>เรื่องที่แจ้ง</legend>
            <label class="cmp-field"><span>หัวข้อเรื่อง</span><input type="text" name="subject" maxlength="160" required value="{{ $old['subject'] ?? '' }}"></label>
            <label class="cmp-field"><span>รายละเอียด (ใคร ทำอะไร เมื่อไร ที่ไหน)</span><textarea name="detail" rows="8" minlength="10" maxlength="4000" required>{{ $old['detail'] ?? '' }}</textarea></label>
            <label class="cmp-field"><span>ไฟล์แนบ (ถ้ามี ไม่เกิน {{ $maxFiles }} ไฟล์ รูป PDF หรือเอกสารสำนักงาน)</span><small>ถ้าเลือกส่งโดยไม่ระบุชื่อ ระบบจะลบข้อมูลผู้สร้างและตำแหน่งในไฟล์ให้ และเปลี่ยนชื่อไฟล์ แต่รับเฉพาะรูปภาพ .docx .xlsx และ .txt ขอให้ตรวจเนื้อหาในไฟล์ด้วยว่าไม่มีข้อมูลที่ระบุตัวท่านได้</small><input type="file" name="files[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt"></label>
        </fieldset>
        <label class="cmp-hp" aria-hidden="true">เว้นว่างช่องนี้<input name="website" tabindex="-1" autocomplete="off"></label>
        @if($turnstile)
            <div class="cf-turnstile" data-sitekey="{{ $turnstile }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif
        <button type="submit" class="cmp-submit">ส่งเรื่อง</button>
    </form>
</section>
<style>
.cmp-page{max-width:720px;margin:0 auto 48px;padding:0 16px}.cmp-note{background:var(--surface-raised,#fffdf4);border:1px solid var(--line,#bac8b4);border-radius:10px;padding:10px 14px;margin:0 0 14px;font-size:.95rem}
.cmp-error{border:1px solid #b3261e;border-radius:10px;padding:10px 14px;margin:0 0 14px;background:#fff1f0;color:#601410}.cmp-error p{margin:4px 0 0}
.cmp-q{border:1px solid var(--line,#bac8b4);border-radius:12px;padding:12px 14px;margin:0 0 12px;background:var(--surface-raised,#fffdf4)}.cmp-q legend{font-weight:600;padding:0 6px}
.cmp-field{display:grid;gap:4px;margin:8px 0}.cmp-field input,.cmp-field textarea{width:100%;min-height:44px;padding:8px 12px;font-size:16px;box-sizing:border-box}
.cmp-opt{display:flex;gap:10px;align-items:center;min-height:44px;padding:4px 0}.cmp-opt input{width:22px;height:22px;flex:none}
.cmp-hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
.cmp-submit{min-height:48px;padding:0 28px;font-size:1rem;font-weight:600;border:0;border-radius:10px;background:var(--brand,#285747);color:var(--on-brand,#fff);cursor:pointer;width:100%}
@media (min-width:640px){.cmp-submit{width:auto}}
</style>
<script>
(()=>{const form=document.querySelector('.cmp-form');if(form)form.addEventListener('submit',()=>{const b=form.querySelector('.cmp-submit');if(b)setTimeout(()=>{b.disabled=true;},0);});})();
(()=>{const a=document.getElementById('cmp-anon'),c=document.getElementById('cmp-contact');if(!a||!c)return;const sync=()=>{c.hidden=a.checked;c.querySelectorAll('input').forEach(i=>{i.disabled=a.checked;});};a.addEventListener('change',sync);sync();})();
</script>
@endsection
