<!doctype html>
<html lang="th">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subjectLine }}</title></head>
<body style="margin:0;padding:16px;background:#f4f6f8;font-family:Tahoma,'Noto Sans Thai',Arial,sans-serif;color:#1d2733;line-height:1.6">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #d9dee4;border-radius:8px;padding:20px">
    <p style="margin:0 0 12px;font-size:18px"><strong>สวัสดี {{ $name }}</strong></p>
    @foreach ($lines as $line)
        <p style="margin:0 0 10px">{{ $line }}</p>
    @endforeach
    <p style="margin:18px 0">
        <a href="{{ $url }}" style="display:inline-block;background:#1f5fbf;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px">เปิดดูในระบบ</a>
    </p>
    <p style="margin:0 0 12px;font-size:13px;color:#52606d">หากกดปุ่มไม่ได้ ให้คัดลอกลิงก์นี้ไปเปิดในเบราว์เซอร์: {{ $url }}</p>
    <hr style="border:0;border-top:1px solid #d9dee4;margin:16px 0">
    <p style="margin:0;font-size:13px;color:#52606d">ส่งโดยระบบ {{ $display }}</p>
</div>
</body>
</html>
