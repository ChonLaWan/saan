<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>หนังสือรับรองการหักภาษี ณ ที่จ่าย (50 ทวิ) ปี {{ $c['year_be'] }}</title>
<style>
:root{--ink:#1a1f2b;--muted:#566070;--line:#9aa3b2;--paper:#fff;--bg:#eef0f4;--accent:#0b5a8a}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 "Sarabun","Noto Sans Thai",system-ui,sans-serif}
.bar{display:flex;gap:8px;align-items:center;justify-content:center;padding:12px 16px}
.bar button{font:inherit;padding:8px 18px;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:6px;cursor:pointer}
.sheet{position:relative;overflow:hidden;width:100%;max-width:210mm;min-height:200mm;margin:0 auto 24px;padding:12mm 14mm;background:var(--paper);border:1px solid var(--line)}
.wm{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:0}
.wm span{position:absolute;left:-20%;width:140%;text-align:center;font-size:1.1rem;font-weight:700;color:#000;opacity:.06;transform:rotate(-24deg);white-space:nowrap}
.body{position:relative;z-index:1}
h1{margin:0;font-size:1.25rem;text-align:center}.sub{color:var(--muted);font-size:.9rem;text-align:center}
.box{border:1px solid var(--line);padding:8px 10px;margin:10px 0}
.box h2{margin:0 0 4px;font-size:1rem}
dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 12px;margin:0}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}
table{width:100%;border-collapse:collapse;margin-top:6px}th,td{padding:5px 6px;border:1px solid var(--line);text-align:left;font-weight:400}
th{background:#f3f5f8}td.n,th.n{text-align:right;white-space:nowrap}
.sign{display:flex;justify-content:flex-end;margin-top:26px}.sign div{width:70mm;text-align:center;border-top:1px dotted var(--ink);padding-top:4px;font-size:.9rem}
.foot{margin-top:14px;color:var(--muted);font-size:.85rem}
@page{size:A4 portrait;margin:0}
@media print{html,body{background:#fff}.bar{display:none}.sheet{width:210mm;max-width:none;margin:0;border:0}.box,table,.sign{break-inside:avoid}}
@media (max-width:520px){.sheet{padding:14px}}
</style>
</head>
<body>
<div class="bar"><button type="button" id="print-btn">พิมพ์หนังสือรับรอง (บันทึกเป็น PDF ได้)</button></div>
<main class="sheet">
<div class="wm" aria-hidden="true">@for($i = 0; $i < 9; $i++)<span style="top:{{ $i * 12 - 4 }}%">{{ $viewer }} &middot; {{ $opened }}</span>@endfor</div>
<div class="body">
<h1>หนังสือรับรองการหักภาษี ณ ที่จ่าย</h1>
<div class="sub">ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร &middot; ปีภาษี {{ $c['year_be'] }} &middot; เลขที่ {{ $c['cert_no'] }}</div>
<div class="box"><h2>ผู้จ่ายเงิน</h2>
<dl><dt>ชื่อ</dt><dd>{{ $c['payer']['name'] }}</dd>
<dt>เลขประจำตัวผู้เสียภาษี</dt><dd>{{ $c['payer']['tax_id'] !== '' ? $c['payer']['tax_id'] : '-' }}</dd>
<dt>ที่อยู่</dt><dd>{{ $c['payer']['address'] !== '' ? $c['payer']['address'] : '-' }}</dd></dl></div>
<div class="box"><h2>ผู้ถูกหักภาษี ณ ที่จ่าย</h2>
<dl><dt>ชื่อ</dt><dd>{{ $c['payee']['name'] }}</dd>
<dt>เลขประจำตัวประชาชน</dt><dd>{{ $c['payee']['national_id'] ?? '-' }}</dd></dl></div>
<table>
<thead><tr><th>ประเภทเงินได้พึงประเมิน</th><th class="n">จำนวนเงินที่จ่าย (บาท)</th><th class="n">ภาษีที่หักและนำส่ง (บาท)</th></tr></thead>
<tbody>
<tr><td>{{ $c['income_type'] }} เงินเดือน ค่าจ้าง เบี้ยเลี้ยง โบนัส ฯลฯ</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($c['gross']) }}</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($c['tax']) }}</td></tr>
<tr><td>รวมเงินที่จ่ายและภาษีที่หักนำส่ง</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($c['gross']) }}</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($c['tax']) }}</td></tr>
</tbody></table>
<table>
<tbody>
<tr><td>เงินสมทบประกันสังคม</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($c['sso']) }}</td></tr>
<tr><td>เงินสะสมกองทุนสำรองเลี้ยงชีพ</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($c['pf']) }}</td></tr>
</tbody></table>
<div class="sub" style="margin-top:8px">คำนวณจากสลิปเงินเดือนที่เผยแพร่แล้วในปี {{ $c['year_be'] }} จำนวน {{ $c['slips'] }} งวด</div>
<div class="sign"><div>ผู้จ่ายเงิน {{ $c['payer']['name'] }}</div></div>
<div class="foot">พิมพ์โดย {{ $viewer }} เมื่อ {{ $opened }} เอกสารนี้เป็นข้อมูลส่วนบุคคล ห้ามเผยแพร่</div>
</div>
</main>
<script>document.getElementById('print-btn').addEventListener('click',function(){window.print();});</script>
</body>
</html>
