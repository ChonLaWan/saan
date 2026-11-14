@extends('website.layout')
@section('content')
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/hub.css') }}">
@php($base = url('/portal/groups/'.$hub->slug))
<section class="collection-heading hub-heading">
    <div class="collection-heading-copy">
        <p class="site-eyebrow"><a href="{{ $base }}">{{ $hub->title }}</a></p>
        <h1>ข่าว{{ $hub->title }}</h1>
    </div>
</section>
<div class="hub collection-content">
    @if($posts->count())
    <ul class="hub-news-list">
        @foreach($posts as $post)
        <li><a href="{{ \App\Support\WebsiteView::url($post) }}"><strong>{{ $post->title }}</strong><small>{{ \App\Support\WebsiteView::date($post->published_at) }}</small>@if($post->excerpt)<span>{{ $post->excerpt }}</span>@endif</a></li>
        @endforeach
    </ul>
    @else
    <div class="site-empty">ยังไม่มีข่าวของกลุ่มนี้</div>
    @endif
    @if($posts->hasPages())
    <nav class="site-pagination" aria-label="หน้ารายการ">
        @if($posts->previousPageUrl())<a href="{{ $posts->previousPageUrl() }}">ก่อนหน้า</a>@endif
        <span>หน้า {{ $posts->currentPage() }} / {{ $posts->lastPage() }}</span>
        @if($posts->nextPageUrl())<a href="{{ $posts->nextPageUrl() }}">ถัดไป</a>@endif
    </nav>
    @endif
    <p><a class="hub-all" href="{{ $base }}"><span aria-hidden="true"><x-ui-icon name="arrow-left"/></span> กลับหน้า{{ $hub->title }}</a></p>
</div>
@endsection
