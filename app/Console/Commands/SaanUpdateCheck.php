<?php
namespace App\Console\Commands;

use App\Support\Modules\UpdateFeed;
use Illuminate\Console\Command;

class SaanUpdateCheck extends Command
{
    protected $signature = 'saan:update-check';
    protected $description = 'Check the signed Saan update feed for a new version (only when the admin switched the feed on; never installs anything)';

    public function handle(): int
    {
        if (!UpdateFeed::enabled()) { $this->line('[OK] ปิดการตรวจรุ่นใหม่อยู่ ไม่ได้เชื่อมต่อภายนอก'); return self::SUCCESS; }
        $r = (new UpdateFeed)->check();
        $last = \App\Support\Saan\Settings::get('updates.last_check');
        if (!($last['ok'] ?? false)) { $this->error('[ERR] '.($last['error'] ?? 'ตรวจไม่สำเร็จ')); return self::FAILURE; }
        $this->line(($r['any'] ?? false) ? '[OK] มีรุ่นใหม่' : '[OK] เป็นรุ่นล่าสุดแล้ว');
        return self::SUCCESS;
    }
}
