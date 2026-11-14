@php($orientation = 'landscape')
@extends('reports-pp::layout')
@section('title', 'สรุปผลการเรียน ห้อง '.$d['classroom'])
@section('toolbar')<a href="{{ url('/api/reports-pp/classrooms/'.request()->route('id').'/summary/csv?term_id='.$d['term']['id']) }}">ส่งออก CSV</a>@endsection
@section('content')
<section class="sheet last">
    @include('reports-pp::_head')
    <div class="meta"><span>ห้อง <strong>{{ $d['classroom'] }}</strong></span><span>จำนวนนักเรียน {{ $d['student_count'] }} คน</span></div>

    <h3>การกระจายผลการเรียนรายวิชา</h3>
    <table>
        <thead><tr><th>รหัส</th><th>รายวิชา</th><th class="n">ทั้งหมด</th>@foreach(\Saan\Modules\ReportsPp\Calc::BUCKETS as $b)<th class="n">{{ $b }}</th>@endforeach<th class="n">ร</th><th class="n">มผ</th><th class="n">มส</th><th class="n">ยังไม่สมบูรณ์</th><th class="n">คะแนนเฉลี่ย</th></tr></thead>
        <tbody>
        @forelse($d['subjects'] as $s)
            <tr><td>{{ $s['course']['code'] }}</td><td>{{ $s['course']['name'] }}</td><td class="n">{{ $s['dist']['total'] }}</td>
                @foreach(\Saan\Modules\ReportsPp\Calc::BUCKETS as $b)<td class="n">{{ $s['dist'][$b] }}</td>@endforeach
                <td class="n">{{ $s['dist']['ร'] }}</td><td class="n">{{ $s['dist']['มผ'] }}</td><td class="n">{{ $s['dist']['มส'] }}</td><td class="n">{{ $s['dist']['incomplete'] }}</td>
                <td class="num">{{ $s['dist']['graded'] ? number_format($s['dist']['sum_total'] / $s['dist']['graded'], 2) : '-' }}</td></tr>
        @empty
            <tr><td colspan="{{ 5 + count(\Saan\Modules\ReportsPp\Calc::BUCKETS) + 3 }}" class="n muted">ยังไม่มีรายวิชาในภาคเรียนนี้</td></tr>
        @endforelse
        </tbody>
    </table>
    @php($pl = $d['placeholders'])
    <p class="note">จำนวนผลที่ต้องติดตาม: ได้เกรด 0 = {{ $pl['zero'] }} | ร = {{ $pl['r'] }} | มผ = {{ $pl['mp'] }} | มส = {{ $pl['ms'] }} | คะแนนยังไม่สมบูรณ์ = {{ $pl['incomplete'] }}</p>

    <h3>ผลการเรียนเฉลี่ยรายบุคคล@if($d['show_rank']) และลำดับ@endif</h3>
    <table>
        <thead><tr><th class="n">เลขที่</th><th>รหัส</th><th>ชื่อ-สกุล</th><th class="n">GPA</th>@if($d['show_rank'])<th class="n">อันดับ</th>@endif<th class="n">ได้ 0</th><th class="n">ร</th><th class="n">มผ</th><th class="n">มส</th><th class="n">ยังไม่สมบูรณ์</th></tr></thead>
        <tbody>
        @foreach($d['students'] as $i => $s)
            <tr><td class="n">{{ $s['student']['roll'] ?? $s['seq'] }}</td><td>{{ $s['student']['code'] }}</td><td>{{ $s['student']['name'] }}</td>
                <td class="n">{{ \Saan\Modules\ReportsPp\Calc::fmtCenti($s['gpa']['gpa_centi']) }}</td>@if($d['show_rank'])<td class="n">{{ $s['rank'] ?? '-' }}</td>@endif
                <td class="n">{{ $s['zero'] }}</td><td class="n">{{ $s['r'] }}</td><td class="n">{{ $s['mp'] }}</td><td class="n">{{ $s['ms'] }}</td><td class="n">{{ $s['incomplete'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @include('reports-pp::_sign')
</section>
@endsection
