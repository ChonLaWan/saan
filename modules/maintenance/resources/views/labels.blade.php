<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>ป้ายรหัสครุภัณฑ์</title>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/modules/maintenance/maintenance-print.css') }}">
<script defer src="{{ \App\Support\WebsiteView::asset('assets/modules/maintenance/maintenance-print.js') }}"></script>
</head>
<body class="ml">
<div class="ml-bar"><a href="{{ url('/work') }}#assets">กลับหน้าครุภัณฑ์</a><span>{{ count($labels) }} ป้าย (พิมพ์เมื่อ {{ $printedOn }})</span><button type="button" id="ml-print-btn">พิมพ์ / บันทึกเป็น PDF</button></div>
<main class="ml-sheet">
@forelse($labels as $l)
<div class="ml-label"><p class="ml-school">{{ $school }}</p><p class="ml-code">{{ $l['code'] }}</p><p class="ml-name">{{ $l['name'] }}</p>@if($l['location'] !== '')<p class="ml-loc">{{ $l['location'] }}</p>@endif</div>
@empty
<p class="ml-empty">ไม่มีครุภัณฑ์ให้พิมพ์ป้าย</p>
@endforelse
</main>
</body>
</html>
