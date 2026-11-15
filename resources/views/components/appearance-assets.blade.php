@props(['mode'=>'light','website'=>false])
<script src="{{ \App\Support\WebsiteView::asset('assets/saan-core.js') }}"></script><meta name="saan-today" content="{{ now('Asia/Bangkok')->toDateString() }}">
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/ui-icons.css') }}"><script src="{{ \App\Support\WebsiteView::asset('assets/ui-icons.js') }}"></script>
<meta name="school-base" content="{{ url('/') }}"><meta name="school-color-mode" content="{{ $mode }}">
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/appearance.css') }}"><script src="{{ \App\Support\WebsiteView::asset('assets/appearance.js') }}"></script>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/thai-compact.css') }}">
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/mock-media.css') }}">
@if(!$website)<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/accessibility.css') }}">@endif
@if(!$website)<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/card-layout.css') }}">@endif
@if(!$website)<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/saan-brand.css') }}"><link rel="icon" type="image/svg+xml" href="{{ \App\Support\WebsiteView::asset(config('saan.assets.favicon_svg')) }}"><link rel="icon" type="image/png" sizes="32x32" href="{{ \App\Support\WebsiteView::asset(config('saan.assets.favicon_png')) }}"><link rel="apple-touch-icon" href="{{ \App\Support\WebsiteView::asset(config('saan.assets.apple_touch')) }}">@endif
@if(request()->is('cms'))<script defer src="{{ \App\Support\WebsiteView::asset('assets/compact-editors.js') }}"></script>@endif
@if(!$website)<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/saan-glass.css') }}">@endif
