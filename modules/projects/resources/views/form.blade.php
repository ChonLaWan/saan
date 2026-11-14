<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>แบบเสนอโครงการ {{ $p['code'] }}</title>
<style>
:root{--ink:#1a1f2b;--muted:#566070;--line:#9aa2b0;--paper:#fff;--bg:#eef0f4;--accent:#0b5a8a}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 "Sarabun","TH Sarabun New","Noto Sans Thai",system-ui,sans-serif}
.bar{display:flex;gap:8px;align-items:center;justify-content:center;padding:12px 16px}
.bar button{font:inherit;padding:8px 18px;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:6px;cursor:pointer;min-height:44px}
.sheet{width:100%;max-width:210mm;min-height:297mm;margin:0 auto 24px;padding:15mm 16mm;background:var(--paper);color:var(--ink);border:1px solid var(--line)}
h1{margin:0;font-size:1.3rem;text-align:center}.sub{color:var(--muted);font-size:.9rem;text-align:center}
.head{border-bottom:2px solid var(--ink);padding-bottom:8px;margin-bottom:12px}
dl{display:grid;grid-template-columns:max-content 1fr;gap:3px 14px;margin:0 0 12px}dt{font-weight:700}dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}
h2{font-size:1.05rem;margin:14px 0 6px}
table{width:100%;border-collapse:collapse;margin-bottom:8px}th,td{padding:4px 6px;border:1px solid var(--line);text-align:left;vertical-align:top}
th.n,td.n{text-align:right;white-space:nowrap}tfoot td{font-weight:700}
.sign{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:28px;break-inside:avoid}
.sign div{text-align:center;font-size:.92rem}.sign .line{border-bottom:1px dotted var(--ink);height:34px;margin-bottom:4px}
@page{size:A4 portrait;margin:12mm}
@media print{
html,body{background:#fff}
.bar{display:none}
.sheet{width:auto;max-width:none;min-height:0;margin:0;padding:0;border:0}
tr,.sign{break-inside:avoid}
}
@media (max-width:520px){.sheet{padding:14px}dl{grid-template-columns:1fr}.sign{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="bar"><button type="button" id="print-btn">พิมพ์แบบเสนอโครงการ</button></div>
<main class="sheet">
<div class="head">
<h1>{{ $school }}</h1>
@if($address)<div class="sub">{{ $address }}</div>@endif
<div class="sub">แบบเสนอโครงการตามแผนปฏิบัติการ ปีงบประมาณ {{ $p['fiscal_year_be'] }}</div>
</div>
<dl>
<dt>รหัสโครงการ</dt><dd>{{ $p['code'] }}</dd>
<dt>ชื่อโครงการ</dt><dd>{{ $p['title'] }}</dd>
<dt>ผู้รับผิดชอบ</dt><dd>{{ $p['owner_name'] }}</dd>
@if($p['department'])<dt>กลุ่มงาน/ฝ่าย</dt><dd>{{ $p['department'] }}</dd>@endif
@if($p['strategy'])<dt>ยุทธศาสตร์/มาตรฐาน</dt><dd>{{ $p['strategy'] }}</dd>@endif
<dt>วัตถุประสงค์</dt><dd>{{ $p['objective'] }}</dd>
@if($p['target'])<dt>เป้าหมาย</dt><dd>{{ $p['target'] }}</dd>@endif
<dt>ระยะเวลา</dt><dd>{{ \Saan\Modules\Projects\Support::thaiDate($p['starts_on']) }} ถึง {{ \Saan\Modules\Projects\Support::thaiDate($p['ends_on']) }}</dd>
<dt>งบประมาณ</dt><dd>{{ \Saan\Modules\Projects\Support::fmt($p['budget_satang']) }} บาท (แหล่งงบ: {{ $p['budget_source'] }})</dd>
<dt>สถานะ</dt><dd>{{ ['proposed' => 'เสนอโครงการ', 'approved' => 'อนุมัติแล้ว', 'in_progress' => 'กำลังดำเนินการ', 'done' => 'เสร็จสิ้น', 'cancelled' => 'ยกเลิก'][$p['status']] ?? $p['status'] }}</dd>
</dl>
<h2>กิจกรรมและงบประมาณ</h2>
<table>
<thead><tr><th>#</th><th>กิจกรรม</th><th>กำหนดการ</th><th class="n">งบ (บาท)</th><th class="n">ใช้จ่าย (บาท)</th></tr></thead>
<tbody>
@forelse($activities as $i => $a)
<tr><td>{{ $i + 1 }}</td><td>{{ $a['title'] }}@if($a['note'])<br><small>{{ $a['note'] }}</small>@endif</td><td>{{ \Saan\Modules\Projects\Support::thaiDate($a['planned_on']) }}</td><td class="n">{{ \Saan\Modules\Projects\Support::fmt($a['budget_satang']) }}</td><td class="n">{{ \Saan\Modules\Projects\Support::fmt($a['spent_satang']) }}</td></tr>
@empty
<tr><td colspan="5">ยังไม่มีกิจกรรม</td></tr>
@endforelse
</tbody>
<tfoot><tr><td colspan="3">รวม</td><td class="n">{{ \Saan\Modules\Projects\Support::fmt($p['allocated_satang']) }}</td><td class="n">{{ \Saan\Modules\Projects\Support::fmt($p['spent_satang']) }}</td></tr></tfoot>
</table>
<div class="sign">
<div><div class="line"></div>ผู้เสนอโครงการ<br>({{ $p['owner_name'] }})</div>
<div><div class="line"></div>หัวหน้ากลุ่มงาน/ฝ่าย</div>
<div><div class="line"></div>ผู้อำนวยการโรงเรียน<br>@if($approver)({{ $approver }})<br>อนุมัติเมื่อ {{ \Saan\Modules\Projects\Support::thaiDate($p['approved_at']) }}@endif</div>
</div>
</main>
<script>document.getElementById('print-btn').addEventListener('click',function(){window.print();});</script>
</body>
</html>
