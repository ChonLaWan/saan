<?php

namespace App\Http\Controllers;

use App\Domain\SchoolAccounts;
use App\Support\Saan\Edition;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Pairing;
use App\Support\Saan\Publish\Publisher;
use App\Support\Saan\Settings;
use Illuminate\Http\Request;

/** Admin page "เชื่อมเว็บไซต์หน้าบ้าน" (S6): pairing status and the one-time pairing code. Internal edition only; admin role (route middleware). */
class PairingAdminController extends Controller
{
    public function page()
    {
        app(SchoolAccounts::class)->authorize();
        $active = Edition::isInternal();
        $info = null;
        if ($active) {
            $info = ['paired_at' => Settings::get('saan.paired_at'), 'push_url' => Publisher::rawUrl(), 'retired' => count(Keys::retiredInboxKeys()),
                'quarantine' => (int) \Illuminate\Support\Facades\DB::table('saan_inbox_quarantine')->count()];
        }
        return view('pairing-admin', ['active' => $active, 'edition' => Edition::current(), 'info' => $info, 'code' => session('pairing_code'), 'expires' => session('pairing_expires'), 'flash' => session('pairing_flash')]);
    }

    /** Saves the public site address and shows a new one-time code (once, from the session flash). */
    public function issue(Request $request)
    {
        app(SchoolAccounts::class)->authorize();
        abort_unless(Edition::isInternal(), 404);
        $d = $request->validate(['public_url' => ['required', 'string', 'max:200']], ['public_url.required' => 'กรุณากรอกที่อยู่เว็บไซต์หน้าบ้าน']);
        $push = Publisher::acceptable(rtrim(trim($d['public_url']), '/').'/_saan/push');
        if ($push === '' || !preg_match('~^https?://[A-Za-z0-9.-]+(:\d{1,5})?/_saan/push$~D', $push)) {
            return redirect('/admin/pairing')->with('pairing_flash', 'ที่อยู่หน้าบ้านต้องเป็นรูปแบบ https://www.โรงเรียน.ac.th (ไม่มีพาธต่อท้าย)');
        }
        Settings::put('saan.public_push_url', $push);
        $c = Pairing::issue((int) auth()->id(), null, $push); // the code is bound to this address
        return redirect('/admin/pairing')->with(['pairing_code' => $c['code'], 'pairing_expires' => $c['expires_at'], 'pairing_flash' => 'สร้างรหัสจับคู่แล้ว']);
    }
}
