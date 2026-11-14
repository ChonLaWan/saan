<x-account-layout title="ผู้ใช้งานของโรงเรียน">
<p class="account-intro">ให้แต่ละคนมีบัญชีของตัวเอง เลือกสิทธิ์ตามงานที่รับผิดชอบ</p>
<section class="account-card glass"><h2>เพิ่มผู้ใช้งาน</h2><p class="account-help">ผู้ดูแลเว็บไซต์ดูแลธีมและข่าวได้ โดยไม่เข้าถึงข้อมูลนักเรียน</p>
<form class="account-form account-create" method="post" action="{{ url('/admin/users') }}">@csrf
<label>ชื่อผู้ใช้งาน<input name="name" value="{{ old('name') }}" maxlength="140" required></label>
<label>อีเมล<input name="email" type="email" value="{{ old('email') }}" maxlength="254" autocomplete="off" required></label>
<label>สิทธิ์<select name="role">@foreach($roles as $key=>$label)<option value="{{ $key }}" @selected(old('role','editor')===$key)>{{ $label }}</option>@endforeach</select></label>
<input name="is_active" type="hidden" value="1">
<label class="account-invite"><input type="checkbox" name="invite" value="1" data-invite-toggle @checked(old('invite'))> ส่งลิงก์ให้ผู้ใช้ตั้งรหัสผ่านเองทางอีเมล (แนะนำ)</label>
<label data-password-field>รหัสผ่านเริ่มต้น<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required><small>อย่างน้อย 12 ตัว มีตัวอักษรและตัวเลข</small></label>
<label data-password-field>ยืนยันรหัสผ่าน<input type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required></label>
<script>document.querySelectorAll('[data-invite-toggle]').forEach(c=>{const f=()=>c.form.querySelectorAll('[data-password-field]').forEach(l=>{l.hidden=c.checked;l.querySelector('input').required=!c.checked;if(c.checked)l.querySelector('input').value='';});c.addEventListener('change',f);f();});</script>
<details class="account-teacher-links"><summary>ข้อมูลเพิ่มเติมสำหรับบัญชีครู</summary><div class="account-form account-create">
<label>ครูเจ้าของบัญชี<select name="teacher_id"><option value="">เลือกครู</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->name }}</option>@endforeach</select></label>
<label>ห้องที่รับผิดชอบ<select name="classroom_id"><option value="">เลือกห้อง</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}">{{ $classroom->name }}</option>@endforeach</select></label></div></details>
<div><button class="primary">เพิ่มบัญชีผู้ใช้งาน</button></div></form></section>
<section class="account-card glass"><h2>บัญชี{{ request()->boolean('inactive')?'ทั้งหมด':'ที่ใช้งาน' }} <small>{{ $users->count() }}</small></h2><p class="account-help"><a href="{{ url('/admin/users').(request()->boolean('inactive')?'':'?inactive=1') }}">{{ request()->boolean('inactive')?'แสดงเฉพาะบัญชีที่ใช้งาน':'แสดงบัญชีที่ปิดใช้งานด้วย' }}</a></p>
<div class="account-users">@foreach($users as $user)
<details class="account-user"><summary><span class="account-avatar">{{ mb_substr($user->name,0,1) }}</span><span><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></span><span class="account-user-tags"><span class="tag">{{ $roles[$user->role] ?? $user->role }}</span><span class="tag {{ $user->is_active?'':'warm' }}">{{ $user->is_demo?'สาธิต':($user->is_active?'ใช้งาน':'ปิดบัญชี') }}</span></span></summary>
@if($user->is_demo)<p class="account-help">บัญชีข้อมูลสาธิต แยกจากบัญชีใช้งานจริง</p>@else
<form class="account-form account-create" method="post" action="{{ url('/admin/users/'.$user->id) }}">@csrf @method('put')<input type="hidden" name="version" value="{{ $user->version }}">
<label>ชื่อผู้ใช้งาน<input name="name" value="{{ $user->name }}" maxlength="140" required></label>
<label>อีเมล<input name="email" type="email" value="{{ $user->email }}" maxlength="254" required></label>
<label>สิทธิ์<select name="role">@foreach($roles as $key=>$label)<option value="{{ $key }}" @selected($user->role===$key)>{{ $label }}</option>@endforeach</select></label>
<label>สถานะ<select name="is_active"><option value="1" @selected($user->is_active)>ใช้งาน</option><option value="0" @selected(!$user->is_active)>ปิดบัญชี</option></select></label>
<label>ครูเจ้าของบัญชี<select name="teacher_id"><option value="">ไม่ระบุ</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}" @selected($user->teacher_id===$teacher->id)>{{ $teacher->name }}</option>@endforeach</select></label>
<label>ห้องที่รับผิดชอบ<select name="classroom_id"><option value="">ไม่ระบุ</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected($user->classroom_id===$classroom->id)>{{ $classroom->name }}</option>@endforeach</select></label>
<div><button>บันทึกบัญชี</button></div></form>@if($user->is_active)<form method="post" action="{{ url('/admin/users/'.$user->id.'/password-link') }}" class="account-form">@csrf<button type="submit">ส่งลิงก์ตั้งรหัสผ่านทางอีเมล</button></form>@endif @endif</details>
@endforeach</div></section>
</x-account-layout>
