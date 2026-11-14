<?php

namespace App\Http\Controllers;

use App\Domain\SchoolAccounts;
use App\Support\Saan\Edition;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Publisher;

/** Admin page "ส่งข้อมูลไปหน้าเว็บ" (S3): last sync, queue length, errors and the full resend button. Internal edition only. */
class PublishAdminController extends Controller
{
    public function page()
    {
        app(SchoolAccounts::class)->authorize();
        $active = Publisher::active();
        $pub = null;
        if ($active) { try { $pub = Keys::publicKey(); } catch (\Throwable) { $pub = null; } }
        return view('publish-admin', ['active' => $active, 'edition' => Edition::current(), 'status' => $active ? Publisher::status() : null, 'publicKey' => $pub, 'flash' => session('publish_flash')]);
    }

    public function status()
    {
        app(SchoolAccounts::class)->authorize();
        abort_unless(Publisher::active(), 404);
        return response()->json(Publisher::status());
    }

    /** Queues a full rebuild and tries to deliver right away (the scheduler continues when the public side is slow or down). */
    public function full()
    {
        app(SchoolAccounts::class)->authorize();
        abort_unless(Publisher::active(), 404);
        if (Publisher::url() === '') return redirect('/admin/publish')->with('publish_flash', 'ยังไม่ได้ตั้งที่อยู่หน้าบ้าน: จับคู่ที่หน้า เชื่อมเว็บไซต์หน้าบ้าน หรือตั้ง SAAN_PUBLIC_PUSH_URL ในไฟล์ .env');
        try {
            $r = (new Publisher())->fullAndDeliver(20); // one lock: the scheduler and request hooks cannot interleave
            $msg = 'จัดคิวส่งข้อมูลทั้งหมดใหม่ '.$r['queued'].' แถว ส่งแล้ว '.$r['sent'].' ชุด'.($r['failed'] ? ' (ยังมีค้าง: '.($r['error'] ?? '').')' : '');
        } catch (\Throwable $e) {
            $msg = 'ส่งไม่สำเร็จ: '.$e->getMessage();
        }
        return redirect('/admin/publish')->with('publish_flash', $msg);
    }
}
