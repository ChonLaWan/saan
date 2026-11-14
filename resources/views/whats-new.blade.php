<x-account-layout title="มีอะไรใหม่">
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/modules-admin.css') }}">
<p class="account-intro">สิ่งที่เปลี่ยนในรุ่น {{ $version }} ตามบันทึกการเปลี่ยนแปลงที่ติดมากับระบบ</p>
@if($feed && ($feed['enabled'] ?? false) && ($feed['available']['any'] ?? false))
<section class="account-card glass mods-new" role="status"><h2>มีรุ่นใหม่ให้ติดตั้ง</h2><ul>
@if($feed['available']['core'] ?? null)<li>แกนระบบ {{ $feed['available']['core']['version'] }} (ใช้อยู่ {{ $feed['available']['current'] }}) @if($feed['available']['core']['summary'])<br>{{ $feed['available']['core']['summary'] }}@endif @if($feed['available']['core']['notes_url'])<a href="{{ $feed['available']['core']['notes_url'] }}" rel="noopener noreferrer" target="_blank">รายละเอียด</a>@endif</li>@endif
@foreach($feed['available']['modules'] ?? [] as $mu)<li>{{ $mu['name'] }} {{ $mu['version'] }} (ใช้อยู่ {{ $mu['current'] }})</li>@endforeach
</ul><p class="account-help">ดาวน์โหลดแพ็กเกจที่ลงลายเซ็นแล้วนำไปติดตั้งตามคู่มืออัปเดต ระบบไม่ติดตั้งให้เอง</p></section>
@endif
@if($core)
<section class="account-card glass"><h2>แกนระบบ {{ $core['title'] }}@if($core['date']) <small>({{ $core['date'] }})</small>@endif</h2>
@unless($core['exact'])<p class="account-help">ยังไม่มีหัวข้อของรุ่น {{ $version }} จึงแสดงหัวข้อล่าสุดในบันทึก</p>@endunless
@foreach($core['groups'] as $g)@if($g['heading'])<h3>{{ $g['heading'] }}</h3>@endif<ul class="mods-notes">@foreach($g['items'] as $it)<li>{!! \App\Support\Modules\WhatsNew::inline($it) !!}</li>@endforeach</ul>@endforeach</section>
@else
<section class="account-card glass"><p>ไม่พบไฟล์ CHANGELOG.md</p></section>
@endif
@foreach($modules as $m)
<section class="account-card glass"><h2>{{ $m['name'] }} {{ $m['version'] }}@if($m['section']['date']) <small>({{ $m['section']['date'] }})</small>@endif</h2>
@foreach($m['section']['groups'] as $g)@if($g['heading'])<h3>{{ $g['heading'] }}</h3>@endif<ul class="mods-notes">@foreach($g['items'] as $it)<li>{!! \App\Support\Modules\WhatsNew::inline($it) !!}</li>@endforeach</ul>@endforeach</section>
@endforeach
</x-account-layout>
