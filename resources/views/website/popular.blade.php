@if($popular->count())
@php($popularDays=(int)($site['extras']['popular']['days']??7))
<section class="school-popular" id="popular" aria-labelledby="popular-title"><div class="section-heading"><div><p class="site-eyebrow">ข่าวยอดนิยม</p><h2 id="popular-title">ข่าวที่ถูกอ่านมากที่สุด</h2></div><p>ในช่วง {{ $popularDays }} วันที่ผ่านมา</p></div>
<ol class="popular-list">@foreach($popular as $post)<li><span class="popular-rank" aria-hidden="true">{{ $loop->iteration }}</span><div><a href="{{ \App\Support\WebsiteView::url($post) }}">{{ $post->title }}</a><time>{{ \App\Support\WebsiteView::date($post->published_at) }}</time></div></li>@endforeach</ol></section>
@endif