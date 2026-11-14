@extends('website.layout')
@section('content')
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/hub.css') }}">
@php
    $base = url('/portal/groups/'.$hub->slug);
    $months = \App\Support\Fiscal::MONTHS_TH;
@endphp
<section class="collection-heading hub-heading">
    <div class="collection-heading-copy">
        <p class="site-eyebrow"><a href="{{ $base }}">{{ $hub->title }}</a></p>
        <h1>{{ $category->label }}</h1>
        <p>จัดกลุ่มตามปีงบประมาณ (1 ตุลาคม ถึง 30 กันยายน) เดือนที่ยังไม่เผยแพร่จะแสดงว่า "ยังไม่เผยแพร่"</p>
    </div>
</section>
<div class="hub collection-content">
    <form class="public-filters hub-filters" method="get" action="{{ $base.'/docs/'.$category->id }}">
        <label>ปีงบประมาณ (พ.ศ.)
            <select name="fy">@foreach($m['fys'] as $y)<option value="{{ $y }}" @selected($m['fy'] === $y)>{{ $y }}</option>@endforeach</select>
        </label>
        <label>เดือน
            <select name="month"><option value="">ทุกเดือน</option>@foreach($months as $n => $label)<option value="{{ $n }}" @selected($m['month'] === $n)>{{ $label }}</option>@endforeach</select>
        </label>
        <button class="site-button">แสดง</button>
    </form>
    <p class="collection-count">ปีงบประมาณ {{ $m['fy'] }} เผยแพร่แล้ว {{ $m['published'] }} จาก {{ count($m['rows']) }} เดือน</p>
    <ul class="hub-docs hub-docs-full">
        @foreach($m['rows'] as $row)
            @if($row['doc'])
                @php($d = $row['doc'])
                <li class="hub-doc-item">
                    <a class="hub-doc" href="{{ \Saan\Modules\WebCms\Hubs::downloadUrl($d, false) }}" rel="nofollow">
                        <svg class="hub-doc-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6"/></svg>
                        <span class="hub-doc-main">
                            <span class="hub-doc-title">{{ $row['label'] }}</span>
                            <small class="hub-doc-meta">{{ $d->title }} · {{ \Saan\Modules\WebCms\Hubs::typeLabel($d) }}@if(\Saan\Modules\WebCms\Hubs::size($d->bytes ?? null)) · {{ \Saan\Modules\WebCms\Hubs::size($d->bytes ?? null) }}@endif</small>
                        </span>
                        <span class="hub-doc-go">ดาวน์โหลด</span>
                    </a>
                </li>
            @else
                <li class="hub-doc-item">
                    <div class="hub-doc-missing">
                        <span class="hub-doc-title">{{ $row['label'] }}</span>
                        <small class="hub-doc-meta">ยังไม่เผยแพร่</small>
                    </div>
                </li>
            @endif
        @endforeach
    </ul>
    <p><a class="hub-all" href="{{ $base }}"><span aria-hidden="true"><x-ui-icon name="arrow-left"/></span> กลับหน้า{{ $hub->title }}</a></p>
</div>
@endsection
