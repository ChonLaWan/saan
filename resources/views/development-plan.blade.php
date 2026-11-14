<x-account-layout title="Checklist พัฒนาระบบโรงเรียน">
@php
    $allTasks = collect($plan['modules'])->concat($plan['foundations'])->flatMap(fn($module)=>$module['tasks']);
    $done = $allTasks->where('status','local_verified')->count();
@endphp
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/development-plan.css') }}">
<script defer src="{{ \App\Support\WebsiteView::asset('assets/development-plan.js') }}"></script>
<p class="account-intro">24 ระบบงาน และงานฐานระบบที่ใช้ร่วมกัน เริ่มจาก CMS ที่ครูปรับธีมและเขียนข่าวได้ง่าย</p>
<div class="plan-stats"><div><strong>24</strong><span>ระบบงานหลัก</span></div><div><strong>{{ $allTasks->count() }}</strong><span>รายการพัฒนาและตรวจรับ</span></div><div><strong>{{ $done }}</strong><span>มีโค้ดและทดสอบในเครื่อง</span></div><div><strong>{{ $allTasks->count()-$done }}</strong><span>ยังต้องทำและตรวจรับ</span></div></div>
<div class="plan-notice">รายการที่ทำแล้วหมายถึงผ่านการทดสอบในเครื่องด้วยข้อมูลสมมติ ทุกโมดูลยังต้องตรวจรับกับโรงเรียนก่อนเปิดใช้จริง</div>
<nav class="plan-tools" aria-label="เครื่องมือ checklist"><label>ค้นหาระบบหรือรายการ<input id="plan-search" type="search" placeholder="เช่น ธีม ตารางสอน ปพ.5" autocomplete="off"></label><label>สถานะ<select id="plan-status"><option value="all">ทุกรายการ</option><option value="pending">ยังต้องทำ</option><option value="local_verified">มีโค้ดในเครื่อง</option></select></label><a class="quiet-button" href="{{ url('/development-plan/export') }}">ดาวน์โหลด checklist</a><button type="button" id="plan-print">พิมพ์</button></nav>
<p id="plan-results" class="account-help" role="status"></p>
<section class="account-card glass"><h2>ลำดับการพัฒนาที่แนะนำ</h2><div class="plan-phases">@foreach($plan['phases'] as $phase)<div><span>{{ $phase['id'] }}</span><h3>{{ $phase['title'] }}</h3><p>{{ $phase['exit'] }}</p></div>@endforeach</div></section>
<section class="account-card glass"><h2>เกณฑ์เสร็จของทุกโมดูล</h2><p class="account-help">เขียนหน้าจอเสร็จอย่างเดียวยังไม่นับว่าโมดูลเสร็จ</p><ul class="plan-dod">@foreach($plan['definition_of_done'] as $rule)<li>{{ $rule }}</li>@endforeach</ul></section>
<div id="plan-modules">
@foreach(array_merge($plan['modules'],$plan['foundations']) as $module)
<section class="account-card glass plan-module" id="{{ $module['id'] }}" data-plan-module>
    <header><div><p class="plan-kicker">{{ $module['id'] }} · {{ $module['phase'] }}</p><h2>{{ $module['title'] }}</h2></div><span class="tag">{{ collect($module['tasks'])->where('status','local_verified')->count() }}/{{ count($module['tasks']) }} ในเครื่อง</span></header>
    @if(!empty($module['depends_on']))<p class="account-help">เชื่อมกับ {{ implode(', ',$module['depends_on']) }}</p>@endif
    @if(!empty($module['design_url'])&&is_file(public_path(ltrim(strtok($module['design_url'],'#'),'/'))))<p><a class="quiet-button" href="{{ url($module['design_url']) }}">เปิดแบบข้อมูลและ DFD</a></p><p class="account-help">{{ $module['design_status'] }}</p>@endif
    <ul class="plan-tasks">@foreach($module['tasks'] as $task)<li data-plan-status="{{ $task['status'] }}"><span class="plan-check {{ $task['status']==='local_verified'?'checked':'' }}" aria-label="{{ $task['status']==='local_verified'?'มีโค้ดและทดสอบในเครื่อง':'ยังต้องทำ' }}">@if($task['status']==='local_verified')<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L20 5"/></svg>@endif</span><div><small>{{ $task['id'] }}</small><span>{{ $task['text'] }}</span>@if(!empty($task['implementation_note']))<small class="plan-implementation-note">{{ $task['implementation_note'] }}</small>@endif</div><em>{{ $task['status']==='local_verified'?'ในเครื่อง':'รอทำ' }}</em></li>@endforeach</ul>
    @if(isset($module['source_acceptance']))<details class="plan-acceptance"><summary>ขอบเขตและเกณฑ์ตรวจรับปลายทาง</summary><p>{{ $module['source_requirement'] }}</p><p><strong>ตรวจรับ:</strong> {{ $module['source_acceptance'] }}</p>@if($module['evidence_kind']==='proposed')<p>เป็นโมดูลที่เสนอเพิ่ม ไม่ได้ยืนยันว่าโรงเรียนนำร่องใช้อยู่แล้ว</p>@endif</details>@endif
</section>
@endforeach
</div>
<section class="account-card glass"><h2>ที่มาของขอบเขต</h2><p>ครอบคลุม M01–M24 จาก functional catalogue ของกรณีศึกษาโรงเรียนขนาดใหญ่ และโจทย์ CMS โรงเรียนทั่วไป รวมรายการเพิ่มเติมสำหรับแจกใช้งานจริง</p>@php($planLinks=array_filter(['/design/index.html#traceability'=>'DFD และ requirement traceability','/cms-release/index.html'=>'CMS และหลักฐานการตรวจล่าสุด','/student-registry-design/index.html'=>'แบบทะเบียนนักเรียนและผู้ปกครอง'],fn($label,$path)=>is_file(public_path(ltrim(strtok($path,'#'),'/'))),ARRAY_FILTER_USE_BOTH))
@if($planLinks)<nav class="plan-source-links">@foreach($planLinks as $path=>$label)<a href="{{ url($path) }}">{{ $label }}</a>@endforeach</nav>@else<p class="account-help">เอกสารออกแบบฉบับเต็มไม่ได้แจกมากับชุดติดตั้งนี้</p>@endif<p class="account-help">ร่างวันที่ 5 พฤศจิกายน 2569 ยังไม่กำหนดวันส่งมอบหรือ licence แทนเจ้าของโครงการ เลขบนเว็บใช้ 0–9 ระบบร่างเอกสารจัดซื้อจัดจ้างอยู่นอกขอบเขต</p></section>
</x-account-layout>
