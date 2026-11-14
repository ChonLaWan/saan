<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>เข้าสู่ระบบด้วยรหัสนักเรียน</title>
    <link rel="stylesheet" href="{{ asset('assets/modules/api-gateway/authorize.css') }}">
</head>
<body>
<main class="sso">
    <h1>เข้าสู่ระบบด้วยรหัสนักเรียน</h1>
    <p>แอป <strong>{{ $client }}</strong> ขอให้คุณยืนยันตัวตน แอปจะเห็นเพียงชื่อ ห้องเรียน และรหัสนักเรียนของคุณ</p>
    @if ($error)
        <p class="sso-error" role="alert">{{ $error }}</p>
    @endif
    <form method="post" action="{{ url('/oauth/authorize') }}" autocomplete="off">
        @csrf
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <label>รหัสนักเรียน
            <input name="student_code" type="text" maxlength="40" required autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="text">
        </label>
        <label>รหัสผ่านหรือ PIN ที่ใช้เข้าระบบนักเรียน
            <input name="pin" type="password" maxlength="200" required autocomplete="off">
        </label>
        <button type="submit">เข้าสู่ระบบ</button>
    </form>
    <p class="sso-help">ลืม PIN หรือถูกพักการใช้งาน ให้แจ้งครูประจำชั้นเพื่อออก PIN ใหม่</p>
</main>
</body>
</html>
