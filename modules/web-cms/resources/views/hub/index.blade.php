@extends('website.layout')
@section('content')
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/hub.css') }}">
<section class="collection-heading hub-heading">
    <div class="collection-heading-copy">
        <p class="site-eyebrow">ข้อมูลจากโรงเรียน</p>
        <h1>{{ $pageTitle }}</h1>
    </div>
</section>
<div class="hub collection-content">
    @forelse($hubs->groupBy('kind') as $kind => $rows)
    <section class="hub-section">
        <h2>{{ $kinds[$kind] ?? 'กลุ่ม' }}</h2>
        <ul class="hub-index">
            @foreach($rows as $h)
            <li><a href="{{ url('/portal/groups/'.$h->slug) }}"><strong>{{ $h->title }}</strong>@if($h->description)<span>{{ \Illuminate\Support\Str::limit(strip_tags($h->description), 120) }}</span>@endif</a></li>
            @endforeach
        </ul>
    </section>
    @empty
    <div class="site-empty">ยังไม่มีหน้ากลุ่มงานหรือกลุ่มสาระการเรียนรู้</div>
    @endforelse
</div>
@endsection
