@php
    try { $theme = app(\App\Domain\CmsPublishing::class)->theme((int) config('school.school_id'))['published'] ?? []; } catch (\Throwable) { $theme = []; }
    $name = trim((string) ($theme['school_name'] ?? ''));
    $logo = (int) ($theme['logo_media_id'] ?? 0);
@endphp
<header class="sg-login-id">
    @if($name !== '')<div class="sg-login-school">@if($logo)<img src="{{ url('/media/'.$logo) }}" alt="" width="48" height="48">@else<span aria-hidden="true">{{ mb_substr($name, 0, 1) }}</span>@endif<strong>{{ $name }}</strong></div>@endif
    <x-saan-brand class="sg-login-saan" />
</header>
