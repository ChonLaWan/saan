@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">แบบสอบถาม</p><h1>{{ $heading }}</h1></section>
<section class="srv-notice" style="max-width:720px;margin:0 auto 48px;padding:0 16px">
    <p role="status">{{ $message }}</p>
    @if(!empty($certificateNo))
        <p>เกียรติบัตรของท่านเลขที่ <strong>{{ $certificateNo }}</strong></p>
        <p><a class="text-link" href="{{ $certUrl }}">เปิดเกียรติบัตร (พิมพ์หรือบันทึกเป็น PDF)</a></p>
        <p><small>เก็บเลขที่นี้ไว้ ใช้ตรวจสอบได้ที่ <a href="{{ $verifyUrl }}">หน้าตรวจสอบเกียรติบัตร</a></small></p>
    @endif
    <p><a class="text-link" href="{{ url('/') }}">กลับหน้าแรก</a></p>
</section>
@endsection
