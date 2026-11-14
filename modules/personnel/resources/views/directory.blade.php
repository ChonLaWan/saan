{{--
  Public staff directory partial (G3). A theme includes it with:  @include('personnel::directory')
  Optional: @include('personnel::directory', ['staff' => $rows, 'heading' => 'บุคลากร'])
  Data comes from per_profiles (HR record), same source as GET /api/personnel/directory: name, position, department,
  work_group and a re-encoded photo only. Every value is escaped by Blade.
--}}
@php
    $staff = $staff ?? (app(\App\Support\Modules\ModuleRegistry::class)->active('personnel') && \Illuminate\Support\Facades\Schema::hasTable('per_profiles') ? \Saan\Modules\Personnel\Staff::directory() : []);
    $heading = $heading ?? 'บุคลากร';
    $groups = collect($staff)->groupBy(fn ($p) => ($p['work_group'] ?? '') ?: (($p['department'] ?? '') ?: 'บุคลากร'));
    $execAnchored = false;
@endphp
<section class="personnel-directory" aria-label="{{ $heading }}">
    <h2>{{ $heading }}</h2>
    @if(count($staff) === 0)
        <p class="personnel-empty">ยังไม่มีข้อมูลบุคลากร</p>
    @else
        @foreach($groups as $groupName => $members)
            <div class="personnel-group">
                @if(!$execAnchored && $members->contains(fn ($p) => \App\Support\WebsiteView::isExecutive($p['position'] ?? '')))<span id="executives"></span>@php($execAnchored = true)@endif
                <h3>{{ $groupName }}</h3>
                <ul class="personnel-list">
                    @foreach($members as $p)
                        <li class="personnel-card">
                            @if(!empty($p['photo_url']))
                                <img src="{{ $p['photo_url'] }}" alt="{{ $p['name'] }}" width="120" height="120" loading="lazy" decoding="async">
                            @endif
                            <strong>{{ $p['name'] }}</strong>
                            @if(!empty($p['position']))<span>{{ $p['position'] }}</span>@endif
                            @if(!empty($p['work_group']) && !empty($p['department']))<small>{{ $p['department'] }}</small>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif
</section>
