<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>ตารางสอน {{ $type === 'classroom' ? 'ห้อง' : 'ครู' }} {{ $subjectName }}</title>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/modules/timetable/timetable-print.css') }}">
<script defer src="{{ \App\Support\WebsiteView::asset('assets/modules/timetable/timetable-print.js') }}"></script>
</head>
<body class="tt-print">
<div class="tt-bar"><a href="{{ url('/work') }}#timetable">กลับหน้าตารางสอน</a><button type="button" id="tt-print-btn">พิมพ์ / บันทึกเป็น PDF</button></div>
<main class="tt-sheet">
<header>
<p class="tt-school">{{ $school }}</p>
<h1>ตารางสอน{{ $type === 'classroom' ? 'ห้อง ' : 'ครู ' }}{{ $subjectName }}</h1>
<p class="tt-sub">ปีการศึกษา {{ $term->year }} ภาคเรียนที่ {{ $term->term }}@if(!empty($term->label)) ({{ $term->label }})@endif</p>
</header>
<table class="tt-grid">
<thead><tr><th class="tt-day">วัน / คาบ</th>@foreach($periods as $p)<th>@if(($p->kind ?? 'class') === 'break')พัก@else{{ $p->number }}@endif<small>{{ $p->starts_at }}-{{ $p->ends_at }}</small></th>@endforeach</tr></thead>
<tbody>
@foreach($days as $d)
<tr><th class="tt-day">{{ $dayNames[$d] }}</th>
@foreach($periods as $p)
@if(($p->kind ?? 'class') === 'break')<td class="tt-break">พัก</td>
@else
<td>@foreach($cells[$d][$p->id] ?? [] as $l)<div class="tt-lesson"><b>{{ $l['subject'] }}</b><span>@if($type === 'classroom'){{ $l['teacher_name'] }}@else{{ $l['classroom_name'] }}@endif</span>@if($l['room'] !== '')<span>{{ $l['room'] }}</span>@endif</div>@endforeach</td>
@endif
@endforeach
</tr>
@endforeach
</tbody>
</table>
<footer>
<p>รวม {{ $count }} คาบต่อสัปดาห์</p>
<p class="tt-meta">พิมพ์เมื่อ {{ date('d/m/', strtotime($printedOn)) }}{{ (int) date('Y', strtotime($printedOn)) + 543 }}</p>
</footer>
</main>
</body>
</html>
