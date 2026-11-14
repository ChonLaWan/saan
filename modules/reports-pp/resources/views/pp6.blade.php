@php($orientation = 'portrait')
@php($o = $d['template']['options'])
@extends('reports-pp::layout')
@section('title', 'ปพ.6 ห้อง '.$d['classroom'])
@section('toolbar')@if(!empty($d['classroom_id']))<a href="{{ url('/api/reports-pp/classrooms/'.$d['classroom_id'].'/csv?term_id='.$d['term']['id']) }}">ส่งออก CSV</a>@endif @endsection
@section('content')
@forelse($d['pages'] as $p)
<section class="sheet portrait {{ $loop->last ? 'last' : 'break' }}">
    @include('reports-pp::_head')
    <div class="meta">
        <span>ชื่อ-สกุล <strong>{{ $p['student']['name'] }}</strong></span>
        <span>รหัสนักเรียน {{ $p['student']['code'] }}</span>
        <span>ห้อง {{ $d['classroom'] }}@if($p['student']['roll']) เลขที่ {{ $p['student']['roll'] }}@endif</span>
    </div>
    <table>
        <thead><tr><th class="n">ลำดับ</th><th>รหัสวิชา</th><th>รายวิชา</th><th class="n">หน่วยกิต</th><th class="n">คะแนนรวม</th><th class="n">ผลการเรียน</th></tr></thead>
        <tbody>
        @forelse($p['lines'] as $i => $l)
            @php($res = $l['result'])
            <tr>
                <td class="n">{{ $i + 1 }}</td><td>{{ $l['course']['code'] }}</td>
                <td>{{ $l['course']['name'] }}@if($res['state'] === 'special' && $res['note']) <span class="muted">({{ $res['note'] }})</span>@endif</td>
                <td class="n">{{ \Saan\Modules\ReportsPp\Calc::fmtCredit($l['course']['credit_tenths']) }}</td>
                <td class="num">{{ $res['state'] === 'graded' ? \Saan\Modules\ReportsPp\Calc::fmtCenti($res['total_centi']) : '-' }}</td>
                <td class="n {{ $res['state'] === 'special' ? 'special' : '' }}">{{ $res['grade'] ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="n muted">ยังไม่มีรายวิชาในภาคเรียนนี้</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr><td colspan="3" class="num"><strong>ผลการเรียนเฉลี่ยประจำภาคเรียน (GPA)</strong> <span class="muted">(ไม่รวมวิชาที่ได้ ร มผ มส หรือคะแนนยังไม่ครบ)</span></td>
            <td class="n">{{ \Saan\Modules\ReportsPp\Calc::fmtCredit($p['gpa']['credits_tenths'] ?: null) }}</td>
            <td class="n" colspan="2"><strong>{{ \Saan\Modules\ReportsPp\Calc::fmtCenti($p['gpa']['gpa_centi']) }}</strong>@if($d['show_rank'] && $p['rank'] !== null) <br>อันดับที่ {{ $p['rank'] }}@endif</td></tr></tfoot>
    </table>

    @if($o['show_attributes'])
        <h3>ผลการประเมินคุณลักษณะและสมรรถนะ</h3>
        <div class="attrs">
        @foreach(\Saan\Modules\ReportsPp\ReportsPp::ATTR_KINDS as $kind => $label)
            <table>
                <thead><tr><th colspan="2">{{ $label }}</th></tr></thead>
                <tbody>
                @forelse($p['attributes'][$kind] ?? [] as $a)
                    <tr><td>{{ $a['code'] }}</td><td class="n">{{ \Saan\Modules\ReportsPp\ReportsPp::LEVELS[$a['level']] ?? $a['level'] }}</td></tr>
                @empty
                    <tr><td colspan="2" class="n muted">ยังไม่ได้ประเมิน</td></tr>
                @endforelse
                </tbody>
            </table>
        @endforeach
        </div>
    @endif

    @if($o['show_attendance'] && $d['has_attendance'])
        @php($a = $p['attendance'])
        <h3>สรุปเวลาเรียน</h3>
        <table>
            <thead><tr><th class="n">มา</th><th class="n">สาย</th><th class="n">ขาด</th><th class="n">ลา</th><th class="n">ป่วย</th><th class="n">รวมวันที่บันทึก</th></tr></thead>
            <tbody><tr>
                @foreach(['present', 'late', 'absent', 'leave', 'sick'] as $st)<td class="n">{{ $a[$st] ?? 0 }}</td>@endforeach
                <td class="n">{{ array_sum($a) }}</td>
            </tr></tbody>
        </table>
        @if(!empty($p['attendance_warning']))<p class="warn">{{ $p['attendance_warning'] }}</p>@endif
    @endif

    <h3>ความเห็นของครูประจำชั้น</h3>
    <div class="box">{{ $p['remark'] }}</div>
    @include('reports-pp::_sign')
</section>
@empty
<section class="sheet portrait last">@include('reports-pp::_head')<p class="muted">ไม่พบนักเรียนในห้องนี้สำหรับภาคเรียนที่เลือก</p></section>
@endforelse
@endsection
