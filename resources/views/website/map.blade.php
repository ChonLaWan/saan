@php($mapView=\App\Domain\Cms\SiteExtras::mapView($site['extras']??[]))
@if($mapView)
@php(app(\App\Support\PublicCsp::class)->allowFrame($mapView['frame_source']))
{{-- F5a map block: a static placeholder with no external request. The provider iframe is inserted by site-extras.js only after "โหลดแผนที่" is pressed; the link works without JS. --}}
<section class="school-map" id="school-map" aria-labelledby="school-map-title" data-map-block data-map-embed="{{ $mapView['embed'] }}" data-map-provider="{{ $mapView['provider_name'] }}">
<div class="section-heading"><div><p class="site-eyebrow">แผนที่</p><h2 id="school-map-title">{{ $mapView['title'] }}</h2></div></div>
<div class="map-stage" data-map-stage>
<div class="map-placeholder"><svg class="map-art" viewBox="0 0 320 160" role="img" aria-label="ภาพประกอบแผนที่" focusable="false"><rect x="0" y="0" width="320" height="160" rx="10" class="map-art-bg"/><path class="map-art-road" d="M0 112 C70 92 110 130 170 100 S270 60 320 76M92 0 L112 160M214 0 L196 160"/><path class="map-art-pin" d="M160 28c-17 0-30 13-30 29 0 22 30 55 30 55s30-33 30-55c0-16-13-29-30-29z"/><circle class="map-art-dot" cx="160" cy="57" r="10"/></svg>
<p class="map-address">{{ $theme['address']!==''&&$theme['address']!==null?$theme['address']:($mapView['query']!==''?$mapView['query']:$theme['school_name']) }}</p>
<div class="map-actions"><a class="site-button" href="{{ $mapView['link'] }}" target="_blank" rel="noopener noreferrer" aria-label="เปิดในแผนที่ {{ $mapView['provider_name'] }} (เปิดในแท็บใหม่)">เปิดในแผนที่ <x-ui-icon name="arrow-up-right"/></a><button class="map-load" type="button" data-map-load hidden>โหลดแผนที่</button></div>
<p class="map-note">แผนที่จะยังไม่เชื่อมต่อ {{ $mapView['provider_name'] }} จนกว่าคุณจะกด "โหลดแผนที่"</p>
</div></div></section>
@endif