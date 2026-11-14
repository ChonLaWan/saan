{{-- One document row. $d = row from Hubs::documents(), $preview = internal preview, $subs = [category id => label] of the sub-categories (optional). --}}
@php
    $external = $d->media_id === null;
    $href = \Saan\Modules\WebCms\Hubs::downloadUrl($d, $preview);
    $type = \Saan\Modules\WebCms\Hubs::typeLabel($d);
    $size = \Saan\Modules\WebCms\Hubs::size($d->bytes ?? null);
@endphp
<li class="hub-doc-item">
    <a class="hub-doc" href="{{ $href }}" rel="nofollow{{ $external ? ' noopener noreferrer' : '' }}" @if($external) target="_blank" @endif>
        <svg class="hub-doc-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6"/></svg>
        <span class="hub-doc-main">
            <span class="hub-doc-title">{{ $d->title }}</span>
            <small class="hub-doc-meta">@if(\Saan\Modules\WebCms\Hubs::periodLabel($d) !== ''){{ \Saan\Modules\WebCms\Hubs::periodLabel($d) }}@else{{ \App\Support\WebsiteView::date($d->doc_date) }}@endif · {{ $type }}@if($size) · {{ $size }}@endif @if(!empty($subs[$d->category_id])) · {{ $subs[$d->category_id] }}@endif</small>
        </span>
        @if($preview && !$d->is_published)<span class="hub-draft">ร่าง</span>@endif
        <span class="hub-doc-go">{{ $external ? 'เปิดลิงก์' : 'ดาวน์โหลด' }}</span>
    </a>
</li>
