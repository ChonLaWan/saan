<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>เกียรติบัตรกิจกรรมชุมนุม</title>
<style>
:root{--ink:#1d2b24;--gold:#8a6d1d;--paper:#fffef9}
*{box-sizing:border-box}body{margin:0;background:#e9e6da;color:var(--ink);font-family:"Sarabun","TH Sarabun New","Noto Sans Thai",Tahoma,sans-serif;line-height:1.5}
.cert-tools{max-width:297mm;margin:12px auto;padding:0 12px;display:flex;gap:8px;flex-wrap:wrap}.cert-tools button{min-height:44px;padding:0 18px;border:1px solid var(--ink);border-radius:8px;background:#fff;color:var(--ink);font:inherit;cursor:pointer}
.cert{width:297mm;max-width:100%;margin:0 auto 24px;aspect-ratio:297/210;background:var(--paper);border:10px double var(--gold);padding:12mm 16mm;display:flex;flex-direction:column;align-items:center;justify-content:space-between;text-align:center}
.cert .school{font-size:1.5rem;font-weight:700;margin:4px 0 0}.cert h1{font-size:2.6rem;margin:0;color:var(--gold);letter-spacing:.04em}
.cert .name{font-size:2.2rem;font-weight:700;border-bottom:2px solid var(--gold);padding:0 24px 4px;margin:0 auto;display:inline-block;min-width:50%}
.cert .body{font-size:1.25rem;max-width:210mm;margin:0 auto}.cert .sign{min-width:60mm}.cert .sign .line{border-top:1px solid var(--ink);padding-top:4px}
.cert .meta{font-size:.9rem;display:flex;justify-content:space-between;width:100%;gap:12px;align-items:flex-end}
@media (max-width:760px){.cert{aspect-ratio:auto;padding:16px;gap:14px;border-width:6px}.cert h1{font-size:1.6rem}.cert .name{font-size:1.4rem;min-width:0}.cert .body{font-size:1rem}.cert .meta{flex-direction:column;align-items:center}}
@page{size:A4 landscape;margin:0}
@media print{body{background:#fff}.cert-tools{display:none}.cert{width:297mm;height:210mm;max-width:none;margin:0;aspect-ratio:auto;border-width:10px;break-after:page;break-inside:avoid}.cert:last-of-type{break-after:auto}.cert .meta{flex-direction:row}}
</style></head><body>
<div class="cert-tools"><button type="button" id="cert-print">พิมพ์ / บันทึกเป็น PDF ({{ count($rows) }} ใบ)</button></div>
@foreach($rows as $row)
@php($s = $row['snapshot'])
<main class="cert">
    <header><p class="school">{{ $s['school_name'] ?? '' }}</p></header>
    <h1>เกียรติบัตร</h1>
    <div><p>ให้ไว้เพื่อแสดงว่า</p><p class="name">{{ $s['student_name'] ?? '' }}</p></div>
    <p class="body">ได้เข้าร่วมกิจกรรมชุมนุม {{ $s['club_name'] ?? '' }} {{ $s['term_label'] ?? '' }} และผ่านเกณฑ์การประเมินกิจกรรมพัฒนาผู้เรียน</p>
    <div class="sign">@if(!empty($s['signer_name']))<div class="line">({{ $s['signer_name'] }})</div>@endif @if(!empty($s['signer_title']))<div>{{ $s['signer_title'] }}</div>@endif</div>
    <footer class="meta"><span>ให้ไว้ ณ วันที่ {{ $s['date'] ?? '' }}</span><span>เลขที่ {{ $row['number'] }}</span></footer>
</main>
@endforeach
<script>document.getElementById('cert-print').addEventListener('click',function(){window.print();});</script>
</body></html>
