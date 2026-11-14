@php
    use App\Support\WebsiteView as W;
    $articleMeta=$article?W::meta($article):[];
    $title=$articleMeta['seo_title']??$pageTitle;
    $description=$articleMeta['seo_description']??($article?->excerpt?:$site['default_seo_description']);
    $navigation=$site['navigation']?:$theme['menus'];
    // a menu item that points to a group/association/report page (/portal/groups/{slug}) is shown only while that page is published
    if(!$preview&&class_exists(\Saan\Modules\WebCms\Hubs::class)){
        $liveHubs=null;
        $keepHub=function(array $items)use(&$keepHub,&$liveHubs):array{
            $out=[];
            foreach($items as $item){
                if(is_array($item)&&preg_match('~^(?:https?://[^/]+)?/portal/groups/([^/?#]+)~',(string)($item['url']??''),$m)){
                    $liveHubs??=\Saan\Modules\WebCms\Hubs::listPublished()->pluck('slug')->all();
                    if(!in_array(rawurldecode($m[1]),$liveHubs,true))continue;
                }
                if(is_array($item)&&is_array($item['children']??null))$item['children']=$keepHub($item['children']);
                $out[]=$item;
            }
            return $out;
        };
        $navigation=$keepHub((array)$navigation);
        $site['footer_navigation']=$keepHub((array)($site['footer_navigation']??[]));
    }
@endphp
<!doctype html><html lang="th" data-appearance="{{ str_starts_with($presentationPreviewMode??$site['color_mode'],'dark')?'dark':'light' }}" data-contrast="{{ str_ends_with($presentationPreviewMode??$site['color_mode'],'-high')?'high':'normal' }}"@if(\App\Domain\Cms\Observance::grayscaleShown(\App\Domain\Cms\Observance::normalize($site['observance']),(bool)($observanceForce??false))) data-school-mourning="true"@endif><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="{{ $description }}"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title?$title.' — ':'' }}{{ $theme['school_name'] }}</title><meta property="og:title" content="{{ $title?:$theme['school_name'] }}"><meta property="og:description" content="{{ $description }}"><meta property="og:type" content="{{ $article?'article':'website' }}"><meta property="og:url" content="{{ request()->url() }}">
@if($ogImage=$article?->cover_media_id?:$theme['hero_media_id'])<meta property="og:image" content="{{ url('/media/'.$ogImage) }}">@endif
@if($site['favicon_media_id'])<link rel="icon" href="{{ url('/media/'.$site['favicon_media_id']) }}">@else<link rel="icon" type="image/svg+xml" href="{{ \App\Support\WebsiteView::asset($site['branding']['use_mark_favicon']?'assets/brand/'.\App\Support\PresentationCss::mark($site).'.svg':'assets/school-mark.svg') }}">@endif
@if($preview||config('school.demo'))<meta name="robots" content="noindex,nofollow">@endif
<link rel="canonical" href="{{ request()->url() }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/website.css') }}"><script defer src="{{ \App\Support\WebsiteView::asset('assets/website.js') }}"></script>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/campus-hero.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/school-sections.css') }}"><x-appearance-assets :mode="$site['color_mode']" :website="true"/><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/observance.css') }}">@if($themeStyles=\App\Domain\Cms\ThemeRegistry::asset($theme['preset'],'stylesheet'))<link rel="stylesheet" data-theme-stylesheet href="{{ $themeStyles }}">@endif<script defer src="{{ \App\Support\WebsiteView::asset('assets/campus-hero.js') }}"></script><script defer src="{{ \App\Support\WebsiteView::asset('assets/observance.js') }}"></script>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/presentation.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/accessibility.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/card-layout.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/site-extras.css') }}"><script defer src="{{ \App\Support\WebsiteView::asset('assets/site-extras.js') }}"></script><style>:root{ {{ \App\Support\PresentationCss::variables($site) }};--brand:{{ $theme['accent'] }};--page-bg:{{ $theme['background'] }};--on-brand:{{ \App\Domain\CmsPublishing::buttonInk($theme['accent']) }}}</style></head>
<body data-preset="{{ $theme['preset'] }}" data-layout="{{ $theme['layout'] }}" data-font="{{ $theme['font'] }}">
<a class="site-skip" href="#site-main">ข้ามไปเนื้อหา</a>
@if($preview)<div class="preview-ribbon">ตัวอย่างก่อนเผยแพร่ · ร่างนี้ยังไม่แสดงต่อผู้ชม</div>@endif
@include('website.header')
@include('website.observance')
<main id="site-main">@yield('content')</main>
<section class="school-contact" id="contact" data-theme-contact @if(!$theme['show_contact']) hidden @endif><div><h2>ติดต่อโรงเรียน</h2><p data-theme-text="school_name">{{ $theme['school_name'] }}</p></div><div><p data-theme-text="address">{{ $theme['address'] }}</p><p data-theme-text="phone">{{ $theme['phone'] }}</p><p>{{ $site['email'] }}</p><small>{{ $site['office_hours'] }}</small><a class="text-link" href="{{ url('/portal/contact') }}">ติดต่อโรงเรียน <span aria-hidden="true"><x-ui-icon name="arrow-right"/></span></a></div></section>
<footer class="school-footer"><div><strong data-theme-text="school_name">{{ $theme['school_name'] }}</strong><p class="school-mission">{{ $site['mission'] }}</p><p>{{ $site['footer_text'] }}</p></div><nav aria-label="ลิงก์ส่วนท้าย"><a href="{{ url('/portal/directory') }}">ข้อมูลและบริการทั้งหมด</a><a href="{{ url('/portal/newsletters') }}">จดหมายข่าว</a><a href="{{ url('/portal/videos') }}">วิดีโอ</a>@foreach($site['footer_navigation'] as $item)<a href="{{ W::link($item['url']) }}">{{ $item['label'] }}</a>@foreach($item['children']??[] as $child)<a href="{{ W::link($child['url']) }}">{{ $child['label'] }}</a>@foreach($child['children']??[] as $grandchild)<a href="{{ W::link($grandchild['url']) }}">{{ $grandchild['label'] }}</a>@endforeach @endforeach @endforeach<a href="{{ url('/portal/downloads') }}">เอกสารดาวน์โหลด</a><a href="{{ url('/portal/procurement') }}">จัดซื้อจัดจ้าง</a><a href="{{ url('/portal/contact') }}">ติดต่อเรา</a>@foreach($site['social_links'] as $link)<a href="{{ $link['url'] }}" rel="noopener noreferrer">{{ $link['label'] }}</a>@endforeach @if($adminUrl=\App\Support\Saan\Edition::adminUrl())<a class="site-admin-link" href="{{ $adminUrl }}" rel="noopener">ผู้ดูแลระบบ</a>@endif</nav>@include('website.chat',['variant'=>'footer'])<small>© {{ now('Asia/Bangkok')->year+543 }} @if(config('school.demo')) · เว็บไซต์สาธิต ข้อมูลโรงเรียนสมมติ · ภาพประกอบสร้างด้วย AI @endif</small></footer>@include('website.chat',['variant'=>'floating'])
</body></html>
