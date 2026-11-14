{{-- plain text mail: no HTML context, so values are printed raw (escaping would turn & and quotes into entities) --}}สวัสดี {!! $name !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
เปิดดูในระบบ: {!! $url !!}

ส่งโดยระบบ {!! $display !!}
