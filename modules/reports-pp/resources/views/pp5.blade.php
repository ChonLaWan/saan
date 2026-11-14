@php($orientation = 'landscape')
@php($o = $d['template']['options'])
@php($comps = $d['course']['policy']['components'])
@php($attrKinds = $o['show_attributes'] ? $d['attr_codes'] : [])
@extends('reports-pp::layout')
@section('title', 'ปพ.5 '.$d['course']['code'].' '.$d['course']['name'].' '.$d['classroom'])
@section('toolbar')<a href="{{ url('/api/reports-pp/courses/'.$d['course']['id'].'/csv') }}">ส่งออก CSV</a>@endsection
@section('content')
<section class="sheet last">
    @include('reports-pp::_head')
    <div class="meta">
        <span>รายวิชา <strong>{{ $d['course']['code'] }} {{ $d['course']['name'] }}</strong></span>
        <span>กลุ่มสาระ {{ $d['course']['group_name'] ?: '-' }}</span>
        <span>หน่วยกิต {{ \Saan\Modules\ReportsPp\Calc::fmtCredit($d['course']['credit_tenths']) }}</span>
        <span>ห้อง {{ $d['classroom'] }}</span>
        <span>ครูผู้สอน {{ $d['course']['teacher'] ?: '-' }}</span>
        <span>สัดส่วนคะแนน ระหว่างภาค {{ $d['course']['policy']['continuous_ratio'] }} : ปลายภาค {{ $d['course']['policy']['exam_ratio'] }}</span>
    </div>
    <table>
        <thead>
            <tr>
                <th class="n" rowspan="2">เลขที่</th><th rowspan="2">รหัส</th><th rowspan="2">ชื่อ-สกุล</th>
                @if($o['show_components'])
                    @foreach($comps as $c)<th class="n">{{ $c['label'] }}</th>@endforeach
                @endif
                <th class="n" rowspan="2">รวม (100)</th><th class="n" rowspan="2">ผลการเรียน</th>
                @foreach($attrKinds as $kind => $codes)<th colspan="{{ count($codes) }}">{{ \Saan\Modules\ReportsPp\ReportsPp::ATTR_KINDS[$kind] ?? $kind }}</th>@endforeach
                <th rowspan="2">หมายเหตุ</th>
            </tr>
            <tr>
                @if($o['show_components'])
                    @foreach($comps as $c)<th class="n">({{ $c['max'] }})</th>@endforeach
                @endif
                @foreach($attrKinds as $kind => $codes)@foreach($codes as $code)<th class="n">{{ $code }}</th>@endforeach @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($d['rows'] as $i => $r)
                @php($res = $r['result'])
                <tr>
                    <td class="n">{{ $r['student']['roll'] ?? $i + 1 }}</td><td>{{ $r['student']['code'] }}</td><td>{{ $r['student']['name'] }}</td>
                    @if($o['show_components'])
                        @foreach($comps as $c)<td class="num">{{ \Saan\Modules\ReportsPp\Calc::fmtScore($res['centi'][$c['key']] ?? null) }}</td>@endforeach
                    @endif
                    <td class="num">{{ $res['state'] === 'graded' ? \Saan\Modules\ReportsPp\Calc::fmtCenti($res['total_centi']) : '' }}</td>
                    <td class="n {{ $res['state'] === 'special' ? 'special' : '' }}">{{ $res['grade'] ?? '-' }}</td>
                    @foreach($attrKinds as $kind => $codes)
                        @php($lv = collect($r['attributes'][$kind] ?? [])->pluck('level', 'code'))
                        @foreach($codes as $code)<td class="n">{{ $lv->has($code) ? $lv[$code] : '' }}</td>@endforeach
                    @endforeach
                    <td>{{ $res['state'] === 'special' ? (\Saan\Modules\ReportsPp\Calc::SPECIAL[$res['special']] ?? '').($res['note'] ? ' - '.$res['note'] : '') : ($res['state'] === 'graded' ? '' : 'คะแนนยังไม่ครบ') }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 6 + ($o['show_components'] ? count($comps) : 0) + collect($attrKinds)->sum(fn ($c) => count($c)) }}" class="n muted">ยังไม่มีนักเรียนในรายวิชานี้</td></tr>
            @endforelse
        </tbody>
    </table>
    @php($s = $d['stats'])
    <p class="note">สรุปผลการเรียน (นักเรียน {{ $s['total'] }} คน):
        @foreach(\Saan\Modules\ReportsPp\Calc::BUCKETS as $b) เกรด {{ $b }} = {{ $s[$b] }} @endforeach
        | ร = {{ $s['ร'] }} | มผ = {{ $s['มผ'] }} | มส = {{ $s['มส'] }} | ยังไม่สมบูรณ์ = {{ $s['incomplete'] }}
    </p>
    <p class="note muted">ระดับผลการประเมินคุณลักษณะ: 3 = ดีเยี่ยม, 2 = ดี, 1 = ผ่าน, 0 = ไม่ผ่าน</p>
    @include('reports-pp::_sign')
</section>
@endsection
