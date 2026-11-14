@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">แบบสอบถาม</p><h1>ยืนยันคำตอบ</h1><p>{{ $survey->title }}</p></section>
<section class="srv-notice" style="max-width:720px;margin:0 auto 48px;padding:0 16px">
    <div class="srv-pending-summary" style="margin:0 0 16px;padding:12px 16px;border:1px solid currentColor;border-radius:8px">
        <p><strong>ชื่อที่จะปรากฏบนเกียรติบัตร:</strong> {{ $displayName }}</p>
        @if (!empty($summary))
        <p><strong>คำตอบที่จะถูกนับ</strong></p>
        <ul>@foreach ($summary as [$label, $value])<li>{{ $label }}: {{ $value }}</li>@endforeach</ul>
        @endif
        <p>หากไม่ใช่ชื่อหรือคำตอบของท่าน อย่ากดยืนยัน และกรอกแบบสอบถามใหม่</p>
    </div>
    <p>กดปุ่มด้านล่างเพื่อยืนยันว่าท่านเป็นผู้ส่งคำตอบนี้ คำตอบจะถูกนับเมื่อกดยืนยันเท่านั้น</p>
    <form method="post" action="{{ $action }}">
        @csrf
        <button type="submit" style="min-height:48px;padding:0 24px;font-size:16px">ยืนยันคำตอบของฉัน</button>
    </form>
</section>
@endsection
