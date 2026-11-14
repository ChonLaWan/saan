@props(['variant'=>'logo','tone'=>'auto','href'=>null])
@php
    $kind = $variant === 'mark' ? 'mark' : 'logo';
    $light = \App\Support\WebsiteView::asset(config('saan.assets.'.$kind.'_light'));
    $dark = \App\Support\WebsiteView::asset(config('saan.assets.'.$kind.'_dark'));
    $alt = config('saan.display');
    $size = $kind === 'mark' ? 'width="512" height="512"' : 'width="1110" height="432"';
@endphp
@if($href)<a href="{{ $href }}" {{ $attributes->merge(['class'=>'saan-brand saan-brand-'.$kind.' saan-tone-'.$tone]) }}>@else<span {{ $attributes->merge(['class'=>'saan-brand saan-brand-'.$kind.' saan-tone-'.$tone]) }}>@endif
@if($tone !== 'dark')<img class="saan-brand-light" src="{{ $light }}" alt="{{ $alt }}" {!! $size !!} decoding="async">@endif
@if($tone !== 'light')<img class="saan-brand-dark" src="{{ $dark }}" alt="{{ $tone === 'dark' ? $alt : '' }}" @if($tone !== 'dark') aria-hidden="true" @endif {!! $size !!} decoding="async">@endif
@if($href)</a>@else</span>@endif
