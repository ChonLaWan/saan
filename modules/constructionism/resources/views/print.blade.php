@php
    $p = $d['project'];
    $crit = $d['rubric']['criteria'];
    $thai = fn ($date) => \Carbon\CarbonImmutable::parse($date)->locale('th')->translatedFormat('j M').' '.(\Carbon\CarbonImmutable::parse($date)->year + 543);
@endphp
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>สรุปโครงงาน {{ $p['title'] }}</title>
<style>
:root{color-scheme:light;--ink:#14231c;--muted:#4b5c53;--line:#b8c2bb}
*{box-sizing:border-box}body{margin:0;background:#fff;color:var(--ink);font:15px/1.6 "Noto Sans Thai",system-ui,sans-serif}
main{max-width:800px;margin:0 auto;padding:16px}h1{font-size:1.5rem;margin:0 0 4px}h2{font-size:1.1rem;margin:20px 0 6px;border-bottom:2px solid var(--ink);padding-bottom:2px}
.meta{color:var(--muted);margin:0 0 8px}.q{border-left:4px solid var(--ink);padding:4px 12px;margin:10px 0;background:#f3f6f4}
table{border-collapse:collapse;width:100%}th,td{border:1px solid var(--line);padding:5px 8px;text-align:left;vertical-align:top}th{background:#eef2ef}
.log{margin:0 0 8px;padding:0 0 8px;border-bottom:1px dotted var(--line)}.log b{display:inline-block;min-width:7.5em}.tag{color:var(--muted);font-size:.85rem}
.bar{margin:0 0 12px}.bar button{min-height:44px;padding:0 16px;font:inherit;cursor:pointer}
.sign{display:flex;gap:40px;margin-top:40px}.sign div{flex:1;text-align:center;border-top:1px solid var(--ink);padding-top:4px}
@media (max-width:600px){table,thead,tbody,tr,th,td{display:block}thead{position:absolute;left:-9999px}tr{margin:0 0 8px;border:1px solid var(--line)}td{border:0;border-bottom:1px solid var(--line)}}
@media print{.bar{display:none}main{padding:0;max-width:none}h2{break-after:avoid}tr,.log{break-inside:avoid}table,thead,tbody,tr,th,td{display:revert}thead{position:static}tr{margin:0;border:0}td{border:1px solid var(--line)}}
</style>
</head>
<body>
<main>
<p class="bar"><button type="button" id="do-print">พิมพ์ใบสรุป</button></p>
<h1>{{ $p['title'] }}</h1>
<p class="meta">ห้อง {{ $p['classroom_name'] }} · @if($term)ปีการศึกษา {{ $term->year }} ภาคเรียนที่ {{ $term->term }} · @endif สถานะ {{ $p['status_label'] }} · ครูที่ปรึกษา {{ $p['advisor_name'] ?: '-' }}</p>
@if($p['driving_question'])<div class="q"><strong>คำถามนำ</strong><br>{!! nl2br(e($p['driving_question'])) !!}</div>@endif

<h2>สมาชิกทีม ({{ count($d['members']) }} คน)</h2>
@if(!count($d['members']))<p>ยังไม่มีสมาชิก</p>@else
<table><thead><tr><th>เลขประจำตัว</th><th>ชื่อ</th><th>บทบาท</th></tr></thead><tbody>
@foreach($d['members'] as $m)<tr><td>{{ $m['code'] }}</td><td>{{ $m['name'] }}</td><td>{{ $m['role'] ?: '-' }}</td></tr>@endforeach
</tbody></table>@endif

<h2>สมุดบันทึกความก้าวหน้า ({{ count($d['logs']) }} รายการ)</h2>
@forelse($d['logs'] as $l)
<div class="log"><b>{{ $thai($l['date']) }}</b> <span class="tag">{{ $l['by_teacher'] ? 'ครูสังเกต' : 'บันทึกจากคำบอกเล่าของนักเรียน' }} · {{ $l['by_name'] }}</span><br>{!! nl2br(e($l['entry'])) !!}</div>
@empty<p>ยังไม่มีบันทึก</p>@endforelse

<h2>ชิ้นงาน ({{ count($d['artifacts']) }} รายการ)</h2>
@if(!count($d['artifacts']))<p>ยังไม่มีชิ้นงาน</p>@else
<table><thead><tr><th>ชื่อชิ้นงาน</th><th>ชนิด</th><th>ที่เก็บ</th></tr></thead><tbody>
@foreach($d['artifacts'] as $a)<tr><td>{{ $a['title'] }}</td><td>{{ $a['kind_label'] }}</td><td>@if($a['has_file']){{ $a['original_name'] }} ({{ number_format($a['size'] / 1024) }} KB, ไฟล์ในระบบโรงเรียน)@else{{ $a['url'] }}@endif</td></tr>@endforeach
</tbody></table>@endif

<h2>การสะท้อนคิดและรูบริก</h2>
@if(!count($d['reflections']))<p>ยังไม่มีการสะท้อนคิด</p>@else
@foreach($d['reflections'] as $r)
<div class="log"><b>{{ $r['student_name'] }}</b>@if($r['text'])<br>{!! nl2br(e($r['text'])) !!}@endif</div>
@endforeach
<table><thead><tr><th>นักเรียน</th>@foreach($crit as $c)<th>{{ $c['label'] }}</th>@endforeach</tr></thead><tbody>
@foreach($d['reflections'] as $r)<tr><td>{{ $r['student_name'] }}</td>@foreach($crit as $c)@php($lv = $r['rubric'][$c['key']] ?? null)<td>{{ $lv ? $lv.' ('.$levels[$lv].')' : '-' }}</td>@endforeach</tr>@endforeach
</tbody></table>
<p class="tag">ระดับ 1 = {{ $levels[1] }}, 2 = {{ $levels[2] }}, 3 = {{ $levels[3] }}, 4 = {{ $levels[4] }}</p>
@endif

<div class="sign"><div>ครูที่ปรึกษาโครงงาน</div><div>ผู้อำนวยการโรงเรียน</div></div>
<p class="tag">พิมพ์เมื่อ {{ $thai(now()->toDateString()) }}</p>
</main>
<script>document.getElementById('do-print').addEventListener('click',function(){window.print();});</script>
</body>
</html>
