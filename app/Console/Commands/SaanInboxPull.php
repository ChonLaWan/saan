<?php
namespace App\Console\Commands;

use App\Support\Saan\Inbox\Puller;
use Illuminate\Console\Command;

class SaanInboxPull extends Command
{
    protected $signature = 'saan:inbox-pull';
    protected $description = 'Internal edition: pull sealed envelopes from the public edition, open them, hand them to their module and acknowledge them';

    public function handle(): int
    {
        if (!Puller::active()) { $this->line('[OK] ไม่ใช่ระบบภายใน (internal) ไม่มีกล่องรับเรื่องให้ดึง'); return self::SUCCESS; }
        $r = (new Puller())->run();
        if (isset($r['note'])) { $this->line('[OK] '.$r['note']); return self::SUCCESS; }
        $this->line('[OK] ดึง '.$r['pulled'].' ซอง ส่งต่อแล้ว '.$r['handled'].' เข้า quarantine '.$r['quarantined'].($r['failed'] ? ' | [WARN] ค้าง '.$r['failed'].' ซอง (ลองใหม่รอบหน้า)' : ''));
        if (isset($r['error'])) { $this->error('[ERR] '.$r['error']); return self::FAILURE; }
        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
