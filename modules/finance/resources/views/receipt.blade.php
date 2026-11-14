<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>ใบเสร็จรับเงิน {{ $p->receipt_no }}</title>
<style>
:root{--ink:#1a1f2b;--muted:#566070;--line:#c9ced8;--paper:#fff;--bg:#eef0f4;--accent:#0b5a8a;--danger:#a11d1d}
@media (prefers-color-scheme:dark){:root:not([data-print]){--bg:#14171d}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 "Sarabun","Noto Sans Thai",system-ui,sans-serif}
.bar{display:flex;gap:8px;align-items:center;justify-content:center;padding:12px 16px}
.bar button{font:inherit;padding:8px 18px;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:6px;cursor:pointer}
.sheet{position:relative;width:100%;max-width:210mm;min-height:148mm;margin:0 auto 24px;padding:12mm 14mm;background:var(--paper);color:var(--ink);border:1px solid var(--line)}
h1{margin:0;font-size:1.25rem}.sub{color:var(--muted);font-size:.9rem}
.head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;border-bottom:2px solid var(--ink);padding-bottom:8px;margin-bottom:10px}
.meta{text-align:right}.meta strong{font-size:1.05rem}
dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 12px;margin:0 0 10px}dt{color:var(--muted)}dd{margin:0}
table{width:100%;border-collapse:collapse;margin-bottom:8px}th,td{padding:4px 6px;border-bottom:1px solid var(--line);text-align:left}
th.n,td.n{text-align:right;white-space:nowrap}
.total{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-weight:700;border-top:2px solid var(--ink);padding-top:6px}
.text{margin:6px 0 14px;color:var(--ink)}
.sign{display:flex;justify-content:flex-end;margin-top:22px}.sign div{width:60mm;text-align:center;border-top:1px dotted var(--ink);padding-top:4px;font-size:.9rem}
.void{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;font-size:4rem;font-weight:700;color:var(--danger);opacity:.28;transform:rotate(-18deg)}
@page{size:A4 portrait;margin:0}
@media print{
html,body{background:#fff}
.bar{display:none}
.sheet{width:210mm;height:auto;min-height:148mm;max-width:none;margin:0;border:0;padding:10mm 14mm;overflow:visible}
.total,.text,.sign,tr{break-inside:avoid}
}
@media (max-width:520px){.sheet{padding:14px}.meta{text-align:left}}
</style>
</head>
<body>
<div class="bar"><button type="button" id="print-btn">พิมพ์ใบเสร็จ</button></div>
<main class="sheet">
@if($p->status === 'void')<div class="void">ยกเลิก</div>@endif
<div class="head">
<div><h1>{{ $school }}</h1>@if($address)<div class="sub">{{ $address }}</div>@endif<div class="sub">ใบเสร็จรับเงินค่าธรรมเนียมนักเรียน</div></div>
<div class="meta"><div>เลขที่ <strong>{{ $p->receipt_no }}</strong></div><div>วันที่ {{ \Saan\Modules\Finance\Support::thaiDate($p->paid_on) }}</div></div>
</div>
<dl>
<dt>ได้รับเงินจาก</dt><dd>{{ $s->name }} ({{ $s->code }}){{ $s->classroom ? ' ชั้น '.$s->classroom : '' }}</dd>
<dt>อ้างอิงใบแจ้งหนี้</dt><dd>{{ $inv->invoice_no }}</dd>
<dt>วิธีชำระ</dt><dd>{{ \Saan\Modules\Finance\Billing::METHOD_LABELS[$p->method] ?? $p->method }}{{ $p->reference ? ' / อ้างอิง '.$p->reference : '' }}</dd>
</dl>
<table>
<thead><tr><th>รายการ</th><th class="n">จำนวนเงิน (บาท)</th></tr></thead>
<tbody>
@foreach($lines as $l)
<tr><td>{{ $l->label }}@if($l->discount_satang > 0) <span class="sub">(ส่วนลด {{ \Saan\Modules\Finance\Baht::format($l->discount_satang) }})</span>@endif</td><td class="n">{{ \Saan\Modules\Finance\Baht::format($l->amount_satang - $l->discount_satang) }}</td></tr>
@endforeach
</tbody>
</table>
<div class="sub">ยอดใบแจ้งหนี้ {{ \Saan\Modules\Finance\Baht::format($inv->total_satang) }} บาท</div>
<div class="total"><span>รับชำระครั้งนี้</span><span>{{ \Saan\Modules\Finance\Baht::format($p->amount_satang) }} บาท</span></div>
<div class="text">({{ \Saan\Modules\Finance\Baht::text($p->amount_satang) }})</div>
@if($p->status === 'void')<div class="sub">ยกเลิกแล้ว: {{ $p->void_reason }}</div>@endif
<div class="sign"><div>ผู้รับเงิน {{ $p->received_by_name }}</div></div>
</main>
<script>document.getElementById('print-btn').addEventListener('click',function(){window.print();});</script>
</body>
</html>
