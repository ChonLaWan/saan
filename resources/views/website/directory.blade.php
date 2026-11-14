@extends('website.layout')
@section('content')
<section class="directory-intro"><h1>ข้อมูลและบริการทั้งหมด</h1><p>{{ $site['intro_heading'] }}</p><p>เลือกหมวดเพื่อดูข้อมูลที่โรงเรียนเผยแพร่ ค้นหาเพิ่มเติมได้ที่ <a href="{{ url('/portal/search') }}">ค้นหาข้อมูลโรงเรียน</a></p></section>
<div class="directory-grid">@foreach($sections as $key=>$section)<a class="directory-card" data-public-section="{{ $key }}" href="{{ url($section['public_path']) }}">@include('website.icon',['iconIndex'=>$section['icon']])<h2>{{ $section['label'] }}</h2><p>{{ $section['description'] }}</p><div class="card-footer"><small>{{ $sectionCounts[$key]??0 }} รายการเผยแพร่</small><x-ui-icon name="arrow-right"/></div></a>@endforeach</div>
@endsection
