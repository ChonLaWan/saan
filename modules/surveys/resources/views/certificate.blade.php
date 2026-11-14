<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{{ $conf['title'] ?? 'เกียรติบัตร' }} {{ $row->certificate_no }}</title>
<style>
:root{--ink:#1d2b24;--gold:#8a6d1d;--paper:#fffef9}
*{box-sizing:border-box}body{margin:0;background:#e9e6da;color:var(--ink);font-family:"Sarabun","TH Sarabun New","Noto Sans Thai",Tahoma,sans-serif;line-height:1.5}
.cert-tools{max-width:297mm;margin:12px auto;padding:0 12px;display:flex;gap:8px;flex-wrap:wrap}.cert-tools button,.cert-tools a{min-height:44px;padding:0 18px;display:inline-flex;align-items:center;border:1px solid var(--ink);border-radius:8px;background:#fff;color:var(--ink);font:inherit;text-decoration:none;cursor:pointer}
.cert{width:297mm;max-width:100%;margin:0 auto 24px;aspect-ratio:297/210;background:var(--paper);border:10px double var(--gold);padding:12mm 16mm;display:flex;flex-direction:column;align-items:center;justify-content:space-between;text-align:center}
.cert img{max-height:26mm;max-width:60mm;object-fit:contain}.cert .school{font-size:1.5rem;font-weight:700;margin:4px 0 0}
.cert h1{font-size:2.6rem;margin:0;color:var(--gold);letter-spacing:.04em}.cert .name{font-size:2.2rem;font-weight:700;border-bottom:2px solid var(--gold);padding:0 24px 4px;margin:0 auto;display:inline-block;min-width:50%}
.cert .body{font-size:1.25rem;max-width:210mm;margin:0 auto}.cert .signs{display:flex;justify-content:space-around;gap:24px;width:100%;align-items:flex-end}.cert .sign{min-width:60mm}.cert .sign .line{border-top:1px solid var(--ink);padding-top:4px}
.cert .meta{font-size:.9rem;display:flex;justify-content:space-between;width:100%;gap:12px;align-items:flex-end}.cert .meta span{overflow-wrap:anywhere}
@media (max-width:760px){.cert{aspect-ratio:auto;padding:16px;gap:14px;border-width:6px}.cert h1{font-size:1.6rem}.cert .name{font-size:1.4rem;min-width:0}.cert .body{font-size:1rem}.cert .signs,.cert .meta{flex-direction:column;align-items:center}}
@page{size:A4 landscape;margin:0}
@media print{body{background:#fff}.cert-tools{display:none}.cert{width:297mm;height:210mm;max-width:none;margin:0;aspect-ratio:auto;border-width:10px;page-break-after:avoid;break-inside:avoid}.cert .name{font-size:2.2rem}.cert h1{font-size:2.6rem}.cert .body{font-size:1.25rem}.cert .signs,.cert .meta{flex-direction:row}}
</style></head><body>
<div class="cert-tools"><button type="button" id="cert-print">พิมพ์ / บันทึกเป็น PDF</button><a href="{{ $verifyUrl }}">หน้าตรวจสอบเกียรติบัตร</a></div>
<main class="cert">
    <header>@if($logo)<img src="{{ $logo }}" alt="ตราสถานศึกษา">@endif<p class="school">{{ $schoolName }}</p></header>
    <h1>{{ $conf['title'] ?? 'เกียรติบัตร' }}</h1>
    <div><p>ให้ไว้เพื่อแสดงว่า</p><p class="name">{{ $row->display_name }}</p></div>
    <p class="body">{!! nl2br(e($body)) !!}</p>
    <div class="signs">
        <div class="sign">@if(!empty($conf['signer_name']))<div class="line">({{ $conf['signer_name'] }})</div>@endif @if(!empty($conf['signer_title']))<div>{{ $conf['signer_title'] }}</div>@endif</div>
    </div>
    <footer class="meta"><span>ให้ไว้ ณ วันที่ {{ $date }}</span><span>เลขที่ {{ $row->certificate_no }} ตรวจสอบได้ที่ {{ url('/portal/certificates/verify') }}</span></footer>
</main>
<script>document.getElementById('cert-print').addEventListener('click',function(){window.print();});</script>
</body></html>
