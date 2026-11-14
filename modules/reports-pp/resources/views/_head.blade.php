<div class="head">
    @if($d['school']['logo'])<img src="{{ $d['school']['logo'] }}" alt="">@endif
    <h1>{{ $d['school']['name'] }}</h1>
    <h2>{{ $d['template']['options']['title'] }}</h2>
    <p>ปีการศึกษา {{ $d['term']['year'] }} ภาคเรียนที่ {{ $d['term']['term'] }}</p>
</div>
