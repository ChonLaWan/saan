@php
    $groups = \App\Support\SchoolNavigation::groups(auth()->user()->role);
    $schoolName = app(\App\Domain\CmsPublishing::class)->theme((int) auth()->user()->school_id)['published']['school_name'];
    $term = \Illuminate\Support\Facades\DB::table('academic_terms')->where('school_id',auth()->user()->school_id)->where('id',config('school.term_id'))->first();
    $icons = [
        'grid'=>'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
        'calendar'=>'M4 5h16v16H4zM8 2v6M16 2v6M4 10h16M8 14h3M8 17h6',
        'shield'=>'M12 3 4 7v6c0 4 8 8 8 8s8-4 8-8V7zM8 12l3 3 5-6',
        'people'=>'M4 21v-3a5 5 0 0 1 10 0v3M9 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8M17 3a4 4 0 0 1 0 8M18 14a4 4 0 0 1 4 4v3',
        'check'=>'m5 12 4 4L20 5',
        'layers'=>'m12 3-10 5 10 5 10-5-10-5M2 12l10 5 10-5M2 16l10 5 10-5',
        'web'=>'M3 4h18v16H3zM3 9h18M8 9v11',
        'edit'=>'m4 16-1 5 5-1L20 8l-4-4L4 16M14 6l4 4',
        'document'=>'M5 3h10l4 4v14H5zM14 3v5h5M9 12h6M9 16h6',
        'palette'=>'M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 0-4h-1a2 2 0 0 1 0-4h5a4 4 0 0 0 4-4c0-4-5-6-9-6M7 8h.1M11 6h.1M16 8h.1M6 13h.1',
        'chart'=>'M4 21V11h4v10M10 21V3h4v18M16 21V8h4v13',
        'person'=>'M5 21v-2a7 7 0 0 1 14 0v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
    ];
@endphp
<button class="school-menu-toggle" type="button" aria-label="เปิดเมนูหลัก" aria-controls="school-navigation" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
<div class="school-nav-overlay" hidden></div>
<aside class="school-nav" id="school-navigation" aria-label="เมนูหลักโรงเรียน" tabindex="-1">
    <header class="school-nav-brand"><x-saan-brand :href="url('/')" tone="dark" /><button class="school-menu-close" type="button" aria-label="ปิดเมนูหลัก"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
        <div class="school-nav-school"><span aria-hidden="true">{{ mb_substr($schoolName,0,1) }}</span><div><strong>{{ $schoolName }}</strong><small>ระบบบริหารโรงเรียน</small></div></div>
    </header>
    <nav class="school-nav-scroll" aria-label="ระบบงานโรงเรียน">
        @foreach($groups as $group)
            @if($group['collapsible'])<details class="school-nav-group"><summary>{{ $group['label'] }}<span>{{ count($group['items']) }}</span></summary><div>@else<section class="school-nav-group"><h2>{{ $group['label'] }}</h2>@endif
            @foreach($group['items'] as $item)
                <a class="school-nav-link" data-nav-key="{{ $item['key'] }}" href="{{ url(explode('#',$item['path'],2)[0]).(str_contains($item['path'],'#')?'#'.explode('#',$item['path'],2)[1]:'') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icons[$item['icon']] }}"/></svg><span>{{ $item['label'] }}</span>@if($item['status']==='plan')<small>แผน</small>@elseif($item['status']==='off')<small>ปิด</small>@endif
                </a>
            @endforeach
            @if($group['collapsible'])</div></details>@else</section>@endif
        @endforeach
    </nav>
    <footer class="school-nav-footer"><p><i aria-hidden="true"></i>{{ $term?'ภาคเรียนที่ '.$term->term.' / '.$term->year:'ข้อมูลโรงเรียน' }}</p><div><a href="{{ url('/account') }}">{{ auth()->user()->name }}</a><form method="post" action="{{ url('/logout') }}">@csrf<button aria-label="ออกจากระบบ" title="ออกจากระบบ"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 3H4v18h6M10 12h11m-5-5 5 5-5 5"/></svg></button></form></div></footer>
</aside>
