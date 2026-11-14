<?php
namespace App\Console\Commands;

use App\Support\Saan\Edition;
use App\Support\Saan\Inbox\PullServer;
use Illuminate\Console\Command;

class SaanInboxPurge extends Command
{
    protected $signature = 'saan:inbox-purge';
    protected $description = 'Public edition: delete sealed envelopes older than 7 days that were never pulled (the doctor reports them)';

    public function handle(): int
    {
        if (!Edition::isPublic()) { $this->line('[OK] ไม่ใช่หน้าบ้าน (public) ไม่มีกล่องรับเรื่องให้ล้าง'); return self::SUCCESS; }
        $n = (new PullServer())->purge();
        $this->line($n > 0 ? '[WARN] ลบซองที่ค้างเกิน '.PullServer::MAX_AGE_DAYS.' วันโดยไม่มีใครดึง '.$n.' ซอง' : '[OK] ไม่มีซองหมดอายุ');
        return self::SUCCESS;
    }
}
