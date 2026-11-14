@extends('website.layout')
@section('content')
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/hub.css') }}">
@php
    $preview = (bool) ($hubPreview ?? false);
    $kindLabel = \Saan\Modules\WebCms\Hubs::KINDS[$hub->kind] ?? 'กลุ่ม';
    $base = url('/portal/groups/'.$hub->slug);
@endphp
<section class="collection-heading hub-heading">
    <div class="collection-heading-copy">
        <p class="site-eyebrow">{{ $kindLabel }}</p>
        <h1>{{ $hub->title }}</h1>
        @if($hub->description)<p>{!! nl2br(e($hub->description)) !!}</p>@endif
    </div>
</section>
<div class="hub collection-content">
    @if(count($committee ?? []))
    <section class="hub-section hub-committee" aria-labelledby="hub-committee-h">
        <h2 id="hub-committee-h">คณะกรรมการ</h2>
        <ul class="hub-people">
            @foreach($committee as $p)
            <li class="hub-person"><strong>{{ $p['name'] }}</strong>@if($p['role'] !== '')<span>{{ $p['role'] }}</span>@endif</li>
            @endforeach
        </ul>
    </section>
    @endif
    @if(trim((string) ($hub->contact ?? '')) !== '')
    <section class="hub-section hub-contact" aria-labelledby="hub-contact-h">
        <h2 id="hub-contact-h">ติดต่อ</h2>
        <p>{!! nl2br(e($hub->contact)) !!}</p>
    </section>
    @endif
    @if(count($heads) || count($members))
    <section class="hub-section hub-staff" aria-labelledby="hub-staff-h">
        <h2 id="hub-staff-h">บุคลากร{{ $hub->group_name ? 'ใน'.$hub->group_name : '' }}</h2>
        @if(count($heads))
        <ul class="hub-people hub-heads">
            @foreach($heads as $p)
            <li class="hub-person">
                @if($p['photo_url'])<img src="{{ $p['photo_url'] }}" alt="" width="96" height="96" loading="lazy" decoding="async">@endif
                <strong>{{ $p['name'] }}</strong>
                @if($p['position'])<span>{{ $p['position'] }}</span>@endif
            </li>
            @endforeach
        </ul>
        @endif
        @if(count($members))
        <ul class="hub-people">
            @foreach($members as $p)
            <li class="hub-person">
                @if($p['photo_url'])<img src="{{ $p['photo_url'] }}" alt="" width="96" height="96" loading="lazy" decoding="async">@endif
                <strong>{{ $p['name'] }}</strong>
                @if($p['position'])<span>{{ $p['position'] }}</span>@endif
            </li>
            @endforeach
        </ul>
        @endif
    </section>
    @endif

    <section class="hub-section" aria-labelledby="hub-docs-h">
        <h2 id="hub-docs-h">เอกสารและข้อมูล</h2>
        @if(!count($boxes))<p class="hub-empty">ยังไม่มีหมวดเอกสาร</p>@endif
        <div class="hub-boxes">
            @foreach($boxes as $b)
            @php($cat = $b['category'])
            @php($subs = collect($cat->children ?? [])->pluck('label', 'id')->all())
            <section class="hub-box" aria-labelledby="hub-box-{{ $cat->id }}">
                <header class="hub-box-head">
                    <h3 id="hub-box-{{ $cat->id }}">{{ $cat->label }}@if($preview && $cat->is_hidden) <span class="hub-draft">ซ่อน</span>@endif</h3>
                    <span class="hub-count">{{ $b['total'] }} รายการ</span>
                </header>
                @if(count($b['items']))
                <ul class="hub-docs">
                    @foreach($b['items'] as $d)@include('web-cms::hub._doc', ['d' => $d, 'preview' => $preview, 'subs' => $subs])@endforeach
                </ul>
                @else
                <p class="hub-empty">ยังไม่มีเอกสารในหมวดนี้</p>
                @endif
                @if(!$preview || $hub->status === 'published')
                <a class="hub-all" href="{{ $base.'/docs/'.$cat->id }}">ดูทั้งหมด <span aria-hidden="true"><x-ui-icon name="arrow-right"/></span></a>
                @endif
            </section>
            @endforeach
        </div>
    </section>

    @if($hasNews)
    <section class="hub-section hub-news" aria-labelledby="hub-news-h">
        <h2 id="hub-news-h">ข่าว{{ $hub->title }}</h2>
        @if(count($news))
        <ul class="hub-news-list">
            @foreach($news as $post)
            <li><a href="{{ \App\Support\WebsiteView::url($post) }}"><strong>{{ $post->title }}</strong><small>{{ \App\Support\WebsiteView::date($post->published_at) }}</small></a></li>
            @endforeach
        </ul>
        @else
        <p class="hub-empty">ยังไม่มีข่าวของกลุ่มนี้</p>
        @endif
        @if(!$preview || $hub->status === 'published')<a class="hub-all" href="{{ $base.'/news' }}">ดูข่าวทั้งหมด <span aria-hidden="true"><x-ui-icon name="arrow-right"/></span></a>@endif
    </section>
    @endif
</div>
@endsection
