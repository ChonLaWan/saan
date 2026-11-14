<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title>ลิงก์การเรียนรู้{{ $room ? ' '.$room : '' }} | {{ config('saan.display') }}</title>
<style>
:root{color-scheme:light dark;--bg:#f6f4ea;--ink:#163b30;--muted:#51675b;--line:#c4ccbe;--sheet:#fffdf5;--accent:#1d6b4f;--err:#8a1c1c}
@media (prefers-color-scheme:dark){:root{--bg:#10201a;--ink:#e8f0e6;--muted:#a9bdb0;--line:#2f4a3e;--sheet:#17302a;--accent:#7fd1a8;--err:#ffb4b4}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 system-ui,"Noto Sans Thai",sans-serif}
main{max-width:720px;margin:0 auto;padding:20px 16px 48px}h1{font-size:1.4rem;margin:0 0 4px}p.lead{margin:0 0 16px;color:var(--muted)}
form{display:grid;gap:10px;max-width:360px}label{display:grid;gap:4px}input{min-height:48px;padding:8px 12px;font-size:1.2rem;letter-spacing:.15em;text-transform:uppercase;border:1px solid var(--line);border-radius:10px;background:var(--sheet);color:var(--ink)}
button{min-height:48px;border:0;border-radius:10px;background:var(--accent);color:var(--bg);font-size:1rem;font-weight:600;cursor:pointer}
.err{color:var(--err);font-weight:600}ul{list-style:none;margin:0;padding:0;display:grid;gap:10px}
li a{display:block;padding:14px 16px;border:1px solid var(--line);border-radius:12px;background:var(--sheet);color:var(--ink);text-decoration:none;min-height:48px}
li a:hover,li a:focus-visible{border-color:var(--accent);outline:2px solid var(--accent);outline-offset:2px}
.t{font-weight:600;display:block}.m{color:var(--muted);font-size:.9rem}.sso{display:inline-block;margin-top:4px;padding:2px 8px;border-radius:999px;border:1px solid var(--accent);font-size:.85rem}
</style>
</head>
<body>
<main>
@if($links === null)
    <h1>ลิงก์การเรียนรู้</h1>
    <p class="lead">กรอกรหัสห้องเรียนที่ครูให้ เพื่อเปิดดูลิงก์ของห้อง</p>
    @if($error)<p class="err" role="alert">{{ $error }}</p>@endif
    <form method="post" action="{{ url('/learn/'.$id) }}" autocomplete="off">
        @csrf
        <label>รหัสห้องเรียน<input name="code" required maxlength="12" inputmode="text" autocapitalize="characters" spellcheck="false" aria-describedby="code-help"></label>
        <span id="code-help" class="m">เป็นตัวอักษรและตัวเลข 8 ตัว</span>
        <button type="submit">เปิดดูลิงก์</button>
    </form>
@else
    <h1>ลิงก์การเรียนรู้{{ $room ? ' ห้อง '.$room : '' }}</h1>
    <p class="lead">ลิงก์เปิดในแท็บใหม่ ลิงก์ที่มีป้ายด้านล่างใช้บัญชีโรงเรียนเข้าสู่ระบบได้</p>
    @if(!count($links))<p>ครูยังไม่ได้เพิ่มลิงก์</p>@endif
    <ul>
    @foreach($links as $l)
        <li><a href="{{ $l['url'] }}" target="_blank" rel="noopener noreferrer nofollow"><span class="t">{{ $l['title'] }}</span><span class="m">{{ $l['kind_label'] }}</span>@if($l['sso'])<br><span class="sso">เข้าสู่ระบบด้วยบัญชีโรงเรียน</span>@endif</a></li>
    @endforeach
    </ul>
@endif
</main>
</body>
</html>
