@php($mark=\App\Support\PresentationCss::mark($site))
<svg class="school-brand-mark" viewBox="0 0 96 96" fill="none" aria-hidden="true" focusable="false">
@if($mark==='circle')<circle cx="48" cy="48" r="43" class="brand-outline"/><g transform="translate(8 8) scale(.84)">@elseif($mark==='shield')<path d="M48 5 86 20v29c0 20-15 33-38 42C25 82 10 69 10 49V20Z" class="brand-outline"/><g transform="translate(9 8) scale(.82)">@else<g>@endif
<path d="M12 38c13-5 25-3 36 5 11-8 23-10 36-5v36c-13-4-25-2-36 6-11-8-23-10-36-6Z" class="brand-book"/>
<path d="M48 45v32M22 50c7-1 13 1 19 5M55 55c6-4 12-6 19-5" class="brand-line"/>
<path d="M22 65c11-7 18 6 29-1 9-6 15 1 23-4" class="brand-stream"/>
<path d="M48 38V21" class="brand-line"/>
<path d="M47 28C32 29 27 22 28 13c12 0 20 5 19 15Z" class="brand-leaf"/>
<path d="M49 23C49 10 58 5 69 7c-1 11-7 18-20 16Z" class="brand-leaf"/>
</g></svg>
