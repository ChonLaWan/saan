@extends('website.layout')
@section('content')
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/hub.css') }}">
@php
    $base = url('/portal/groups/'.$hub->slug);
    $subs = collect($category->children ?? [])->pluck('label', 'id')->all();
    $preview = false;
@endphp
<section class="collection-heading hub-heading">
    <div class="collection-heading-copy">
        <p class="site-eyebrow"><a href="{{ $base }}">{{ $hub->title }}</a></p>
        <h1>{{ $category->label }}</h1>
    </div>
</section>
<div class="hub collection-content">
    <form class="public-filters hub-filters" method="get" action="{{ $base.'/docs/'.$category->id }}">
        <label>ค้นหาชื่อเอกสาร<input type="search" name="q" maxlength="100" value="{{ $f['q'] ?? '' }}" placeholder="พิมพ์คำค้นหา"></label>
        <label>ปี พ.ศ.
            <select name="year"><option value="">ทุกปี</option>@foreach($years as $y)<option value="{{ $y }}" @selected((int) ($f['year'] ?? 0) === $y)>{{ $y }}</option>@endforeach</select>
        </label>
        @if(count($subs))
        <label>หมวดย่อย
            <select name="sub"><option value="">ทุกหมวดย่อย</option>@foreach($subs as $id => $label)<option value="{{ $id }}" @selected((int) ($f['sub'] ?? 0) === (int) $id)>{{ $label }}</option>@endforeach</select>
        </label>
        @endif
        <button class="site-button">ค้นหา</button>
    </form>
    <p class="collection-count">พบ {{ $docs->total() }} รายการ</p>
    @if($docs->count())
    <ul class="hub-docs hub-docs-full">
        @foreach($docs as $d)@include('web-cms::hub._doc', ['d' => $d, 'preview' => false, 'subs' => $subs])@endforeach
    </ul>
    @else
    <div class="site-empty">ไม่พบเอกสารตามเงื่อนไข ลองเปลี่ยนคำค้นหาหรือปี</div>
    @endif
    @if($docs->hasPages())
    <nav class="site-pagination" aria-label="หน้ารายการ">
        @if($docs->previousPageUrl())<a href="{{ $docs->previousPageUrl() }}">ก่อนหน้า</a>@endif
        <span>หน้า {{ $docs->currentPage() }} / {{ $docs->lastPage() }}</span>
        @if($docs->nextPageUrl())<a href="{{ $docs->nextPageUrl() }}">ถัดไป</a>@endif
    </nav>
    @endif
    <p><a class="hub-all" href="{{ $base }}"><span aria-hidden="true"><x-ui-icon name="arrow-left"/></span> กลับหน้า{{ $hub->title }}</a></p>
</div>
@endsection
