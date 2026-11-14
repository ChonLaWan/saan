<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $bookLabel }} เดือน{{ $monthName }} {{ $yearBe }}</title>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/modules/documents/documents-print.css') }}">
<script defer src="{{ \App\Support\WebsiteView::asset('assets/modules/documents/documents-print.js') }}"></script>
</head>
<body class="dp">
<div class="dp-bar"><a href="{{ url('/work') }}#documents">กลับหน้าทะเบียนหนังสือ</a><button type="button" id="dp-print-btn">พิมพ์ / บันทึกเป็น PDF</button></div>
<main class="dp-sheet">
<header>
<p class="dp-school">{{ $school }}</p>
<h1>{{ $bookLabel }}</h1>
<p class="dp-sub">เดือน{{ $monthName }} พ.ศ. {{ $yearBe }} (พิมพ์เมื่อ {{ $printedOn }})</p>
</header>
<table class="dp-table">
<thead><tr><th class="dp-no">เลขทะเบียน</th><th>วันที่ลงทะเบียน</th><th>ที่</th><th>ลงวันที่</th><th>@if($kind === 'outgoing')ถึง@else จาก@endif</th>@if($kind === 'incoming')<th>ถึง</th>@endif<th class="dp-subject">เรื่อง</th><th>ความเร่งด่วน</th><th>การปฏิบัติ</th><th>หมายเหตุ</th></tr></thead>
<tbody>
@forelse($rows as $r)
<tr><td class="dp-no">{{ $r['reg_label'] }}</td><td>{{ $r['registered_on'] }}</td><td>{{ $r['doc_no'] }}</td><td>{{ $r['doc_date'] }}</td><td>{{ $r['party'] }}</td>@if($kind === 'incoming')<td>{{ $r['to'] }}</td>@endif<td class="dp-subject">{{ $r['subject'] }}</td><td>{{ $r['urgency'] }}</td><td>{{ $r['action'] }}</td><td>{{ $r['note'] }}</td></tr>
@empty
<tr><td colspan="{{ $kind === 'incoming' ? 10 : 9 }}" class="dp-empty">ไม่มีรายการในเดือนนี้</td></tr>
@endforelse
</tbody>
</table>
<p class="dp-count">รวม {{ count($rows) }} รายการ</p>
</main>
</body>
</html>
