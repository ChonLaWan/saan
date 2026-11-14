<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title')</title>
<style>
:root { --fs: {{ (int) ($d['template']['options']['font_size'] ?? 12) }}pt; }
* { box-sizing: border-box; }
body { margin: 0; background: #e9edf1; color: #111; font-family: "Sarabun", "TH Sarabun New", "Noto Sans Thai", Tahoma, sans-serif; font-size: var(--fs); line-height: 1.35; }
.toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 8px 12px; background: #1d3557; color: #fff; }
.toolbar a, .toolbar button { color: #fff; background: #2f5d8c; border: 0; border-radius: 6px; padding: 8px 14px; font: inherit; text-decoration: none; cursor: pointer; min-height: 40px; }
.toolbar span { flex: 1; min-width: 160px; }
.sheet { background: #fff; width: 100%; max-width: 297mm; margin: 12px auto; padding: 12mm; box-shadow: 0 1px 6px rgba(0,0,0,.25); overflow-x: auto; }
.sheet.portrait { max-width: 210mm; }
.head { text-align: center; margin-bottom: 8px; }
.head img { height: 18mm; width: auto; display: block; margin: 0 auto 4px; }
.head h1 { font-size: 1.35em; margin: 0; }
.head h2 { font-size: 1.1em; margin: 2px 0; font-weight: 600; }
.head p { margin: 2px 0; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #222; padding: 2px 5px; vertical-align: top; }
th { background: #f0f0f0; font-weight: 600; text-align: center; }
td.n, th.n { text-align: center; white-space: nowrap; }
td.num { text-align: right; white-space: nowrap; }
.meta { display: flex; flex-wrap: wrap; gap: 4px 24px; margin: 6px 0; }
.sign { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-around; margin-top: 18mm; break-inside: avoid; }
.sign div { flex: 1 1 45mm; min-width: 45mm; text-align: center; }
.sign .line { border-bottom: 1px dotted #222; height: 12mm; margin-bottom: 4px; }
.note { margin-top: 8px; font-size: .9em; }
.muted { color: #555; }
.special { font-weight: 600; }
.box { border: 1px solid #222; padding: 4px 8px; margin: 6px 0; min-height: 14mm; }
.attrs { display: grid; grid-template-columns: repeat(auto-fit, minmax(60mm, 1fr)); gap: 6px; }
.warn { border: 1px solid #b45309; background: #fffbeb; color: #92400e; padding: 4px 8px; margin: 6px 0; }
.break { break-after: page; page-break-after: always; }
@media (max-width: 700px) { .sheet { padding: 8px; margin: 6px 0; } }
@media print {
    @page { size: A4 {{ ($orientation ?? 'landscape') }}; margin: 10mm; }
    body { background: #fff; }
    .toolbar, .warn { display: none; }
    .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; width: auto; overflow: visible; }
    .sheet.last { break-after: auto; page-break-after: auto; }
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
}
</style>
</head>
<body data-template-year="{{ $d['template']['year_be'] ?? 'default' }}">
<div class="toolbar"><span>@yield('title')</span>@yield('toolbar')<button type="button" data-print>พิมพ์ / บันทึกเป็น PDF</button><a href="{{ url('/work#reports') }}">กลับ</a></div>
@yield('content')
<script src="{{ asset('assets/modules/reports-pp/print.js') }}" defer></script>
</body>
</html>
