<?php
namespace App\Console\Commands;

use App\Support\Saan\Edition;
use App\Support\Saan\Publish\Keys;
use App\Support\Saan\Publish\Pairing;
use App\Support\Saan\Publish\Publisher;
use Illuminate\Console\Command;

class SaanKeysRotate extends Command
{
    protected $signature = 'saan:keys-rotate {--push : signing key of the push channel} {--inbox : inbox box key} {--pull : signing key of the pull channel} {--unlock : unlock-code HMAC key} {--all : all of them} {--no-code : do not issue a new pairing code}';
    protected $description = 'Internal edition: rotate key pairs and issue a pairing code to pair the public edition again. The old inbox secret is kept until every envelope sealed with it has been pulled';

    public function handle(): int
    {
        if (!Edition::isInternal()) { $this->error('[ERR] ใช้ได้เฉพาะระบบภายใน (SAAN_EDITION=internal)'); return self::FAILURE; }
        $which = $this->option('all') ? ['push', 'inbox', 'pull', 'unlock'] : array_values(array_filter(['push', 'inbox', 'pull', 'unlock'], fn ($k) => $this->option($k)));
        if (!$which) { $this->error('[ERR] ระบุอย่างน้อยหนึ่งอย่าง: --push --inbox --pull --unlock หรือ --all'); return self::FAILURE; }
        try { $done = Keys::rotate($which); } catch (\Throwable $e) { $this->error('[ERR] '.$e->getMessage()); return self::FAILURE; }
        $this->line('[OK] หมุนกุญแจแล้ว: '.implode(', ', $done));
        if (in_array('inbox', $done, true)) $this->line('[OK] เก็บกุญแจ inbox เดิมไว้ ('.count(Keys::retiredInboxKeys()).' ชุด) จนกว่าจะดึงซองที่ปิดผนึกด้วยกุญแจเดิมหมด');
        $this->warn('[WARN] หน้าบ้านยังใช้กุญแจเดิมจนกว่าจะจับคู่ใหม่ การส่งข้อมูล/ดึงซองจะถูกปฏิเสธชั่วคราว'.(in_array('unlock', $done, true) ? ' และรหัสปลดล็อกที่ออกไว้ก่อนหน้า (ห้องเรียนรู้ ประกาศ) ต้องออกใหม่บนหน้าบ้าน' : ''));
        if (!$this->option('no-code')) {
            if (Publisher::url() === '') { $this->warn('[WARN] ยังไม่ได้ตั้งที่อยู่หน้าบ้าน จึงไม่ออกรหัสจับคู่ ตั้งที่หน้า เชื่อมเว็บไซต์หน้าบ้าน แล้วออกรหัสจากหน้านั้น'); return self::SUCCESS; }
            $c = Pairing::issue(null, null, Publisher::url());
            $this->line('[OK] รหัสจับคู่ใหม่ (หมดอายุ '.$c['expires_at'].' ใช้ได้ครั้งเดียว): '.$c['code']);
            $this->line('     บนหน้าบ้านรัน: php artisan saan:pair <url ระบบภายใน> <รหัส>');
        }
        return self::SUCCESS;
    }
}
