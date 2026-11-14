<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>สลิปเงินเดือน {{ $v['period']['title'] }}</title>
<style>
:root{--ink:#1a1f2b;--muted:#566070;--line:#c9ced8;--paper:#fff;--bg:#eef0f4;--accent:#0b5a8a}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 "Sarabun","Noto Sans Thai",system-ui,sans-serif}
.bar{display:flex;gap:8px;align-items:center;justify-content:center;padding:12px 16px}
.bar button{font:inherit;padding:8px 18px;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:6px;cursor:pointer}
.sheet{position:relative;overflow:hidden;width:100%;max-width:148mm;min-height:200mm;margin:0 auto 24px;padding:10mm 10mm;background:var(--paper);color:var(--ink);border:1px solid var(--line)}
.wm{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:0}
.wm span{position:absolute;left:-20%;width:140%;text-align:center;font-size:1.05rem;font-weight:700;color:#000;opacity:.07;transform:rotate(-24deg);white-space:nowrap}
.body{position:relative;z-index:1}
h1{margin:0;font-size:1.2rem}.sub{color:var(--muted);font-size:.9rem}
.head{border-bottom:2px solid var(--ink);padding-bottom:8px;margin-bottom:10px}
dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 12px;margin:0 0 10px}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}
h2{font-size:1rem;margin:12px 0 4px}
table{width:100%;border-collapse:collapse;margin-bottom:6px}th,td{padding:3px 4px;border-bottom:1px solid var(--line);text-align:left;font-weight:400}
td.n,th.n{text-align:right;white-space:nowrap}
tr.sum td{font-weight:700;border-bottom:2px solid var(--ink)}
.net{display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:1.1rem;border-top:2px solid var(--ink);padding-top:6px;margin-top:10px}
.foot{margin-top:16px;color:var(--muted);font-size:.85rem}
@page{size:A5 portrait;margin:0}
@media print{html,body{background:#fff}.bar{display:none}.sheet{width:148mm;max-width:none;margin:0;border:0;min-height:210mm}tr,.net{break-inside:avoid}}
@media (max-width:520px){.sheet{padding:14px}}
</style>
</head>
<body>
<div class="bar"><button type="button" id="print-btn">พิมพ์สลิป (บันทึกเป็น PDF ได้)</button></div>
<main class="sheet">
<div class="wm" aria-hidden="true">@for($i = 0; $i < 9; $i++)<span style="top:{{ $i * 12 - 4 }}%">{{ $viewer }} &middot; {{ $opened }}</span>@endfor</div>
<div class="body">
<div class="head"><h1>{{ $school }}</h1><div class="sub">สลิปเงินเดือน งวด {{ $v['period']['title'] }}</div></div>
<dl>
<dt>ชื่อ</dt><dd>{{ $name }}</dd>
<dt>รหัสผู้รับเงิน</dt><dd>{{ $code }}</dd>
<dt>วันที่จ่าย</dt><dd>{{ $v['period']['pay_date_th'] }}</dd>
@if(!empty($v['data']['bank']))<dt>บัญชีรับเงิน</dt><dd>{{ $v['data']['bank_name'] }} {{ $v['data']['bank'] }}</dd>@endif
</dl>
@php($d = $v['data'])
<h2>รายรับ</h2>
<table><tbody>
@foreach($d['e'] as $it)<tr><td>{{ $it[0] }}</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($it[1]) }}</td></tr>@endforeach
<tr class="sum"><td>รวมรายรับ</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($d['gross']) }}</td></tr>
</tbody></table>
<h2>รายการหัก</h2>
<table><tbody>
@foreach($d['d'] as $it)<tr><td>{{ $it[0] }}</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($it[1]) }}</td></tr>@endforeach
@if($d['tax'] > 0)<tr><td>ภาษีหัก ณ ที่จ่าย</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($d['tax']) }}</td></tr>@endif
@if($d['sso'] > 0)<tr><td>เงินสมทบประกันสังคม</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($d['sso']) }}</td></tr>@endif
@if($d['pf'] > 0)<tr><td>กองทุนสำรองเลี้ยงชีพ</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($d['pf']) }}</td></tr>@endif
<tr class="sum"><td>รวมรายการหัก</td><td class="n">{{ \Saan\Modules\Payslip\Support::baht($d['ded']) }}</td></tr>
</tbody></table>
<div class="net"><span>รับสุทธิ (บาท)</span><span>{{ \Saan\Modules\Payslip\Support::baht($d['net']) }}</span></div>
<div class="foot">เปิดโดย {{ $viewer }} เมื่อ {{ $opened }} เอกสารนี้เป็นข้อมูลส่วนบุคคล ห้ามเผยแพร่</div>
</div>
</main>
<script>document.getElementById('print-btn').addEventListener('click',function(){window.print();});</script>
</body>
</html>
