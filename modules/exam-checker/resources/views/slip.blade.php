<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>ใบแจ้งผลสอบ {{ $exam->title }}</title>
<style>
:root{--ink:#163b30;--muted:#4d6258;--line:#c4ccbe;--bad:#a72d1b}
*{box-sizing:border-box}
body{margin:0;background:#eef0ea;color:var(--ink);font-family:"Sarabun","Noto Sans Thai","Tahoma",sans-serif;font-size:14px;line-height:1.5}
.bar{display:flex;gap:.6rem;align-items:center;justify-content:space-between;padding:.7rem 1rem;background:#fff;border-bottom:1px solid var(--line)}
.bar button{min-height:44px;padding:.4rem 1.2rem;border-radius:999px;border:1px solid var(--ink);background:var(--ink);color:#fff;font:inherit;cursor:pointer}
.slip{width:210mm;min-height:297mm;margin:1rem auto;padding:16mm 16mm 12mm;background:#fff;border:1px solid var(--line);page-break-after:always;break-after:page}
.slip:last-child{page-break-after:auto;break-after:auto}
h1{margin:0 0 .2rem;font-size:20px}
.sub{margin:0;color:var(--muted)}
.who{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem 1rem;margin:1rem 0;padding:.7rem 0;border-top:2px solid var(--ink);border-bottom:1px solid var(--line)}
.who span{display:block;color:var(--muted);font-size:12px}
.score{display:flex;align-items:baseline;gap:.6rem;margin:.5rem 0 1rem}
.score b{font-size:40px;line-height:1}
.score small{color:var(--muted)}
table{border-collapse:collapse;width:100%;margin:.4rem 0 1rem}
th,td{padding:.3rem .5rem;border-bottom:1px solid var(--line);text-align:left}
th{font-size:12px;color:var(--muted);font-weight:600}
h2{font-size:15px;margin:1rem 0 .2rem}
.none{color:var(--muted)}
.foot{margin-top:1.5rem;color:var(--muted);font-size:12px}
@media (max-width:800px){.slip{width:auto;min-height:0;margin:.5rem;padding:1rem}.who{grid-template-columns:1fr 1fr}}
@page{size:A4;margin:0}
@media print{body{background:#fff}.bar{display:none}.slip{margin:0;border:0;width:210mm;min-height:297mm}}
</style>
</head>
<body>
<div class="bar"><span>{{ $exam->title }} : {{ count($slips) }} ใบ</span><button type="button" id="print">พิมพ์</button></div>
@forelse($slips as $s)
<section class="slip">
    <h1>ใบแจ้งผลการสอบ</h1>
    <p class="sub">{{ $exam->title }}@if($exam->subject_label) | {{ $exam->subject_label }}@endif @if($exam->grade_level) | {{ $exam->grade_level }}@endif</p>
    <div class="who">
        <div><span>ชื่อ</span>{{ $s['sheet']->display_name }}</div>
        <div><span>รหัสนักเรียน</span>{{ $s['sheet']->student_code }}</div>
        <div><span>ห้อง</span>{{ $s['sheet']->classroom_label ?: '-' }}</div>
    </div>
    <div class="score"><b>{{ rtrim(rtrim(number_format((float) $s['sheet']->score, 2, '.', ''), '0'), '.') }}</b><span>/ {{ rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') }} คะแนน</span><small>ร้อยละ {{ $s['percent'] }}</small></div>
    @if($s['sections'])
    <h2>คะแนนแยกตามส่วน</h2>
    <table><thead><tr><th>ส่วน</th><th>ได้</th><th>เต็ม</th></tr></thead><tbody>
    @foreach($s['sections'] as $sec)<tr><td>{{ $sec['label'] }}</td><td>{{ $sec['score'] }}</td><td>{{ $sec['max'] }}</td></tr>@endforeach
    </tbody></table>
    @endif
    <h2>ข้อที่ทำผิด ({{ count($s['wrong']) }} ข้อ)</h2>
    @if($s['wrong'])
    <table><thead><tr><th>ข้อ</th><th>ที่ตอบ</th><th>เฉลย</th></tr></thead><tbody>
    @foreach($s['wrong'] as $w)<tr><td>{{ $w['q'] }}</td><td>{{ $w['chosen'] }}</td><td>{{ $w['correct'] }}</td></tr>@endforeach
    </tbody></table>
    @else
    <p class="none">ไม่มีข้อที่ผิด</p>
    @endif
    <p class="foot">ข้อมูลนี้ออกจากระบบ {{ config('saan.display') }} ใช้เพื่อแจ้งผลแก่นักเรียนและผู้ปกครองเท่านั้น</p>
</section>
@empty
<section class="slip"><p class="none">ยังไม่มีใบตอบให้พิมพ์</p></section>
@endforelse
<script>document.getElementById('print')?.addEventListener('click',function(){window.print();});</script>
</body>
</html>
