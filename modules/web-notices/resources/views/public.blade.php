@extends('website.layout')
@section('content')
<meta name="robots" content="noindex,nofollow,noarchive">
<section class="collection-heading"><p class="site-eyebrow">ประกาศรายชื่อ</p><h1>{{ $list->title }}</h1>
@if($list->intro)<p>{{ $list->intro }}</p>@endif</section>
<section class="notice-page">
    @if(!empty($gate))
        {{-- Anti-bot delivery: no name, no row, only title, intro, count and the Turnstile step. Rows arrive as JSON after a one-time token and are written with textContent. --}}
        <p class="notice-meta" role="status">{{ $gate['count'] }} รายการ · แสดงถึงวันที่ {{ \App\Support\WebsiteView::date($list->expires_on) }}</p>
        @if(!$gate['ready'])
            <p class="notice-error" role="alert">ประกาศนี้ยังไม่พร้อมแสดงชั่วคราว กรุณาแจ้งเจ้าหน้าที่โรงเรียน</p>
        @else
            <div id="notice-gate" data-slug="{{ $list->slug }}" data-mode="{{ $gate['search'] ? 'search' : 'list' }}" data-sitekey="{{ $gate['siteKey'] }}" data-csrf="{{ csrf_token() }}" data-base="{{ url('/portal/notices/'.$list->slug) }}">
                @if($gate['search'])
                    <form id="notice-search" class="notice-unlock" autocomplete="off" novalidate>
                        <label for="notice-q">ค้นหาชื่อของตนเอง@if($gate['numeric']) หรือเลขที่สมัคร/เลขที่นั่งสอบ@endif</label>
                        <input id="notice-q" name="q" type="text" inputmode="search" minlength="{{ $gate['min'] }}" maxlength="80" autocomplete="off" required>
                        <p class="notice-hint">พิมพ์อย่างน้อย {{ $gate['min'] }} ตัวอักษรของชื่อ@if($gate['numeric']) หรือเลขที่สมัคร/เลขที่นั่งสอบให้ครบอย่างน้อย {{ $gate['min_digits'] }} หลัก (เลขที่อย่างเดียวจะแสดงชื่อแบบย่อ หากต้องการชื่อเต็มให้พิมพ์เลขที่ตามด้วยชื่อ เช่น 10001 สมชาย)@endif ระบบแสดงผลสูงสุด {{ $gate['max'] }} รายการ ไม่แสดงรายชื่อทั้งหมด</p>
                        <div id="notice-turnstile" class="notice-turnstile"></div>
                        <button type="submit">ค้นหา</button>
                    </form>
                @else
                    <p class="notice-hint">กรุณายืนยันว่าเป็นผู้ใช้จริงเพื่อดูรายชื่อ</p>
                    <div id="notice-turnstile" class="notice-turnstile"></div>
                @endif
                <p id="notice-status" class="notice-hint" role="status" aria-live="polite"></p>
                <div id="notice-result"></div>
            </div>
            <noscript><p class="notice-error">ต้องเปิด JavaScript เพื่อยืนยันตัวตนและดูรายชื่อ</p></noscript>
            <p class="notice-hint">@if($gate['full'])แสดงชื่อเต็มถึง {{ \App\Support\WebsiteView::date(substr((string) $gate['until'], 0, 10)) }} เวลา {{ substr((string) $gate['until'], 11, 5) }} น. หลังจากนั้นชื่อจะถูกปิดบังบางส่วน @else ชื่อถูกปิดบังบางส่วนเพื่อความเป็นส่วนตัว @endif หน้านี้จะหมดอายุและถูกลบโดยอัตโนมัติ</p>
            <script src="{{ \App\Support\WebsiteView::asset('assets/modules/web-notices/notices-public.js') }}" defer></script>
        @endif
    @elseif($rows === null)
        <form method="post" action="{{ url('/portal/notices/'.$list->slug.'/unlock') }}" class="notice-unlock" autocomplete="off">
            @csrf
            <label for="notice-code">รหัสเข้าดูรายชื่อ</label>
            <input id="notice-code" name="code" type="password" required maxlength="100" autocomplete="off" @if($error) aria-invalid="true" aria-describedby="notice-err" @endif>
            @if($error)<p id="notice-err" class="notice-error" role="alert">{{ $error }}</p>@endif
            <button type="submit">เปิดดูรายชื่อ</button>
            <p class="notice-hint">รหัสแจ้งให้ผู้ปกครองหรือผู้สมัครทราบโดยโรงเรียน</p>
        </form>
    @elseif(!$rows)
        <p class="notice-empty">ไม่มีรายชื่อในประกาศนี้</p>
    @else
        <p class="notice-meta" role="status">{{ count($rows) }} รายการ · แสดงถึงวันที่ {{ \App\Support\WebsiteView::date($list->expires_on) }}</p>
        @php($labels=['order_no'=>'ลำดับ','display_name'=>'ชื่อ','classroom'=>'ห้อง/ชั้น','group'=>'กลุ่ม','note'=>'หมายเหตุ','applicant_no'=>'เลขที่สมัคร/เลขที่นั่งสอบ'])
        <table class="notice-table">
            <thead><tr>@foreach($columns as $c)<th scope="col">{{ $labels[$c] ?? $c }}</th>@endforeach</tr></thead>
            <tbody>@foreach($rows as $row)<tr>@foreach($columns as $c)<td data-label="{{ $labels[$c] ?? $c }}">{{ $row[$c] ?? '' }}</td>@endforeach</tr>@endforeach</tbody>
        </table>
        <p class="notice-hint">ชื่อถูกปิดบังบางส่วนเพื่อความเป็นส่วนตัว หน้านี้จะหมดอายุและถูกลบโดยอัตโนมัติ</p>
    @endif
</section>
<style>
.notice-page{max-width:980px;margin:0 auto 48px;padding:0 16px}.notice-meta,.notice-hint,.notice-empty{opacity:.8;font-size:.92rem}
.notice-table{width:100%;border-collapse:collapse;background:var(--surface-raised,#fffdf4);border:1px solid var(--line,#bac8b4)}
.notice-table th,.notice-table td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line,#bac8b4)}.notice-table td:first-child{font-variant-numeric:tabular-nums}
.notice-unlock{display:grid;gap:10px;max-width:360px}.notice-unlock input{min-height:44px;padding:8px 12px;font-size:16px}.notice-unlock button,.notice-retry{min-height:44px;padding:0 16px}.notice-error{color:#8a1f1f;margin:0}.notice-turnstile{min-height:65px}#notice-result{margin-top:12px}
@media (max-width:640px){.notice-table thead{position:absolute;left:-9999px}.notice-table,.notice-table tbody,.notice-table tr,.notice-table td{display:block;width:100%}.notice-table tr{margin:10px 0;border:1px solid var(--line,#bac8b4);border-radius:10px;overflow:hidden}.notice-table td{display:flex;gap:10px;justify-content:space-between;border-bottom:1px solid var(--line,#bac8b4)}.notice-table td:last-child{border-bottom:0}.notice-table td::before{content:attr(data-label);font-weight:600;opacity:.75}}
</style>
@endsection
