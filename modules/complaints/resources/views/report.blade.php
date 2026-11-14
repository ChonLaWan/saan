@extends('website.layout')
@section('content')
<section class="collection-heading"><p class="site-eyebrow">ร้องเรียนและแจ้งเรื่อง</p><h1>รายงานสรุปเรื่องร้องเรียนและรับเรื่อง</h1><p>{{ $report['label'] }}</p></section>
<section class="cmp-page">
    <p class="cmp-note">รายงานนี้แสดงเฉพาะจำนวนเรื่องแยกตามประเภทและสถานะ กับเวลาดำเนินการเฉลี่ยของเรื่องที่ปิดแล้ว ไม่มีชื่อ ข้อมูลติดต่อ หรือเนื้อหาของเรื่องใด ๆ</p>
    <div class="cmp-scroll">
    <table class="cmp-table">
        <thead><tr><th scope="col">ประเภท</th><th scope="col">ทั้งหมด</th>@foreach($statuses as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">เวลาเฉลี่ย (วัน)</th></tr></thead>
        <tbody>
        @foreach(array_merge(array_values($report['rows']), [$report['totals']]) as $row)
            <tr @if($loop->last) class="cmp-total" @endif><th scope="row">{{ $row['label'] }}</th><td>{{ $row['total'] }}</td>@foreach($statuses as $key => $label)<td>{{ $row['by_status'][$key] }}</td>@endforeach<td>{{ $row['avg_days'] === null ? '-' : number_format($row['avg_days'], 1) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @if($publishedAt)<p><small>เผยแพร่เมื่อ {{ \Carbon\CarbonImmutable::parse($publishedAt)->locale('th')->translatedFormat('j F') }} {{ \Carbon\CarbonImmutable::parse($publishedAt)->year + 543 }}</small></p>@endif
    <p><a class="text-link" href="{{ url('/portal/complaints/reports/'.$report['fiscal_year'].'.csv') }}">ดาวน์โหลดเป็นไฟล์ CSV</a> · <a class="text-link" href="{{ url('/portal/complaints') }}">ช่องทางร้องเรียน</a></p>
</section>
<style>
.cmp-page{max-width:980px;margin:0 auto 48px;padding:0 16px}.cmp-note{background:var(--surface-raised,#fffdf4);border:1px solid var(--line,#bac8b4);border-radius:10px;padding:10px 14px;margin:0 0 14px;font-size:.95rem}
.cmp-scroll{overflow-x:auto}.cmp-table{border-collapse:collapse;width:100%;min-width:560px}.cmp-table th,.cmp-table td{border:1px solid var(--line,#bac8b4);padding:8px 10px;text-align:right}.cmp-table th[scope=row],.cmp-table thead th:first-child{text-align:left}
.cmp-table thead th{background:var(--surface-raised,#fffdf4)}.cmp-total th,.cmp-total td{font-weight:700;background:var(--surface-raised,#fffdf4)}
</style>
@endsection
