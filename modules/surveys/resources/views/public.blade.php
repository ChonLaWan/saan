@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">แบบสอบถาม</p><h1>{{ $survey->title }}</h1>
@if($survey->intro)<p class="srv-intro">{!! nl2br(e($survey->intro)) !!}</p>@endif</section>
<section class="srv-page">
    @if($survey->identity_mode === 'anonymous')
        <p class="srv-note">แบบสอบถามนี้ไม่ระบุตัวตน ระบบจำได้เพียงว่าเครื่องนี้ตอบแล้ว (ใช้คุกกี้) จึงไม่อาจรับรองได้ว่าหนึ่งคนตอบหนึ่งครั้ง</p>
    @elseif($survey->identity_mode === 'name_email')
        <p class="srv-note">กรอกชื่อและอีเมลที่ใช้งานได้ เราจะส่งลิงก์ยืนยันให้ คำตอบจะนับเมื่อกดลิงก์ยืนยัน (หนึ่งอีเมลตอบได้หนึ่งครั้ง) ระบบไม่เก็บอีเมลไว้หลังส่งลิงก์</p>
    @else
        <p class="srv-note">กรอกรหัสที่ได้รับจากโรงเรียน รหัสหนึ่งรหัสใช้ได้ครั้งเดียว</p>
    @endif
    @if($cert)<p class="srv-note">เมื่อตอบเสร็จ ท่านจะได้รับเกียรติบัตรที่ตรวจสอบเลขที่ได้@if(($cert['min_score'] ?? null) !== null) (ต้องได้คะแนนไม่ต่ำกว่าร้อยละ {{ $cert['min_score'] }})@endif</p>@endif
    @if($errors->any())<div class="srv-error" role="alert"><strong>กรุณาตรวจสอบข้อมูลที่กรอก</strong><ul>@foreach($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul></div>@endif
    <form method="post" action="{{ url('/portal/surveys/'.$survey->slug.'/submit') }}" class="srv-form" novalidate>
        @csrf
        @if($survey->identity_mode === 'name_email')
            <fieldset class="srv-q"><legend>ข้อมูลผู้ตอบ</legend>
                <label class="srv-field"><span>ชื่อ-สกุล (ที่จะแสดงในเกียรติบัตร)</span><input type="text" name="name" value="{{ old('name') }}" maxlength="160" autocomplete="name" required>@error('name')<small class="srv-err">{{ $message }}</small>@enderror</label>
                <label class="srv-field"><span>อีเมล</span><input type="email" name="email" value="{{ old('email') }}" maxlength="190" autocomplete="email" inputmode="email" required>@error('email')<small class="srv-err">{{ $message }}</small>@enderror</label>
            </fieldset>
        @elseif($survey->identity_mode === 'roster_code')
            <fieldset class="srv-q"><legend>รหัสผู้ตอบ</legend>
                <label class="srv-field"><span>รหัสที่ได้รับ</span><input type="text" name="code" value="{{ old('code') }}" maxlength="40" autocomplete="off" autocapitalize="characters" required>@error('code')<small class="srv-err">{{ $message }}</small>@enderror</label>
            </fieldset>
        @endif
        @foreach($questions as $i => $q)
            @php($f = 'a.'.$q['key'])
            <fieldset class="srv-q" @if($errors->has($f)) aria-invalid="true" @endif>
                <legend><span class="srv-num">{{ $i + 1 }}.</span> {{ $q['label'] }}@if($q['required'])<abbr title="จำเป็นต้องตอบ" class="srv-req"> *</abbr>@endif</legend>
                @if($q['type'] === 'single')
                    @foreach($q['options'] as $o)<label class="srv-opt"><input type="radio" name="a[{{ $q['key'] }}]" value="{{ $o }}" @checked(old($f) === $o)><span>{{ $o }}</span></label>@endforeach
                @elseif($q['type'] === 'multi')
                    @foreach($q['options'] as $o)<label class="srv-opt"><input type="checkbox" name="a[{{ $q['key'] }}][]" value="{{ $o }}" @checked(in_array($o, (array) old($f, []), true))><span>{{ $o }}</span></label>@endforeach
                @elseif($q['type'] === 'scale')
                    <div class="srv-scale" role="group"><small>น้อยที่สุด</small>@for($n = 1; $n <= ($q['max'] ?? 5); $n++)<label class="srv-dot"><input type="radio" name="a[{{ $q['key'] }}]" value="{{ $n }}" @checked((string) old($f) === (string) $n)><span>{{ $n }}</span></label>@endfor<small>มากที่สุด</small></div>
                @else
                    <textarea name="a[{{ $q['key'] }}]" rows="4" maxlength="2000">{{ old($f) }}</textarea>
                @endif
                @error($f)<small class="srv-err">{{ $message }}</small>@enderror
            </fieldset>
        @endforeach
        <button type="submit" class="srv-submit">ส่งคำตอบ</button>
    </form>
</section>
<style>
.srv-page{max-width:720px;margin:0 auto 48px;padding:0 16px}.srv-intro{max-width:60ch}.srv-note{background:var(--surface-raised,#fffdf4);border:1px solid var(--line,#bac8b4);border-radius:10px;padding:10px 14px;margin:0 0 14px;font-size:.95rem}
.srv-error{border:1px solid #b3261e;border-radius:10px;padding:10px 14px;margin:0 0 14px;background:#fff1f0;color:#601410}.srv-error ul{margin:6px 0 0 18px;padding:0}
.srv-q{border:1px solid var(--line,#bac8b4);border-radius:12px;padding:12px 14px;margin:0 0 12px;background:var(--surface-raised,#fffdf4)}.srv-q[aria-invalid=true]{border-color:#b3261e}.srv-q legend{font-weight:600;padding:0 6px}
.srv-field{display:grid;gap:4px;margin:8px 0}.srv-field input,.srv-q textarea{width:100%;min-height:44px;padding:8px 12px;font-size:16px;box-sizing:border-box}
.srv-opt{display:flex;gap:10px;align-items:center;min-height:44px;padding:4px 0}.srv-opt input{width:22px;height:22px;flex:none}
.srv-scale{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.srv-dot{position:relative}.srv-dot input{position:absolute;opacity:0;inset:0;width:100%;height:100%;margin:0;cursor:pointer}.srv-dot span{display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;border:1px solid var(--line,#7a8f74);border-radius:10px}.srv-dot input:checked+span{background:var(--brand,#285747);color:var(--on-brand,#fff)}.srv-dot input:focus-visible+span{outline:3px solid currentColor;outline-offset:2px}
.srv-err{display:block;color:#8c1d18;margin-top:4px}.srv-submit{min-height:48px;padding:0 28px;font-size:1rem;font-weight:600;border:0;border-radius:10px;background:var(--brand,#285747);color:var(--on-brand,#fff);cursor:pointer;width:100%}
@media (min-width:640px){.srv-submit{width:auto}}
</style>
@endsection
