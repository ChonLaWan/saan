<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title }} — {{ config('saan.display') }}</title>
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/atelier.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/accounts.css') }}"><link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/school-navigation.css') }}"><script defer src="{{ \App\Support\WebsiteView::asset('assets/school-navigation.js') }}"></script><x-appearance-assets/></head>
<body class="account-body school-layout"><x-school-navigation /><div class="account-shell">
<header class="account-top glass"><x-saan-brand :href="url('/')" /><nav aria-label="บัญชีและระบบ"><a href="{{ url('/') }}">พื้นที่ทำงาน</a><a href="{{ url('/account') }}">บัญชีของฉัน</a>@if(auth()->user()->role==='admin')<a href="{{ url('/admin/users') }}">ผู้ใช้งาน</a><a href="{{ url('/admin/modules') }}">โมดูล</a>@if(\App\Support\Saan\Edition::isInternal())<a href="{{ url('/admin/publish') }}">ส่งข้อมูลหน้าเว็บ</a>@endif @endif<a href="{{ url('/whats-new') }}">มีอะไรใหม่</a><form method="post" action="{{ url('/logout') }}">@csrf<button>ออกจากระบบ</button></form></nav></header>
<main class="account-main"><p class="eyebrow">บัญชีและสิทธิ์การใช้งาน</p><h1>{{ $title }}</h1>
@if(session('status'))<div class="account-alert" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="account-alert error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
{{ $slot }}</main></div></body></html>
