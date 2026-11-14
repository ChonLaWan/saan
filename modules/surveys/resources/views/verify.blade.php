@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">เกียรติบัตร</p><h1>ตรวจสอบเกียรติบัตร</h1><p>กรอกเลขที่เกียรติบัตรเพื่อตรวจสอบว่าออกโดย{{ $theme['school_name'] }}จริงหรือไม่</p></section>
<section style="max-width:720px;margin:0 auto 48px;padding:0 16px">
    <form method="get" action="{{ url('/portal/certificates/verify') }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-bottom:16px">
        <label style="flex:1 1 240px;display:grid;gap:4px"><span>เลขที่เกียรติบัตร</span><input type="text" name="no" value="{{ $no }}" maxlength="60" style="min-height:44px;padding:8px 12px;font-size:16px" placeholder="เช่น 1-2569-000001" autocomplete="off"></label>
        <button type="submit" style="min-height:44px;padding:0 20px">ตรวจสอบ</button>
    </form>
    @if($no !== '')
        @if($row)
            <div role="status" style="border:2px solid #1b6b3a;border-radius:12px;padding:14px 16px;background:#effaf2;color:#0f3d20">
                <strong>ถูกต้อง: พบเกียรติบัตรเลขที่ {{ $row->certificate_no }}</strong>
                <p style="margin:6px 0 0">@if($full)ผู้รับ: {{ $shownName }}<br>แบบสอบถาม: {{ $surveyTitle }}<br>@endif ออกโดย: {{ $theme['school_name'] }}<br>วันที่ออก: {{ $date }}</p>@unless($full)<p style="margin:6px 0 0"><small>ไม่แสดงชื่อผู้รับเพื่อคุ้มครองข้อมูลส่วนบุคคล ผู้ถือเกียรติบัตรเปิดชื่อเต็มได้จากลิงก์ตรวจสอบบนเกียรติบัตรของตน</small></p>@endunless
            </div>
        @else
            <div role="status" style="border:2px solid #b3261e;border-radius:12px;padding:14px 16px;background:#fff1f0;color:#601410"><strong>ไม่ถูกต้อง: ไม่พบเกียรติบัตรเลขที่ "{{ $no }}"</strong><p style="margin:6px 0 0">โปรดตรวจเลขที่อีกครั้ง หากยังไม่พบให้ติดต่อโรงเรียน</p></div>
        @endif
    @endif
</section>
@endsection
