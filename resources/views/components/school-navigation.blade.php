@php
    $groups = \App\Support\SchoolNavigation::groups(auth()->user()->role);
    $schoolName = app(\App\Domain\CmsPublishing::class)->theme((int) auth()->user()->school_id)['published']['school_name'];
    $term = \App\Support\Saan\TermScope::current(); // the same current term the modules use (setting academic.current_term_id first)
@endphp
<button class="school-menu-toggle" type="button" aria-label="เปิดเมนูหลัก" aria-controls="school-navigation" aria-expanded="false"><x-ui-icon name="menu"/></button>
<div class="school-nav-overlay" hidden></div>
<aside class="school-nav" id="school-navigation" aria-label="เมนูหลักโรงเรียน" tabindex="-1">
    <header class="school-nav-brand"><x-saan-brand :href="url('/')" /><button class="school-menu-close" type="button" aria-label="ปิดเมนูหลัก"><x-ui-icon name="close"/></button>
        <div class="school-nav-school"><span aria-hidden="true">{{ mb_substr($schoolName,0,1) }}</span><div><strong>{{ $schoolName }}</strong><small>ระบบบริหารโรงเรียน</small></div></div>
    </header>
    <nav class="school-nav-scroll" aria-label="ระบบงานโรงเรียน">
        @foreach($groups as $group)
            @if($group['collapsible'])<details class="school-nav-group"><summary>{{ $group['label'] }}<span>{{ count($group['items']) }}</span></summary><div>@else<section class="school-nav-group"><h2>{{ $group['label'] }}</h2>@endif
            @foreach($group['items'] as $item)
                <a class="school-nav-link" title="{{ $item['label'] }}" data-nav-key="{{ $item['key'] }}" href="{{ url(explode('#',$item['path'],2)[0]).(str_contains($item['path'],'#')?'#'.explode('#',$item['path'],2)[1]:'') }}">
                    <x-ui-icon :name="\App\Support\WorkAreas::navGlyph($item['key'],$item['icon'])"/><span>{{ $item['label'] }}</span>@if($item['status']==='plan')<small>แผน</small>@elseif($item['status']==='off')<small>ปิด</small>@endif
                </a>
            @endforeach
            @if($group['collapsible'])</div></details>@else</section>@endif
        @endforeach
    </nav>
    <footer class="school-nav-footer"><p><i aria-hidden="true"></i>{{ $term?'ภาคเรียนที่ '.$term->term.' / '.$term->year:'ข้อมูลโรงเรียน' }}</p><div><a href="{{ url('/account') }}">{{ auth()->user()->name }}</a><form method="post" action="{{ url('/logout') }}">@csrf<button aria-label="ออกจากระบบ" title="ออกจากระบบ"><x-ui-icon name="logout"/></button></form></div></footer>
</aside>
