@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">การเปิดเผยข้อมูลสาธารณะ</p><h1>{{ $ita['year']['title'] ?? 'การเปิดเผยข้อมูลสาธารณะ (ITA)' }}</h1>
<p>ข้อมูลตามตัวชี้วัดการประเมินคุณธรรมและความโปร่งใสในการดำเนินงานของหน่วยงานภาครัฐ</p></section>
<section class="ita-page">
    @if($years->count() > 1)
        <nav class="ita-years" aria-label="ปีงบประมาณ">@foreach($years as $y)<a href="{{ url('/portal/ita/'.$y->fiscal_year) }}" @if(($ita['year']['fiscal_year'] ?? null) == $y->fiscal_year) aria-current="page" @endif>ปีงบประมาณ {{ $y->fiscal_year }}</a>@endforeach</nav>
    @endif
    @if(!$ita)
        <p class="ita-empty">ยังไม่มีข้อมูลที่เผยแพร่</p>
    @else
        <div class="ita-tools"><label>ค้นหารายการ<input type="search" data-ita-search placeholder="พิมพ์ชื่อรายการหรือรหัสตัวชี้วัด"></label><button type="button" data-ita-expand="1">ขยายทั้งหมด</button><button type="button" data-ita-expand="0">ย่อทั้งหมด</button></div>
        <p class="ita-count" data-ita-count role="status"></p>
        @foreach($ita['topics'] as $topic)
            <details class="ita-topic" open data-ita-topic>
                <summary>@if($topic['code'] !== '')<span class="ita-code">{{ $topic['code'] }}</span>@endif {{ $topic['title'] }} <small>{{ count($topic['items']) }} รายการ</small></summary>
                @if(!$topic['items'])<p class="ita-empty">ยังไม่มีรายการข้อมูล</p>@endif
                <ol>@foreach($topic['items'] as $item)
                    <li data-ita-item>
                        @php($res = $item['resolved'] ?? null)
                        @php($auto = ($res['mode'] ?? '') === 'auto')
                        @php($href = $item['media_id'] ? url('/media/'.$item['media_id']) : ($item['url'] ?: ($auto && $res['ok'] ? url($res['path']) : null)))
                        @if($href)<a href="{{ $href }}" @if(str_starts_with((string) $href, 'https://')) target="_blank" rel="noopener noreferrer" @endif>{{ $item['title'] }}</a>@else<span>{{ $item['title'] }}</span>@endif
                        @if($auto && $res['ok'])<small class="ita-evidence">{{ $res['evidence'] }}</small>@foreach($res['extra'] as $x)<a class="ita-extra" href="{{ url($x['path']) }}">{{ $x['title'] }}</a>@endforeach
                        @elseif($auto)<small class="ita-missing" data-ita-missing>{{ !empty($res['yearly']) ? 'ยังไม่มีข้อมูลในปีงบประมาณนี้' : 'ยังไม่มีข้อมูล' }}</small>@endif
                        @if($item['media'])<small>{{ strtoupper(pathinfo((string) $item['media']['original_name'], PATHINFO_EXTENSION) ?: 'ไฟล์') }} · {{ number_format($item['media']['bytes'] / 1024) }} KB</small>@endif
                        @if($item['note'] !== '')<p>{{ $item['note'] }}</p>@endif
                    </li>
                @endforeach</ol>
                @if(!empty($topic['auto_links']))<ul class="ita-auto" aria-label="ลิงก์ที่เกี่ยวข้อง">@foreach($topic['auto_links'] as $link)<li data-ita-item><a href="{{ $link['url'] }}">{{ $link['title'] }}</a></li>@endforeach</ul>@endif
            </details>
        @endforeach
        <p class="ita-updated">ปรับปรุงล่าสุด {{ \Carbon\CarbonImmutable::parse($ita['year']['updated_at'])->locale('th')->translatedFormat('j M') }} {{ \Carbon\CarbonImmutable::parse($ita['year']['updated_at'])->year + 543 }}</p>
    @endif
</section>
<style>
.ita-page{max-width:980px;margin:0 auto 48px;padding:0 16px}.ita-years{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}.ita-years a{padding:6px 12px;border:1px solid var(--line,#bac8b4);border-radius:999px;text-decoration:none;color:inherit}.ita-years a[aria-current=page]{background:var(--ink,#163d30);color:var(--surface,#fffef7)}
.ita-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-bottom:8px}.ita-tools label{flex:1 1 260px;display:grid;gap:4px}.ita-tools input{min-height:44px;padding:8px 12px;font-size:16px}.ita-tools button{min-height:44px;padding:0 14px}
.ita-topic{border:1px solid var(--line,#bac8b4);border-radius:12px;margin:10px 0;background:var(--surface-raised,#fffdf4)}.ita-topic>summary{cursor:pointer;padding:12px 16px;font-weight:600;display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}.ita-topic small{font-weight:400;opacity:.8}
.ita-evidence,.ita-missing{display:block;opacity:.8}.ita-missing{font-style:italic}.ita-extra{display:inline-block;margin:2px 12px 2px 0;min-height:24px}
.ita-auto{margin:0 16px 14px 36px;padding:0;list-style:square}.ita-auto li{margin:6px 0}
.ita-code{display:inline-block;min-width:3em;font-variant-numeric:tabular-nums}.ita-topic ol{margin:0 16px 14px 36px;padding:0}.ita-topic li{margin:6px 0}.ita-topic li p{margin:2px 0;opacity:.85}.ita-empty{opacity:.75;padding:0 16px 12px}.ita-updated{opacity:.75;font-size:.9rem}
</style>
<script>
(()=>{const search=document.querySelector('[data-ita-search]');if(!search)return;const topics=[...document.querySelectorAll('[data-ita-topic]')],count=document.querySelector('[data-ita-count]');
const apply=()=>{const q=search.value.trim().toLowerCase();let shown=0;topics.forEach(t=>{let any=false;t.querySelectorAll('[data-ita-item]').forEach(i=>{const ok=!q||i.textContent.toLowerCase().includes(q)||t.querySelector('summary').textContent.toLowerCase().includes(q);i.hidden=!ok;if(ok){any=true;shown++;}});t.hidden=!!q&&!any;if(q&&any)t.open=true;});count.textContent=q?('พบ '+shown+' รายการ'):'';};
search.addEventListener('input',apply);document.querySelectorAll('[data-ita-expand]').forEach(b=>b.addEventListener('click',()=>topics.forEach(t=>t.open=b.dataset.itaExpand==='1')));})();
</script>
@endsection
